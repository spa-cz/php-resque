<?php
/**
 * Resque_Worker tests.
 *
 * @package		Resque/Tests
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Tests_WorkerTest extends Resque_Tests_TestCase
{
	public function testWorkerRegistersInList()
	{
		$worker = new Resque_Worker('*');
		$worker->setLogger($this->logger);
		$worker->registerWorker();

		// Make sure the worker is in the list
		$this->assertTrue((bool)$this->redis->sismember('resque:workers', (string)$worker));
	}

	public function testGetAllWorkers()
	{
		$num = 3;
		// Register a few workers
		for($i = 0; $i < $num; ++$i) {
			$worker = new Resque_Worker('queue_' . $i);
			$worker->setLogger($this->logger);
			$worker->registerWorker();
		}

		// Now try to get them
		$this->assertEquals($num, count(Resque_Worker::all()));
	}

	public function testGetWorkerById()
	{
		$worker = new Resque_Worker('*');
		$worker->setLogger($this->logger);
		$worker->registerWorker();

		$newWorker = Resque_Worker::find((string)$worker);
		$this->assertEquals((string)$worker, (string)$newWorker);
	}

	public function testInvalidWorkerDoesNotExist()
	{
		$this->assertFalse(Resque_Worker::exists('blah'));
	}

	public function testWorkerCanUnregister()
	{
		$worker = new Resque_Worker('*');
		$worker->setLogger($this->logger);
		$worker->registerWorker();
		$worker->unregisterWorker();

		$this->assertFalse(Resque_Worker::exists((string)$worker));
		$this->assertEquals(array(), Resque_Worker::all());
		$this->assertEquals(array(), $this->redis->smembers('resque:workers'));
	}

	public function testPausedWorkerDoesNotPickUpJobs()
	{
		$worker = new Resque_Worker('*');
		$worker->setLogger($this->logger);
		$worker->pauseProcessing();
		Resque::enqueue('jobs', 'Test_Job');
		$worker->work(0);
		$worker->work(0);
		$this->assertEquals(0, Resque_Stat::get('processed'));
	}

	public function testResumedWorkerPicksUpJobs()
	{
		$worker = new Resque_Worker('*');
		$worker->setLogger($this->logger);
		$worker->pauseProcessing();
		Resque::enqueue('jobs', 'Test_Job');
		$worker->work(0);
		$this->assertEquals(0, Resque_Stat::get('processed'));
		$worker->unPauseProcessing();
		$worker->work(0);
		$this->assertEquals(1, Resque_Stat::get('processed'));
	}

	public function testWorkerCanWorkOverMultipleQueues()
	{
		$worker = new Resque_Worker(array(
			'queue1',
			'queue2'
		));
		$worker->setLogger($this->logger);
		$worker->registerWorker();
		Resque::enqueue('queue1', 'Test_Job_1');
		Resque::enqueue('queue2', 'Test_Job_2');

		$job = $worker->reserve();
		$this->assertEquals('queue1', $job->queue);

		$job = $worker->reserve();
		$this->assertEquals('queue2', $job->queue);
	}

	public function testWorkerWorksQueuesInSpecifiedOrder()
	{
		$worker = new Resque_Worker(array(
			'high',
			'medium',
			'low'
		));
		$worker->setLogger($this->logger);
		$worker->registerWorker();

		// Queue the jobs in a different order
		Resque::enqueue('low', 'Test_Job_1');
		Resque::enqueue('high', 'Test_Job_2');
		Resque::enqueue('medium', 'Test_Job_3');

		// Now check we get the jobs back in the right order
		$job = $worker->reserve();
		$this->assertEquals('high', $job->queue);

		$job = $worker->reserve();
		$this->assertEquals('medium', $job->queue);

		$job = $worker->reserve();
		$this->assertEquals('low', $job->queue);
	}

	public function testWildcardQueueWorkerWorksAllQueues()
	{
		$worker = new Resque_Worker('*');
		$worker->setLogger($this->logger);
		$worker->registerWorker();

		Resque::enqueue('queue1', 'Test_Job_1');
		Resque::enqueue('queue2', 'Test_Job_2');

		$job = $worker->reserve();
		$this->assertEquals('queue1', $job->queue);

		$job = $worker->reserve();
		$this->assertEquals('queue2', $job->queue);
	}

	public function testWorkerDoesNotWorkOnUnknownQueues()
	{
		$worker = new Resque_Worker('queue1');
		$worker->setLogger($this->logger);
		$worker->registerWorker();
		Resque::enqueue('queue2', 'Test_Job');

		$this->assertFalse($worker->reserve());
	}

	public function testWorkerClearsItsStatusWhenNotWorking()
	{
		Resque::enqueue('jobs', 'Test_Job');
		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$job = $worker->reserve();
		$worker->workingOn($job);
		$worker->doneWorking();
		$this->assertEquals(array(), $worker->job());
	}

	public function testWorkerRecordsWhatItIsWorkingOn()
	{
		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$worker->registerWorker();

		$payload = array(
			'class' => 'Test_Job'
		);
		$job = new Resque_Job('jobs', $payload);
		$worker->workingOn($job);

		$job = $worker->job();
		$this->assertEquals('jobs', $job['queue']);
		if(!isset($job['run_at'])) {
			$this->fail('Job does not have run_at time');
		}
		$this->assertEquals($payload, $job['payload']);
	}

	public function testWorkerErasesItsStatsWhenShutdown()
	{
		Resque::enqueue('jobs', 'Test_Job');
		Resque::enqueue('jobs', 'Invalid_Job');

		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$worker->work(0);
		$worker->work(0);

		$this->assertEquals(0, $worker->getStat('processed'));
		$this->assertEquals(0, $worker->getStat('failed'));
	}

	public function testWorkerCleansUpDeadWorkersOnStartup()
	{
		// Register a good worker
		$goodWorker = new Resque_Worker('jobs');
		$goodWorker->setLogger($this->logger);
		$goodWorker->registerWorker();
		$workerId = explode(':', $goodWorker);

		// Register some bad workers
		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$worker->setId($workerId[0].':1:jobs');
		$worker->registerWorker();

		$worker = new Resque_Worker(array('high', 'low'));
		$worker->setLogger($this->logger);
		$worker->setId($workerId[0].':2:high,low');
		$worker->registerWorker();

		$this->assertEquals(3, count(Resque_Worker::all()));

		$goodWorker->pruneDeadWorkers();

		// There should only be $goodWorker left now
		$this->assertEquals(1, count(Resque_Worker::all()));
	}

	public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers()
	{
		// Register a bad worker on this machine
		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$workerId = explode(':', $worker);
		$worker->setId($workerId[0].':1:jobs');
		$worker->registerWorker();

		// Register some other false workers
		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$worker->setId('my.other.host:1:jobs');
		$worker->registerWorker();

		$this->assertEquals(2, count(Resque_Worker::all()));

		$worker->pruneDeadWorkers();

		// my.other.host should be left
		$workers = Resque_Worker::all();
		$this->assertEquals(1, count($workers));
		$this->assertEquals((string)$worker, (string)$workers[0]);
	}

	public function testWorkerFailsUncompletedJobsOnExit()
	{
		$worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
		$worker->registerWorker();

		$payload = array(
			'class' => 'Test_Job'
		);
		$job = new Resque_Job('jobs', $payload);

		$worker->workingOn($job);
		$worker->unregisterWorker();

		$this->assertEquals(1, Resque_Stat::get('failed'));
	}

    public function testBlockingListPop()
    {
        $worker = new Resque_Worker('jobs');
		$worker->setLogger($this->logger);
        $worker->registerWorker();

        Resque::enqueue('jobs', 'Test_Job_1');
        Resque::enqueue('jobs', 'Test_Job_2');

        $i = 1;
        while($job = $worker->reserve(true, 1))
        {
            $this->assertEquals('Test_Job_' . $i, $job->payload['class']);

            if($i == 2) {
                break;
            }

            $i++;
        }

        $this->assertEquals(2, $i);
    }

    public function testWorkerFailsSegmentationFaultJob()
    {
        Resque::enqueue('jobs', 'Test_Infinite_Recursion_Job');

        $worker = new Resque_Worker('jobs');
        $worker->setLogger($this->logger);
        $worker->work(0);

        $this->assertEquals(1, Resque_Stat::get('failed'));
    }

    protected $originalRouterEnabledEnv;
    protected $originalRouterScriptPathEnv;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalRouterEnabledEnv = getenv('RESQUE_ROUTER_ENABLED');
        $this->originalRouterScriptPathEnv = getenv('RESQUE_ROUTER_SCRIPT_PATH');
        putenv('RESQUE_ROUTER_ENABLED');
        putenv('RESQUE_ROUTER_SCRIPT_PATH');
    }

    public function tearDown(): void
    {
        if ($this->originalRouterEnabledEnv === false) {
            putenv('RESQUE_ROUTER_ENABLED');
        } else {
            putenv('RESQUE_ROUTER_ENABLED=' . $this->originalRouterEnabledEnv);
        }

        if ($this->originalRouterScriptPathEnv === false) {
            putenv('RESQUE_ROUTER_SCRIPT_PATH');
        } else {
            putenv('RESQUE_ROUTER_SCRIPT_PATH=' . $this->originalRouterScriptPathEnv);
        }
    }

    public function testShouldRouteJobReturnsFalseWhenRouterEnabledEnvNotSet()
    {
        putenv('RESQUE_ROUTER_ENABLED');

        $job = new Resque_Job('jobs', array('class' => 'Test_Job', 'pwd' => sys_get_temp_dir()));

        $worker = new Resque_Worker('jobs');
        $this->assertFalse($worker->shouldRouteJob($job));
    }

    public function testShouldRouteJobReturnsFalseWhenPayloadHasNoPwd()
    {
        putenv('RESQUE_ROUTER_ENABLED=1');

        $job = new Resque_Job('jobs', array('class' => 'Test_Job'));

        $worker = new Resque_Worker('jobs');
        $this->assertFalse($worker->shouldRouteJob($job));
    }

    public function testShouldRouteJobReturnsFalseWhenPwdEqualsCwd()
    {
        putenv('RESQUE_ROUTER_ENABLED=1');

        $job = new Resque_Job('jobs', array('class' => 'Test_Job', 'pwd' => getcwd()));

        $worker = new Resque_Worker('jobs');
        $this->assertFalse($worker->shouldRouteJob($job));
    }

    public function testShouldRouteJobReturnsTrueForNonexistentPwdPath()
    {
        putenv('RESQUE_ROUTER_ENABLED=1');

        $job = new Resque_Job('jobs', array('class' => 'Test_Job', 'pwd' => '/definitely/does/not/exist/' . uniqid()));

        $worker = new Resque_Worker('jobs');
        $this->assertTrue($worker->shouldRouteJob($job));
    }

    public function testShouldRouteJobReturnsTrueOnGenuineMismatch()
    {
        putenv('RESQUE_ROUTER_ENABLED=1');

        $job = new Resque_Job('jobs', array('class' => 'Test_Job', 'pwd' => sys_get_temp_dir()));

        $worker = new Resque_Worker('jobs');
        $this->assertTrue($worker->shouldRouteJob($job));
    }

    public function testResolveRouterScriptFindsDefaultVendorBinLocation()
    {
        $pwd = sys_get_temp_dir() . '/resque-resolve-test-' . uniqid();
        mkdir($pwd . '/vendor/bin', 0777, true);
        touch($pwd . '/vendor/bin/resque-run-job');

        $worker = new Resque_Worker('jobs');
        $this->assertEquals($pwd . '/vendor/bin/resque-run-job', $worker->resolveRouterScript($pwd));

        $this->rrmdir($pwd);
    }

    public function testResolveRouterScriptReturnsNullWhenNothingExists()
    {
        $pwd = sys_get_temp_dir() . '/resque-resolve-test-' . uniqid();

        $worker = new Resque_Worker('jobs');
        $this->assertNull($worker->resolveRouterScript($pwd));
    }

    public function testResolveRouterScriptHonoursSingleCustomPath()
    {
        $pwd = sys_get_temp_dir() . '/resque-resolve-test-' . uniqid();
        mkdir($pwd . '/lib/vendor/bin', 0777, true);
        touch($pwd . '/lib/vendor/bin/resque-run-job');

        putenv('RESQUE_ROUTER_SCRIPT_PATH=lib/vendor/bin/resque-run-job');

        $worker = new Resque_Worker('jobs');
        $this->assertEquals($pwd . '/lib/vendor/bin/resque-run-job', $worker->resolveRouterScript($pwd));

        $this->rrmdir($pwd);
    }

    /**
     * Reproduces the reported bug: a single checkout enqueues jobs from two
     * different entry points with two different working directories - a
     * PHP-FPM request rooted one level below the checkout (in a "www/"
     * subdirectory) and a CLI/cron script rooted at the checkout itself.
     * A single static RESQUE_ROUTER_SCRIPT_PATH must resolve both, via the
     * bounded upward walk trying each parent directory in turn.
     */
    public function testResolveRouterScriptWalksUpwardToFindCheckoutRoot()
    {
        $checkoutRoot = sys_get_temp_dir() . '/resque-resolve-test-' . uniqid();
        mkdir($checkoutRoot . '/www', 0777, true);
        mkdir($checkoutRoot . '/lib/vendor/bin', 0777, true);
        touch($checkoutRoot . '/lib/vendor/bin/resque-run-job');

        putenv('RESQUE_ROUTER_SCRIPT_PATH=lib/vendor/bin/resque-run-job');

        $worker = new Resque_Worker('jobs');

        // CLI/cron-style pwd: matches directly at the checkout root, no
        // upward walk needed.
        $this->assertEquals(
            $checkoutRoot . '/lib/vendor/bin/resque-run-job',
            $worker->resolveRouterScript($checkoutRoot)
        );

        // PHP-FPM-style pwd, one level below the checkout: doesn't exist
        // directly under it, found one level up via the upward walk.
        $this->assertEquals(
            $checkoutRoot . '/www/../lib/vendor/bin/resque-run-job',
            $worker->resolveRouterScript($checkoutRoot . '/www')
        );

        $this->rrmdir($checkoutRoot);
    }

    public function testResolveRouterScriptReturnsNullBeyondSearchDepth()
    {
        $checkoutRoot = sys_get_temp_dir() . '/resque-resolve-test-' . uniqid();
        $tooDeep = $checkoutRoot . str_repeat('/nested', Resque_Worker::MAX_ROUTER_SCRIPT_SEARCH_DEPTH + 1);
        mkdir($tooDeep, 0777, true);
        mkdir($checkoutRoot . '/lib/vendor/bin', 0777, true);
        touch($checkoutRoot . '/lib/vendor/bin/resque-run-job');

        putenv('RESQUE_ROUTER_SCRIPT_PATH=lib/vendor/bin/resque-run-job');

        $worker = new Resque_Worker('jobs');
        $this->assertNull($worker->resolveRouterScript($tooDeep));

        $this->rrmdir($checkoutRoot);
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
