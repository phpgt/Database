<?php
namespace Gt\Database\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\Parameter;
use Gt\Config\ConfigFactory;
use Gt\Database\Connection\Settings;
use Gt\Database\Migration\MigrationIntegrityException;
use Gt\Database\Migration\Migrator;
use Gt\Database\StatementExecutionException;
use Gt\Database\StatementPreparationException;

class ExecuteCommand extends Command {
	public function run(?ArgumentValueList $arguments = null):void {
		$repoBasePath = getcwd();
		$defaultPath = $this->getDefaultPath($repoBasePath);
		$config = $this->getConfig($repoBasePath, $defaultPath);

		$settings = $this->buildSettingsFromConfig($config, $repoBasePath, $arguments);
		[$migrationPath, $migrationTable] = $this->getMigrationLocation($config, $repoBasePath, $arguments);

		$migrator = new Migrator($settings, $migrationPath, $migrationTable);
		$migrator->setOutput(
			$this->stream->getOutStream(),
			$this->stream->getErrorStream()
		);

		if($this->isForced($arguments)) {
			$migrator->deleteAndRecreateSchema();
		}

		$migrator->selectSchema();
		$migrator->createMigrationTable();
		$migrationCount = $migrator->getMigrationCount();
		$migrationFileList = $migrator->getMigrationFileList();

		$runFrom = $this->calculateResetNumber($arguments, $migrationFileList, $migrator, $migrationCount);

		$this->executeMigrations($migrator, $migrationFileList, $runFrom);
	}

	/** Determine whether the --force flag was provided. */
	private function isForced(?ArgumentValueList $arguments):bool {
		return $arguments?->contains("force") ?? false;
	}

	/** Build Settings from config for the current repository. */
	protected function buildSettingsFromConfig(
		\Gt\Config\Config $config,
		string $repoBasePath,
		?ArgumentValueList $arguments = null
	): Settings {
		$queryPath = $this->getOverrideOrConfigValue(
			$config,
			$arguments,
			"base-directory",
			"database.query_path",
			"query"
		);
		return new Settings(
			$this->resolvePath($repoBasePath, $queryPath),
			$this->getOverrideOrConfigValue(
				$config,
				$arguments,
				"driver",
				"database.driver",
				"mysql"
			),
			$this->getOverrideOrConfigValue(
				$config,
				$arguments,
				"database",
				"database.schema"
			),
			$this->getOverrideOrConfigValue(
				$config,
				$arguments,
				"host",
				"database.host",
				"localhost"
			),
			(int)$this->getOverrideOrConfigValue(
				$config,
				$arguments,
				"port",
				"database.port",
				"3306"
			),
			$this->getOverrideOrConfigValue(
				$config,
				$arguments,
				"username",
				"database.username",
				""
			),
			$this->getOverrideOrConfigValue(
				$config,
				$arguments,
				"password",
				"database.password",
				""
			)
		);
	}

	/**
	 * Return [migrationPath, migrationTable] derived from config.
	 *
	 * @return list<string>
	 */
	protected function getMigrationLocation(
		\Gt\Config\Config $config,
		string $repoBasePath,
		?ArgumentValueList $arguments = null
	): array {
		$queryPath = $this->getOverrideOrConfigValue(
			$config,
			$arguments,
			"base-directory",
			"database.query_path",
			"query"
		);
		$migrationPath = implode(DIRECTORY_SEPARATOR, [
			$this->resolvePath($repoBasePath, $queryPath),
			$config->get("database.migration_path") ?? "_migration",
		]);
		$migrationTable = $config->get("database.migration_table") ?? "_migration";
		return [$migrationPath, $migrationTable];
	}

	/**
	 * Calculate the migration start point from --reset or current migration count.
	 *
	 * @param list<string> $migrationFileList
	 */
	private function calculateResetNumber(
		?ArgumentValueList $arguments,
		array $migrationFileList,
		Migrator $migrator,
		int $migrationCount
	): int {
		$resetNumber = null;
		if($arguments?->contains("reset")) {
			$resetNumber = $arguments->get("reset")->get();
			if(!$resetNumber) {
				$lastKey = array_key_last($migrationFileList);
				$lastNumber = $migrator->extractNumberFromFilename($migrationFileList[$lastKey]);
				$resetNumber = max(0, $lastNumber - 1);
			}
			$resetNumber = (int)$resetNumber;
		}
		return $resetNumber ?? $migrationCount;
	}

