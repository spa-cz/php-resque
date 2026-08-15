<?php

/**
 * Resque_Job tests.
 *
 * @package		Resque/Tests
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Tests_JobTest extends Resque_Tests_TestCase
{
	protected $worker;

	public function setUp(): void
	{
		parent::setUp();

		// Register a worker to test with
		$this->worker = new Resque_Worker('jobs');
		$this->worker->setLogger($this->logger);
		$this->worker->registerWorker();
	}

	public function testJobCanBeQueued()
	{
		$this->assertTrue((bool)Resque::enqueue('jobs', 'Test_Job'));
	}

	public function testRedisErrorThrowsExceptionOnJobCreation()
	{
		$this->expectException(Resque_RedisException::class);

		$mockCredis = $this->getMockBuilder('Credis_Client')
			->setMethods(['connect', '__call'])
			->getMock();
		$mockCredis->expects($this->any())->method('__call')
			->will($this->throwException(new CredisException('failure')));

		Resque::setBackend(function($database) use ($mockCredis) {
			return new Resque_Redis('localhost:6379', $database, $mockCredis);
		});
		Resque::enqueue('jobs', 'This is a test');
	}

	public function testQeueuedJobCanBeReserved()
	{
		Resque::enqueue('jobs', 'Test_Job');

		$job = Resque_Job::reserve('jobs');
		if($job == false) {
			$this->fail('Job could not be reserved.');
		}
		$this->assertEquals('jobs', $job->queue);
		$this->assertEquals('Test_Job', $job->payload['class']);
	}

	public function testObjectArgumentsCannotBePassedToJob()
	{
		$this->expectException(InvalidArgumentException::class);

		$args = new stdClass;
		$args->test = 'somevalue';
		Resque::enqueue('jobs', 'Test_Job', $args);
	}

	public function testQueuedJobReturnsExactSamePassedInArguments()
	{
		$args = array(
			'int' => 123,
			'numArray' => array(
				1,
				2,
			),
			'assocArray' => array(
				'key1' => 'value1',
				'key2' => 'value2'
			),
		);
		Resque::enqueue('jobs', 'Test_Job', $args);
		$job = Resque_Job::reserve('jobs');

		$this->assertEquals($args, $job->getArguments());
	}

	public function testAfterJobIsReservedItIsRemoved()
	{
		Resque::enqueue('jobs', 'Test_Job');
		Resque_Job::reserve('jobs');
		$this->assertFalse(Resque_Job::reserve('jobs'));
	}

	public function testRecreatedJobMatchesExistingJob()
	{
		$args = array(
			'int' => 123,
			'numArray' => array(
				1,
				2,
			),
			'assocArray' => array(
				'key1' => 'value1',
				'key2' => 'value2'
			),
		);

		Resque::enqueue('jobs', 'Test_Job', $args);
		$job = Resque_Job::reserve('jobs');

		// Now recreate it
		$job->recreate();

		$newJob = Resque_Job::reserve('jobs');
		$this->assertEquals($job->payload['class'], $newJob->payload['class']);
		$this->assertEquals($job->getArguments(), $newJob->getArguments());
	}

	public function testJobPayloadDefaultsPwdToCwd()
	{
		Resque::enqueue('jobs', 'Test_Job');
		$job = Resque_Job::reserve('jobs');

		$this->assertEquals(getcwd(), $job->payload['pwd']);
	}

	public function testJobPayloadPwdCanBeOverridden()
	{
		Resque::enqueue('jobs', 'Test_Job', null, false, '', '/some/other/pwd');
		$job = Resque_Job::reserve('jobs');

		$this->assertEquals('/some/other/pwd', $job->payload['pwd']);
	}

	public function testRecreatePreservesOriginalPwd()
	{
		Resque::enqueue('jobs', 'Test_Job', null, false, '', '/some/other/pwd');
		$job = Resque_Job::reserve('jobs');

		$job->recreate();

		$newJob = Resque_Job::reserve('jobs');
		$this->assertEquals('/some/other/pwd', $newJob->payload['pwd']);
	}

	public function testLegacyPayloadWithoutPwdStillWorks()
	{
		$payload = array(
			'class' => 'Test_Job',
			'args'  => array(null),
		);
		$job = new Resque_Job('jobs', $payload);

		$id = $job->recreate();

		$this->assertNotEmpty($id);
		$newJob = Resque_Job::reserve('jobs');
		$this->assertEquals(getcwd(), $newJob->payload['pwd']);
	}

	/**
	 * Running as this repo's own test suite, resolveCheckoutRoot()'s
	 * __DIR__-based Composer-root lookup always finds this repo's own root
	 * (the "root package" candidate - see findComposerRoot()). These tests
	 * exercise the common-ancestor logic directly against that real,
	 * verified anchor, using purely synthetic $cwd strings - no real
	 * directories needed, since findCommonAncestor() is pure string logic.
	 */
	public function testResolveCheckoutRootReturnsComposerRootWhenCwdIsInsideIt()
	{
		$repoRoot = realpath(__DIR__ . '/../../..');

		$this->assertEquals($repoRoot, Resque_Job::resolveCheckoutRoot($repoRoot));
		$this->assertEquals($repoRoot, Resque_Job::resolveCheckoutRoot($repoRoot . '/some/nested/path'));
	}

	/**
	 * Reproduces the reported scenario: Composer lives in a subdirectory of
	 * the actual checkout (e.g. "lib/"), and the calling process runs from
	 * a sibling directory (e.g. "app/" or "www/"). Their common ancestor -
	 * the outer checkout root - is what APP_INCLUDE's conventional paths
	 * are written relative to.
	 */
	public function testResolveCheckoutRootReturnsCommonAncestorForSiblingCwd()
	{
		$repoRoot = realpath(__DIR__ . '/../../..');
		$outerRoot = dirname($repoRoot);
		$siblingCwd = $outerRoot . '/some-sibling-dir/deeper';

		$this->assertEquals($outerRoot, Resque_Job::resolveCheckoutRoot($siblingCwd));
	}

	public function testResolveCheckoutRootFallsBackToComposerRootWhenCwdIsUnrelated()
	{
		$repoRoot = realpath(__DIR__ . '/../../..');

		// "/" shares only the empty root segment with $repoRoot - climbing
		// that far exceeds MAX_REPO_ROOT_CLIMB, so the guard should trigger.
		$this->assertEquals($repoRoot, Resque_Job::resolveCheckoutRoot('/'));
	}

	/**
	 * findComposerRoot()'s "installed as a normal Composer dependency"
	 * candidate (5 levels up from __DIR__, checking for
	 * vendor/bin/resque-run-job) can't be exercised from this repo's own
	 * test suite - __DIR__ is fixed at compile time to wherever Job.php
	 * actually lives, which here is always the "root package" depth. This
	 * requires a fresh subprocess with its own copy of Job.php nested at a
	 * genuinely different depth. Job.php has no extends/implements/use of
	 * other Resque classes, so the subprocess only needs a bare require -
	 * no autoloader, no Redis, no full Resque::enqueue() round trip.
	 */
	public function testResolveCheckoutRootFindsComposerRootWhenInstalledAsDependency()
	{
		$tmp = realpath(sys_get_temp_dir()) . '/resque-dep-test-' . uniqid();
		mkdir($tmp . '/vendor/resque/php-resque/lib/Resque', 0777, true);
		mkdir($tmp . '/vendor/bin', 0777, true);
		copy(__DIR__ . '/../../../lib/Resque/Job.php', $tmp . '/vendor/resque/php-resque/lib/Resque/Job.php');
		touch($tmp . '/vendor/bin/resque-run-job');

		try {
			// Explicit $cwd = $tmp itself (the "CLI/cron-style" case: cwd is
			// the checkout root), so this test isolates findComposerRoot()
			// from the common-ancestor step (covered separately below) - an
			// unspecified subprocess cwd would otherwise combine with $tmp
			// unpredictably.
			$result = $this->runResolveCheckoutRootInSubprocess(
				$tmp . '/vendor/resque/php-resque/lib/Resque/Job.php',
				$tmp
			);

			$this->assertEquals($tmp, $result);
		} finally {
			$this->rrmdir($tmp);
		}
	}

	/**
	 * The actual web-shaped scenario end to end, combining both mechanisms
	 * in a real subprocess: Composer nested under "lib/" (found via the
	 * dependency-install __DIR__ candidate) plus a sibling "app/" cwd
	 * (found via the common-ancestor step) - together resolving to the
	 * outer checkout root, not the Composer root.
	 */
	public function testResolveCheckoutRootCombinesBothMechanismsForNestedComposerRoot()
	{
		$tmp = realpath(sys_get_temp_dir()) . '/resque-dep-test-' . uniqid();
		mkdir($tmp . '/lib/vendor/resque/php-resque/lib/Resque', 0777, true);
		mkdir($tmp . '/lib/vendor/bin', 0777, true);
		mkdir($tmp . '/app', 0777, true);
		copy(__DIR__ . '/../../../lib/Resque/Job.php', $tmp . '/lib/vendor/resque/php-resque/lib/Resque/Job.php');
		touch($tmp . '/lib/vendor/bin/resque-run-job');

		try {
			$result = $this->runResolveCheckoutRootInSubprocess(
				$tmp . '/lib/vendor/resque/php-resque/lib/Resque/Job.php',
				$tmp . '/app'
			);

			$this->assertEquals($tmp, $result);
		} finally {
			$this->rrmdir($tmp);
		}
	}

	private function runResolveCheckoutRootInSubprocess($jobPhpPath, $cwd = null)
	{
		// tempnam() itself creates the file at its returned path - write
		// there directly rather than appending ".php" to a different,
		// never-created path (which would leak the original).
		$runner = tempnam(sys_get_temp_dir(), 'resque-runner-');
		file_put_contents(
			$runner,
			"<?php\n" .
			"require \$argv[1];\n" .
			"if (isset(\$argv[2])) {\n" .
			"    chdir(\$argv[2]);\n" .
			"}\n" .
			"echo Resque_Job::resolveCheckoutRoot();\n"
		);

		try {
			$command = 'php ' . escapeshellarg($runner) . ' ' . escapeshellarg($jobPhpPath);
			if ($cwd !== null) {
				$command .= ' ' . escapeshellarg($cwd);
			}

			$output = shell_exec($command);

			return trim((string)$output);
		} finally {
			unlink($runner);
		}
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

	public function testFailedJobExceptionsAreCaught()
	{
		$payload = array(
			'class' => 'Failing_Job',
			'args' => null
		);
		$job = new Resque_Job('jobs', $payload);
		$job->worker = $this->worker;

		$this->worker->perform($job);

		$this->assertEquals(1, Resque_Stat::get('failed'));
		$this->assertEquals(1, Resque_Stat::get('failed:'.$this->worker));
	}

	public function testJobWithoutPerformMethodThrowsException()
	{
		$this->expectException(Resque_Exception::class);

		Resque::enqueue('jobs', 'Test_Job_Without_Perform_Method');
		$job = $this->worker->reserve();
		$job->worker = $this->worker;
		$job->perform();
	}

	public function testInvalidJobThrowsException()
	{
		$this->expectException(Resque_Exception::class);

		Resque::enqueue('jobs', 'Invalid_Job');
		$job = $this->worker->reserve();
		$job->worker = $this->worker;
		$job->perform();
	}

	public function testJobWithSetUpCallbackFiresSetUp()
	{
		$payload = array(
			'class' => 'Test_Job_With_SetUp',
			'args' => array(
				'somevar',
				'somevar2',
			),
		);
		$job = new Resque_Job('jobs', $payload);
		$job->perform();

		$this->assertTrue(Test_Job_With_SetUp::$called);
	}

	public function testJobWithTearDownCallbackFiresTearDown()
	{
		$payload = array(
			'class' => 'Test_Job_With_TearDown',
			'args' => array(
				'somevar',
				'somevar2',
			),
		);
		$job = new Resque_Job('jobs', $payload);
		$job->perform();

		$this->assertTrue(Test_Job_With_TearDown::$called);
	}

	public function testNamespaceNaming() {
		$fixture = array(
			array('test' => 'more:than:one:with:', 'assertValue' => 'more:than:one:with:'),
			array('test' => 'more:than:one:without', 'assertValue' => 'more:than:one:without:'),
			array('test' => 'resque', 'assertValue' => 'resque:'),
			array('test' => 'resque:', 'assertValue' => 'resque:'),
		);

		foreach($fixture as $item) {
			Resque_Redis::prefix($item['test']);
			$this->assertEquals(Resque_Redis::getPrefix(), $item['assertValue']);
		}
	}

	public function testJobWithNamespace()
	{
		Resque_Redis::prefix('php');
		$queue = 'jobs';
		$payload = array('another_value');
		Resque::enqueue($queue, 'Test_Job_With_TearDown', $payload);

		$this->assertEquals(Resque::queues(), array('jobs'));
		$this->assertEquals(Resque::size($queue), 1);

		Resque_Redis::prefix('resque');
		$this->assertEquals(Resque::size($queue), 0);
	}

	public function testDequeueAll()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		$this->assertEquals(Resque::size($queue), 2);
		$this->assertEquals(Resque::dequeue($queue), 2);
		$this->assertEquals(Resque::size($queue), 0);
	}

	public function testDequeueMakeSureNotDeleteOthers()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		$other_queue = 'other_jobs';
		Resque::enqueue($other_queue, 'Test_Job_Dequeue');
		Resque::enqueue($other_queue, 'Test_Job_Dequeue');
		$this->assertEquals(Resque::size($queue), 2);
		$this->assertEquals(Resque::size($other_queue), 2);
		$this->assertEquals(Resque::dequeue($queue), 2);
		$this->assertEquals(Resque::size($queue), 0);
		$this->assertEquals(Resque::size($other_queue), 2);
	}

	public function testDequeueSpecificItem()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue1');
		Resque::enqueue($queue, 'Test_Job_Dequeue2');
		$this->assertEquals(Resque::size($queue), 2);
		$test = array('Test_Job_Dequeue2');
		$this->assertEquals(Resque::dequeue($queue, $test), 1);
		$this->assertEquals(Resque::size($queue), 1);
	}

	public function testDequeueSpecificMultipleItems()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue1');
		Resque::enqueue($queue, 'Test_Job_Dequeue2');
		Resque::enqueue($queue, 'Test_Job_Dequeue3');
		$this->assertEquals(Resque::size($queue), 3);
		$test = array('Test_Job_Dequeue2', 'Test_Job_Dequeue3');
		$this->assertEquals(Resque::dequeue($queue, $test), 2);
		$this->assertEquals(Resque::size($queue), 1);
	}

	public function testDequeueNonExistingItem()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue1');
		Resque::enqueue($queue, 'Test_Job_Dequeue2');
		Resque::enqueue($queue, 'Test_Job_Dequeue3');
		$this->assertEquals(Resque::size($queue), 3);
		$test = array('Test_Job_Dequeue4');
		$this->assertEquals(Resque::dequeue($queue, $test), 0);
		$this->assertEquals(Resque::size($queue), 3);
	}

	public function testDequeueNonExistingItem2()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue1');
		Resque::enqueue($queue, 'Test_Job_Dequeue2');
		Resque::enqueue($queue, 'Test_Job_Dequeue3');
		$this->assertEquals(Resque::size($queue), 3);
		$test = array('Test_Job_Dequeue4', 'Test_Job_Dequeue1');
		$this->assertEquals(Resque::dequeue($queue, $test), 1);
		$this->assertEquals(Resque::size($queue), 2);
	}

	public function testDequeueItemID()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		$qid = Resque::enqueue($queue, 'Test_Job_Dequeue');
		$this->assertEquals(Resque::size($queue), 2);
		$test = array('Test_Job_Dequeue' => $qid);
		$this->assertEquals(Resque::dequeue($queue, $test), 1);
		$this->assertEquals(Resque::size($queue), 1);
	}

	public function testDequeueWrongItemID()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		$qid = Resque::enqueue($queue, 'Test_Job_Dequeue');
		$this->assertEquals(Resque::size($queue), 2);
		#qid right but class name is wrong
		$test = array('Test_Job_Dequeue1' => $qid);
		$this->assertEquals(Resque::dequeue($queue, $test), 0);
		$this->assertEquals(Resque::size($queue), 2);
	}

	public function testDequeueWrongItemID2()
	{
		$queue = 'jobs';
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		$qid = Resque::enqueue($queue, 'Test_Job_Dequeue');
		$this->assertEquals(Resque::size($queue), 2);
		$test = array('Test_Job_Dequeue' => 'r4nD0mH4sh3dId');
		$this->assertEquals(Resque::dequeue($queue, $test), 0);
		$this->assertEquals(Resque::size($queue), 2);
	}

	public function testDequeueItemWithArg()
	{
		$queue = 'jobs';
		$arg = array('foo' => 1, 'bar' => 2);
		Resque::enqueue($queue, 'Test_Job_Dequeue9');
		Resque::enqueue($queue, 'Test_Job_Dequeue9', $arg);
		$this->assertEquals(Resque::size($queue), 2);
		$test = array('Test_Job_Dequeue9' => $arg);
		$this->assertEquals(Resque::dequeue($queue, $test), 1);
		#$this->assertEquals(Resque::size($queue), 1);
	}

	public function testDequeueSeveralItemsWithArgs()
	{
		// GIVEN
		$queue = 'jobs';
		$args = array('foo' => 1, 'bar' => 10);
		$removeArgs = array('foo' => 1, 'bar' => 2);
		Resque::enqueue($queue, 'Test_Job_Dequeue9', $args);
		Resque::enqueue($queue, 'Test_Job_Dequeue9', $removeArgs);
		Resque::enqueue($queue, 'Test_Job_Dequeue9', $removeArgs);
		$this->assertEquals(Resque::size($queue), 3);

		// WHEN
		$test = array('Test_Job_Dequeue9' => $removeArgs);
		$removedItems = Resque::dequeue($queue, $test);

		// THEN
		$this->assertEquals($removedItems, 2);
		$this->assertEquals(Resque::size($queue), 1);
		$item = Resque::pop($queue);
		$this->assertIsArray($item['args']);
		$this->assertEquals(10, $item['args'][0]['bar'], 'Wrong items were dequeued from queue!');
	}

	public function testDequeueItemWithUnorderedArg()
	{
		$queue = 'jobs';
		$arg = array('foo' => 1, 'bar' => 2);
		$arg2 = array('bar' => 2, 'foo' => 1);
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		Resque::enqueue($queue, 'Test_Job_Dequeue', $arg);
		$this->assertEquals(Resque::size($queue), 2);
		$test = array('Test_Job_Dequeue' => $arg2);
		$this->assertEquals(Resque::dequeue($queue, $test), 1);
		$this->assertEquals(Resque::size($queue), 1);
	}

	public function testDequeueItemWithiWrongArg()
	{
		$queue = 'jobs';
		$arg = array('foo' => 1, 'bar' => 2);
		$arg2 = array('foo' => 2, 'bar' => 3);
		Resque::enqueue($queue, 'Test_Job_Dequeue');
		Resque::enqueue($queue, 'Test_Job_Dequeue', $arg);
		$this->assertEquals(Resque::size($queue), 2);
		$test = array('Test_Job_Dequeue' => $arg2);
		$this->assertEquals(Resque::dequeue($queue, $test), 0);
		$this->assertEquals(Resque::size($queue), 2);
	}

	public function testUseDefaultFactoryToGetJobInstance()
	{
		$payload = array(
			'class' => 'Some_Job_Class',
			'args' => null
		);
		$job = new Resque_Job('jobs', $payload);
		$instance = $job->getInstance();
		$this->assertInstanceOf('Some_Job_Class', $instance);
	}

	public function testUseFactoryToGetJobInstance()
	{
		$payload = array(
			'class' => 'Some_Job_Class',
			'args' => array(array())
		);
		$job = new Resque_Job('jobs', $payload);
		$factory = new Some_Stub_Factory();
		$job->setJobFactory($factory);
		$instance = $job->getInstance();
		$this->assertInstanceOf('Resque_JobInterface', $instance);
	}

	public function testDoNotUseFactoryToGetInstance()
	{
		$payload = array(
			'class' => 'Some_Job_Class',
			'args' => array(array())
		);
		$job = new Resque_Job('jobs', $payload);
		$factory = $this->getMockBuilder('Resque_Job_FactoryInterface')
			->getMock();
		$testJob = $this->getMockBuilder('Resque_JobInterface')
			->getMock();
		$factory->expects(self::never())->method('create')->will(self::returnValue($testJob));
		$instance = $job->getInstance();
		$this->assertInstanceOf('Resque_JobInterface', $instance);
	}

	public function testJobStatusIsNullIfIdMissingFromPayload()
	{
		$payload = array(
			'class' => 'Some_Job_Class',
			'args' => null
		);
		$job = new Resque_Job('jobs', $payload);
		$this->assertEquals(null, $job->getStatus());
	}

	public function testJobCanBeRecreatedFromLegacyPayload()
	{
		$payload = array(
			'class' => 'Some_Job_Class',
			'args' => null
		);
		$job = new Resque_Job('jobs', $payload);
		$job->recreate();
		$newJob = Resque_Job::reserve('jobs');
		$this->assertEquals('jobs', $newJob->queue);
		$this->assertEquals('Some_Job_Class', $newJob->payload['class']);
		$this->assertNotNull($newJob->payload['id']);
	}
}

#[AllowDynamicProperties]
class Some_Job_Class implements Resque_JobInterface
{

	/**
	 * @return bool
	 */
	public function perform()
	{
		return true;
	}
}

class Some_Stub_Factory implements Resque_Job_FactoryInterface
{

	/**
	 * @param $className
	 * @param array $args
	 * @param $queue
	 * @return Resque_JobInterface
	 */
	public function create($className, $args, $queue)
	{
		return new Some_Job_Class();
	}
}
