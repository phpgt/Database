<?php
namespace GT\Database\Test\Query;

use GT\Database\Connection\Driver;
use GT\Database\Connection\Settings;
use GT\Database\MissingParameterException;
use GT\Database\Query\PreparedStatementException;
use GT\Database\Query\QueryNotFoundException;
use GT\Database\Query\SqlQuery;
use PHPUnit\Framework\TestCase;
use GT\Database\Test\Helper\Helper;

class SqlQueryTest extends TestCase {
	/** @var Driver */
	private $driver;

	protected function setUp():void {
		$driver = $this->driverSingleton();
		$connection = $driver->getConnection();
		$output = $connection->exec("CREATE TABLE test_table ( id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(32), timestamp DATETIME DEFAULT current_timestamp); CREATE UNIQUE INDEX test_table_name_uindex ON test_table (name);");
		static::assertNotFalse($output);

		$insertStatement = $connection->prepare(
			"INSERT INTO test_table (name) VALUES
			('one'),
			('two'),
			('three')"
		);
		$success = $insertStatement->execute();
		static::assertTrue($success, "Success inserting fake data");
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider */
	public function testQueryNotFound(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		self::expectException(QueryNotFoundException::class);
		new SqlQuery($queryPath, $this->driverSingleton());
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testQueryFound(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		static::assertFileExists($query->getFilePath());
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider */
	public function testBadPreparedStatementThrowsException(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		file_put_contents($queryPath, "insert blahblah into nothing");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		self::expectException(PreparedStatementException::class);
		$query->execute();
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testPreparedStatement(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		file_put_contents($queryPath, "SELECT * FROM test_table");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$resultSet = $query->execute();

		foreach(["one", "two", "three"] as $i => $name) {
			$row = $resultSet->fetch();
			static::assertEquals($i + 1, $row->id);
			static::assertEquals($name, $row->name);
		}
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testLastInsertId(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		$uuid = uniqid("test-");
		file_put_contents($queryPath, "insert into test_table (name) values ('$uuid')");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$resultSet = $query->execute();
		$id = $resultSet->lastInsertId();
		static::assertNotEmpty($id);

		file_put_contents($queryPath, "select * from test_table where id = $id");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$resultSet = $query->execute();

		$row = $resultSet->fetch();
		static::assertEquals($uuid, $row->name);
	}

	public function testSubsequentCounts() {
		$testData = Helper::queryPathExistsProvider();
		$queryPath = $testData[0][2];
		file_put_contents($queryPath, "SELECT * FROM test_table");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$resultSet = $query->execute();
		$count = count($resultSet);
		static::assertGreaterThan(0, $count);
		static::assertCount($count, $resultSet);
	}

	public function testSubsequentCalls() {
		$testData = [
			Helper::queryPathExistsProvider(),
			Helper::queryPathExistsProvider(),
		];
		$queryPath = [
			$testData[0][0][2],
			$testData[1][0][2],
		];

		$lastTestWord = "";

		foreach(["Hello", "Goodbye"] as $i => $testWord) {
			static::assertNotEquals($testWord, $lastTestWord);
			file_put_contents($queryPath[$i], "select '$testWord' as test");
			$query = new SqlQuery($queryPath[$i], $this->driverSingleton());
			$resultSet = $query->execute();
			$row = $resultSet->fetch();
			static::assertEquals($testWord, $row->test);
			$lastTestWord = $testWord;
		}
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testPlaceholderReplacement(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		$uuid = uniqid("test-");
		file_put_contents($queryPath, "select :testPlaceholder as `testValue`");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$resultSet = $query->execute([
			"testPlaceholder" => $uuid,
		]);

		$row = $resultSet->fetch();
		static::assertEquals($uuid, $row->testValue);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider
	 */
	public function testPlaceholderReplacementInComments(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		$uuid = uniqid("test-");
// The question mark could cause problems with preparing queries.
		file_put_contents($queryPath, "select :test as `test` -- does this test work?");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$resultSet = $query->execute([
			"test" => $uuid,
		]);

		$row = $resultSet->fetch();
		static::assertEquals($uuid, $row->test);
	}

	public function testPlaceholderReplacementSubsequentCalls() {
		$pathDataList = Helper::queryPathExistsProvider();

		$testQueryList = [
			"select :testPlaceholder as `testPlaceholder`",
			"select :firstPlaceholder as `firstPlaceholder`, :secondPlaceholder as `secondPlaceholder`",
		];
		$placeholderList = [
			[
				"testPlaceholder" => uniqid()
			],
			[
				"firstPlaceholder" => uniqid("first"),
				"secondPlaceholder" => uniqid("second"),
			]
		];

		foreach([$pathDataList[0], $pathDataList[1]] as $i => $pathData) {
			$queryName = $pathData[0];
			$queryCollectionPath = $pathData[1];
			$queryPath = $pathData[2];

			file_put_contents($queryPath, $testQueryList[$i]);
			$query = new SqlQuery($queryPath, $this->driverSingleton());
			$resultSet = $query->execute($placeholderList[$i]);
			$row = $resultSet->fetch();

			static::assertCount(
				count($placeholderList[$i]),
				$row,
				"Iteration $i"
			);

			foreach($placeholderList[$i] as $key => $value) {
				static::assertEquals($value, $row->$key, "Iteration $i");
			}
		}
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider */
	public function testMissingIndexedParametersThrowsHelpfulException(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	):void {
		file_put_contents(
			$queryPath,
			"select * from test_table where id = ? and name = ? and timestamp = ?"
		);
		$query = new SqlQuery($queryPath, $this->driverSingleton());

		$this->expectException(MissingParameterException::class);
		$this->expectExceptionMessage(
			"Too few parameters were bound - expected 3, received 2"
		);
		$query->execute([1, "one"]);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider */
	public function testMissingNamedParametersThrowsHelpfulException(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	):void {
		file_put_contents(
			$queryPath,
			"select * from test_table where name = :name and timestamp > :date"
		);
		$query = new SqlQuery($queryPath, $this->driverSingleton());

		$this->expectException(MissingParameterException::class);
		$this->expectExceptionMessage(
			"Too few parameters were bound - missing `date`"
		);
		$query->execute([
			"name" => "one",
		]);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider */
	public function testMissingNamedParametersIgnoreCommentsAndStrings(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	):void {
		file_put_contents(
			$queryPath,
			"select ':ignored' as literal from test_table -- :comment\n"
			. "where name = :name and timestamp > :date"
		);
		$query = new SqlQuery($queryPath, $this->driverSingleton());

		$this->expectException(MissingParameterException::class);
		$this->expectExceptionMessage(
			"Too few parameters were bound - missing `date`"
		);
		$query->execute([
			"name" => "one",
		]);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testSpecialBindingsNoAscDesc(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "select * from something order by :orderBy limit :limit offset :offset";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$injectedSql = $query->injectSpecialBindings($sql, [
			"orderBy" => "sortColumn",
			"limit" => 100,
			"offset" => 25,
		]);

		self::assertStringNotContainsString(":orderBy", $injectedSql);
		self::assertStringNotContainsString(":limit", $injectedSql);
		self::assertStringNotContainsString(":offset", $injectedSql);

		self::assertStringContainsString("order by `sortColumn`", $injectedSql);
		self::assertStringContainsString("limit 100", $injectedSql);
		self::assertStringContainsString("offset 25", $injectedSql);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testSpecialBindingsAscDesc(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "select * from something order by :orderBy limit :limit offset :offset";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$injectedSql = $query->injectSpecialBindings($sql, [
			"orderBy" => "sortColumn desc",
			"limit" => 100,
			"offset" => 25,
		]);

		self::assertStringContainsString("order by `sortColumn` desc", $injectedSql);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testSpecialBindingsInClause(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "select * from something where `status` in (:statusList)";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$bindings = [
			"statusList" => [
				"good",
				"very-good",
				"excellent"
			],
		];
		$injectedSql = $query->injectSpecialBindings(
			$sql,
			$bindings
		);

		for($i = 0; $i < count($bindings["statusList"]); $i++) {
			self::assertStringContainsString(
				"statusList__$i",
				$injectedSql
			);
		}

		$i++;

		self::assertStringNotContainsString(
			"statusList__$i",
			$injectedSql
		);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testDynamicBindingsInsertMultiple(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "insert into test_table (`id`, `name`) values ( :__dynamicValueSet )";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$data = [
			"__dynamicValueSet" => [
				["id" => 100, "name" => "first inserted"],
				["id" => 101, "name" => "second inserted"],
				["id" => 102, "name" => "third inserted"],
			],
		];
		$originalData = $data;
		$injectedSql = $query->injectDynamicBindings($sql, $data);

		self::assertStringNotContainsString("dynamicFieldset", $injectedSql);

		self::assertStringContainsString(":id_00000", $injectedSql);
		self::assertStringContainsString(":id_00001", $injectedSql);
		self::assertStringContainsString(":id_00002", $injectedSql);
		self::assertStringContainsString(":name_00000", $injectedSql);
		self::assertStringContainsString(":name_00001", $injectedSql);
		self::assertStringContainsString(":name_00002", $injectedSql);

		foreach($originalData["__dynamicValueSet"] as $i => $kvp) {
			foreach($kvp as $key => $value) {
				$indexedKey = $key . "_" . str_pad($i, 5, "0", STR_PAD_LEFT);
				self::assertSame($data[$indexedKey], $value);
			}
		}

		self::assertArrayNotHasKey("__dynamicValueSet", $data);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testDynamicBindingsWhereIn(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "select `id`, `name` from `test_table` where `createdAt` > :startDate and `id` in ( :__dynamicIn ) limit 10";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$data = [
			"startDate" => "2020-01-01",
			"__dynamicIn" => [1, 2, 3, 4, 5, 50, 60, 70, 80, 90],
		];
		$originalData = $data;
		$injectedSql = $query->injectDynamicBindings($sql, $data);

		self::assertStringNotContainsString("dynamicIn", $injectedSql);

		self::assertStringContainsString("where `createdAt` > :startDate and `id` in ( 1, 2, 3, 4, 5, 50, 60, 70, 80, 90 ) limit 10", $injectedSql);
		self::assertArrayNotHasKey("__dynamicIn", $data);
		self::assertSame("2020-01-01", $data["startDate"]);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testDynamicBindingsWhereInStrings(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "select `id`, `name` from `test_table` where `createdAt` > :startDate and `name` in ( :__dynamicIn ) limit 10";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$data = [
			"startDate" => "2020-01-01",
			"__dynamicIn" => ["one", "two", "three's the last"],
		];
		$injectedSql = $query->injectDynamicBindings($sql, $data);

		self::assertStringNotContainsString("dynamicIn", $injectedSql);

		self::assertStringContainsString("where `createdAt` > :startDate and `name` in ( 'one', 'two', 'three''s the last' ) limit 10", $injectedSql);
		self::assertArrayNotHasKey("__dynamicIn", $data);
		self::assertSame("2020-01-01", $data["startDate"]);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testDynamicBindingsOr(
		string $queryName,
		string $queryCollectionPath,
		string $filePath,
	) {
		$sql = "select `id`, `customerId`, `productId` from `Purchases` where :__dynamicOr limit 10";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$data = [
			"__dynamicOr" => [
				["customerId" => "cust_105", "productId" => 001],
				["customerId" => "cust_450", "productId" => 941],
				["customerId" => "cust_450", "productId" => 433],
			]
		];
		$injectedSql = $query->injectDynamicBindings($sql, $data);

		self::assertStringNotContainsString("dynamicOr", $injectedSql);

		$injectedSql = str_replace(["\t", "\n"], " ", $injectedSql);
		$injectedSql = str_replace("  ", " ", $injectedSql);
		self::assertStringContainsString("where ( (`customerId` = 'cust_105' and `productId` = 1) or (`customerId` = 'cust_450' and `productId` = 941) or (`customerId` = 'cust_450' and `productId` = 433) ) limit 10", $injectedSql);
	}

	/**
	 * @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider()
	 */
	public function testPrepareBindingsWithArray(
		string $queryName,
		string $queryCollectionPath,
		string $filePath
	) {
		$sql = "select * from something where `status` in (:statusList)";
		file_put_contents($filePath, $sql);
		$query = new SqlQuery($filePath, $this->driverSingleton());
		$bindings = [
			"statusList" => [
				"good",
				"very-good",
				"excellent"
			],
		];
		$preparedBindings = $query->prepareBindings(
			$bindings
		);

		self::assertArrayHasKey("statusList", $bindings);
		self::assertArrayNotHasKey("statusList", $preparedBindings);

		foreach($bindings["statusList"] as $i => $binding) {
			self::assertArrayHasKey(
				"statusList__$i",
				$preparedBindings
			);
		}

		$i++;
		self::assertArrayNotHasKey(
			"statusList__$i",
			$preparedBindings
		);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathExistsProvider() */
	public function testMultipleStatements(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath,
	):void {
		file_put_contents($queryPath, "insert into test_table(name) values ('four'); insert into test_table(name) values ('five'); insert into test_table(name) values ('six'); delete from test_table where name = 'two'");
		$query = new SqlQuery($queryPath, $this->driverSingleton());
		$query->execute();
		file_put_contents($queryPath, "select name from test_table order by id");
		$resultSet = $query->execute();

		$row = $resultSet->fetch();
		self::assertSame("one", $row->getString("name"));
		$row = $resultSet->fetch();
		self::assertSame("three", $row->getString("name"));
	}

	private function driverSingleton():Driver {
		if(is_null($this->driver)) {
			$settings = new Settings(
				Helper::getTmpDir(),
				Settings::DRIVER_SQLITE,
				Settings::SCHEMA_IN_MEMORY
			);
			$this->driver = new Driver($settings);
		}

		return $this->driver;
	}
}
