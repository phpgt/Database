<?php
namespace GT\Database\Test\Query;

use GT\Database\Connection\Settings;
use GT\Database\Connection\Driver;
use GT\Database\Query\QueryCollection;
use GT\Database\Query\QueryCollectionClass;
use GT\Database\Query\QueryCollectionDirectory;
use GT\Database\Query\QueryCollectionFactory;
use GT\Database\Query\QueryCollectionNotFoundException;
use GT\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;

class QueryCollectionFactoryTest extends TestCase {
	public function testCurrentWorkingDirectoryDefault() {
		$queryCollectionName = "exampleTest";
		$baseDir = Helper::getTmpDir();
		$queryCollectionDirectoryPath = implode(DIRECTORY_SEPARATOR, [
			$baseDir,
			$queryCollectionName,
		]);

		mkdir($queryCollectionDirectoryPath, 0775, true);
		chdir($baseDir);

		$driver = new Driver(new Settings(
				$baseDir,
				Settings::DRIVER_SQLITE,
				Settings::SCHEMA_IN_MEMORY)
		);

		$queryCollectionFactory = new QueryCollectionFactory($driver);
		$queryCollection = $queryCollectionFactory->create(
			$queryCollectionName
		);

		static::assertEquals(
			$queryCollectionDirectoryPath,
			$queryCollection->getDirectoryPath()
		);
	}

	public function testMissingCollectionThrowsOnlyWhenQueryIsCalled():void {
		$queryCollectionName = "exampleTest";
		$baseDir = Helper::getTmpDir();
		mkdir($baseDir, 0775, true);

		$driver = new Driver(new Settings(
			$baseDir,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		));
		$queryCollectionFactory = new QueryCollectionFactory($driver);

		try {
			$queryCollection = $queryCollectionFactory->create($queryCollectionName);
			self::assertInstanceOf(QueryCollection::class, $queryCollection);

			self::expectException(QueryCollectionNotFoundException::class);
			self::expectExceptionMessage($queryCollectionName);
			$queryCollection->query("selectExample");
		}
		finally {
			Helper::deleteDir($baseDir);
		}
	}

	public function testClassOverridesDirectoryCaseInsensitively():void {
		$baseDir = Helper::getTmpDir();
		$queryBase = "$baseDir/query";
		mkdir("$queryBase/user", 0775, true);
		touch("$queryBase/User.php");

		$driver = new Driver(new Settings(
			$queryBase,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		));
		$queryCollectionFactory = new QueryCollectionFactory($driver);

		try {
			$queryCollection = $queryCollectionFactory->create("user");
			self::assertInstanceOf(QueryCollectionClass::class, $queryCollection);

			$queryCollection = $queryCollectionFactory->create("User");
			self::assertInstanceOf(QueryCollectionClass::class, $queryCollection);
		}
		finally {
			Helper::deleteDir($baseDir);
		}
	}

	public function testDirectoryUsedWhenNoClassExistsCaseInsensitively():void {
		$baseDir = Helper::getTmpDir();
		$queryBase = "$baseDir/query";
		mkdir("$queryBase/user", 0775, true);

		$driver = new Driver(new Settings(
			$queryBase,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		));
		$queryCollectionFactory = new QueryCollectionFactory($driver);

		try {
			$queryCollection = $queryCollectionFactory->create("User");
			self::assertInstanceOf(QueryCollectionDirectory::class, $queryCollection);
		}
		finally {
			Helper::deleteDir($baseDir);
		}
	}
}
