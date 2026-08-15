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

    /**
     * Reproduces the reported production bug end to end via a real
     * pcntl_exec(): a checkout whose router script lives at
     * lib/vendor/bin/resque-run-job (not the default vendor/bin/...), where
     * the job gets *scheduled* from a "www/" subdirectory one level below
     * the checkout root (e.g. a PHP-FPM request through /www/index.php)
     * rather than the checkout root itself.
     *
     * The fix now lives at schedule time (Resque_Job::create(), via the
     * bounded upward walk), not routing time - so this test enqueues with
     * NO explicit $pwd override, chdir()-ing the test process itself into
     * the "www/" subdirectory first, exactly like a real PHP-FPM request
     * would. Resque_Worker::routeJobToPwd() then trusts the stored 'pwd'
     * as-is (plain concatenation, no search) and still routes correctly -
     * proving resolution genuinely happened at enqueue time, not routing
     * time.
     */
    public function testJobScheduledFromSubdirectoryIsRoutedToCheckoutRoot()
    {
        $originalRouterScriptPathEnv = getenv('RESQUE_ROUTER_SCRIPT_PATH');
        $originalAppIncludeEnv = getenv('APP_INCLUDE');
        $originalCwd = getcwd();

        $checkoutRoot = realpath(sys_get_temp_dir()) . '/resque-router-subdir-test-' . uniqid();
        $webDir = $checkoutRoot . '/www';
        mkdir($checkoutRoot . '/lib/vendor/bin', 0777, true);
        mkdir($webDir, 0777, true);

        $realAutoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
        file_put_contents(
            $checkoutRoot . '/lib/vendor/autoload.php',
            "<?php\nrequire_once " . var_export($realAutoload, true) . ";\n"
        );

        copy(__DIR__ . '/../../../bin/resque-run-job', $checkoutRoot . '/lib/vendor/bin/resque-run-job');
        chmod($checkoutRoot . '/lib/vendor/bin/resque-run-job', 0755);

        // Routing chdir()s to the stored 'pwd' - now the checkout root, not
        // "www/" - so APP_INCLUDE lives there too.
        $marker = $checkoutRoot . '/marker.txt';
        file_put_contents(
            $checkoutRoot . '/bootstrap.php',
            "<?php\n" .
            "#[AllowDynamicProperties]\n" .
            "class Router_Subdir_Test_Job {\n" .
            "    public function perform() {\n" .
            "        file_put_contents(\$this->args['marker'], getcwd());\n" .
            "    }\n" .
            "}\n"
        );

        putenv('APP_INCLUDE=./bootstrap.php');
        putenv('RESQUE_ROUTER_SCRIPT_PATH=lib/vendor/bin/resque-run-job');

        try {
            // Simulate a PHP-FPM request rooted in "www/": chdir() there
            // before enqueuing, with no explicit $pwd override, so
            // Resque_Job::create() must resolve the checkout root itself.
            chdir($webDir);
            $token = Resque::enqueue(
                'router-test-jobs',
                'Router_Subdir_Test_Job',
                array('marker' => $marker),
                true
            );
            chdir($originalCwd);

            $worker = new Resque_Worker('router-test-jobs');
            $worker->setLogger($this->logger);
            $worker->work(0);

            $this->assertFileExists($marker);
            $this->assertEquals($checkoutRoot, trim(file_get_contents($marker)));

            $status = new Resque_Job_Status($token);
            $this->assertEquals(Resque_Job_Status::STATUS_COMPLETE, $status->get());
        } finally {
            chdir($originalCwd);

            putenv($originalRouterScriptPathEnv === false ? 'RESQUE_ROUTER_SCRIPT_PATH' : 'RESQUE_ROUTER_SCRIPT_PATH=' . $originalRouterScriptPathEnv);
            putenv($originalAppIncludeEnv === false ? 'APP_INCLUDE' : 'APP_INCLUDE=' . $originalAppIncludeEnv);

            @unlink($checkoutRoot . '/lib/vendor/bin/resque-run-job');
            @unlink($checkoutRoot . '/lib/vendor/autoload.php');
            @rmdir($checkoutRoot . '/lib/vendor/bin');
            @rmdir($checkoutRoot . '/lib/vendor');
            @rmdir($checkoutRoot . '/lib');
            @unlink($checkoutRoot . '/bootstrap.php');
            @unlink($checkoutRoot . '/marker.txt');
            @rmdir($webDir);
            @rmdir($checkoutRoot);
        }
    }
}
