<?php
namespace GT\Database\Test\Migration;

use DateTime;
use Exception;
use GT\Database\Database;
use GT\Database\Connection\Settings;
use GT\Database\DatabaseException;
use GT\Database\Migration\MigrationDirectoryNotFoundException;
use GT\Database\Migration\MigrationFileNameFormatException;
use GT\Database\Migration\MigrationIntegrityException;
use GT\Database\Migration\MigrationSequenceOrderException;
use GT\Database\Migration\Migrator;
use GT\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;
use SplFileObject;
use stdClass;

class MigratorTest extends TestCase {
	const MIGRATION_CREATE
		= "create table `test` (`id` int primary key, `name` varchar(32))";
	const MIGRATION_ALTER = "alter table `test` add `new_column` varchar(32)";

	public function getMigrationDirectory():string {
		$tmp = Helper::getTmpDir();

		$path = implode(DIRECTORY_SEPARATOR, [
			$tmp,
			"query",
			"_migration",
		]);
		mkdir($path, 0775, true);
		return $path;
	}

	public function testMigrationZeroAtStartWithoutTable() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$migrator->createMigrationTable();
		self::assertEquals(0, $migrator->getMigrationCount());
	}

	public function testCheckMigrationTableExists() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		self::assertFalse($migrator->checkMigrationTableExists());
	}

	public function testCreateMigrationTable() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$migrator->createMigrationTable();
		self::assertTrue($migrator->checkMigrationTableExists());
	}

	public function testMigrationZeroAtStartWithTable() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$migrator->createMigrationTable();
		self::assertEquals(0, $migrator->getMigrationCount());
	}

	/** @dataProvider dataMigrationFileList */
	public function testGetMigrationFileList(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createFiles($fileList, $path);
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$actualFileList = $migrator->getMigrationFileList();
		self::assertSameSize($fileList, $actualFileList);
	}

	public function testGetMigrationFileListNotExists() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator(
			$settings,
			dirname($path) . "does-not-exist"
		);
		$this->expectException(MigrationDirectoryNotFoundException::class);
		$migrator->getMigrationFileList();
	}

	/** @dataProvider dataMigrationFileList */
	public function testCheckFileListOrder(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$actualFileList = $migrator->getMigrationFileList();
		$exception = null;

		try {
			$migrator->checkFileListOrder($actualFileList);
		}
		catch(Exception $exception) {}

		self::assertNull(
			$exception,
			"No exception should be thrown"
		);
	}

	/** @dataProvider dataMigrationFileListMissing */
	public function testCheckFileListOrderMissing(array $fileList) {
		$path = self::getMigrationDirectory();
		$this->createFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$actualFileList = $migrator->getMigrationFileList();
		$exception = null;
		try {
			$migrator->checkFileListOrder($actualFileList);
		}
		catch (Exception $exception) {}
		self::assertNull($exception, "No exception should be thrown for missing sequence numbers as long as order is increasing and non-duplicated");
	}

	/** @dataProvider dataMigrationFileListDuplicate */
	public function testCheckFileListOrderDuplicate(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$actualFileList = $migrator->getMigrationFileList();
		$this->expectException(MigrationSequenceOrderException::class);
		$migrator->checkFileListOrder($actualFileList);
	}

	public function testCheckFileListOrderOutOfOrder():void {
		$path = $this->getMigrationDirectory();
		$files = [
			str_pad(1, 4, "0", STR_PAD_LEFT) . "-" . uniqid() . ".sql",
			str_pad(2, 4, "0", STR_PAD_LEFT) . "-" . uniqid() . ".sql",
			str_pad(3, 4, "0", STR_PAD_LEFT) . "-" . uniqid() . ".sql",
		];
		$this->createFiles($files, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);

		$absolute = array_map(function($f) use ($path) {
			return implode(DIRECTORY_SEPARATOR, [$path, $f]);
		}, $files);

		// Pass files deliberately out of numeric order: 1, 3, 2
		$outOfOrder = [$absolute[0], $absolute[2], $absolute[1]];

		$this->expectException(MigrationSequenceOrderException::class);
		$migrator->checkFileListOrder($outOfOrder);
	}

	/** @dataProvider dataMigrationFileList */
	public function testCheckIntegrityGood(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);
		$this->hashMigrationToDb($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		self::assertEquals(
			count($absoluteFileList),
			$migrator->checkIntegrity($absoluteFileList)
		);
	}

	/** @dataProvider dataMigrationFileList */
	public function testCheckIntegrityBad(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);
		$this->hashMigrationToDb($fileList, $path);

		$migrationToBreak = implode(DIRECTORY_SEPARATOR, [
			$path,
			$fileList[array_rand($fileList)],
		]);
		$sql = file_get_contents($migrationToBreak);
		$sql = substr_replace(
			$sql,
			"EDITED",
			rand(0, 20),
			0
		);
		file_put_contents($migrationToBreak, $sql);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		self::expectException(MigrationIntegrityException::class);
		$migrator->checkIntegrity($absoluteFileList);
	}

	public function testMigrationCountZeroAtStart() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$migrator->createMigrationTable();
		self::assertEquals(0, $migrator->getMigrationCount());
	}

	/** @dataProvider dataMigrationFileList */
	public function testMigrationCountNotZeroAfterMigration(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);
		$this->hashMigrationToDb($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		self::assertEquals(
			count($absoluteFileList),
			$migrator->getMigrationCount()
		);
	}

	/** @dataProvider dataMigrationFileList */
	public function testMigrationCountReturnsZeroOnException(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		self::assertEquals(0, $migrator->getMigrationCount());
	}

	/**
	 * @dataProvider dataMigrationFileList
	 */
	public function testMigrationFileNameFormat(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);
		$this->hashMigrationToDb($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		$fileToBreakFormat = array_rand($absoluteFileList);
		$absoluteFileList[$fileToBreakFormat] = str_replace(
			".sql",
			".broken",
			$absoluteFileList[$fileToBreakFormat]
		);

		self::expectException(MigrationFileNameFormatException::class);
		$migrator->checkFileListOrder($absoluteFileList);
	}

	/**
	 * @dataProvider dataMigrationFileList
	 */
	public function testForcedMigration(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);
		$this->hashMigrationToDb($fileList, $path);

		$settings = $this->createSettings($path);
		$exception = null;

		try {
			new Migrator(
				$settings,
				$path,
				"_migration",
				true
			);
		}
		catch(Exception $exception) {}

		self::assertNull($exception,"Exception should not be thrown");
	}

	/** @dataProvider dataMigrationFileList */
	public function testHashMismatchAfterEditingFirstFile(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file) use ($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		}, $fileList);

