<?php
namespace Gt\Database\Test\Cli;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Stream;
use Gt\Config\Config;
use Gt\Config\ConfigSection;
use Gt\Database\Cli\ExecuteCommand;
use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use Gt\Database\Test\Helper\Helper;
use Gt\Cli\Parameter\Parameter;
use PHPUnit\Framework\TestCase;
use SplFileObject;

class ExecuteCommandTest extends TestCase {
	const MIGRATION_CREATE
		= "create table `test` (`id` int primary key, `name` varchar(32))";
	const MIGRATION_ALTER = "alter table `test` add `new_column` varchar(32)";

	private function createProjectDir():string {
		$root = Helper::getTmpDir();
		// Ensure empty directory for each test run
		$project = implode(DIRECTORY_SEPARATOR, [$root, uniqid("proj-")]);
		mkdir($project, 0775, true);
		return $project;
	}

	private function writeConfigIni(string $projectRoot, string $sqlitePath, string $queryPath = "query", string $migrationPath = "_migration"):void {
		$config = [];
		$config[] = "[database]";
		$config[] = "driver = sqlite";
		$config[] = "schema = \"" . str_replace("\\", "/", $sqlitePath) . "\"";
		$config[] = "query_path = $queryPath";
		$config[] = "migration_path = $migrationPath";
		$config[] = "username = \"\"";
		$config[] = "password = \"\"";
		file_put_contents($projectRoot . DIRECTORY_SEPARATOR . "config.ini", implode(PHP_EOL, $config));
	}

	private function createMigrations(string $projectRoot, int $count):array {
		$queryDir = $projectRoot . DIRECTORY_SEPARATOR . "query";
		$migDir = $queryDir . DIRECTORY_SEPARATOR . "_migration";
		mkdir($migDir, 0775, true);

		$fileList = [];
		for($i = 1; $i <= $count; $i++) {
			$filename = str_pad((string)$i, 4, "0", STR_PAD_LEFT) . "-" . uniqid() . ".sql";
			$path = $migDir . DIRECTORY_SEPARATOR . $filename;
			if($i === 1) {
				$sql = self::MIGRATION_CREATE;
			}
			else {
				$sql = str_replace("`new_column`", "`new_column_{$i}`", self::MIGRATION_ALTER);
			}
			file_put_contents($path, $sql);
			$fileList[] = $path;
		}
		return $fileList;
	}

