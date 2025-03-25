<?php
namespace Gt\Database\Connection;

class Settings implements SettingsInterface {
	use ImmutableSettings;

	const CHARSET = "utf8mb4";
	const COLLATION = "utf8mb4_general_ci";

	const DRIVER_MYSQL = "mysql";
	const DRIVER_POSTGRES = "pgsql";
	const DRIVER_SQLITE = "sqlite";
	const DRIVER_SQLSERVER = "dblib";

	const SCHEMA_IN_MEMORY = ":memory:";

	protected string $baseDirectory;
	protected string $driver;
	protected ?string $schema;
	protected string $host;
	protected int $port;
	protected string $username;
	protected string $password;
	protected string $connectionName;
	/** @var array<string, string> */
	protected array $config = [];
	protected string $collation;
	protected ?string $charset;
	protected ?string $initQuery;

	public function __construct(
		string $baseDirectory,
		string $driver,
		?string $schema = null,
		string $host = DefaultSettings::DEFAULT_HOST,
		?int $port = null,
		string $username = DefaultSettings::DEFAULT_USERNAME,
		string $password = DefaultSettings::DEFAULT_PASSWORD,
		string $connectionName = DefaultSettings::DEFAULT_NAME,
		string $collation = DefaultSettings::DEFAULT_COLLATION,
		?string $charset = null,
		?string $initQuery = null,
	) {
		$this->baseDirectory = $baseDirectory;
		$this->driver = $driver;
		$this->schema = $schema;
		$this->host = $host;
		$this->port = $port ?? DefaultSettings::DEFAULT_PORT[$driver];
		$this->username = $username;
		$this->password = $password;
		$this->connectionName = $connectionName;
		$this->collation = $collation;
		$this->charset = $charset;
		$this->initQuery = $initQuery;
	}

	/** @param array<string, string> $config */
	public function setConfig(array $config):void {
		$this->config = $config;
	}

	public function getBaseDirectory():string {
		return $this->baseDirectory;
	}

	public function getDriver():string {
		return $this->driver;
	}

	public function getSchema():string {
		return $this->schema;
	}

	public function getHost():string {
		return $this->host;
	}

	public function getPort():int {
		return $this->port;
	}

	public function getUsername():string {
		return $this->username;
	}

	public function getPassword():string {
		return $this->password;
	}

	public function getConnectionName():string {
		return $this->connectionName;
	}

	/** @return array<string, string> */
	public function getConnectionSettings():array {
		$currentSettings = [
			"driver" => $this->getDriver(),
			"host" => $this->getHost(),
			"schema" => $this->getSchema(),
			"port" => $this->getPort(),
			"username" => $this->getUsername(),
			"password" => $this->getPassword(),
			"charset" => self::CHARSET,
			"collation" => self::COLLATION,
		];

		return array_merge(
			DefaultSettings::DEFAULT_CONFIG,
			$currentSettings,
			$this->config
		);
	}

	public function getConnectionString():string {
		$driver = $this->getDriver();
		$connectionString = "$driver:";

		switch($driver) {
		case self::DRIVER_SQLITE:
			$connectionString .= $this->getSchema();
			break;

		default:
			$connectionString .= "host=" . $this->getHost();
			$connectionString .= ";port=" . $this->getPort();
			$connectionString .= ";dbname=" . $this->getSchema();
			$connectionString .= ";charset=" . self::CHARSET;
			break;
		}

		return $connectionString;
	}

	public function getCharset():string {
		if(!empty($this->charset)) {
			return $this->charset;
		}

		return $this->getCharsetFromCollation();
	}

	public function getCollation():string {
		return $this->collation;
	}

	public function getInitQuery():?string {
		return $this->initQuery;
	}

	protected function getCharsetFromCollation():string {
		return substr(
			$this->collation,
			0,
			strpos($this->collation, "_")
		);
	}
}
