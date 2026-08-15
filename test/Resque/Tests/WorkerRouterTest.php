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
     * Covers the case that motivated storing 'pwd' and 'composerRoot' as
     * two distinct payload fields: a checkout whose Composer root lives in
     * a subdirectory (e.g. "lib/", like web's layout), where 'pwd' (the
     * outer checkout root) and 'composerRoot' (where vendor/bin/ actually
     * lives) are genuinely different directories.
     *
     * Auto-*discovering* this split (Resque_Job::resolveCheckoutRoot() /
     * findComposerRoot(), combining getcwd() with where this library's own
     * Job.php is installed via __DIR__) is covered directly and precisely
     * in JobTest.php, including via real subprocesses where __DIR__
     * genuinely differs - it can't be exercised meaningfully here, since
     * this test process's own already-loaded Job.php always resolves
     * against this repo's own real root, not a throwaway checkout. This
     * test instead passes both $pwd and $composerRoot explicitly (like
     * testJobWithMismatchedPwdIsRoutedAndRunsFromThatPwd above), to prove
     * that WHEN they're correctly resolved and stored, routing genuinely
     * uses each for its own distinct purpose: chdir()s the exec'd process
     * into 'pwd' (so a conventionally-written, relative APP_INCLUDE
     * resolves correctly there) while finding the router script under
     * 'composerRoot' specifically - proven via a real pcntl_exec().
     */
    public function testJobWithNestedComposerRootIsRoutedCorrectly()
    {
        $originalAppIncludeEnv = getenv('APP_INCLUDE');

        $checkoutRoot = realpath(sys_get_temp_dir()) . '/resque-router-nested-test-' . uniqid();
        $composerRoot = $checkoutRoot . '/lib';
        mkdir($composerRoot . '/vendor/bin', 0777, true);

        copy(__DIR__ . '/../../../bin/resque-run-job', $composerRoot . '/vendor/bin/resque-run-job');
        chmod($composerRoot . '/vendor/bin/resque-run-job', 0755);

        $realAutoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
        file_put_contents(
            $composerRoot . '/vendor/autoload.php',
            "<?php\nrequire_once " . var_export($realAutoload, true) . ";\n"
        );

        // Routing chdir()s to 'pwd' (the outer $checkoutRoot), so
        // APP_INCLUDE - resolved relative to cwd, same as bin/resque -
        // must live there, not under $composerRoot.
        $marker = $checkoutRoot . '/marker.txt';
        file_put_contents(
            $checkoutRoot . '/bootstrap.php',
            "<?php\n" .
            "#[AllowDynamicProperties]\n" .
            "class Router_Nested_Test_Job {\n" .
            "    public function perform() {\n" .
            "        file_put_contents(\$this->args['marker'], getcwd());\n" .
            "    }\n" .
            "}\n"
        );

        putenv('APP_INCLUDE=./bootstrap.php');

        try {
            $token = Resque::enqueue(
                'router-test-jobs',
                'Router_Nested_Test_Job',
                array('marker' => $marker),
                true,
                '',
                $checkoutRoot,
                $composerRoot
            );

            $worker = new Resque_Worker('router-test-jobs');
            $worker->setLogger($this->logger);
            $worker->work(0);

            $this->assertFileExists($marker);
            $this->assertEquals($checkoutRoot, trim(file_get_contents($marker)));

            $status = new Resque_Job_Status($token);
            $this->assertEquals(Resque_Job_Status::STATUS_COMPLETE, $status->get());
        } finally {
            putenv($originalAppIncludeEnv === false ? 'APP_INCLUDE' : 'APP_INCLUDE=' . $originalAppIncludeEnv);

            @unlink($composerRoot . '/vendor/bin/resque-run-job');
            @unlink($composerRoot . '/vendor/autoload.php');
            @rmdir($composerRoot . '/vendor/bin');
            @rmdir($composerRoot . '/vendor');
            @rmdir($composerRoot);
            @unlink($checkoutRoot . '/bootstrap.php');
            @unlink($checkoutRoot . '/marker.txt');
            @rmdir($checkoutRoot);
        }
    }
}
