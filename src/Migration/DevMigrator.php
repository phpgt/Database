<?php /** @noinspection SqlNoDataSourceInspection */
namespace Gt\Database\Migration;

use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use SplFileInfo;
use SplFileObject;

class DevMigrator {
	const string COLUMN_FILE_NAME = "fileName";
	const string COLUMN_QUERY_HASH = "queryHash";
	const string COLUMN_MIGRATED_AT = "migratedAt";

	const string STREAM_OUT = "out";
	const string STREAM_ERROR = "error";

	protected ?SplFileObject $streamError = null;
	protected ?SplFileObject $streamOut = null;

	protected string $driver;
	protected Database $dbClient;
	protected string $path;
	protected string $tableName;

	public function __construct(
		Settings $settings,
		string $path,
		string $tableName = "_migration_dev"
	) {
		$this->driver = $settings->getDriver();
		$this->path = $path;
		$this->tableName = $tableName;

		if($this->driver !== Settings::DRIVER_SQLITE) {
			$settings = $settings->withoutSchema();
		}

		$this->dbClient = new Database($settings);
	}

	public function setOutput(
		SplFileObject $out,
		?SplFileObject $error = null
	):void {
		$this->streamOut = $out;
		$this->streamError = $error;
	}

	public function createMigrationTable():void {
		$this->dbClient->executeSql(implode("\n", [
			"create table if not exists `{$this->tableName}` (",
			"`" . self::COLUMN_FILE_NAME . "` varchar(255) primary key,",
			"`" . self::COLUMN_QUERY_HASH . "` varchar(32) not null,",
			"`" . self::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));
	}

	/** @return array<string> */
	public function getMigrationFileList():array {
		if(!is_dir($this->path)) {
			return [];
		}

		$fileList = glob("$this->path/*.sql");
		sort($fileList);
		return $fileList;
	}

	/** @param array<string> $fileList */
	public function checkFileListOrder(array $fileList):void {
		$previousNumber = null;

		foreach($fileList as $file) {
			$migrationNumber = $this->extractNumberFromFilename($file);

			if(!is_null($previousNumber)) {
				if($migrationNumber === $previousNumber) {
					throw new MigrationSequenceOrderException("Duplicate: $migrationNumber");
				}
				if($migrationNumber < $previousNumber) {
					throw new MigrationSequenceOrderException("Out of order: $migrationNumber before $previousNumber");
				}
				if($migrationNumber !== $previousNumber + 1) {
					throw new MigrationSequenceOrderException("Gap: $previousNumber before $migrationNumber");
				}
			}
			elseif($migrationNumber !== 1) {
				throw new MigrationSequenceOrderException("Gap: expected 1, got $migrationNumber");
			}

			$previousNumber = $migrationNumber;
		}
	}

	/** @param array<string> $migrationFileList */
	public function checkIntegrity(array $migrationFileList):void {
		foreach($migrationFileList as $file) {
			$fileName = basename($file);
			$md5 = md5_file($file);
			$hashInDb = $this->getStoredHash($fileName);

			if($hashInDb && $hashInDb !== $md5) {
				throw new MigrationIntegrityException($file);
			}
		}
	}

	public function extractNumberFromFilename(string $pathName):int {
		$file = new SplFileInfo($pathName);
		$filename = $file->getFilename();
		preg_match("/(\d+)-?.*\.sql/", $filename, $matches);

		if(!isset($matches[1])) {
			throw new MigrationFileNameFormatException($filename);
		}

		return (int)$matches[1];
	}

	/** @param array<string> $migrationFileList */
	public function performMigration(array $migrationFileList):int {
		$numCompleted = 0;
		$sqlStatementSplitter = new SqlStatementSplitter();

		foreach($migrationFileList as $file) {
			$fileName = basename($file);
			if($this->hasMigrationBeenApplied($fileName)) {
				continue;
			}

			$fileNumber = $this->extractNumberFromFilename($file);
			$this->output("Dev migration $fileNumber: `$file`.");
			$md5 = md5_file($file);

			foreach($sqlStatementSplitter->split(file_get_contents($file)) as $sql) {
				$this->dbClient->executeSql($sql);
			}

			$this->recordMigrationSuccess($fileName, $md5);
			$numCompleted++;
		}

		if($numCompleted === 0) {
			$this->output("Dev migrations are already up to date.");
		}
		else {
			$this->output("$numCompleted dev migrations were completed successfully.");
		}

		return $numCompleted;
	}

	public function mergeIntoMainMigrationDirectory(
		Migrator $migrator,
		string $targetPath
	):int {
		$migrationFileList = $this->getMigrationFileList();
		$this->checkFileListOrder($migrationFileList);
		$this->checkIntegrity($migrationFileList);

		if(empty($migrationFileList)) {
			$this->output("No dev migrations to merge.");
			return 0;
		}

		if(!is_dir($targetPath)) {
			mkdir($targetPath, 0775, true);
		}

		$nextNumber = $this->getNextMainMigrationNumber($migrator);
		$numMerged = 0;

		foreach($migrationFileList as $file) {
			$fileName = basename($file);
			$storedHash = $this->getStoredHash($fileName);
			if(!$storedHash) {
				throw new MigrationIntegrityException($file);
			}

			$targetName = $this->createMergedFilename($fileName, $nextNumber);
			$targetFile = implode(DIRECTORY_SEPARATOR, [$targetPath, $targetName]);
			if(file_exists($targetFile)) {
				throw new MigrationSequenceOrderException("Duplicate: $targetName");
			}

			rename($file, $targetFile);
			$migrator->markMigrationApplied($nextNumber, $storedHash);
			$this->deleteMigrationRecord($fileName);
			$this->output("Merged dev migration `$fileName` to `$targetName`.");
			$nextNumber++;
			$numMerged++;
		}

		$this->output("$numMerged dev migrations were merged successfully.");
		return $numMerged;
	}

	protected function hasMigrationBeenApplied(string $fileName):bool {
		return (bool)$this->getStoredHash($fileName);
	}

	protected function getStoredHash(string $fileName):?string {
		$result = $this->dbClient->executeSql(implode("\n", [
			"select `" . self::COLUMN_QUERY_HASH . "`",
			"from `{$this->tableName}`",
			"where `" . self::COLUMN_FILE_NAME . "` = ?",
			"limit 1",
		]), [$fileName]);

		return ($result->fetch())?->getString(self::COLUMN_QUERY_HASH);
	}

	protected function getNextMainMigrationNumber(Migrator $migrator):int {
		$currentHighest = $migrator->getMigrationCount();
		$mainMigrationFileList = $migrator->getMigrationFileList();

		foreach($mainMigrationFileList as $file) {
			$currentHighest = max(
				$currentHighest,
				$migrator->extractNumberFromFilename($file)
			);
		}

		return $currentHighest + 1;
	}

	protected function createMergedFilename(string $fileName, int $number):string {
		preg_match("/^\d+(-?.*\.sql)$/", $fileName, $matches);
		$suffix = $matches[1] ?? ".sql";
		return str_pad((string)$number, 4, "0", STR_PAD_LEFT) . $suffix;
	}

	protected function recordMigrationSuccess(string $fileName, string $hash):void {
		$now = "now()";

		if($this->driver === Settings::DRIVER_SQLITE) {
			$now = "datetime('now')";
		}

		$this->dbClient->executeSql(implode("\n", [
			"insert into `{$this->tableName}` (",
			"`" . self::COLUMN_FILE_NAME . "`, ",
			"`" . self::COLUMN_QUERY_HASH . "`, ",
			"`" . self::COLUMN_MIGRATED_AT . "` ",
			") values (",
			"?, ?, $now",
			")",
		]), [$fileName, $hash]);
	}

	protected function deleteMigrationRecord(string $fileName):void {
		$this->dbClient->executeSql(implode("\n", [
			"delete from `{$this->tableName}`",
			"where `" . self::COLUMN_FILE_NAME . "` = ?",
		]), [$fileName]);
	}

	protected function output(
		string $message,
		string $streamName = self::STREAM_OUT
	):void {
		$stream = $this->streamOut ?? null;
		if($streamName === self::STREAM_ERROR) {
			$stream = $this->streamError;
		}

		if(is_null($stream)) {
			return;
		}

		$stream->fwrite($message . PHP_EOL);
	}
}
