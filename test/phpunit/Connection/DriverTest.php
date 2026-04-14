<?php
namespace GT\Database\Test\Connection;

use GT\Database\Connection\Connection;
use GT\Database\Connection\DefaultSettings;
use GT\Database\Connection\Driver;
use GT\Database\Connection\Settings;
use PHPUnit\Framework\TestCase;

class DriverTest extends TestCase {
	protected $baseDirectory = "test-base-directory";
	protected $connectionName = "test-connection-name";
	protected $connectionString = "sqlite::memory:";
	protected $username = "test-username";
	protected $password = "test-password";
	protected $initQuery = null;

	public function testGetBaseDirectory() {
		$settings = $this->getSettingsMock();
		$driver = new Driver($settings);
		self::assertEquals(
			$this->baseDirectory,
			$driver->getBaseDirectory()
		);
		self::assertEquals(
			$this->connectionName,
			$driver->getConnectionName()
		);
		self::assertInstanceOf(
			Connection::class,
			$driver->getConnection()
		);
	}

	public function testConstruct_initQueryDefaultForeignKeyCheck() {
		$settings = $this->getSettingsMock();
		$driver = new Driver($settings);
		$connection = $driver->getConnection();
		$defaultForeignKeysValue = $connection->query("PRAGMA foreign_keys")->fetch()["foreign_keys"];

		if($defaultForeignKeysValue) {
			$newValue = "OFF";
		}
		else {
			$newValue = "ON";
		}
		$settings = $this->getSettingsMock(initQuery: "PRAGMA foreign_keys = $newValue");
		$driver = new Driver($settings);
		$connection = $driver->getConnection();
		$actualNewValue = $connection->query("PRAGMA foreign_keys")->fetch()["foreign_keys"];
		if($defaultForeignKeysValue) {
			self::assertSame(0, $actualNewValue);
		}
		else {
			self::assertSame(1, $actualNewValue);
		}
	}

	public function testConstruct_initQueryMultipleInitQueries() {
		$settings = $this->getSettingsMock();
		$driver = new Driver($settings);
		$connection = $driver->getConnection();
		$defaultForeignKeysValue = $connection->query("PRAGMA foreign_keys")->fetch()["foreign_keys"];
		$defaultFullSyncValue = $connection->query("PRAGMA fullfsync")->fetch()["fullfsync"];

		if($defaultForeignKeysValue) {
			$newForeignKeysValue = "OFF";
		}
		else {
			$newForeignKeysValue = "ON";
		}
		if($defaultFullSyncValue) {
			$newFullSyncValue = "OFF";
		}
		else {
			$newFullSyncValue = "ON";
		}

		$initQuery = implode(";", [
			"PRAGMA foreign_keys = $newForeignKeysValue",
			"PRAGMA fullfsync = $newFullSyncValue",
		]);
		$settings = $this->getSettingsMock(initQuery: $initQuery);
		$driver = new Driver($settings);
		$connection = $driver->getConnection();
		$actualNewForeignKeysValue = $connection->query("PRAGMA foreign_keys")->fetch()["foreign_keys"];
		$actualNewFullSyncValue = $connection->query("PRAGMA fullfsync")->fetch()["fullfsync"];

		if($defaultForeignKeysValue) {
			self::assertSame(0, $actualNewForeignKeysValue);
		}
		else {
			self::assertSame(1, $actualNewForeignKeysValue);
		}
		if($defaultFullSyncValue) {
			self::assertSame(0, $actualNewFullSyncValue);
		}
		else {
			self::assertSame(1, $actualNewFullSyncValue);
		}
	}

	protected function getSettingsMock(
		string $baseDirectory = null,
		string $connectionName = null,
		string $connectionString = null,
		string $username = null,
		string $password = null,
		string $initQuery = null,
	):Settings {
		$settings = $this->createMock(Settings::class);
		$settings->method("getBaseDirectory")
			->willReturn($baseDirectory ?? $this->baseDirectory);
		$settings->method("getConnectionName")
			->willReturn($connectionName ?? $this->connectionName);
		$settings->method("getConnectionString")
			->willReturn($connectionString ?? $this->connectionString);
		$settings->method("getUsername")
			->willReturn($username ?? $this->username);
		$settings->method("getPassword")
			->willReturn($password ?? $this->password);
		$settings->method("getInitQuery")
			->willReturn($initQuery ?? $this->initQuery);

		return $settings;
	}
}
