<?php

/**
 * Resque job.
 *
 * @package		Resque/Job
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Job
{
	/**
	 * @var string The name of the queue that this job belongs to.
	 */
	public $queue;

	/**
	 * @var Resque_Worker Instance of the Resque worker running this job.
	 */
	public $worker;

	/**
	 * @var array Array containing details of the job.
	 */
	public $payload;

	/**
	 * @var object|Resque_JobInterface Instance of the class performing work for this job.
	 */
	private $instance;

	/**
	 * @var Resque_Job_FactoryInterface
	 */
	private $jobFactory;

	/**
	 * Instantiate a new instance of a job.
	 *
	 * @param string $queue The queue that the job belongs to.
	 * @param array $payload array containing details of the job.
	 */
	public function __construct($queue, $payload)
	{
		$this->queue = $queue;
		$this->payload = $payload;
	}

	/**
	 * Create a new job and save it to the specified queue.
	 *
	 * @param string $queue The name of the queue to place the job in.
	 * @param string $class The name of the class that contains the code to execute the job.
	 * @param array $args Any optional arguments that should be passed when the job is executed.
	 * @param boolean $monitor Set to true to be able to monitor the status of a job.
	 * @param string $id Unique identifier for tracking the job. Generated if not supplied.
	 * @param string $prefix The prefix needs to be set for the status key
	 * @param string|null $pwd The filesystem root that is enqueuing this job - used
	 *        for chdir() and as the base APP_INCLUDE's conventional paths are written
	 *        relative to. If not supplied, defaults to resolveCheckoutRoot() - found
	 *        with zero configuration by combining where this library itself is
	 *        installed (via __DIR__) with getcwd() - so callers enqueuing from a
	 *        subdirectory of the checkout (e.g. a PHP-FPM request rooted in "www/")
	 *        still get the checkout root stored, not that subdirectory - falling back
	 *        to plain getcwd() if no checkout root can be found.
	 * @param string|null $composerRoot The directory directly containing this
	 *        checkout's vendor/bin/resque-run-job - used by a router pool to find the
	 *        script to exec. Distinct from $pwd whenever Composer lives in a
	 *        subdirectory of the checkout (e.g. "lib/"): $pwd is then the outer
	 *        checkout root (for chdir()/APP_INCLUDE), while $composerRoot is that
	 *        subdirectory. If not supplied, defaults to the auto-discovered Composer
	 *        root when $pwd is also being auto-resolved, or to $pwd itself otherwise
	 *        (assuming vendor/ sits directly under a caller-supplied $pwd).
	 *
	 * @return string
	 * @throws \InvalidArgumentException
	 */
	public static function create($queue, $class, $args = null, $monitor = false, $id = null, $prefix = "", $pwd = null, $composerRoot = null)
	{
		if (is_null($id)) {
			$id = Resque::generateJobId();
		}

		if ($args !== null && !is_array($args)) {
			throw new InvalidArgumentException(
				'Supplied $args must be an array.'
			);
		}

		if ($pwd === null) {
			$foundComposerRoot = self::findComposerRoot();
			if ($foundComposerRoot === null) {
				$pwd = getcwd();
			} else {
				if ($composerRoot === null) {
					$composerRoot = $foundComposerRoot;
				}
				$cwd = getcwd();
				$pwd = ($cwd === false) ? $foundComposerRoot : self::combineComposerRootAndCwd($foundComposerRoot, $cwd);
			}
		}

		if ($composerRoot === null) {
			$composerRoot = $pwd;
		}

		Resque::push($queue, array(
			'class'	        => $class,
			'args'	        => array($args),
			'id'	        => $id,
			'prefix'        => $prefix,
			'pwd'           => $pwd,
			'composerRoot'  => $composerRoot,
			'queue_time'    => microtime(true),
		));

		if ($monitor) {
			Resque_Job_Status::create($id, $prefix);
		}

		return $id;
	}

	/**
	 * Find the next available job from the specified queue and return an
	 * instance of Resque_Job for it.
	 *
	 * @param string $queue The name of the queue to check for a job in.
	 * @return false|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
	 */
	public static function reserve($queue)
	{
		$payload = Resque::pop($queue);
		if (!is_array($payload)) {
			return false;
		}

		return new Resque_Job($queue, $payload);
	}

	/**
	 * Find the next available job from the specified queues using blocking list pop
	 * and return an instance of Resque_Job for it.
	 *
	 * @param array             $queues
	 * @param int               $timeout
	 * @return false|object Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
	 */
	public static function reserveBlocking(array $queues, $timeout = null)
	{
		$item = Resque::blpop($queues, $timeout);

		if (!is_array($item)) {
			return false;
		}

		return new Resque_Job($item['queue'], $item['payload']);
	}

	/**
	 * Update the status of the current job.
	 *
	 * @param int $status Status constant from Resque_Job_Status indicating the current status of a job.
	 */
	public function updateStatus($status, $result = null)
	{
		if (empty($this->payload['id'])) {
			return;
		}

		$statusInstance = new Resque_Job_Status($this->payload['id'], $this->getPrefix());
		$statusInstance->update($status, $result);
	}

	/**
	 * Return the status of the current job.
	 *
	 * @return int|null The status of the job as one of the Resque_Job_Status constants or null if job is not being tracked.
	 */
	public function getStatus()
	{
		if (empty($this->payload['id'])) {
			return null;
		}

		$status = new Resque_Job_Status($this->payload['id'], $this->getPrefix());
		return $status->get();
	}

	/**
	 * Get the arguments supplied to this job.
	 *
	 * @return array Array of arguments.
	 */
	public function getArguments()
	{
		if (!isset($this->payload['args'])) {
			return array();
		}

		return $this->payload['args'][0];
	}

	/**
	 * Get the instantiated object for this job that will be performing work.
	 * @return Resque_JobInterface Instance of the object that this job belongs to.
	 * @throws Resque_Exception
	 */
	public function getInstance()
	{
		if (!is_null($this->instance)) {
			return $this->instance;
		}

		$this->instance = $this->getJobFactory()->create($this->payload['class'], $this->getArguments(), $this->queue);
		$this->instance->job = $this;
		return $this->instance;
	}

	/**
	 * Actually execute a job by calling the perform method on the class
	 * associated with the job with the supplied arguments.
	 *
	 * @return bool
	 * @throws Resque_Exception When the job's class could not be found or it does not contain a perform method.
	 */
	public function perform()
	{
		$result = true;
		try {
			Resque_Event::trigger('beforePerform', $this);

			$instance = $this->getInstance();
			if (is_callable([$instance, 'setUp'])) {
				$instance->setUp();
			}

			$result = $instance->perform();

			if (is_callable([$instance, 'tearDown'])) {
				$instance->tearDown();
			}

			Resque_Event::trigger('afterPerform', $this);
		}
		// beforePerform/setUp have said don't perform this job. Return.
		catch (Resque_Job_DontPerform $e) {
			$result = false;
		}

		return $result;
	}

	/**
	 * Mark the current job as having failed.
	 *
	 * @param $exception
	 */
	public function fail($exception)
	{
		Resque_Event::trigger('onFailure', array(
			'exception' => $exception,
			'job' => $this,
		));

		$this->updateStatus(Resque_Job_Status::STATUS_FAILED);
		if ($exception instanceof Error) {
			Resque_Failure::createFromError(
				$this->payload,
				$exception,
				$this->worker,
				$this->queue
			);
		} else {
			Resque_Failure::create(
				$this->payload,
				$exception,
				$this->worker,
				$this->queue
			);
		}
		Resque_Stat::incr('failed');
		Resque_Stat::incr('failed:' . $this->worker);
	}

	/**
	 * Re-queue the current job.
	 * @return string
	 */
	public function recreate()
	{
		$monitor = false;
		if (!empty($this->payload['id'])) {
			$status = new Resque_Job_Status($this->payload['id'], $this->getPrefix());
			if ($status->isTracking()) {
				$monitor = true;
			}
		}

		return self::create($this->queue, $this->payload['class'], $this->getArguments(), $monitor, null, $this->getPrefix(), $this->getPwd(), $this->getComposerRoot());
	}

	/**
	 * Generate a string representation used to describe the current job.
	 *
	 * @return string The string representation of the job.
	 */
	public function __toString()
	{
		$name = array(
			'Job{' . $this->queue . '}'
		);
		if (!empty($this->payload['id'])) {
			$name[] = 'ID: ' . $this->payload['id'];
		}
		$name[] = $this->payload['class'];
		if (!empty($this->payload['args'])) {
			$name[] = json_encode($this->payload['args']);
		}
		return '(' . implode(' | ', $name) . ')';
	}

	/**
	 * @param Resque_Job_FactoryInterface $jobFactory
	 * @return Resque_Job
	 */
	public function setJobFactory(Resque_Job_FactoryInterface $jobFactory)
	{
		$this->jobFactory = $jobFactory;

		return $this;
	}

	/**
	 * @return Resque_Job_FactoryInterface
	 */
	public function getJobFactory()
	{
		if ($this->jobFactory === null) {
			$this->jobFactory = new Resque_Job_Factory();
		}
		return $this->jobFactory;
	}

	/**
	 * @return string
	 */
	private function getPrefix()
	{
		if (isset($this->payload['prefix'])) {
			return $this->payload['prefix'];
		}

		return '';
	}

	/**
	 * @return string|null
	 */
	private function getPwd()
	{
		if (isset($this->payload['pwd'])) {
			return $this->payload['pwd'];
		}

		return null;
	}

	/**
	 * @return string|null
	 */
	private function getComposerRoot()
	{
		if (isset($this->payload['composerRoot'])) {
			return $this->payload['composerRoot'];
		}

		return null;
	}

	/**
	 * How many levels above the auto-discovered Composer root (see
	 * findComposerRoot()) we're willing to trust as the "true" checkout root
	 * when combining it with getcwd() - see resolveCheckoutRoot(). Composer
	 * sometimes lives in a subdirectory of the actual checkout root (e.g.
	 * "lib/"); getcwd() from an entry point that runs alongside that
	 * subdirectory (e.g. a sibling "app/" or "www/" dir) shares a common
	 * ancestor with the Composer root at exactly that outer level. Bounded
	 * so a getcwd() that's genuinely unrelated to this checkout (e.g. a
	 * stray cron cwd) can't drag the result all the way up toward "/" -
	 * beyond this bound we just trust the Composer root itself instead.
	 */
	const MAX_REPO_ROOT_CLIMB = 3;

	/**
	 * Find the checkout root with zero configuration, by combining two
	 * signals that are already available on every entry point without any
	 * setup:
	 *
	 *  1. findComposerRoot() - where this library itself is installed,
	 *     via __DIR__, which is fixed at compile time to wherever this file
	 *     physically lives on disk and is therefore identical no matter
	 *     which entry point (PHP-FPM, CLI, cron) loads it.
	 *  2. $cwd (defaults to getcwd()) - whatever directory the calling
	 *     process happens to be running from.
	 *
	 * Their longest common ancestor path is the checkout root: if Composer
	 * lives directly at the checkout root, $cwd is always a descendant of
	 * it and the common ancestor is the Composer root itself, unchanged. If
	 * Composer lives in a subdirectory (e.g. "lib/") and the caller runs
	 * from a sibling directory (e.g. "app/" or "www/"), their common
	 * ancestor is exactly the outer checkout root - which is what
	 * APP_INCLUDE's conventional paths are written relative to.
	 *
	 * This resolves the checkout root once, at job-creation time, so
	 * Resque_Worker::routeJobToPwd() can trust the stored 'pwd' as-is and
	 * doesn't need to repeat any of this on every route.
	 *
	 * @param string|null $cwd Defaults to getcwd(); overridable for tests.
	 * @return string|null Null only if the Composer root itself can't be found.
	 */
	public static function resolveCheckoutRoot($cwd = null)
	{
		$composerRoot = self::findComposerRoot();
		if ($composerRoot === null) {
			return null;
		}

		if ($cwd === null) {
			$cwd = getcwd();
		}
		if ($cwd === false) {
			return $composerRoot;
		}

		return self::combineComposerRootAndCwd($composerRoot, $cwd);
	}

	/**
	 * The common-ancestor-with-climb-bound step of resolveCheckoutRoot(),
	 * factored out so Resque_Job::create() can reuse it after already
	 * having called findComposerRoot() itself (to also capture the
	 * Composer root separately, for the 'composerRoot' payload field),
	 * without searching the filesystem for it twice.
	 *
	 * @param string $composerRoot
	 * @param string $cwd
	 * @return string
	 */
	private static function combineComposerRootAndCwd($composerRoot, $cwd)
	{
		$ancestor = self::findCommonAncestor($composerRoot, $cwd);

		$climbed = self::pathDepth($composerRoot) - self::pathDepth($ancestor);
		if ($climbed < 0 || $climbed > self::MAX_REPO_ROOT_CLIMB) {
			return $composerRoot;
		}

		return $ancestor;
	}

	/**
	 * Locate the Composer root - the directory containing this library's
	 * own installed vendor/bin/resque-run-job - by walking up from __DIR__,
	 * which is fixed at compile time to wherever this file physically lives
	 * on disk. Tries two depths, first file_exists() match wins:
	 *
	 *  - 5 levels up, checking for 'vendor/bin/resque-run-job': installed as
	 *    a normal Composer dependency at
	 *    {composerRoot}/vendor/resque/php-resque/lib/Resque/Job.php.
	 *  - 2 levels up, checking for 'bin/resque-run-job' (no "vendor/"
	 *    prefix): php-resque itself is the root package (its own dev
	 *    checkout / test suite) - Composer does not symlink a root
	 *    package's own declared "bin" entries into its own vendor/bin/.
	 *
	 * Returned path is realpath()'d before returning where possible - it
	 * ends up logged and persisted in Redis job payloads, so it should read
	 * as a clean, canonical path rather than one with embedded "/.."
	 * segments.
	 *
	 * @return string|null
	 */
	private static function findComposerRoot()
	{
		$candidates = array(
			array(5, 'vendor/bin/resque-run-job'),
			array(2, 'bin/resque-run-job'),
		);

		foreach ($candidates as $candidate) {
			list($levelsUp, $relative) = $candidate;
			$candidateRoot = __DIR__ . str_repeat('/..', $levelsUp);
			if (file_exists($candidateRoot . '/' . $relative)) {
				$realCandidateRoot = realpath($candidateRoot);
				return $realCandidateRoot !== false ? $realCandidateRoot : $candidateRoot;
			}
		}

		return null;
	}

	/**
	 * The longest common leading path segment of $a and $b. Pure string
	 * logic - no filesystem access - so it's safe (and cheap) to call with
	 * synthetic paths in tests.
	 *
	 * @param string $a
	 * @param string $b
	 * @return string
	 */
	private static function findCommonAncestor($a, $b)
	{
		$partsA = explode('/', rtrim($a, '/'));
		$partsB = explode('/', rtrim($b, '/'));

		$common = array();
		$max = min(count($partsA), count($partsB));
		for ($i = 0; $i < $max; $i++) {
			if ($partsA[$i] !== $partsB[$i]) {
				break;
			}
			$common[] = $partsA[$i];
		}

		$joined = implode('/', $common);
		return $joined === '' ? '/' : $joined;
	}

	/**
	 * Number of non-empty path segments in $path - used to bound how far
	 * resolveCheckoutRoot() is willing to climb above the Composer root.
	 *
	 * @param string $path
	 * @return int
	 */
	private static function pathDepth($path)
	{
		return count(array_filter(explode('/', $path), 'strlen'));
	}
}
