<?php
namespace Gt\Database\Test\Query;

use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Driver;
use Gt\Database\Query\Query;
use Gt\Database\Query\QueryFactory;
use Gt\Database\Query\QueryFileExtensionException;
use Gt\Database\Query\QueryNotFoundException;
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
}