// Perform the initial full migration.
		$migrator->createMigrationTable();
		$migrator->performMigration($absoluteFileList);

// Now change the contents of the first migration file to break integrity.
		$firstFile = $absoluteFileList[0];
		$originalSql = file_get_contents($firstFile);
		file_put_contents($firstFile, $originalSql . "\n-- edited to break hash\n");

// First, when providing the current migration count (skipping
// already-migrated files), the integrity check should NOT throw an exception
// because it skips the altered first file.
		$exception = null;
		$migrationCount = $migrator->getMigrationCount();
		try {
			$migrator->checkIntegrity($absoluteFileList, $migrationCount);
		}
		catch(Exception $exception) {}

		self::assertNull($exception);

// However, checking integrity with no migration count should fail due to a hash
// mismatch in the first file.
		self::expectException(MigrationIntegrityException::class);
		$migrator->checkIntegrity($absoluteFileList);
	}

	/**
	 * This test needs an explanation because it's not immediately obvious.
	 * The fileList is generated as usual, but then to simulate a real
	 * production "messy" codebase, a new migration file is created with a
	 * much higher sequence (15 higher than the last in the fileList).
	 *
	 * Because of this, the migration will fail. However, we reset the
	 * migration sequence before performing the migration, and even though
	 * none of the files in fileList are migrated yet, we should only see
	 * 1 migration take place.
	 *
	 * @dataProvider dataMigrationFileList
	 */
	public function testResetMigration(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);

		$migrator = new Migrator(
			$settings,
			$path,
			"_migration",
		);

		$newNumber = count($fileList) + 15;

		$newFileName = str_pad($newNumber, 4, "0", STR_PAD_LEFT);
		$newFileName .= "-" . uniqid() . ".sql";
		$newFilePath = implode(DIRECTORY_SEPARATOR, [
			$path,
			$newFileName,
		]);
		array_push($fileList, $newFileName);

		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		$lastKey = array_key_last($absoluteFileList);
		file_put_contents($absoluteFileList[$lastKey], "create table migrated_out_of_order ( id int primary key )");

		$migrator->createMigrationTable();
		$migrator->resetMigrationSequence($newNumber - 1);
		$migrationCount = $migrator->getMigrationCount();
		$migrator->checkIntegrity($absoluteFileList, $migrationCount);
		$migrationsExecuted = $migrator->performMigration($absoluteFileList, $migrationCount);
		self::assertSame(1, $migrationsExecuted);
	}

	/**
	 * @dataProvider dataMigrationFileList
	 * @runInSeparateProcess
	 */
	public function testPerformMigrationGood(array $fileList):void {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		$migrator->createMigrationTable();

		$exception = null;

		try {
			ob_start();
			$migrator->performMigration($absoluteFileList);
			ob_end_clean();
		}
		catch(Exception $exception) {}

		$db = new Database($settings);
		$result = $db->executeSql("PRAGMA table_info(test);");
// There should be one more column than the number of files, due to the fact that the first
// migration creates the table with two columns.
		self::assertCount(
		count($absoluteFileList) + 1,
			$result->fetchAll()
		);
	}

	/** @dataProvider dataMigrationFileList */
	public function testPerformMigrationBad(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file) use ($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		}, $fileList);

		$fileToMessUp = $absoluteFileList[array_rand($absoluteFileList)];
		file_put_contents($fileToMessUp, "create nothing because nothing really matters");

		$migrator->createMigrationTable();
		$exception = null;

		self::expectException(DatabaseException::class);
		$migrator->performMigration($absoluteFileList);
	}

	/** @dataProvider dataMigrationFileList */
	public function testPerformMigrationAlreadyCompleted(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		}, $fileList);

		$existingCount = count($absoluteFileList);

		$migrator->createMigrationTable();
		$exception = null;

		try {
			ob_start();
			$numCompleted = $migrator->performMigration(
				$absoluteFileList,
				$existingCount
			);
			ob_end_clean();
		}
		catch(Exception $exception) {}

		self::assertNull($exception);
		self::assertEquals(0, $numCompleted);

	}

	/**
	 * @dataProvider dataMigrationFileList
	 */
	public function testNonSqlExtensions(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);

		$extensionsToCreate = ["txt", "md", "sql"];

		for($i = 0; $i < 10; $i++) {
			$randomFile = implode(DIRECTORY_SEPARATOR, [
				$path,
				uniqid() . $extensionsToCreate[array_rand($extensionsToCreate)],
			]);
			file_put_contents($randomFile, "Hello, Database!");
		}

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		$migrator->createMigrationTable();

		$exception = null;

		try {
			ob_start();
			$migrator->performMigration($absoluteFileList);
			ob_end_clean();
		}
		catch(Exception $exception) {}

		self::assertNull($exception);
	}

	/** @dataProvider dataMigrationFileList */
	public function testMigrationThrowsExceptionWhenNoMigrationTable(array $fileList) {
		$path = $this->getMigrationDirectory();

		$this->createMigrationFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		self::expectException(DatabaseException::class);
		$migrator->performMigration($absoluteFileList);
	}

	public function testMigrationNoOutputEmpty() {
		$path = $this->getMigrationDirectory();
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);
		ob_start();
		$migrator->performMigration([]);
		$output = ob_get_clean();
		self::assertEmpty($output);
	}

	/** @dataProvider dataMigrationFileList */
	public function testMigrationNoOutput(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);

		$settings = $this->createSettings($path);

		ob_start();

		$migrator = new Migrator($settings, $path);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		$migrator->createMigrationTable();
		$migrator->performMigration($absoluteFileList);

		$output = ob_get_clean();
		self::assertEmpty($output);
	}

	/** @dataProvider dataMigrationFileList */
	public function testMigrationOutputToStream(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);

		$settings = $this->createSettings($path);
		$streamOut = new SplFileObject("php://memory", "w");

		$migrator = new Migrator($settings, $path);
		$migrator->setOutput($streamOut);
		$absoluteFileList = array_map(function($file)use($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		},$fileList);

		$migrator->createMigrationTable();
		$migrator->performMigration($absoluteFileList);

		$streamOut->rewind();
		$output = $streamOut->fread(4096);
		self::assertStringContainsString("Migration 1:", $output);

		$expectedCount = count($fileList);
		self::assertStringContainsString("$expectedCount migrations were completed successfully.", $output);
	}

	/** @dataProvider dataMigrationFileList */
	public function testMigrationErrorOutputToStream(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);

		$streamOut = new SplFileObject("php://memory", "w");
		$streamError = new SplFileObject("php://memory", "w");

		$migrator = new Migrator($settings, $path);
		$migrator->setOutput($streamOut, $streamError);
		$absoluteFileList = array_map(function($file) use ($path) {
			return implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
		}, $fileList);

		$fileToMessUp = $absoluteFileList[array_rand($absoluteFileList)];
		file_put_contents($fileToMessUp, "create nothing because nothing really matters");

		$migrator->createMigrationTable();
		$exception = null;

		try {
			$migrator->performMigration($absoluteFileList);
		}
		catch(DatabaseException $exception) {
			$streamOut->rewind();
			$output = $streamOut->fread(1024);
			$streamError->rewind();
			$outputError = $streamError->fread(1024);
			self::assertStringContainsString(
				"Migration 1:",
				$output
			);
			self::assertStringNotContainsString(
				"Migration 1:",
				$outputError
			);
			self::assertStringNotContainsString(
				"General error: 1 near \"nothing\": syntax error",
				$output
			);
		}
	}

	public static function dataMigrationFileList():array {
		$fileList = self::generateFileList();
		return [
			[$fileList]
		];
	}

	public static function dataMigrationFileListMissing():array {
		$fileList = self::generateFileList(
			true,
			false
		);
		return [
			[$fileList]
		];
	}

	public static function dataMigrationFileListDuplicate():array {
		$fileList = self::generateFileList(
			false,
			true
		);
		return [
			[$fileList]
		];
	}

	protected function createMigrationFiles(array $fileList, string $path):void {
		foreach($fileList as $i => $fileName) {
			$migPathName = implode(DIRECTORY_SEPARATOR, [
				$path,
				$fileName,
			]);
			if($i === 0) {
				$mig = self::MIGRATION_CREATE;
			}
			else {
				$mig = self::MIGRATION_ALTER;
				$mig = str_replace(
					"`new_column`",
					"`new_column_$i`",
					$mig
				);
			}

			file_put_contents($migPathName, $mig);
		}
	}

	protected function hashMigrationToDb(
		array $fileList,
		string $path,
		bool $stopEarly = false
	):void {
		$hashUpTo = null;

		if($stopEarly) {
			$hashUpTo = count($fileList) - rand(0, count($fileList) - 5);
		}

		$settings = $this->createSettings($path);
		$db = new Database($settings);
		$db->executeSql(implode("\n", [
			"create table `_migration` (",
			"`" . Migrator::COLUMN_QUERY_NUMBER . "` int primary key,",
			"`" . Migrator::COLUMN_QUERY_HASH . "` varchar(32) not null,",
			"`" . Migrator::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));

		foreach($fileList as $i => $file) {
			if(!is_null($hashUpTo)
			&& $i >= $hashUpTo) {
				break;
			}

			$migNum = $i + 1;
			$migPathName = implode(DIRECTORY_SEPARATOR, [
				$path,
				$file,
			]);
			$hash = md5_file($migPathName);

			$sql = implode("\n", [
				"insert into `_migration` (",
				"`" . Migrator::COLUMN_QUERY_NUMBER . "`, ",
				"`" . Migrator::COLUMN_QUERY_HASH . "`, ",
				"`" . Migrator::COLUMN_MIGRATED_AT . "` ",
				") values (",
				"?, ?, datetime('now')",
				")",
			]);

			$db->executeSql($sql, [$migNum, $hash]);
		}
	}

	private static function generateFileList($missingFiles = false, $duplicateFiles = false) {
		$fileList = [];

		$migLength = rand(10, 30);
		for($migNum = 1; $migNum <= $migLength; $migNum++) {
			$fileName = str_pad(
				$migNum,
				4,
				"0",
				STR_PAD_LEFT
			);
			$fileName .= "-";
			$fileName .= uniqid();
			$fileName .= ".sql";

			$fileList []= $fileName;
		}

		if($missingFiles) {
			$numToRemove = rand(1, (int)($migLength / 10));
			for($i = 0; $i < $numToRemove; $i++) {
				do {
					$keyToRemove = array_rand($fileList);
				}
				while($keyToRemove === array_key_last($fileList));
				unset($fileList[$keyToRemove]);
			}
		}

		if($duplicateFiles) {
			$numToDuplicate = rand(1, 10);
			for($i = 0; $i < $numToDuplicate; $i++) {
				$keyToDuplicate = array_rand($fileList);
				$newFilename = $fileList[$keyToDuplicate];
				$newFilename = strtok($newFilename, "-");
				$newFilename .= "-";
				$newFilename .= uniqid();
				$newFilename .= ".sql";
				$fileList []= $newFilename;
			}

			$fileList = array_values($fileList);
			sort($fileList);
		}

		$fileList = array_values($fileList);
		return $fileList;
	}

	protected function createSettings(string $path):Settings {
		$sqlitePath = implode(DIRECTORY_SEPARATOR, [
			dirname($path),
			"migrator-test.db",
		]);
		$sqlitePath = str_replace("\\", "/", $sqlitePath);

		return new Settings(
			dirname(dirname($path)),
			Settings::DRIVER_SQLITE,
			$sqlitePath
		);
	}

	/**
	 * New tests for migrating from a specific file number and handling gaps
	 */
	/** @dataProvider dataMigrationFileList */
	public function testPerformMigrationFromSpecificNumber(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);

		$absoluteFileList = array_map(function($file) use ($path) {
			return implode(DIRECTORY_SEPARATOR, [ $path, $file ]);
		}, $fileList);

		$startNumber = $migrator->extractNumberFromFilename($absoluteFileList[0]);
		$from = $startNumber - 1;

		$migrator->createMigrationTable();
		if($from >= 1) {
			// Ensure base table exists when skipping the first migration
			$db = new Database($settings);
			$db->executeSql(self::MIGRATION_CREATE);
		}

		$streamOut = new SplFileObject("php://memory", "w");
		$migrator->setOutput($streamOut);

		$executed = $migrator->performMigration($absoluteFileList, $from);

		$expected = 0;
		foreach($absoluteFileList as $file) {
			if($migrator->extractNumberFromFilename($file) >= $startNumber) {
				$expected++;
			}
		}

		$streamOut->rewind();
		$output = $streamOut->fread(4096);
		self::assertMatchesRegularExpression("/Migration\\s+{$startNumber}:/", $output);
		self::assertStringContainsString("$expected migrations were completed successfully.", $output);
		self::assertSame($expected, $executed);
		self::assertSame($expected, $migrator->getMigrationCount());
	}

	/** @dataProvider dataMigrationFileListMissing */
	public function testPerformMigrationFromSpecificNumberWithGaps(array $fileList) {
		$path = $this->getMigrationDirectory();
		$this->createMigrationFiles($fileList, $path);
		$settings = $this->createSettings($path);
		$migrator = new Migrator($settings, $path);

		$absoluteFileList = array_map(function($file) use ($path) {
			return implode(DIRECTORY_SEPARATOR, [ $path, $file ]);
		}, $fileList);

		// Build the list of actual migration numbers present (with gaps allowed)
		$numbers = array_map(function($file) use ($migrator) {
			return $migrator->extractNumberFromFilename($file);
		}, $absoluteFileList);
		sort($numbers);

		// Pick a start number from the set (not the last one)
		$startNumber = $numbers[(int)floor(count($numbers) / 2)];
		$from = $startNumber - 1;

		$migrator->createMigrationTable();
		if($from >= 1) {
			$db = new Database($settings);
			$db->executeSql(self::MIGRATION_CREATE);
		}

		$streamOut = new SplFileObject("php://memory", "w");
		$migrator->setOutput($streamOut);

		$executed = $migrator->performMigration($absoluteFileList, $from);

		$expected = 0;
		foreach($numbers as $n) {
			if($n >= $startNumber) {
				$expected++;
			}
		}

		$streamOut->rewind();
		$output = $streamOut->fread(4096);
		self::assertMatchesRegularExpression("/Migration\\s+{$startNumber}:/", $output);
		for($n = 1; $n < $startNumber; $n++) {
			self::assertStringNotContainsString("Migration $n:", $output);
		}
		self::assertStringContainsString("$expected migrations were completed successfully.", $output);
		self::assertSame($expected, $executed);
	}

	protected function createFiles(array $files, string $path):void {
		foreach($files as $filename) {
			$pathName = implode(DIRECTORY_SEPARATOR, [
				$path,
				$filename
			]);

			touch($pathName);
		}
	}
}
