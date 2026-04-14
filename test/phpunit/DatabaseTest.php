<?php
namespace GT\Database\Test;

use Exception;
use GT\Database\Connection\Settings;
use GT\Database\Database;
use GT\Database\Query\QueryCollection;
use GT\Database\Query\QueryCollectionClass;
use GT\Database\Query\QueryCollectionNotFoundException;
use GT\Database\Query\QueryOverrideConflictException;
use GT\Database\Test\Helper\Helper;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase {
	private ?Settings $settings = null;
	private string $queryBase;
	private Database $db;

	protected function setUp():void {
		$this->queryBase = Helper::getTmpDir() . "/query";
		$this->db = new Database($this->settingsSingleton());

		$connection = $this->db->getDriver()->getConnection();
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
	}

	public function testInterface() {
		$db = new Database();
		static::assertInstanceOf(Database::class, $db);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryCollectionPathExistsProvider */
	public function testQueryCollectionPathExists(string $name, string $path) {
		$basePath = dirname($path);
		$settings = new Settings(
			$basePath,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY
		);
		$db = new Database($settings);

		$queryCollection = $db->queryCollection($name);
		static::assertInstanceOf(QueryCollection::class, $queryCollection);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathNotExistsProvider */
	public function testQueryCollectionPathNotExists(string $name, string $path) {
		$basePath = dirname($path);

		$settings = new Settings(
			$basePath,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY
		);
		$db = new Database($settings);

		self::expectException(QueryCollectionNotFoundException::class);
		$db->queryCollection($name);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryPathNestedProvider */
	public function testQueryCollectionDots(
		array $nameParts,
		string $path,
		string $basePath
	) {
		array_pop($nameParts);
		$dotName = implode(".", $nameParts);

		$settings = new Settings(
			$basePath,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY
		);
		$db = new Database($settings);
		$queryCollection = $db->queryCollection($dotName);
		self::assertInstanceOf(QueryCollection::class, $queryCollection);
	}

	/** @dataProvider \GT\Database\Test\Helper\Helper::queryCollectionPathNotExistsProvider() */
	public function testQueryCollectionPhp(
		string $name,
		string $path,
	) {
		$path = "$path.php";
		$baseQueryDirectory = dirname($path);
		if(!is_dir($baseQueryDirectory)) {
			mkdir($baseQueryDirectory, recursive: true);
		}
		touch($path);

		$settings = new Settings(
			$baseQueryDirectory,
			Settings::DRIVER_SQLITE,
			Settings::SCHEMA_IN_MEMORY,
		);
		$sut = new Database($settings);
		$queryCollection = $sut->queryCollection($name);

		self::assertInstanceOf(QueryCollectionClass::class, $queryCollection);
	}

	public function testClassCollectionInsertUpdateDeleteBuilders():void {
		mkdir($this->queryBase, 0775, true);
		mkdir($this->queryBase . "/UserCrud", 0775, true);
		file_put_contents(
			$this->queryBase . "/UserCrud/getInsertedState.sql",
			"SELECT number, halfNumber FROM test_table WHERE name = :name LIMIT 1"
		);
		file_put_contents(
			$this->queryBase . "/UserCrud.php",
			<<<PHP
			<?php
			namespace App\Query;

			use Gt\SqlBuilder\DeleteBuilder;
			use Gt\SqlBuilder\InsertBuilder;
			use Gt\SqlBuilder\Query\UpdateQuery;

			class UserCrud {
				public function insertUser():InsertBuilder {
					return (new InsertBuilder())
						->into("test_table")
						->columns("name", "number", "isEven", "halfNumber")
						->values(":name", ":number", ":isEven", ":halfNumber");
				}

				public function updateHalfNumber():UpdateQuery {
					return new class() extends UpdateQuery {
						public function update():array {
							return ["test_table"];
						}

						public function set():array {
							return ["halfNumber"];
						}

						public function where():array {
							return ["name = :name"];
						}
					};
				}

				public function deleteByName():DeleteBuilder {
					return (new DeleteBuilder())
						->from("test_table")
						->where("name = :name");
				}
			}
			PHP
		);

		$insertId = $this->db->insert("UserCrud/insertUser", [
			"name" => "four",
			"number" => 4,
			"isEven" => true,
			"halfNumber" => 2.0,
		]);
		self::assertSame("4", $insertId);

		$updated = $this->db->update("UserCrud/updateHalfNumber", [
			"name" => "four",
			"halfNumber" => 2.5,
		]);
		self::assertSame(1, $updated);

		$row = $this->db->fetch("UserCrud/getInsertedState", [
			"name" => "four",
		]);
		self::assertSame(4, $row->getInt("number"));
		self::assertSame(2.5, $row->getFloat("halfNumber"));

		$deleted = $this->db->delete("UserCrud/deleteByName", [
			"name" => "four",
		]);
		self::assertSame(1, $deleted);
		self::assertNull($this->db->fetch("UserCrud/getInsertedState", [
			"name" => "four",
		]));
	}

	public function testClassCollectionSupportsCaseInsensitiveOverrideDirectory():void {
		mkdir($this->queryBase, 0775, true);
		file_put_contents(
			$this->queryBase . "/UserQuery.php",
			<<<PHP
			<?php
			namespace App\Query;

			use Gt\SqlBuilder\SelectBuilder;

			class UserQuery {
				public function getClassMarker():SelectBuilder {
					return (new SelectBuilder())
						->select("'class' as source")
						->from("test_table");
				}
			}
			PHP
		);
		mkdir($this->queryBase . "/userquery", 0775, true);
		file_put_contents(
			$this->queryBase . "/userquery/getNameById.sql",
			"select name as source from test_table where id = :id"
		);

		$result = $this->db->fetch("userquery/getNameById", [
			"id" => 2,
		]);
		self::assertSame("two", $result->getString("source"));
	}

	public function testClassCollectionThrowsOnConflictingOverride():void {
		mkdir($this->queryBase, 0775, true);
		file_put_contents(
			$this->queryBase . "/UserConflict.php",
			<<<PHP
			<?php
			namespace App\Query;

			use Gt\SqlBuilder\SelectBuilder;

			class UserConflict {
				public function getById():SelectBuilder {
					return (new SelectBuilder())
						->select(":id as id");
				}
			}
			PHP
		);
		mkdir($this->queryBase . "/userconflict", 0775, true);
		file_put_contents(
			$this->queryBase . "/userconflict/getById.sql",
			"select :id as id"
		);

		$this->expectException(QueryOverrideConflictException::class);
		$this->db->fetch("UserConflict/getById", [
			"id" => 2,
		]);
	}

	public function testClassCollectionComplexSelectBuilder():void {
		$connection = $this->db->getDriver()->getConnection();
		$connection->exec("CREATE TABLE parity_lookup ( isEven bool primary key, label varchar(16) );");
		$connection->exec("INSERT INTO parity_lookup (isEven, label) VALUES (0, 'odd'), (1, 'even');");

		mkdir($this->queryBase, 0775, true);
		file_put_contents(
			$this->queryBase . "/Report.php",
			<<<PHP
			<?php
			namespace App\Query;

			use Gt\SqlBuilder\SelectBuilder;

			class Report {
				public function groupedParity():SelectBuilder {
					return (new SelectBuilder())
						->select(
							"parity_lookup.label",
							"count(*) as total",
							"sum(test_table.number) as number_sum"
						)
						->from("test_table")
						->innerJoin("parity_lookup on parity_lookup.isEven = test_table.isEven")
						->where(
							"test_table.number >= :minNumber",
							"test_table.halfNumber >= :minHalf",
							"test_table.name != :excludedName"
						)
						->groupBy("parity_lookup.label")
						->having("count(*) >= 1")
						->orderBy("total desc", "parity_lookup.label");
				}
			}
			PHP
		);

		$resultSet = $this->db->fetchAll("Report/groupedParity", [
			"minNumber" => 1,
			"minHalf" => 0.5,
			"excludedName" => "missing",
		]);

		self::assertCount(2, $resultSet);
		$row0 = $resultSet->fetch();
		$row1 = $resultSet->fetch();
		self::assertSame("odd", $row0->getString("label"));
		self::assertSame(2, $row0->getInt("total"));
		self::assertSame(4, $row0->getInt("number_sum"));
		self::assertSame("even", $row1->getString("label"));
		self::assertSame(1, $row1->getInt("total"));
		self::assertSame(2, $row1->getInt("number_sum"));
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
