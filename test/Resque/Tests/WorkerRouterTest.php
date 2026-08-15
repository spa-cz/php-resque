<?php
/**
 * Resque_Worker pwd-routing (pcntl_exec) integration tests.
 *
 * @package		Resque/Tests
 */
class Resque_Tests_WorkerRouterTest extends Resque_Tests_TestCase
{
    protected $routedPwd;
    protected $originalRouterEnabledEnv;
    protected $originalAppIncludeEnv;
    protected $originalRedisBackendEnv;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalRouterEnabledEnv = getenv('RESQUE_ROUTER_ENABLED');
        $this->originalAppIncludeEnv    = getenv('APP_INCLUDE');
        $this->originalRedisBackendEnv  = getenv('REDIS_BACKEND');

        // Throwaway "checkout" directory simulating a different pwd. It gets
        // its own vendor/bin/resque-run-job (a copy of this repo's, since
        // there's no separate real Composer install for the test target)
        // plus a vendor/autoload.php shim that just requires this repo's
        // real autoloader, and a minimal APP_INCLUDE stub defining the job
        // class - mirroring how a consuming app vendors this library.
        $this->routedPwd = realpath(sys_get_temp_dir()) . '/resque-router-test-' . uniqid();
        mkdir($this->routedPwd . '/vendor/bin', 0777, true);

        $realAutoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
        file_put_contents(
            $this->routedPwd . '/vendor/autoload.php',
            "<?php\nrequire_once " . var_export($realAutoload, true) . ";\n"
        );

        copy(__DIR__ . '/../../../bin/resque-run-job', $this->routedPwd . '/vendor/bin/resque-run-job');
        chmod($this->routedPwd . '/vendor/bin/resque-run-job', 0755);

        file_put_contents(
            $this->routedPwd . '/bootstrap.php',
            "<?php\n" .
            "#[AllowDynamicProperties]\n" .
            "class Router_Test_Job {\n" .
            "    public function perform() {\n" .
            "        file_put_contents(\$this->args['marker'], getcwd());\n" .
            "    }\n" .
            "}\n"
        );

        putenv('RESQUE_ROUTER_ENABLED=1');
        putenv('APP_INCLUDE=./bootstrap.php');

        // Point the routed (exec'd) process at the SAME ad hoc test
        // redis-server instance test/bootstrap.php started - it's only
        // known via an in-PHP Resque::setBackend() call in
        // Resque_Tests_TestCase::setUp(), which pcntl_exec() does NOT carry
        // across a process image replacement, so it must be re-exported as
        // a real OS env var here.
        $config = file_get_contents(REDIS_CONF);
        preg_match('#^\s*port\s+([0-9]+)#m', $config, $matches);
        putenv('REDIS_BACKEND=localhost:' . $matches[1]);
    }

    public function tearDown(): void
    {
        putenv($this->originalRouterEnabledEnv === false ? 'RESQUE_ROUTER_ENABLED' : 'RESQUE_ROUTER_ENABLED=' . $this->originalRouterEnabledEnv);
        putenv($this->originalAppIncludeEnv === false ? 'APP_INCLUDE' : 'APP_INCLUDE=' . $this->originalAppIncludeEnv);
        putenv($this->originalRedisBackendEnv === false ? 'REDIS_BACKEND' : 'REDIS_BACKEND=' . $this->originalRedisBackendEnv);

        @unlink($this->routedPwd . '/vendor/bin/resque-run-job');
        @unlink($this->routedPwd . '/vendor/autoload.php');
        @rmdir($this->routedPwd . '/vendor/bin');
        @rmdir($this->routedPwd . '/vendor');
        @unlink($this->routedPwd . '/bootstrap.php');
        @unlink($this->routedPwd . '/marker.txt');
        @rmdir($this->routedPwd);

        parent::tearDown();
    }

    public function testJobWithMismatchedPwdIsRoutedAndRunsFromThatPwd()
    {
        $marker = $this->routedPwd . '/marker.txt';

        // Distinct queue name (not 'jobs') so this test can't collide with
        // any sibling test's use of the shared 'jobs' queue on the shared
        // test redis instance.
        $token = Resque::enqueue(
            'router-test-jobs',
            'Router_Test_Job',
            array('marker' => $marker),
            true,
            '',
            $this->routedPwd
        );

        $worker = new Resque_Worker('router-test-jobs');
        $worker->setLogger($this->logger);
        $worker->work(0);

        $this->assertFileExists($marker);
        $this->assertEquals($this->routedPwd, trim(file_get_contents($marker)));

        $status = new Resque_Job_Status($token);
        $this->assertEquals(Resque_Job_Status::STATUS_COMPLETE, $status->get());
    }
}
