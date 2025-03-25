<?php
namespace Gt\Database\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Command\Command;
use Gt\Cli\Parameter\NamedParameter;
use Gt\Cli\Parameter\Parameter;
use Gt\Config\ConfigFactory;
use Gt\Database\Connection\Settings;
use Gt\Database\Migration\MigrationIntegrityException;
use Gt\Database\Migration\Migrator;
use Gt\Database\StatementExecutionException;
use Gt\Database\StatementPreparationException;

class ExecuteCommand extends Command {
	public function run(?ArgumentValueList $arguments = null):void {
		$forced = $arguments->contains("force");

		$repoBasePath = getcwd();
		$defaultPath = $this->getDefaultPath($repoBasePath);

		$config = $this->getConfig($repoBasePath, $defaultPath);

		$settings = new Settings(
			implode(DIRECTORY_SEPARATOR, [
				$repoBasePath,
				$config->get("database.query_path")
			]),

			$config->get("database.driver") ?? 'mysql',
			$config->get("database.schema"),
			$config->get("database.host") ?? "localhost",
			(int)($config->get("database.port") ?? "3306"),
			$config->get("database.username"),
			$config->get("database.password")
		);

		$migrationPath = implode(DIRECTORY_SEPARATOR, [
			$repoBasePath,
			$config->get("database.query_path") ?? "query",
			$config->get("database.migration_path") ?? "_migration",
		]);
		$migrationTable = $config->get("database.migration_table") ?? "_migration";

		$migrator = new Migrator($settings, $migrationPath, $migrationTable);
		$migrator->setOutput(
			$this->stream->getOutStream(),
			$this->stream->getErrorStream()
		);

		if($forced) {
			$migrator->deleteAndRecreateSchema();
		}

		$migrator->selectSchema();
		$migrator->createMigrationTable();
		$migrationCount = $migrator->getMigrationCount();
		$migrationFileList = $migrator->getMigrationFileList();

		try {
			$migrator->checkIntegrity($migrationFileList, $migrationCount);
			$migrator->performMigration($migrationFileList, $migrationCount);
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
// TODO: It would be an improvement to allow passing database settings here rather than always require a config.ini
		return [];
	}

	public function getRequiredParameterList():array {
		return [];
	}

	public function getOptionalParameterList():array {
		return [
			new Parameter(
				false,
				"force",
				"f",
				"Forcefully drop the current schema and run from migration 1"
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
			$config->merge($default);
		}
		return $config;
	}
}
