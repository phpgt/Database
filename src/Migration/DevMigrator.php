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
			"`" . self::COLUMN_MIGRATED_AT . "` datetime not null )",
		]));
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
			if($this->hasMigrationBeenApplied($fileName)) {
				continue;
			}

			$fileNumber = $this->extractNumberFromFilename($file);
			$this->output("Dev migration $fileNumber: `$file`.");
			$md5 = $this->executeSqlFile($file);
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
		$now = $this->nowExpression();

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
}
