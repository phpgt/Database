<?php
namespace Gt\Database\Test\Migration;

use Gt\Database\Connection\Settings;
use Gt\Database\Migration\AbstractMigrator;
use Gt\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;
use SplFileObject;

class AbstractMigratorTest extends TestCase {
	private function createWorkspace():string {
		$dir = Helper::getTmpDir() . DIRECTORY_SEPARATOR . uniqid("abstract-migrator-");
		mkdir($dir, 0775, true);
		return $dir;
	}

	private function createSettings(string $baseDirectory, string $driver, string $schema):Settings {
		return new Settings(
			$baseDirectory,
			$driver,
			$schema
		);
	}

	private function createProbe(Settings $settings, string $path, ?string $tableName = null):AbstractMigrator {
		return new class($settings, $path, $tableName) extends AbstractMigrator {
			protected function getDefaultTableName():string {
				return "_probe";
			}

			public function getTableNamePublic():string {
				return $this->tableName;
			}

			public function nowExpressionPublic():string {
				return $this->nowExpression();
			}

			public function setDriverPublic(string $driver):void {
				$this->driver = $driver;
			}

			public function executeSqlFilePublic(string $file):string {
				return $this->executeSqlFile($file);
			}

			public function outputPublic(string $message, string $streamName = self::STREAM_OUT):void {
				$this->output($message, $streamName);
			}
		};
	}

	public function testConstructorUsesDefaultTableNameWhenNotProvided():void {
		$dir = $this->createWorkspace();
		$settings = $this->createSettings($dir, Settings::DRIVER_SQLITE, $dir . "/probe.sqlite");
		$probe = $this->createProbe($settings, $dir);

		self::assertSame("_probe", $probe->getTableNamePublic());
	}

	public function testGetMigrationFileListReturnsEmptyForMissingDirectory():void {
		$dir = $this->createWorkspace();
		$settings = $this->createSettings($dir, Settings::DRIVER_SQLITE, $dir . "/probe.sqlite");
		$probe = $this->createProbe($settings, $dir . "/missing-dir");

		self::assertSame([], $probe->getMigrationFileList());
	}

	public function testNowExpressionUsesDriverSpecificValue():void {
		$dir = $this->createWorkspace();
		$sqliteProbe = $this->createProbe(
			$this->createSettings($dir, Settings::DRIVER_SQLITE, $dir . "/probe.sqlite"),
			$dir
		);
		$mysqlProbe = $this->createProbe(
			$this->createSettings($dir, Settings::DRIVER_SQLITE, $dir . "/probe-mysql.sqlite"),
			$dir
		);
		$mysqlProbe->setDriverPublic(Settings::DRIVER_MYSQL);

		self::assertSame("datetime('now')", $sqliteProbe->nowExpressionPublic());
		self::assertSame("now()", $mysqlProbe->nowExpressionPublic());
	}

	public function testExecuteSqlFileRunsEachStatementAndReturnsHash():void {
		$dir = Helper::getTmpDir() . DIRECTORY_SEPARATOR . uniqid("probe-");
		mkdir($dir, 0775, true);
		$databasePath = $dir . DIRECTORY_SEPARATOR . "probe.sqlite";
		$sqlFile = $dir . DIRECTORY_SEPARATOR . "001-probe.sql";
		file_put_contents($sqlFile, implode("\n", [
			"create table test(id int primary key, name text);",
			"insert into test values(1, 'alpha');",
		]));

		$probe = $this->createProbe(
			$this->createSettings($dir, Settings::DRIVER_SQLITE, $databasePath),
			$dir
		);

		$hash = $probe->executeSqlFilePublic($sqlFile);
		self::assertSame(md5_file($sqlFile), $hash);

		$db = new \Gt\Database\Database(
			$this->createSettings($dir, Settings::DRIVER_SQLITE, $databasePath)
		);
		$row = $db->executeSql("select name from test where id = 1")->fetch();
		self::assertSame("alpha", $row?->getString("name"));
	}

	public function testOutputWritesToErrorStreamWhenRequested():void {
		$dir = $this->createWorkspace();
		$probe = $this->createProbe(
			$this->createSettings($dir, Settings::DRIVER_SQLITE, $dir . "/probe.sqlite"),
			$dir
		);

		$out = new SplFileObject("php://memory", "w+");
		$err = new SplFileObject("php://memory", "w+");
		$probe->setOutput($out, $err);
		$probe->outputPublic("problem", AbstractMigrator::STREAM_ERROR);

		$out->rewind();
		$err->rewind();
		self::assertSame("", $out->fread(128));
		self::assertSame("problem\n", $err->fread(128));
	}
}