	private function makeStreamFiles():array {
		$dir = Helper::getTmpDir();
		// Ensure the directory exists to prevent tempnam() notices
		if(!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		$in = tempnam($dir, "cli-in-");
		$out = tempnam($dir, "cli-out-");
		$err = tempnam($dir, "cli-err-");
		// Ensure files exist
		file_put_contents($in, "");
		file_put_contents($out, "");
		file_put_contents($err, "");
		$stream = new Stream($in, $out, $err);
		return [
			"stream" => $stream,
			"in" => $in,
			"out" => $out,
			"err" => $err,
		];
	}

	private function readFromFiles(string $outPath, string $errPath):array {
		$out = new SplFileObject($outPath, "r");
		$err = new SplFileObject($errPath, "r");
		$out->rewind();
		$err->rewind();
		return [
			"out" => $out->fread(8192),
			"err" => $err->fread(8192),
		];
	}

	public function testGetConfigMergesDefaultConfigWithProjectOverrides():void {
		$project = $this->createProjectDir();
		$sqlitePath = str_replace("\\", "/", $project . DIRECTORY_SEPARATOR . "cli-test.db");
		$this->writeConfigIni($project, $sqlitePath);

		$defaultConfigPath = $project . DIRECTORY_SEPARATOR . "config.default.ini";
		file_put_contents($defaultConfigPath, implode(PHP_EOL, [
			"[database]",
			"host = default-host",
			"port = 4406",
			"migration_table = project_migrations",
			"schema = default-schema",
		]));

		$command = new class extends ExecuteCommand {
			public function getConfigPublic(string $repoBasePath, ?string $defaultPath):\Gt\Config\Config {
				return $this->getConfig($repoBasePath, $defaultPath);
			}
		};

		$config = $command->getConfigPublic($project, $defaultConfigPath);

		self::assertSame("default-host", $config->get("database.host"));
		self::assertSame("4406", $config->get("database.port"));
		self::assertSame("project_migrations", $config->get("database.migration_table"));
		self::assertSame($sqlitePath, $config->get("database.schema"));
		self::assertSame("sqlite", $config->get("database.driver"));
	}

	public function testExecuteMigratesAll():void {
		$project = $this->createProjectDir();
		$sqlitePath = str_replace("\\", "/", $project . DIRECTORY_SEPARATOR . "cli-test.db");
		$this->writeConfigIni($project, $sqlitePath);
		$this->createMigrations($project, 3);

		$cwdBackup = getcwd();
		chdir($project);
		try {
			$cmd = new ExecuteCommand();
			$streams = $this->makeStreamFiles();
			$cmd->setStream($streams["stream"]);

			$args = new ArgumentValueList();
			// No additional params; simply run
			$cmd->run($args);

			list("out" => $out) = $this->readFromFiles($streams["out"], $streams["err"]);
			self::assertStringContainsString("Migration 1:", $out);
			self::assertStringContainsString("3 migrations were completed successfully.", $out);

			// Verify DB state
			$settings = new Settings($project . DIRECTORY_SEPARATOR . "query", Settings::DRIVER_SQLITE, $sqlitePath);
			$db = new Database($settings);
			$result = $db->executeSql("PRAGMA table_info(test);");
			self::assertGreaterThanOrEqual(4, count($result->fetchAll()));
		}
		finally {
			chdir($cwdBackup);
		}
	}

	public function testExecuteWithResetWithoutNumber():void {
		$project = $this->createProjectDir();
		$sqlitePath = str_replace("\\", "/", $project . DIRECTORY_SEPARATOR . "cli-test.db");
		$this->writeConfigIni($project, $sqlitePath);
		$migrations = $this->createMigrations($project, 4);

		// Prepare base state: create table so we can skip first migration safely.
		$settings = new Settings($project . DIRECTORY_SEPARATOR . "query", Settings::DRIVER_SQLITE, $sqlitePath);
		$db = new Database($settings);
		$db->executeSql(self::MIGRATION_CREATE);

		$cwdBackup = getcwd();
		chdir($project);
		try {
			$cmd = new ExecuteCommand();
			$streams = $this->makeStreamFiles();
			$cmd->setStream($streams["stream"]);

			$args = new ArgumentValueList();
			$args->set("reset"); // No number provided: should reset to latest migration number

			$cmd->run($args);

			list("out" => $out) = $this->readFromFiles($streams["out"], $streams["err"]);
			// Should only execute the last migration
			self::assertMatchesRegularExpression("/Migration\\s+4:/", $out);
			self::assertStringContainsString("1 migrations were completed successfully.", $out);
		}
		finally {
			chdir($cwdBackup);
		}
	}

	public function testExecuteWithResetWithNumber():void {
		$project = $this->createProjectDir();
		$sqlitePath = str_replace("\\", "/", $project . DIRECTORY_SEPARATOR . "cli-test.db");
		$this->writeConfigIni($project, $sqlitePath);
		$migrations = $this->createMigrations($project, 5);

		// Prepare base state when skipping the initial migrations.
		$settings = new Settings($project . DIRECTORY_SEPARATOR . "query", Settings::DRIVER_SQLITE, $sqlitePath);
		$db = new Database($settings);
		$db->executeSql(self::MIGRATION_CREATE);

		$cwdBackup = getcwd();
		chdir($project);
		try {
			$cmd = new ExecuteCommand();
			$streams = $this->makeStreamFiles();
			$cmd->setStream($streams["stream"]);

			$args = new ArgumentValueList();
			$args->set("reset", "3"); // Should migrate from 4 and 5 only

			$cmd->run($args);

			list("out" => $out) = $this->readFromFiles($streams["out"], $streams["err"]);
			self::assertStringNotContainsString("Migration 1:", $out);
			self::assertStringNotContainsString("Migration 2:", $out);
			self::assertStringNotContainsString("Migration 3:", $out);
			self::assertStringContainsString("Migration 4:", $out);
			self::assertStringContainsString("Migration 5:", $out);
			self::assertStringContainsString("2 migrations were completed successfully.", $out);
		}
		finally {
			chdir($cwdBackup);
		}
	}

	public function testOptionalParameterListContainsCliOverrides():void {
		$command = new ExecuteCommand();
		$parameterNames = array_map(
			fn(Parameter $parameter) => $parameter->getLongOption(),
			$command->getOptionalParameterList()
		);

		self::assertContains("base-directory", $parameterNames);
		self::assertContains("driver", $parameterNames);
		self::assertContains("database", $parameterNames);
		self::assertContains("host", $parameterNames);
		self::assertContains("port", $parameterNames);
		self::assertContains("username", $parameterNames);
		self::assertContains("password", $parameterNames);
		self::assertContains("force", $parameterNames);
		self::assertContains("reset", $parameterNames);
	}

	public function testCliArgumentsOverrideConfigValuesWhenBuildingSettings():void {
		$repoBasePath = "/tmp/project-root";
		$config = new Config(
			new ConfigSection("database", [
				"query_path" => "query",
				"driver" => "mysql",
				"schema" => "config-db",
				"host" => "config-host",
				"port" => "3306",
				"username" => "config-user",
				"password" => "config-pass",
				"migration_path" => "_migration",
				"migration_table" => "_migration",
			])
		);
		$args = new ArgumentValueList();
		$args->set("base-directory", "custom-query");
		$args->set("driver", "sqlite");
		$args->set("database", "/tmp/override.db");
		$args->set("host", "override-host");
		$args->set("port", "1234");
		$args->set("username", "override-user");
		$args->set("password", "override-pass");

		$command = $this->createCommandProbe();
		$settings = $command->buildSettingsForTest($config, $repoBasePath, $args);

		self::assertSame("/tmp/project-root/custom-query", $settings->getBaseDirectory());
		self::assertSame("sqlite", $settings->getDriver());
		self::assertSame("/tmp/override.db", $settings->getSchema());
		self::assertSame("override-host", $settings->getHost());
		self::assertSame(1234, $settings->getPort());
		self::assertSame("override-user", $settings->getUsername());
		self::assertSame("override-pass", $settings->getPassword());
	}

	public function testBaseDirectoryOverrideIsUsedForMigrationLocation():void {
		$repoBasePath = "/tmp/project-root";
		$config = new Config(
			new ConfigSection("database", [
				"query_path" => "query",
				"migration_path" => "_migration",
				"migration_table" => "migration_log",
			])
		);
		$args = new ArgumentValueList();
		$args->set("base-directory", "alt-query");

		$command = $this->createCommandProbe();
		[$migrationPath, $migrationTable] = $command->getMigrationLocationForTest($config, $repoBasePath, $args);

		self::assertSame("/tmp/project-root/alt-query/_migration", $migrationPath);
		self::assertSame("migration_log", $migrationTable);
	}

	private function createCommandProbe():ExecuteCommand {
		return new class extends ExecuteCommand {
			public function buildSettingsForTest(
				Config $config,
				string $repoBasePath,
				?ArgumentValueList $arguments = null
			): Settings {
				return $this->buildSettingsFromConfig($config, $repoBasePath, $arguments);
			}

			/** @return list<string> */
			public function getMigrationLocationForTest(
				Config $config,
				string $repoBasePath,
				?ArgumentValueList $arguments = null
			): array {
				return $this->getMigrationLocation($config, $repoBasePath, $arguments);
			}
		};
	}

}