	/**
	 * Wrap integrity check and perform migration with error handling.
	 *
	 * @param list<string> $migrationFileList
	 */
	private function executeMigrations(Migrator $migrator, array $migrationFileList, int $runFrom): void {
		try {
			$migrator->checkIntegrity($migrationFileList, $runFrom);
			$migrator->performMigration($migrationFileList, $runFrom);
		}
		catch(MigrationIntegrityException $exception) {
			$this->writeLine(
				"There was an integrity error migrating file '"
				. $exception->getMessage()
				. "' - this migration is recorded to have been run already, "
				. "but the contents of the file has changed.\nFor help, see "
				. "https://www.php.gt/database/migrations#integrity-error");
		}
		catch(StatementPreparationException|StatementExecutionException $exception) {
			$this->writeLine(
				"There was an error executing migration file: "
				. $exception->getMessage()
				. "'\nFor help, see https://www.php.gt/database/migrations#error"
			);
		}
	}

	public function getName():string {
		return "execute";
	}

	public function getDescription():string {
		return "Perform a database migration";
	}

	public function getRequiredNamedParameterList():array {
		return [];
	}

	public function getOptionalNamedParameterList():array {
		return [];
	}

	public function getRequiredParameterList():array {
		return [];
	}

	public function getOptionalParameterList():array {
		return [
			new Parameter(
				true,
				"base-directory",
				null,
				"Override database.query_path for this command"
			),
			new Parameter(
				true,
				"driver",
				null,
				"Override database.driver for this command"
			),
			new Parameter(
				true,
				"database",
				null,
				"Override database.schema for this command"
			),
			new Parameter(
				true,
				"host",
				null,
				"Override database.host for this command"
			),
			new Parameter(
				true,
				"port",
				null,
				"Override database.port for this command"
			),
			new Parameter(
				true,
				"username",
				null,
				"Override database.username for this command"
			),
			new Parameter(
				true,
				"password",
				null,
				"Override database.password for this command"
			),
			new Parameter(
				false,
				"force",
				"f",
				"Forcefully drop the current schema and run from migration 1"
			),
			new Parameter(
				true,
				"reset",
				"r",
				"Reset the integrity checks to a specific migration number"
			)
		];
	}

	private function getDefaultPath(string $repoBasePath):?string {
		$defaultPath = implode(DIRECTORY_SEPARATOR, [
			$repoBasePath,
			"vendor",
			"phpgt",
			"webengine",
		]);
		foreach(["config.default.ini", "default.ini"] as $defaultFile) {
			$defaultFilePath = $defaultPath . DIRECTORY_SEPARATOR . $defaultFile;

			if(is_file($defaultFilePath)) {
				return $defaultFilePath;
			}
		}

		return null;
	}

	/**
	 * @param bool|string $repoBasePath
	 * @param string|null $defaultPath
	 * @return \Gt\Config\Config
	 */
	protected function getConfig(bool|string $repoBasePath, ?string $defaultPath):\Gt\Config\Config {
		$config = ConfigFactory::createForProject($repoBasePath);

		$default = $defaultPath
			? ConfigFactory::createFromPathName($defaultPath)
			: null;

		if($default) {
			$config = $config->withMerge($default);
		}
		return $config;
	}

	protected function getOverrideOrConfigValue(
		\Gt\Config\Config $config,
		?ArgumentValueList $arguments,
		string $argumentKey,
		string $configKey,
		?string $default = null
	): ?string {
		if($arguments?->contains($argumentKey)) {
			return $arguments->get($argumentKey)->get() ?? $default;
		}

		return $config->get($configKey) ?? $default;
	}
	protected function resolvePath(string $repoBasePath, string $path):string {
		if(str_starts_with($path, DIRECTORY_SEPARATOR)) {
			return $path;
		}

		return implode(DIRECTORY_SEPARATOR, [
			$repoBasePath,
			$path,
		]);
	}
}
