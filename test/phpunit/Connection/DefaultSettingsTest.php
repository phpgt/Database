<?php
namespace GT\Database\Test\Connection;

use GT\Database\Connection\DefaultSettings;
use GT\Database\Connection\Settings;
use GT\Database\Connection\SettingsInterface;
use PHPUnit\Framework\TestCase;

class DefaultSettingsTest extends TestCase {
	public function testImplementation() {
		$settings = new DefaultSettings();
		static::assertInstanceOf(SettingsInterface::class, $settings);
	}

	public function testDefaults() {
		$settings = new DefaultSettings();
		static::assertEquals(
			DefaultSettings::DEFAULT_DRIVER,
			$settings->getDriver()
		);
		static::assertEquals(
			DefaultSettings::DEFAULT_SCHEMA,
			$settings->getSchema()
		);
		static::assertEquals(
			DefaultSettings::DEFAULT_PORT[DefaultSettings::DEFAULT_DRIVER],
			$settings->getPort()
		);
		static::assertEquals(
			DefaultSettings::DEFAULT_HOST,
			$settings->getHost()
		);
		static::assertEquals(
			DefaultSettings::DEFAULT_USERNAME,
			$settings->getUsername()
		);
		static::assertEquals(
			DefaultSettings::DEFAULT_PASSWORD,
			$settings->getPassword()
		);
	}

	/** @dataProvider getDrivers */
	public function testDefaultPort(string $dsn, int $port) {
// NOTE: Have to use a Settings object here as it's not possible to use anything other
// than the default_driver otherwise
		$settings = new Settings(
			"/tmp",
			$dsn,
			"test-database"
		);

		static::assertEquals($port, $settings->getPort());
		static::assertEquals([
			"driver" => $dsn,
			"host" => DefaultSettings::DEFAULT_HOST,
			"port" => $port,
			"schema" => "test-database",
			"username" => DefaultSettings::DEFAULT_USERNAME,
			"password" => DefaultSettings::DEFAULT_PASSWORD,
			"charset" => DefaultSettings::CHARSET,
			"collation" => DefaultSettings::COLLATION,
			"options" => DefaultSettings::DEFAULT_CONFIG["options"],
		], $settings->getConnectionSettings());
	}

	/** @dataProvider getDrivers */
	public function testGetConnectionSettings(string $dsn, int $port) {
		$settings = new DefaultSettings();
		$connectionSettings = $settings->getConnectionSettings();

		self::assertArrayHasKey("driver", $connectionSettings);
		self::assertArrayHasKey("host", $connectionSettings);
		self::assertArrayHasKey("port", $connectionSettings);
		self::assertArrayHasKey("database", $connectionSettings);
		self::assertArrayHasKey("username", $connectionSettings);
		self::assertArrayHasKey("password", $connectionSettings);
		self::assertArrayHasKey("charset", $connectionSettings);
		self::assertArrayHasKey("collation", $connectionSettings);
		self::assertArrayHasKey("options", $connectionSettings);

		self::assertEquals(
			DefaultSettings::DEFAULT_CONFIG["options"],
			$connectionSettings["options"]
		);
	}

	public function testGetDefaultCharset() {
		$settings = new DefaultSettings();
		self::assertEquals(DefaultSettings::DEFAULT_CHARSET, $settings->getCharset());
		self::assertEquals(DefaultSettings::DEFAULT_COLLATION, $settings->getCollation());
	}

	public static function getDrivers():array {
		return [
			[Settings::DRIVER_MYSQL, 3306],
			[Settings::DRIVER_POSTGRES, 5432],
			[Settings::DRIVER_SQLSERVER, 1433],
			[Settings::DRIVER_SQLITE, 0],
		];
	}
}
