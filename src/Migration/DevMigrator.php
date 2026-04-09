<?php /** @noinspection SqlNoDataSourceInspection */
namespace Gt\Database\Migration;

class DevMigrator extends AbstractMigrator {
	const string COLUMN_FILE_NAME = "fileName";
	const string COLUMN_QUERY_HASH = "queryHash";
	const string COLUMN_MIGRATED_AT = "migratedAt";

	protected function getDefaultTableName():string {
		return "_migration_dev";
	}

	public function createMigrationTable():void {
		$this->dbClient->executeSql(implode("\n", [
			"create table if not exists `{$this->tableName}` (",
			"`" . self::COLUMN_FILE_NAME . "` varchar(255) primary key,",
			"`" . self::COLUMN_QUERY_HASH . "` varchar(32) not null,",
			"`" . self::COLUMN_LAST_STATEMENT . "` int null,",
			"`" . self::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));
		$this->ensureColumnExists(self::COLUMN_LAST_STATEMENT, "int null");
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

	/** @param array<string> $migrationFileList */
	public function performMigration(array $migrationFileList):int {
		$numCompleted = 0;

		foreach($migrationFileList as $file) {
			$fileName = basename($file);
			$fileNumber = $this->extractNumberFromFilename($file);
			$statementList = $this->splitSqlFile($file);
			$totalStatements = count($statementList);
			$md5 = md5_file($file);
			$progress = $this->getStoredProgress($fileName);
			if($progress && $progress["hash"] !== $md5) {
				throw new MigrationIntegrityException($file);
			}

			$lastCompletedStatement = $this->resolveLastCompletedStatement(
				$progress,
				$totalStatements
			);
			if($lastCompletedStatement >= $totalStatements) {
				continue;
			}

			$this->output("Dev migration $fileNumber: `$file`.");
			foreach($statementList as $statementIndex => $sql) {
				$statementNumber = $statementIndex + 1;
				if($statementNumber <= $lastCompletedStatement) {
					continue;
				}

				$this->dbClient->executeSql($sql);
				$this->recordMigrationProgress($fileName, $md5, $statementNumber);
			}

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
			$storedProgress = $this->getStoredProgress($fileName);
			$storedHash = $storedProgress["hash"] ?? null;
			if(!$storedHash) {
				throw new MigrationIntegrityException($file);
			}

			$targetName = $this->createMergedFilename($fileName, $nextNumber);
			$targetFile = implode(DIRECTORY_SEPARATOR, [$targetPath, $targetName]);
			if(file_exists($targetFile)) {
				throw new MigrationSequenceOrderException("Duplicate: $targetName");
			}

			rename($file, $targetFile);
			$migrator->markMigrationApplied(
				$nextNumber,
				$storedHash,
				$this->countSqlStatements($targetFile)
			);
			$this->deleteMigrationRecord($fileName);
			$this->output("Merged dev migration `$fileName` to `$targetName`.");
			$nextNumber++;
			$numMerged++;
		}

		$this->output("$numMerged dev migrations were merged successfully.");
		return $numMerged;
	}

	protected function hasMigrationBeenApplied(string $fileName):bool {
		$progress = $this->getStoredProgress($fileName);
		return !is_null($progress) && !is_null($progress["hash"]);
	}

	protected function getStoredHash(string $fileName):?string {
		return $this->getStoredProgress($fileName)["hash"] ?? null;
	}

	/** @return array{hash: ?string, lastStatement: ?int}|null */
	protected function getStoredProgress(string $fileName):?array {
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
			"where `" . self::COLUMN_FILE_NAME . "` = ?",
			"limit 1",
		]), [$fileName]);
		$row = $result->fetch();
		if(!$row) {
			return null;
		}

		return [
			"hash" => $row->getString(self::COLUMN_QUERY_HASH),
			"lastStatement" => $row->getInt(self::COLUMN_LAST_STATEMENT),
		];
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
		$this->recordMigrationProgress($fileName, $hash, null);
	}

	protected function recordMigrationProgress(
		string $fileName,
		string $hash,
		?int $lastStatement
	):void {
		$now = $this->nowExpression();
		$existingState = $this->getStoredProgress($fileName);

		if($existingState) {
			$this->dbClient->executeSql(implode("\n", [
				"update `{$this->tableName}`",
				"set `" . self::COLUMN_QUERY_HASH . "` = ?,",
				"`" . self::COLUMN_LAST_STATEMENT . "` = ?,",
				"`" . self::COLUMN_MIGRATED_AT . "` = $now",
				"where `" . self::COLUMN_FILE_NAME . "` = ?",
			]), [$hash, $lastStatement, $fileName]);
			return;
		}

		$this->dbClient->executeSql(implode("\n", [
			"insert into `{$this->tableName}` (",
			"`" . self::COLUMN_FILE_NAME . "`, ",
			"`" . self::COLUMN_QUERY_HASH . "`, ",
			"`" . self::COLUMN_LAST_STATEMENT . "`, ",
			"`" . self::COLUMN_MIGRATED_AT . "` ",
			") values (",
			"?, ?, ?, $now",
			")",
		]), [$fileName, $hash, $lastStatement]);
	}

	protected function deleteMigrationRecord(string $fileName):void {
		$this->dbClient->executeSql(implode("\n", [
			"delete from `{$this->tableName}`",
			"where `" . self::COLUMN_FILE_NAME . "` = ?",
		]), [$fileName]);
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
			return $totalStatements;
		}

		return $progress["lastStatement"];
	}
}
