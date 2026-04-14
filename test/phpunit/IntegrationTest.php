<?php
namespace GT\Database\Test;

use DateTime;
use DateTimeInterface;
use Exception;
use GT\Database\Connection\Driver;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase {
	/** @var Driver */
	private $driver;
	/** @var Settings */
	private $settings;
	/** @var string */
	private $queryBase;
	/** @var Database */
	private $db;

	protected function setUp():void {
		$this->queryBase = Helper::getTmpDir() . "/query";

		$this->db = new Database($this->settingsSingleton());
		$driver = $this->db->getDriver();

		$connection = $driver->getConnection();
		$output = $connection->exec("CREATE TABLE test_table ( id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(32), number integer, isEven bool, halfNumber float, timestamp DATETIME DEFAULT current_timestamp); CREATE UNIQUE INDEX test_table_name_uindex ON test_table (name);");

		if($output === false) {
			$error = $connection->errorInfo();
			throw new Exception($error[2]);
		}

		$insertStatement = $connection->prepare("INSERT INTO test_table (`name`, `number`, `isEven`, `halfNumber`) VALUES ('one', 1, 0, 0.5), ('two', 2, 1, 1), ('three', 3, 0, 1.5)");
		$success = $insertStatement->execute();
		if($success === false) {
			$error = $connection->errorInfo();
			throw new Exception($error[2]);
		}

		static::assertTrue($success, "Success inserting fake data");

		$selectStatement = $connection->query("SELECT * FROM test_table");
		$result = $selectStatement->fetchAll();
		static::assertCount(3, $result);
	}

	public function testSubsequentSqlQueries() {
		$uuid = uniqid();
		$queryCollectionPath = implode(DIRECTORY_SEPARATOR, [
			$this->queryBase,
			"exampleCollection",
		]);
		$insertQueryPath = implode(DIRECTORY_SEPARATOR, [
			$queryCollectionPath,
			"insert.sql",
		]);
		$selectQueryPath = implode(DIRECTORY_SEPARATOR, [
			$queryCollectionPath,
			"selectByName.sql",
		]);

		mkdir($queryCollectionPath, 0775, true);
// placeholders are specifically not named the same.
		file_put_contents(
			$insertQueryPath,
			"INSERT INTO test_table ( name ) VALUES (:nameToInsert)"
		);
		file_put_contents(
			$selectQueryPath,
			"SELECT * FROM test_table WHERE name = :rowName"
		);

		$this->db->insert("exampleCollection/insert", [
			"nameToInsert" => $uuid,
		]);
		$result = $this->db->fetch("exampleCollection/selectByName", [
			"rowName" => $uuid,
		]);

		static::assertEquals($uuid, $result->name);

// perform an insert and select again:
		$uuid2 = uniqid();
		$this->db->insert("exampleCollection/insert", [
			"nameToInsert" => $uuid2,
		]);
		$result1 = $this->db->fetch("exampleCollection/selectByName", [
			"rowName" => $uuid,
		]);
		$result2 = $this->db->fetch("exampleCollection/selectByName", [
			"rowName" => $uuid2,
		]);

		static::assertEquals($uuid, $result1->name);
		static::assertEquals($uuid2, $result2->name);
	}

	public function testQuestionMarkParameter() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByIdQueryPath = $queryCollectionPath . "/getById.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByIdQueryPath,
			"SELECT id, name, number FROM test_table WHERE id = ?"
		);

		$result2 = $this->db->fetch("exampleCollection/getById", 2);
		$result1 = $this->db->fetch("exampleCollection/getById", 1);

		$rqr = $this->db->executeSql("SELECT id, name FROM test_table");

		static::assertEquals(1, $result1->id);
		static::assertEquals(2, $result2->id);
		static::assertCount(3, $rqr);
	}

	public function testMultipleParameterUsage() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getByNameNumber.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT id, name, number FROM test_table WHERE name = :name and number = :number"
		);

		$result1 = $this->db->fetch("exampleCollection/getByNameNumber", [
			"name" => "one",
			"number" => 1,
		]);
		$result2 = $this->db->fetch("exampleCollection/getByNameNumber", [
			"name" => "two",
			"number" => 2,
		]);
		$resultNull = $this->db->fetch("exampleCollection/getByNameNumber", [
			"name" => "three",
			"number" => 55,
		]);

		static::assertEquals(1, $result1->id);
		static::assertEquals(2, $result2->id);
		static::assertNull($resultNull);
	}

	public function testMultipleParameterUsageDotSeperator() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getByNameNumber.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT id, name, number FROM test_table WHERE name = :name and number = :number"
		);

		$result1 = $this->db->fetch("exampleCollection.getByNameNumber", [
			"name" => "one",
			"number" => 1,
		]);
		$result2 = $this->db->fetch("exampleCollection.getByNameNumber", [
			"name" => "two",
			"number" => 2,
		]);
		$resultNull = $this->db->fetch("exampleCollection.getByNameNumber", [
			"name" => "three",
			"number" => 55,
		]);

		static::assertEquals(1, $result1->id);
		static::assertEquals(2, $result2->id);
		static::assertNull($resultNull);
	}

	public function testMultipleParameterUsageDotSeperatorNested() {
		$queryCollectionPath = implode(DIRECTORY_SEPARATOR, [
			$this->queryBase,
			"deeply",
			"nested",
			"exampleCollection",
		]);
		$getByNameNumberQueryPath = implode(DIRECTORY_SEPARATOR, [
			$queryCollectionPath,
			"getByNameNumber.sql",
		]);

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT id, name, number FROM test_table WHERE name = :name and number = :number"
		);

		$result1 = $this->db->fetch("deeply.nested.exampleCollection.getByNameNumber", [
			"name" => "one",
			"number" => 1,
		]);
		$result2 = $this->db->fetch("deeply.nested.exampleCollection.getByNameNumber", [
			"name" => "two",
			"number" => 2,
		]);
		$resultNull = $this->db->fetch("deeply.nested.exampleCollection.getByNameNumber", [
			"name" => "three",
			"number" => 55,
		]);

		static::assertEquals(1, $result1->id);
		static::assertEquals(2, $result2->id);
		static::assertNull($resultNull);
	}

	public function testMultipleArrayParameterUsage() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getByNameNumber.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT id, name, number FROM test_table WHERE name = :name and number = :number"
		);

		$result1 = $this->db->fetch("exampleCollection/getByNameNumber", [
			"name" => "one",
			"number" => 1,
		]);
		$result2 = $this->db->fetch("exampleCollection/getByNameNumber",
			["name" => "two"],
			["number" => 2]
		);
		$result3 = $this->db->fetch("exampleCollection/getByNameNumber", [
			["name" => "three"],
			["number" => 3],
		]);

		static::assertEquals(1, $result1->id);
		static::assertEquals(2, $result2->id);
		static::assertEquals(3, $result3->id);
	}

	public function testFetchBool() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/isNumberEven.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT isEven FROM test_table where number = ?"
		);

		$result1 = $this->db->fetchBool("exampleCollection/isNumberEven", 1);
		$result2 = $this->db->fetchBool("exampleCollection/isNumberEven", 2);
		self::assertFalse($result1);
		self::assertTrue($result2);
	}

	public function testFetchAllBool() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getAllBools.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT isEven FROM test_table"
		);

		$result = $this->db->fetchAllBool("exampleCollection/getAllBools");
		self::assertCount(3, $result);
		self::assertIsArray($result);
		foreach($result as $i => $value) {
			if($i % 2 !== 0) {
				self::assertTrue($value);
			}
			else {
				self::assertFalse($value);
			}
		}
	}


	public function testFetchInt() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getIdByName.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT id FROM test_table WHERE name = :name LIMIT 1"
		);

		$result = $this->db->fetchInt("exampleCollection/getIdByName", "two");
		self::assertEquals(2, $result);
		self::assertIsInt($result);
	}

	public function testFetchAllInt() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getAllNumbers.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT number FROM test_table"
		);

		$result = $this->db->fetchAllInt("exampleCollection/getAllNumbers");
		self::assertCount(3, $result);
		self::assertIsArray($result);
		foreach($result as $value) {
			self::assertIsInt($value);
		}
	}


	public function testFetchString() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getNameById.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT name FROM test_table WHERE id = ? LIMIT 1"
		);

		$result = $this->db->fetchString("exampleCollection/getNameById", 2);
		self::assertEquals("two", $result);
		self::assertIsString($result);
	}

	public function testFetchAllString() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getAllNames.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT name FROM test_table"
		);

		$result = $this->db->fetchAllString("exampleCollection/getAllNames");
		self::assertCount(3, $result);
		self::assertIsArray($result);
		foreach($result as $value) {
			self::assertIsString($value);
		}
	}

	public function testFetchFloat() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getHalfByNumber.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT halfNumber FROM test_table WHERE number = ? LIMIT 1"
		);

		$result2 = $this->db->fetchFloat("exampleCollection/getHalfByNumber", 2);
		$result3 = $this->db->fetchFloat("exampleCollection/getHalfByNumber", 3);
		self::assertIsFloat($result2);
		self::assertIsFloat($result3);
		self::assertEquals(1, $result2);
		self::assertEquals(1.5, $result3);
	}

	public function testFetchAllFloat() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getAllHalves.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT halfNumber FROM test_table"
		);

		$result = $this->db->fetchAllFloat("exampleCollection/getAllHalves");
		self::assertCount(3, $result);
		self::assertIsArray($result);
		foreach($result as $value) {
			self::assertIsFloat($value);
		}
	}

	public function testFetchDateTime() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getLatestTimestamp.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT timestamp FROM test_table ORDER BY timestamp DESC LIMIT 1"
		);

		$result = $this->db->fetchDateTime("exampleCollection/getLatestTimestamp");
		self::assertInstanceOf(DateTimeInterface::class, $result);
	}

	public function testFetchAllDateTime() {
		$queryCollectionPath = $this->queryBase . "/exampleCollection";
		$getByNameNumberQueryPath = $queryCollectionPath . "/getAllTimestamps.sql";

		mkdir($queryCollectionPath, 0775, true);
		file_put_contents(
			$getByNameNumberQueryPath,
			"SELECT timestamp FROM test_table ORDER BY timestamp"
		);

		$result = $this->db->fetchAllDateTime("exampleCollection/getAllTimestamps");
		self::assertCount(3, $result);
		self::assertIsArray($result);
		foreach($result as $value) {
			self::assertInstanceOf(DateTimeInterface::class, $value);
		}
	}

	private function settingsSingleton():Settings {
		if(is_null($this->settings)) {
			$this->settings = new Settings(
				$this->queryBase,
				Settings::DRIVER_SQLITE,
				Settings::SCHEMA_IN_MEMORY,
				"localhost"
			);
		}

		return $this->settings;
	}
}
