<?php /** @noinspection ALL */
namespace Gt\Database\Migration;

use Exception;
use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use Gt\Database\DatabaseException;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Migrator extends AbstractMigrator {
	const COLUMN_QUERY_NUMBER = "queryNumber";
	const COLUMN_QUERY_HASH = "queryHash";
	const COLUMN_MIGRATED_AT = "migratedAt";

	protected string $schema;
	protected string $charset;
	protected string $collate;

	public function __construct(
		Settings $settings,
		string $path,
		string $tableName = "_migration"
	) {
		parent::__construct($settings, $path, $tableName);
		$this->schema = $settings->getSchema();
		$this->charset = $settings->getCharset();
		$this->collate = $settings->getCollation();
	}

	protected function getDefaultTableName():string {
		return "_migration";
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
			"`" . self::COLUMN_LAST_STATEMENT . "` int null,",
			"`" . self::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));
		$this->ensureColumnExists(self::COLUMN_LAST_STATEMENT, "int null");
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

	/** @param array<string> $migrationFileList */
	public function getContiguousCompletedMigrationCount(array $migrationFileList):int {
		$completedCount = 0;

		foreach($migrationFileList as $file) {
			$fileNumber = $this->extractNumberFromFilename($file);
			$progress = $this->getMigrationProgress($fileNumber);
			if(is_null($progress)) {
				break;
			}

			if(is_null($progress["hash"])) {
				$completedCount = $fileNumber;
				continue;
			}

			if(is_null($progress["lastStatement"])) {
				$completedCount = $fileNumber;
				continue;
			}

			if($progress["lastStatement"] < $this->countSqlStatements($file)) {
				break;
			}

			$completedCount = $fileNumber;
		}

		return $completedCount;
	}

	/** @return array<string> */
	public function getMigrationFileList():array {
		if(!is_dir($this->path)) {
			throw new MigrationDirectoryNotFoundException(
				$this->path
			);
		}

		return parent::getMigrationFileList();
	}

	/** @param array<string> $migrationFileList */
	public function checkIntegrity(
		array $migrationFileList,
		?int $migrationStartFrom = null
	):int {
		$fileNumber = 0;

		foreach($migrationFileList as $file) {
			$fileNumber = $this->extractNumberFromFilename($file);

			if(!is_null($migrationStartFrom) && $fileNumber <= $migrationStartFrom) {
				continue;
			}

			$md5 = md5_file($file);
			$hashInDb = $this->getMigrationProgress($fileNumber)["hash"] ?? null;

			if($hashInDb && $hashInDb !== $md5) {
				throw new MigrationIntegrityException($file);
			}
		}

		return $fileNumber;
	}

	/** @param array<string> $migrationFileList */
	public function performMigration(
		array $migrationFileList,
		int $existingFileNumber = 0
	):int {
		$numCompleted = 0;

		foreach($migrationFileList as $file) {
			$fileNumber = $this->extractNumberFromFilename($file);
			if($fileNumber <= $existingFileNumber) {
				continue;
			}

			$statementList = $this->splitSqlFile($file);
			$totalStatements = count($statementList);
			$progress = $this->getMigrationProgress($fileNumber);
			$md5 = md5_file($file);
			if($progress && $progress["hash"] && $progress["hash"] !== $md5) {
				throw new MigrationIntegrityException($file);
			}

			$lastCompletedStatement = $this->resolveLastCompletedStatement(
				$progress,
				$totalStatements
			);
			if($lastCompletedStatement >= $totalStatements) {
				continue;
			}

			$this->output("Migration $fileNumber: `$file`.");
			foreach($statementList as $statementIndex => $sql) {
				$statementNumber = $statementIndex + 1;
				if($statementNumber <= $lastCompletedStatement) {
					continue;
				}

				$this->dbClient->executeSql($sql);
				$this->recordMigrationProgress($fileNumber, $md5, $statementNumber);
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
		$this->recordMigrationProgress($number, $hash, null);
	}

	protected function recordMigrationProgress(
		int $number,
		?string $hash,
		?int $lastStatement
	):void {
		$now = $this->nowExpression();
		$existingState = $this->getMigrationProgress($number);

		if($existingState) {
			$this->dbClient->executeSql(implode("\n", [
				"update `{$this->tableName}`",
				"set `" . self::COLUMN_QUERY_HASH . "` = ?,",
				"`" . self::COLUMN_LAST_STATEMENT . "` = ?,",
				"`" . self::COLUMN_MIGRATED_AT . "` = $now",
				"where `" . self::COLUMN_QUERY_NUMBER . "` = ?",
			]), [$hash, $lastStatement, $number]);
			return;
		}

		$this->dbClient->executeSql(implode("\n", [
			"insert into `{$this->tableName}` (",
			"`" . self::COLUMN_QUERY_NUMBER . "`, ",
			"`" . self::COLUMN_QUERY_HASH . "`, ",
			"`" . self::COLUMN_LAST_STATEMENT . "`, ",
			"`" . self::COLUMN_MIGRATED_AT . "` ",
			") values (",
			"?, ?, ?, $now",
			")",
		]), [$number, $hash, $lastStatement]);
	}

	public function markMigrationApplied(
		int $number,
		string $hash,
		int $statementCount
	):void {
		$this->recordMigrationProgress($number, $hash, $statementCount);
	}

	public function resetMigrationSequence(int $numberToForce):void {
		$this->recordMigrationSuccess($numberToForce, null);
	}

	/** @return array{hash: ?string, lastStatement: ?int}|null */
	protected function getMigrationProgress(int $fileNumber):?array {
		$selectList = "`" . self::COLUMN_QUERY_HASH . "`";
		if($this->tableHasColumn(self::COLUMN_LAST_STATEMENT)) {
			$selectList .= ", `" . self::COLUMN_LAST_STATEMENT . "`";
		}
		else {
			$selectList .= ", null as `" . self::COLUMN_LAST_STATEMENT . "`";
		}

		$result = $this->dbClient->executeSql(implode("\n", [
			"select $selectList",
			"from `{$this->tableName}`",
			"where `" . self::COLUMN_QUERY_NUMBER . "` = ?",
			"limit 1",
		]), [$fileNumber]);
		$row = $result->fetch();
		if(!$row) {
			return null;
		}

		return [
			"hash" => $row->getString(self::COLUMN_QUERY_HASH),
			"lastStatement" => $row->getInt(self::COLUMN_LAST_STATEMENT),
		];
	}

	/** @param array{hash: ?string, lastStatement: ?int}|null $progress */
	protected function resolveLastCompletedStatement(
		?array $progress,
		int $totalStatements
	):int {
		if(!$progress) {
			return 0;
		}

		if(is_null($progress["lastStatement"])) {
			return $progress["hash"] ? $totalStatements : 0;
		}

		return $progress["lastStatement"];
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
}
