<?php /** @noinspection ALL */
namespace Gt\Database\Migration;

use DirectoryIterator;
use Exception;
use Gt\Database\Database;
use Gt\Database\Connection\Settings;
use Gt\Database\DatabaseException;
use SplFileInfo;
use SplFileObject;

class Migrator {
	const COLUMN_QUERY_NUMBER = "queryNumber";
	const COLUMN_QUERY_HASH = "queryHash";
	const COLUMN_MIGRATED_AT = "migratedAt";

	const STREAM_OUT = "out";
	const STREAM_ERROR = "error";

	protected ?SplFileObject $streamError;
	protected ?SplFileObject $streamOut;

	protected string $driver;
	protected string $schema;
	protected Database $dbClient;
	protected string $path;
	protected string $tableName;
	protected string $charset;
	protected string $collate;
	protected Settings $settings;

	public function __construct(
		Settings $settings,
		string $path,
		string $tableName = "_migration"
	) {
		$this->settings = clone $settings;
		$this->schema = $settings->getSchema();
		$this->path = $path;
		$this->tableName = $tableName;
		$this->driver = $settings->getDriver();

		$this->charset = $settings->getCharset();
		$this->collate = $settings->getCollation();

		if($this->driver !== Settings::DRIVER_SQLITE) {
			$settings = $settings->withoutSchema(); // @codeCoverageIgnore
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

	public function checkMigrationTableExists():bool {
		switch($this->driver) {
		case Settings::DRIVER_SQLITE:
			$result = $this->dbClient->executeSql(
				"select name from sqlite_master "
				. "where type=? "
				. "and name like ?", [
					"table",
					$this->tableName,
				]
			);
			break;

		default:
// @codeCoverageIgnoreStart
			$result = $this->dbClient->executeSql(
				"show tables like ?",
				[
					$this->tableName
				]
			);
			break;
// @codeCoverageIgnoreEnd
		}

		return !empty($result->fetch());
	}

	public function createMigrationTable():void {
		$this->dbClient->executeSql(implode("\n", [
			"create table if not exists `{$this->tableName}` (",
			"`" . self::COLUMN_QUERY_NUMBER . "` int primary key,",
			"`" . self::COLUMN_QUERY_HASH . "` varchar(32) null,",
			"`" . self::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));
	}

	public function getMigrationCount():int {
		try {
			$result = $this->dbClient->executeSql("select `"
				. self::COLUMN_QUERY_NUMBER
				. "` from `{$this->tableName}` "
				. "order by `" . self::COLUMN_QUERY_NUMBER . "` desc"
			);
			$row = $result->fetch();
		}
		catch(DatabaseException $exception) {
			return 0;
		}

		return $row?->getInt(self::COLUMN_QUERY_NUMBER) ?? 0;
	}

	/** @return array<string> */
	public function getMigrationFileList():array {
		if(!is_dir($this->path)) {
			throw new MigrationDirectoryNotFoundException(
				$this->path
			);
		}

		$fileList = glob("$this->path/*.sql");
		$fileList = array_values(array_filter($fileList, function(string $file):bool {
			return preg_match("/^\d+.*\.sql$/", basename($file)) === 1;
		}));
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
	public function checkIntegrity(
		array $migrationFileList,
		?int $migrationStartFrom = null
	):int {
		$fileNumber = 0;
		
		foreach($migrationFileList as $file) {
			$fileNumber = $this->extractNumberFromFilename($file);

			// If a start point is provided, skip files at or before that number
			// and only verify files AFTER the provided migration count.
			if(!is_null($migrationStartFrom) && $fileNumber <= $migrationStartFrom) {
				continue;
			}

			$md5 = md5_file($file);

			$result = $this->dbClient->executeSql(implode("\n", [
				"select `" . self::COLUMN_QUERY_HASH . "`",
				"from `{$this->tableName}`",
				"where `" . self::COLUMN_QUERY_NUMBER . "` = ?",
				"limit 1",
			]), [$fileNumber]);

			$hashInDb = ($result->fetch())?->getString(self::COLUMN_QUERY_HASH);

			if($hashInDb && $hashInDb !== $md5) {
				throw new MigrationIntegrityException($file);
			}
		}

		return $fileNumber;
	}

	public function extractNumberFromFilename(string $pathName):int {
		$file = new SplFileInfo($pathName);
		$filename = $file->getFilename();
		preg_match("/^(\d+)-?.*\.sql$/", $filename, $matches);

		if(!isset($matches[1])) {
			throw new MigrationFileNameFormatException($filename);
		}

		return (int)$matches[1];
	}

	/** @param array<string> $migrationFileList */
	public function performMigration(
		array $migrationFileList,
		int $existingFileNumber = 0
	):int {
		$numCompleted = 0;
		$sqlStatementSplitter = new SqlStatementSplitter();
		
		foreach($migrationFileList as $file) {
			$fileNumber = $this->extractNumberFromFilename($file);
			if($fileNumber <= $existingFileNumber) {
				continue;
			}

			$this->output("Migration $fileNumber: `$file`.");
			$md5 = md5_file($file);

			foreach($sqlStatementSplitter->split(file_get_contents($file)) as $sql) {
				$this->dbClient->executeSql($sql);
			}

			$this->recordMigrationSuccess($fileNumber, $md5);
			$numCompleted++;
		}

		if($numCompleted === 0) {
			$this->output("Migrations are already up to date.");
		}
		else {
			$this->output("$numCompleted migrations were completed successfully.");
		}

		return $numCompleted;
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function selectSchema():void {
// SQLITE databases represent their own schema.
		if($this->driver === Settings::DRIVER_SQLITE) {
			return;
		}

		$schema = $this->schema;

		try {
			$this->dbClient->executeSql(
				"create schema if not exists `$schema` default character set {$this->charset} default collate {$this->collate}"
			);
			$this->dbClient->executeSql(
				"use `$schema`"
			);
		}
		catch(DatabaseException $exception) {
			$this->output(
				"Error selecting schema `$schema`.",
				self::STREAM_ERROR
			);

			throw $exception;
		}
	}

	protected function recordMigrationSuccess(int $number, ?string $hash):void {
		$now = "now()";

		if($this->driver === Settings::DRIVER_SQLITE) {
			$now = "datetime('now')";
		}

		$this->dbClient->executeSql(implode("\n", [
			"insert into `{$this->tableName}` (",
			"`" . self::COLUMN_QUERY_NUMBER . "`, ",
			"`" . self::COLUMN_QUERY_HASH . "`, ",
			"`" . self::COLUMN_MIGRATED_AT . "` ",
			") values (",
			"?, ?, $now",
			")",
		]), [$number, $hash]);
	}

	public function markMigrationApplied(int $number, string $hash):void {
		$this->recordMigrationSuccess($number, $hash);
	}

	/**
	 * @param int $numberToForce A null-hashed migration will be marked as
	 * successful with this number. This will allow the next number to be
	 * executed out of sequence.
	 */
	public function resetMigrationSequence(int $numberToForce):void {
		$this->recordMigrationSuccess($numberToForce, null);
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function deleteAndRecreateSchema():void {
		if($this->driver === Settings::DRIVER_SQLITE) {
			unset($this->dbClient);

			if($this->schema !== Settings::SCHEMA_IN_MEMORY
			&& is_file($this->schema)) {
				unlink($this->schema);
			}

			$this->dbClient = new Database($this->settings);
			return;
		}

		try {
			$this->dbClient->executeSql(
				"drop schema if exists `{$this->schema}`"
			);
			$this->dbClient->executeSql(
				"create schema if not exists "
				. $this->schema
				. " default character set "
				. $this->charset
				. " default collate "
				. $this->collate
			);
		}
		catch(Exception $exception) {
			$this->output(
				"Error recreating schema `{$this->schema}`.",
				self::STREAM_ERROR
			);

			throw $exception;
		}
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
