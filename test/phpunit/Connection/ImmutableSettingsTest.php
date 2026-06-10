<?php
namespace GT\Database\Test\Connection;

use DeepCopyTest\Filter\SetNullFilterTest;
use GT\Database\Connection\DefaultSettings;
use PHPUnit\Framework\TestCase;

class ImmutableSettingsTest extends TestCase {
	public function testWithBaseDirectory() {
		$settings = new DefaultSettings();
		$currentBaseDirectory = $settings->getBaseDirectory();
		$newBaseDirectory = "NEW_BASE_DIRECTORY";
		$newSettings = $settings->withBaseDirectory(
			$newBaseDirectory
		);

		self::assertEquals(
			$currentBaseDirectory,
			$settings->getBaseDirectory()
		);
		self::assertEquals(
			$newBaseDirectory,
			$newSettings->getBaseDirectory()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithDriver() {
		$settings = new DefaultSettings();
		$currentDriver = $settings->getDriver();
		$newDriver = "NEW_DRIVER";
		$newSettings = $settings->withDriver(
			$newDriver
		);

		self::assertEquals(
			$currentDriver,
			$settings->getDriver()
		);
		self::assertEquals(
			$newDriver,
			$newSettings->getDriver()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithSchema() {
		$settings = new DefaultSettings();
		$currentSchema = $settings->getSchema();
		$newSchema = "NEW_SCHEMA";
		$newSettings = $settings->withSchema(
			$newSchema
		);

		self::assertEquals(
			$currentSchema,
			$settings->getSchema()
		);
		self::assertEquals(
			$newSchema,
			$newSettings->getSchema()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithHost() {
		$settings = new DefaultSettings();
		$currentHost = $settings->getHost();
		$newHost = "NEW_HOST";
		$newSettings = $settings->withHost(
			$newHost
		);

		self::assertEquals(
			$currentHost,
			$settings->getHost()
		);
		self::assertEquals(
			$newHost,
			$newSettings->getHost()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithPort() {
		$settings = new DefaultSettings();
		$currentPort = $settings->getPort();
		$newPort = 1234;
		$newSettings = $settings->withPort(
			$newPort
		);

		self::assertEquals(
			$currentPort,
			$settings->getPort()
		);
		self::assertEquals(
			$newPort,
			$newSettings->getPort()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithUsername() {
		$settings = new DefaultSettings();
		$currentUsername = $settings->getUsername();
		$newUsername = "NEW_USERNAME";
		$newSettings = $settings->withUsername(
			$newUsername
		);

		self::assertEquals(
			$currentUsername,
			$settings->getUsername()
		);
		self::assertEquals(
			$newUsername,
			$newSettings->getUsername()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithPassword() {
		$settings = new DefaultSettings();
		$currentPassword = $settings->getPassword();
		$newPassword = "NEW_PASSWORD";
		$newSettings = $settings->withPassword(
			$newPassword
		);

		self::assertEquals(
			$currentPassword,
			$settings->getPassword()
		);
		self::assertEquals(
			$newPassword,
			$newSettings->getPassword()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithConnectionName() {
		$settings = new DefaultSettings();
		$currentConnectionName = $settings->getConnectionName();
		$newConnectionName = "NEW_CONNECTION_NAME";
		$newSettings = $settings->withConnectionName(
			$newConnectionName
		);

		self::assertEquals(
			$currentConnectionName,
			$settings->getConnectionName()
		);
		self::assertEquals(
			$newConnectionName,
			$newSettings->getConnectionName()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testWithoutSchema() {
		$settings = new DefaultSettings();
		$currentSchema = $settings->getSchema();
		$newSettings = $settings->withoutSchema();

		self::assertEquals(
			$currentSchema,
			$settings->getSchema()
		);
		self::assertEquals(
			"",
			$newSettings->getSchema()
		);
		self::assertNotSame($settings, $newSettings);
	}

	public function testImmutableSameWhenNoChanges() {
		$settings = new DefaultSettings();
		$newSettings = $settings->withBaseDirectory(sys_get_temp_dir());
		self::assertSame($settings, $newSettings);
		$newSettings = $settings->withDriver(DefaultSettings::DEFAULT_DRIVER);
		self::assertSame($settings, $newSettings);
		$newSettings = $settings->withSchema(DefaultSettings::DEFAULT_SCHEMA);
		self::assertSame($settings, $newSettings);
		$newSettings = $settings->withHost(DefaultSettings::DEFAULT_HOST);
		self::assertSame($settings, $newSettings);
		$newSettings = $settings->withPort(DefaultSettings::DEFAULT_PORT[
			$settings->getDriver()
		]);
		self::assertSame($settings, $newSettings);
		$newSettings = $settings->withUsername(DefaultSettings::DEFAULT_USERNAME);
		self::assertSame($settings, $newSettings);
		$newSettings = $settings->withPassword(DefaultSettings::DEFAULT_PASSWORD);
		self::assertSame($settings, $newSettings);
		$newConnectionName = $settings->withConnectionName(DefaultSettings::DEFAULT_NAME);
		self::assertSame($settings, $newSettings);
	}
}