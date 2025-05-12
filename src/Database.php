<?php
namespace Gt\Database;

use Gt\Database\Connection\Connection;
use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Driver;
use Gt\Database\Connection\SettingsInterface;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Query\QueryCollectionFactory;
use Gt\Database\Result\ResultSet;
use PDOException;

/**
 * The Database Client stores the factory for creating QueryCollections, and an
 * associative array of connection settings, allowing for multiple database
 * connections. If only one database connection is required, a name is not
 * required as the default name will be used.
 */
class Database {
	use Fetchable;

	const COLLECTION_SEPARATOR_CHARACTERS = [".", "/", "\\"];
	/** @var array<QueryCollectionFactory> */
	protected array $queryCollectionFactoryArray;
	/** @var array<Driver> */
	protected array $driverArray;
	protected Connection $currentConnectionName;
	protected string $appNamespace = "\\App";

	public function __construct(SettingsInterface...$connectionSettings) {
		if(empty($connectionSettings)) {
			$connectionSettings[DefaultSettings::DEFAULT_NAME]
				= new DefaultSettings();
		}

		$this->storeConnectionDriverFromSettings($connectionSettings);
		$this->storeQueryCollectionFactoryFromSettings($connectionSettings);
	}

	public function setAppNameSpace(string $namespace):void {
		if(!str_starts_with($namespace, "\\")) {
			$namespace = "\\$namespace";
		}

		$this->appNamespace = $namespace;
	}

	public function insert(string $queryName, mixed...$bindings):string {
		$result = $this->query($queryName, $bindings);
		return $result->lastInsertId();
	}

	public function delete(string $queryName, mixed...$bindings):int {
		$result = $this->query($queryName, $bindings);
		return $result->affectedRows();
	}

	public function update(string $queryName, mixed...$bindings):int {
		$result = $this->query($queryName, $bindings);
		return $result->affectedRows();
	}

	public function query(string $fullQueryPath, mixed...$bindings):ResultSet {
		$queryCollectionName = $queryFile = "";

		foreach(self::COLLECTION_SEPARATOR_CHARACTERS as $char) {
			if(!strstr($fullQueryPath, $char)) {
				continue;
			}

			$queryCollectionName = substr(
				$fullQueryPath,
				0,
				strrpos($fullQueryPath, $char)
			);
			$queryFile = substr(
				$fullQueryPath,
				strrpos($fullQueryPath, $char) + 1
			);

			break;
		}

		$connectionName = $this->currentConnectionName ?? DefaultSettings::DEFAULT_NAME;
		$queryCollection = $this->queryCollection(
			$queryCollectionName,
			$connectionName
		);

		return $queryCollection->query($queryFile, $bindings);
	}

	public function setCurrentConnectionName(string $connectionName):void {
		$this->currentConnectionName = $this->getNamedConnection(
			$connectionName
		);
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	public function executeSql(
		string $query,
		array $bindings = [],
		string $connectionName = DefaultSettings::DEFAULT_NAME
	):ResultSet {
		$connection = $this->getNamedConnection($connectionName);
		try {
			$statement = $connection->prepare($query);
		}
		catch(PDOException $exception) {
			throw new StatementPreparationException(
				$exception->getMessage(),
				intval($exception->getCode())
			);
		}

		try {
			$statement->execute($bindings);
		}
		catch(PDOException $exception) {
			throw new StatementExecutionException(
				$exception->getMessage(),
				intval($exception->getCode())
			);
		}

		return new ResultSet($statement, $connection->lastInsertId());
	}

	protected function getNamedConnection(string $connectionName):Connection {
		$driver = $this->driverArray[$connectionName];

		return $driver->getConnection();
	}

	/** @param array<SettingsInterface> $settingsArray */
	protected function storeConnectionDriverFromSettings(array $settingsArray):void {
		foreach($settingsArray as $settings) {
			$connectionName = $settings->getConnectionName();
			$this->driverArray[$connectionName] = new Driver($settings);
		}
	}

	/** @param array<SettingsInterface> $settingsArray */
	protected function storeQueryCollectionFactoryFromSettings(array $settingsArray):void {
		foreach($settingsArray as $settings) {
			$connectionName = $settings->getConnectionName();
			$this->queryCollectionFactoryArray[$connectionName] =
				new QueryCollectionFactory($this->driverArray[$connectionName]);
		}
	}

	public function queryCollection(
		string $queryCollectionName,
		string $connectionName = DefaultSettings::DEFAULT_NAME
	):QueryCollection {
		return $this->queryCollectionFactoryArray[$connectionName]->create($queryCollectionName);
	}

	public function getDriver(
		string $connectionName = DefaultSettings::DEFAULT_NAME
	):Driver {
		return $this->driverArray[$connectionName];
	}

	protected function getFirstConnectionName():string {
		reset($this->driverArray);

		return key($this->driverArray);
	}
}
