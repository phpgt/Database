<?php
namespace Gt\Database\Test\Migration;

use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use Gt\Database\Migration\DevMigrator;
use Gt\Database\Migration\MigrationIntegrityException;
use Gt\Database\Migration\MigrationSequenceOrderException;
use Gt\Database\Migration\Migrator;
use Gt\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;

class DevMigratorTest extends TestCase {
	private function createProjectDir():string {
		$root = Helper::getTmpDir();
		$project = implode(DIRECTORY_SEPARATOR, [$root, uniqid("dev-mig-")]);
		mkdir($project, 0775, true);
		return $project;
	}

	private function createSettings(string $projectRoot, string $databasePath):Settings {
		return new Settings(
			$projectRoot . DIRECTORY_SEPARATOR . "query",
			Settings::DRIVER_SQLITE,
			$databasePath
		);
	}

	/** @return array<string> */
	private function createMigrationFiles(string $path, string $prefix, int $count):array {
		mkdir($path, 0775, true);
		$fileList = [];

		for($i = 1; $i <= $count; $i++) {
			$fileName = str_pad((string)$i, 3, "0", STR_PAD_LEFT) . "-$prefix-$i.sql";
			$sql = match($i) {
				1 => "create table `test` (`id` int primary key, `name` text)",
				default => "alter table `test` add `{$prefix}_{$i}` text",
			};
			$filePath = implode(DIRECTORY_SEPARATOR, [$path, $fileName]);
			file_put_contents($filePath, $sql);
			array_push($fileList, $filePath);
		}

		return $fileList;
	}

	public function testPerformDevMigrationRunsOnlyPendingFiles():void {
		$project = $this->createProjectDir();
		$databasePath = $project . DIRECTORY_SEPARATOR . "dev.sqlite";
		$settings = $this->createSettings($project, $databasePath);
		$devPath = $project . DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR . "_migration" . DIRECTORY_SEPARATOR . "dev";
		$files = $this->createMigrationFiles($devPath, "feature", 2);

		$devMigrator = new DevMigrator($settings, $devPath);
		$devMigrator->createMigrationTable();

		self::assertSame(2, $devMigrator->performMigration($files));
		self::assertSame(0, $devMigrator->performMigration($files));

		$db = new Database($settings);
		$result = $db->executeSql("pragma table_info(test)");
		self::assertCount(3, $result->fetchAll());
	}

	public function testDevMigrationIntegrityFailsWhenAppliedFileChanges():void {
		$project = $this->createProjectDir();
		$databasePath = $project . DIRECTORY_SEPARATOR . "dev.sqlite";
		$settings = $this->createSettings($project, $databasePath);
		$devPath = $project . DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR . "_migration" . DIRECTORY_SEPARATOR . "dev";
		$files = $this->createMigrationFiles($devPath, "feature", 1);

		$devMigrator = new DevMigrator($settings, $devPath);
		$devMigrator->createMigrationTable();
		$devMigrator->performMigration($files);

		file_put_contents($files[0], "create table `test` (`id` int primary key, `changed` text)");

		$this->expectException(MigrationIntegrityException::class);
		$devMigrator->checkIntegrity($files);
	}

	public function testMergeIntoMainMigrationDirectoryPromotesAppliedDevMigrations():void {
		$project = $this->createProjectDir();
		$databasePath = $project . DIRECTORY_SEPARATOR . "dev.sqlite";
		$settings = $this->createSettings($project, $databasePath);
		$mainPath = $project . DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR . "_migration";
		$devPath = $mainPath . DIRECTORY_SEPARATOR . "dev";

		$mainFiles = $this->createMigrationFiles($mainPath, "main", 1);
		mkdir($devPath, 0775, true);
		$devFiles = [
			$devPath . DIRECTORY_SEPARATOR . "001-feature-1.sql",
			$devPath . DIRECTORY_SEPARATOR . "002-feature-2.sql",
		];
		file_put_contents($devFiles[0], "alter table `test` add `feature_1` text");
		file_put_contents($devFiles[1], "alter table `test` add `feature_2` text");

		$migrator = new Migrator($settings, $mainPath);
		$migrator->createMigrationTable();
		$migrator->performMigration($mainFiles);

		$devMigrator = new DevMigrator($settings, $devPath);
		$devMigrator->createMigrationTable();
		$devMigrator->performMigration($devFiles);

		self::assertSame(2, $devMigrator->mergeIntoMainMigrationDirectory($migrator, $mainPath));

		$mergedFileList = $migrator->getMigrationFileList();
		$mergedNames = array_map("basename", $mergedFileList);

		self::assertContains("0002-feature-1.sql", $mergedNames);
		self::assertContains("0003-feature-2.sql", $mergedNames);
		self::assertFileDoesNotExist($devFiles[0]);
		self::assertFileDoesNotExist($devFiles[1]);
		self::assertSame([], $devMigrator->getMigrationFileList());
		self::assertSame(3, $migrator->getMigrationCount());
	}

	public function testDevMigratorThrowsOnGapsInSequence():void {
		$project = $this->createProjectDir();
		$databasePath = $project . DIRECTORY_SEPARATOR . "dev.sqlite";
		$settings = $this->createSettings($project, $databasePath);
		$devPath = $project . DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR . "_migration" . DIRECTORY_SEPARATOR . "dev";
		mkdir($devPath, 0775, true);
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "001-feature.sql", "select 1");
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "003-feature.sql", "select 1");

		$devMigrator = new DevMigrator($settings, $devPath);

		$this->expectException(MigrationSequenceOrderException::class);
		$devMigrator->checkFileListOrder($devMigrator->getMigrationFileList());
	}

	public function testDevMigratorThrowsOnDuplicateSequenceNumbers():void {
		$project = $this->createProjectDir();
		$databasePath = $project . DIRECTORY_SEPARATOR . "dev.sqlite";
		$settings = $this->createSettings($project, $databasePath);
		$devPath = $project . DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR . "_migration" . DIRECTORY_SEPARATOR . "dev";
		mkdir($devPath, 0775, true);
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "001-feature-a.sql", "select 1");
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "001-feature-b.sql", "select 1");

		$devMigrator = new DevMigrator($settings, $devPath);

		$this->expectException(MigrationSequenceOrderException::class);
		$devMigrator->checkFileListOrder($devMigrator->getMigrationFileList());
	}

	public function testDevMigratorIgnoresNonNumericFilesAndThrowsOnResultingGap():void {
		$project = $this->createProjectDir();
		$databasePath = $project . DIRECTORY_SEPARATOR . "dev.sqlite";
		$settings = $this->createSettings($project, $databasePath);
		$devPath = $project . DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR . "_migration" . DIRECTORY_SEPARATOR . "dev";
		mkdir($devPath, 0775, true);
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "001-first.sql", "select 1");
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "a002-second.sql", "select 1");
		file_put_contents($devPath . DIRECTORY_SEPARATOR . "003-third.sql", "select 1");

		$devMigrator = new DevMigrator($settings, $devPath);
		$fileList = $devMigrator->getMigrationFileList();

		self::assertSame([
			$devPath . DIRECTORY_SEPARATOR . "001-first.sql",
			$devPath . DIRECTORY_SEPARATOR . "003-third.sql",
		], $fileList);

		$this->expectException(MigrationSequenceOrderException::class);
		$devMigrator->checkFileListOrder($fileList);
	}
}
