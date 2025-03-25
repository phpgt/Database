<?php
namespace Gt\Database\Connection;

use Gt\Database\DatabaseException;
use PDO;
use PDOException;

class Driver {
	/** @noinspection PhpUnused */
	const AVAILABLE_DRIVERS = [
		"cubrid",
		"dblib", // Sybase databases
		"sybase",
		"firebird",
		"ibm",
		"informix",
		"mysql",
		"sqlsrv", // MS SQL Server and SQL Azure databases
		"oci", // Oracle
		"odbc",
		"pgsql", // PostgreSQL
		"sqlite",
		"4D",
	];

	protected SettingsInterface $settings;
	protected Connection $connection;

	public function __construct(SettingsInterface $settings) {
		$this->settings = $settings;
		$this->connect();
	}

	public function getBaseDirectory():string {
		return $this->settings->getBaseDirectory();
	}

	public function getConnectionName():string {
		return $this->settings->getConnectionName();
	}

	public function getConnection():Connection {
		return $this->connection;
	}

	protected function connect():void {
		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		];

		if($this->settings->getDriver() === Settings::DRIVER_MYSQL
		&& defined("PDO::MYSQL_ATTR_INIT_COMMAND")) {
			$options[PDO::MYSQL_ATTR_INIT_COMMAND]
				= "SET SESSION collation_connection='"
				. $this->settings->getCollation()
				. "'";
			$options[PDO::MYSQL_ATTR_LOCAL_INFILE] = true;
		}

		try {
			$this->connection = new Connection(
				$this->settings->getConnectionString(),
				$this->settings->getUsername(),
				$this->settings->getPassword(),
				$options
			);
		}
		catch(PDOException $exception) {
			$message = $exception->getMessage();
			$code = $exception->getCode();

			if(preg_match("/^SQL(.+)\[[^]]+\] \[\d+\] (?P<MSG_PART>.+)/", $message, $matches)) {
				$message = $matches["MSG_PART"];
			}

			if($code === 2002) {
				$message = "Could not connect to database - is the "
					. $this->settings->getDriver()
					. " server running at "
					. $this->settings->getHost()
					. " on port "
					. $this->settings->getPort() . "?";
			}
			elseif($code === 0) {
				if($message = "could not find driver") {
					$message = "Could not find driver for "
						. $this->settings->getDriver()
						. " - please ensure you have the package installed";
				}
			}

			throw new DatabaseException($message, $code, $exception);
		}

		if($initQuery = $this->settings->getInitQuery()) {
			foreach(explode(";", $initQuery) as $q) {
				$this->connection->exec($q);
			}
		}
	}
}
