<?php
namespace Gt\Database\Migration;

use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use SplFileInfo;
use SplFileObject;

abstract class AbstractMigrator {
	const string STREAM_OUT = "out";
	const string STREAM_ERROR = "error";
	const string COLUMN_LAST_STATEMENT = "lastStatement";

	protected ?SplFileObject $streamError = null;
	protected ?SplFileObject $streamOut = null;

	protected string $driver;
	protected Database $dbClient;
	protected string $path;
	protected string $tableName;
	protected Settings $settings;

	public function __construct(
		Settings $settings,
		string $path,
		?string $tableName = null
	) {
		$this->settings = clone $settings;
		$this->driver = $settings->getDriver();
		$this->path = $path;
		$this->tableName = $tableName ?? $this->getDefaultTableName();

// @codeCoverageIgnoreStart
		if($this->driver !== Settings::DRIVER_SQLITE) {
			$settings = $settings->withoutSchema();
		}
// @codeCoverageIgnoreEnd

		$this->dbClient = new Database($settings);
	}

	abstract protected function getDefaultTableName():string;

	public function setOutput(
		SplFileObject $out,
		?SplFileObject $error = null
	):void {
		$this->streamOut = $out;
		$this->streamError = $error;
	}

	/** @return array<string> */
	public function getMigrationFileList():array {
		if(!is_dir($this->path)) {
			return [];
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

	public function extractNumberFromFilename(string $pathName):int {
		$file = new SplFileInfo($pathName);
		$filename = $file->getFilename();
		preg_match("/^(\d+)-?.*\.sql$/", $filename, $matches);

		if(!isset($matches[1])) {
			throw new MigrationFileNameFormatException($filename);
		}

		return (int)$matches[1];
	}

	protected function executeSqlFile(string $file):string {
		$md5 = md5_file($file);
		foreach($this->splitSqlFile($file) as $sql) {
			$this->dbClient->executeSql($sql);
		}

		return $md5;
	}

	/** @return array<string> */
	protected function splitSqlFile(string $file):array {
		$sqlStatementSplitter = new SqlStatementSplitter();
		return $sqlStatementSplitter->split(file_get_contents($file));
	}

	protected function countSqlStatements(string $file):int {
		return count($this->splitSqlFile($file));
	}

	protected function tableHasColumn(string $columnName):bool {
		switch($this->driver) {
		case Settings::DRIVER_SQLITE:
			$result = $this->dbClient->executeSql(
				"PRAGMA table_info(`{$this->tableName}`)"
			);
			foreach($result->fetchAll() as $row) {
				if($row->getString("name") === $columnName) {
					return true;
				}
			}
			return false;

			default:
// @codeCoverageIgnoreStart
				$result = $this->dbClient->executeSql(
					"show columns from `{$this->tableName}` like ?",
					[$columnName]
				);
				return !empty($result->fetch());
// @codeCoverageIgnoreEnd
		}
	}

	protected function ensureColumnExists(
		string $columnName,
		string $definition
	):void {
		if($this->tableHasColumn($columnName)) {
			return;
		}

		$this->dbClient->executeSql(
			"alter table `{$this->tableName}` add `$columnName` $definition"
		);
	}

	protected function nowExpression():string {
		if($this->driver === Settings::DRIVER_SQLITE) {
			return "datetime('now')";
		}

		return "now()";
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
