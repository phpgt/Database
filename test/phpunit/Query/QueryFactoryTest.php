<?php
namespace Gt\Database\Test\Query;

use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Driver;
use Gt\Database\Query\PhpQuery;
use Gt\Database\Query\Query;
use Gt\Database\Query\QueryFactory;
use Gt\Database\Query\QueryFileExtensionException;
use Gt\Database\Query\QueryNotFoundException;
use Gt\Database\Query\QueryOverrideConflictException;
use Gt\Database\Query\SqlQuery;
use Gt\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;

class QueryFactoryTest extends TestCase {
	/**
	 * @dataProvider \Gt\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testFindQueryFilePathExists(
		string $queryName,
		string $directoryOfQueries
	) {
		$queryFactory = new QueryFactory(
			$directoryOfQueries,
			new Driver(new DefaultSettings())
		);
		$queryFilePath = $queryFactory->findQueryFilePath($queryName);
		static::assertFileExists($queryFilePath);
	}

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathNotExistsProvider */
	public function testFindQueryFilePathNotExists(
		string $queryName,
		string $directoryOfQueries
	) {
		$queryFactory = new QueryFactory(
			$directoryOfQueries,
			new Driver(new DefaultSettings())
		);

		self::expectException(QueryNotFoundException::class);
		$queryFactory->findQueryFilePath($queryName);
	}

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathExtensionNotValidProvider */
	public function testFindQueryFilePathWithInvalidExtension(
		string $queryName,
		string $directoryOfQueries
	) {
		$queryFactory = new QueryFactory(
			$directoryOfQueries,
			new Driver(new DefaultSettings())
		);

		self::expectException(QueryFileExtensionException::class);
		$queryFactory->findQueryFilePath($queryName);
	}

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathExistsProvider */
	public function testQueryCreated(
		string $queryName,
		string $directoryOfQueries
	) {
		$queryFactory = new QueryFactory(
			$directoryOfQueries,
			new Driver(new DefaultSettings())
		);
		$query = $queryFactory->create($queryName);
		static::assertInstanceOf(Query::class, $query);
	}

	public function testSelectsCorrectFile() {
		$queryCollectionData = Helper::queryCollectionPathExistsProvider();
		$queryCollectionPath = $queryCollectionData[0][1];

		$queryFactory = new QueryFactory(
			$queryCollectionPath,
			new Driver(new DefaultSettings())
		);

		$queryNames = [
			uniqid("q1-"),
			uniqid("q2-"),
			uniqid("q3-"),
			uniqid("q4-")
		];
		$queryFileList = [];
		foreach($queryNames as $queryName) {
			$queryPath = $queryCollectionPath . "/$queryName.sql";
			touch($queryPath);

			$query = $queryFactory->create($queryName);
			static::assertNotContains($query->getFilePath(), $queryFileList);
			$queryFileList[$queryName] = $query->getFilePath();
		}
	}

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathNotExistsProvider */
	public function testCreatePhp(
		string $queryName,
		string $directoryOfQueries,
	) {
		$classPath = "$directoryOfQueries.php";
		if(!is_dir($directoryOfQueries)) {
			mkdir($directoryOfQueries, recursive: true);
		}
		touch($classPath);

		$sut = new QueryFactory($classPath, new Driver(new DefaultSettings()));
		$query = $sut->create("getTimestamp");
		self::assertInstanceOf(PhpQuery::class, $query);
	}

	public function testCreatePhpPrefersOverrideDirectoryQuery():void {
		$basePath = Helper::getTmpDir();
		$classPath = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"User.php",
		]);
		$overrideDirectory = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"User",
		]);
		mkdir($overrideDirectory, 0775, true);
		touch($classPath);
		file_put_contents(
			"$overrideDirectory/getById.sql",
			"select :id as id"
		);

		try {
			$sut = new QueryFactory($classPath, new Driver(new DefaultSettings()));
			$query = $sut->create("getById");

			self::assertInstanceOf(SqlQuery::class, $query);
			self::assertSame(
				realpath("$overrideDirectory/getById.sql"),
				$query->getFilePath()
			);
		}
		finally {
			Helper::deleteDir($basePath);
		}
	}

	public function testCreatePhpPrefersOverrideDirectoryCaseInsensitively():void {
		$basePath = Helper::getTmpDir();
		$classPath = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"User.php",
		]);
		$overrideDirectory = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"user",
		]);
		mkdir($overrideDirectory, 0775, true);
		touch($classPath);
		file_put_contents(
			"$overrideDirectory/getById.sql",
			"select :id as id"
		);

		try {
			$sut = new QueryFactory($classPath, new Driver(new DefaultSettings()));
			$query = $sut->create("getById");

			self::assertInstanceOf(SqlQuery::class, $query);
			self::assertSame(
				realpath("$overrideDirectory/getById.sql"),
				$query->getFilePath()
			);
		}
		finally {
			Helper::deleteDir($basePath);
		}
	}

	public function testCreatePhpPrefersOverrideDirectoryWhenClassIsLowerCase():void {
		$basePath = Helper::getTmpDir();
		$classPath = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"user.php",
		]);
		$overrideDirectory = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"User",
		]);
		mkdir($overrideDirectory, 0775, true);
		touch($classPath);
		file_put_contents(
			"$overrideDirectory/getById.sql",
			"select :id as id"
		);

		try {
			$sut = new QueryFactory($classPath, new Driver(new DefaultSettings()));
			$query = $sut->create("getById");

			self::assertInstanceOf(SqlQuery::class, $query);
			self::assertSame(
				realpath("$overrideDirectory/getById.sql"),
				$query->getFilePath()
			);
		}
		finally {
			Helper::deleteDir($basePath);
		}
	}

	public function testCreatePhpThrowsWhenOverrideConflictsWithPublicMethod():void {
		$basePath = Helper::getTmpDir();
		$classPath = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"User.php",
		]);
		$overrideDirectory = implode(DIRECTORY_SEPARATOR, [
			$basePath,
			"query",
			"user",
		]);
		mkdir($overrideDirectory, 0775, true);
		file_put_contents(
			$classPath,
			<<<PHP
			<?php
			namespace App\Query;

			class FactoryConflictUser {
				public function getById():string {
					return "select 1";
				}
			}
			PHP
		);
		file_put_contents(
			"$overrideDirectory/getById.sql",
			"select :id as id"
		);

		try {
			$sut = new QueryFactory($classPath, new Driver(new DefaultSettings()));

			self::expectException(QueryOverrideConflictException::class);
			$sut->create("getById");
		}
		finally {
			Helper::deleteDir($basePath);
		}
	}
}
