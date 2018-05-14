<?php
namespace Gt\Database\Connection;

use PDO;

class DefaultSettings implements SettingsInterface {
	use ImmutableSettings;

	const CHARSET = "utf8";
	const COLLATION = "utf8_unicode_ci";

	const DEFAULT_NAME = "default";
	const DEFAULT_DATASOURCE = Settings::DRIVER_SQLITE;
	const DEFAULT_SCHEMA = Settings::SCHEMA_IN_MEMORY;
	const DEFAULT_HOST = "localhost";
	const DEFAULT_PORT = [
		Settings::DRIVER_MYSQL => 3306,
		Settings::DRIVER_POSTGRES => 5432,
		Settings::DRIVER_SQLSERVER => 1433,
		Settings::DRIVER_SQLITE => 0,
	];
	const DEFAULT_USERNAME = "admin";
	const DEFAULT_PASSWORD = "";

	const DEFAULT_CONFIG = [
		"options" => [
			PDO::ATTR_EMULATE_PREPARES => true,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_PERSISTENT => true,
		]
	];

	const DEFAULT_TABLE_PREFIX = "";

	public function getBaseDirectory():string {
		return sys_get_temp_dir();
	}

	public function getDataSource():string {
		return self::DEFAULT_DATASOURCE;
	}

	public function getSchema():string {
		return self::DEFAULT_SCHEMA;
	}

	public function getHost():string {
		return self::DEFAULT_HOST;
	}

	public function getPort():int {
		return self::DEFAULT_PORT[self::getDataSource()];
	}

	public function getUsername():string {
		return self::DEFAULT_USERNAME;
	}

	public function getPassword():string {
		return self::DEFAULT_PASSWORD;
	}

	public function getConnectionName():string {
		return self::DEFAULT_NAME;
	}

	public function getTablePrefix():string {
		return "";
	}

	public function getConnectionSettings():array {
		// NOTE: It's not possible to test the 'port' values returned by this method
		// because the DefaultSettings can only ever return the DEFAULT_DATASOURCE port
		return array_merge(
			DefaultSettings::DEFAULT_CONFIG,
			[
				"driver" => $this->getDataSource(),
				"host" => $this->getHost(),
				"port" => $this->getPort(),
				"database" => $this->getSchema(),
				"username" => $this->getUsername(),
				"password" => $this->getPassword(),
				"charset" => self::CHARSET,
				"collation" => self::COLLATION,
				"prefix" => $this->getTablePrefix(),
			]);
	}

	public function getConnectionString():string {
		return implode(":", [
			$this->getDataSource(),
			$this->getSchema(),
		]);
	}

	public function withBaseDirectory(string $baseDirectory):Settings {
		// TODO: Implement withBaseDirectory() method.
	}

	public function withDataSource(string $dataSource):Settings {
		// TODO: Implement withDataSource() method.
	}

	public function withSchema(string $schema):Settings {
		// TODO: Implement withSchema() method.
	}

	public function withHost(string $host):Settings {
		// TODO: Implement withHost() method.
	}

	public function withPort(int $port):Settings {
		// TODO: Implement withPort() method.
	}

	public function withUsername(string $username):Settings {
		// TODO: Implement withUsername() method.
	}

	public function withPassword(string $password):Settings {
		// TODO: Implement withPassword() method.
	}

	public function withTablePrefix(string $tablePrefix):Settings {
		// TODO: Implement withTablePrefix() method.
	}

	public function withConnectionName(string $connectionName):Settings {
		// TODO: Implement withConnectionName() method.
	}

	public function withoutSchema():Settings {
		// TODO: Implement withoutSchema() method.
	}
}
