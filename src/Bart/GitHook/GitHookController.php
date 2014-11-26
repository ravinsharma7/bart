<?php
namespace Bart\GitHook;
use Bart\Diesel;
use Bart\Git\Commit;
use Bart\Log4PHP;

/**
 * Parse input from STDIN and run relevant hooks
 */
class GitHookController
{
	const HOOKS_CONF = 'etc/hooks/generic.conf';
	/** @var \Bart\Git\GitRoot */
	private $gitRoot;
	/** @var string Path to the git project */
	private $gitDir;
	/** @var string Name of the git project */
	private $projectName;
	/** @var string Name of the git hook */
	private $hookName;
	/** @var \Logger */
	private $logger;

	/**
	 * @param string $gitDir Full path to the git dir
	 * @param string $projectName
	 * @param string $hookName
	 */
	private function __construct($gitDir, $projectName, $hookName)
	{
		// $this->gitRoot = Diesel::create('\Bart\Git\GitRoot', $gitDir);
		$this->gitDir = $gitDir;
		$this->projectName = $projectName;
		$this->hookName = $hookName;
		$this->logger = Log4PHP::getLogger(__CLASS__);
	}

	public function __toString()
	{
		return "{$this->projectName}.{$this->hookName}";
	}

	/**
	 * Process STDIN and verify all revisions
	 */
	public function run()
	{
		$hookRunner = $this->createHookRunner();

		$this->logger->debug("Created $hookRunner to process each posted revision");
		$this->processRevisions($hookRunner);
	}

	/**
	 * @param string $invokedScript PHP SCRIPT_NAME e.g. hooks/post-recieve.d/bart-hook-runner
	 * @return GitHookController
	 * @throws GitHookException If the info can't be determined from script name
	 */
	public static function createFromScriptName($invokedScript)
	{
		// Use directory name (e.g. hooks); the realpath of the invoked script is likely a symlink
		$dirOfScript = dirname($invokedScript);

		/** @var \Bart\Shell $shell */
		$shell = Diesel::create('\Bart\Shell');
		// e.g. /var/lib/gitosis/puppet.git/hooks/post-receive.d
		$fullPathToDir = $shell->realpath($dirOfScript);

		// Can always safely assume that project name immediately precedes hooks dir
		// ...for both local and upstreams repos
		$hooksPos = strpos($fullPathToDir, '.git/hooks');

		if ($hooksPos === false) {
			throw new GitHookException("Could not infer project from path $fullPathToDir");
		}

		$pathToRepo = substr($fullPathToDir, 0, $hooksPos);
		$projectName = basename($pathToRepo);

		// Conventionally assume that name of hooks directory matches hook name itself
		// e.g. hooks/pre-receive.d/
		$hookName = substr($fullPathToDir, $hooksPos + strlen('.git/hooks/'));
		$hookName = substr($hookName, 0, strpos($hookName, '.'));

		return new self("$pathToRepo.git", $projectName, $hookName);
	}

	/**
	 * @param string $revision
	 */
	private function extractConfigurations($revision)
	{
		$commit = new Commit($this->gitRoot, $revision);
		// Get raw configs at time of commit
		$rawConfigs = $commit->rawFileContents(self::HOOKS_CONF);
		// TODO Return a new Configuration parsed from $rawConfigs
	}

	/**
	 * Instantiate hook runner for hook type
	 * @return GitHookRunner
	 * @throws GitHookException
	 */
	private function createHookRunner()
	{
		switch ($this->hookName) {
			case 'pre-receive':
				return new PreReceiveRunner($this->gitDir, $this->projectName);
				break;
			case 'post-receive':
				return new PostReceiveRunner($this->gitDir, $this->projectName);
				break;
			default:
				throw new GitHookException('Unknown hook type: ' . $this->hookName);
		}
	}

	/**
	 * Run the hook against all revisions on master branch
	 * @param GitHookRunner $hookRunner
	 */
	private function processRevisions(GitHookRunner $hookRunner)
	{
		/** @var \Bart\Git $git */
		$git = Diesel::create('\Bart\Git', $this->gitDir);

		/** @var \Bart\Shell $shell */
		$shell = Diesel::create('\Bart\Shell');
		// TODO This will need to change to support the 'update' hook
		$stdin = $shell->std_in();

		foreach ($stdin as $rangeAndRef) {
			list($start, $end, $ref) = explode(" ", $rangeAndRef);

			// TODO make this configurable per project/hook
			if ($ref != 'refs/heads/master') {
				$this->logger->info('Skipping hooks on non-master ref ' . $ref);
				continue;
			}

			$revisions = $git->getRevList($start, $end);

			foreach ($revisions as $revision) {
				$this->logger->debug("Verifying all configured hooks against $revision");

				// Let any failures bubble up to caller
				$hookRunner->runAllHooks($revision);
			}
		}
	}
}