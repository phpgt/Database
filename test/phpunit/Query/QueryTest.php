<?php
namespace Gt\Database\Test\Query;

use Gt\Database\Connection\Driver;
use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Settings;
use Gt\Database\DatabaseException;
use Gt\Database\Query\QueryNotFoundException;
use Gt\Database\Query\SqlQuery;
use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase {
	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathNotExistsProvider */
	public function testConstructionQueryPathNotExists(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		self::expectException(QueryNotFoundException::class);
		new SqlQuery($queryPath, new Driver(new DefaultSettings()));
	}

	/**
	 * @dataProvider \Gt\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testConstructionQueryPathExists(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		try {
			$query = new SqlQuery($queryPath, new Driver(new DefaultSettings()));
			static::assertFileExists($query->getFilePath());
		}
		catch(\Exception $e) {
		}
	}

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathExistsProvider */
	public function testExecDoesNotConnect(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	):void {
		$host = uniqid("host.");
		$port = 3306;

		$mysqlSettings = new Settings(
			$queryCollectionPath,
			"CodyDB",
			"DoesNotExist" . uniqid(),
			$host,
			$port,
			"dev",
			"dev_pass",
		);
		self::expectException(DatabaseException::class);
		self::expectExceptionMessage("Could not find driver for CodyDB - please ensure you have the package installed");
		new SqlQuery($queryPath, new Driver($mysqlSettings));
	}
}
