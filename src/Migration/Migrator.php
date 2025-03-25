<?php /** @noinspection ALL */
namespace Gt\Database\Migration;

use DirectoryIterator;
use Exception;
use Gt\Database\Database;
use Gt\Database\Connection\Settings;
use Gt\Database\DatabaseException;
use PHPSQLParser\lexer\PHPSQLLexer;
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

	public function __construct(
		Settings $settings,
		string $path,
		string $tableName = "_migration"
	) {
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
			"`" . self::COLUMN_QUERY_HASH . "` varchar(32) not null,",
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
		sort($fileList);
		return $fileList;
	}

	/** @param array<string> $fileList */
	public function checkFileListOrder(array $fileList):void {
		$counter = 0;
		$sequence = [];

		foreach($fileList as $file) {
			$counter++;
			$migrationNumber = $this->extractNumberFromFilename($file);
			$sequence []= $migrationNumber;

			if($counter !== $migrationNumber) {
				throw new MigrationSequenceOrderException(
					"Missing: $counter"
				);
			}
		}
	}

	/** @param array<string> $migrationFileList */
	public function checkIntegrity(
		array $migrationFileList,
		?int $migrationCount = null
	):int {
		$fileNumber = 0;

		foreach($migrationFileList as $i => $file) {
			$fileNumber = $i + 1;
			$md5 = md5_file($file);

			if(is_null($migrationCount)
				|| $fileNumber <= $migrationCount) {
				$result = $this->dbClient->executeSql(implode("\n", [
					"select `" . self::COLUMN_QUERY_HASH . "`",
					"from `{$this->tableName}`",
					"where `" . self::COLUMN_QUERY_NUMBER . "` = ?",
					"limit 1",
				]), [$fileNumber]);

				$hashInDb = ($result->fetch())->getString(self::COLUMN_QUERY_HASH);

				if($hashInDb !== $md5) {
					throw new MigrationIntegrityException($file);
				}
			}
		}

		return $fileNumber;
	}

	protected function extractNumberFromFilename(string $pathName):int {
		$file = new SplFileInfo($pathName);
		$filename = $file->getFilename();
		preg_match("/(\d+)-?.*\.sql/", $filename, $matches);

		if(!isset($matches[1])) {
			throw new MigrationFileNameFormatException($filename);
		}

		return (int)$matches[1];
	}

	/** @param array<string> $migrationFileList */
	public function performMigration(
		array $migrationFileList,
		int $existingMigrationCount = 0
	):int {
		$fileNumber = 0;
		$numCompleted = 0;

		foreach($migrationFileList as $i => $file) {
			$fileNumber = $i + 1;

			if($fileNumber <= $existingMigrationCount) {
				continue;
			}

			$this->output("Migration $fileNumber: `$file`.");

			$totalSql = file_get_contents($file);
			$md5 = md5_file($file);

			$lexer = new PHPSQLLexer();
			$splitSqlQueryList = [];
			$currentQuery = "";
			foreach($lexer->split($totalSql) as $token) {
				if($token === ";") {
					array_push($splitSqlQueryList, $currentQuery);
					$currentQuery = "";
					continue;
				}

				$currentQuery .= $token;
			}
			if($currentQuery) {
				array_push($splitSqlQueryList, $currentQuery);
			}

			try {
				foreach($splitSqlQueryList as $sql) {
					$sql = trim($sql);
					if(!$sql) {
						continue;
					}
					$this->dbClient->executeSql($sql);
				}

				$this->recordMigrationSuccess($fileNumber, $md5);
			}
			catch(DatabaseException $exception) {
				throw $exception;
			}

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

	protected function recordMigrationSuccess(int $number, string $hash):void {
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

	/**
	 * @codeCoverageIgnore
	 */
	public function deleteAndRecreateSchema():void {
		if($this->driver === Settings::DRIVER_SQLITE) {
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
