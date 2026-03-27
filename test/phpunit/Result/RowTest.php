<?php
namespace Gt\Database\Test\Result;

use DateTimeImmutable;
use DateTimeInterface;
use Gt\Database\Result\Row;
use PHPUnit\Framework\TestCase;

class RowTest extends TestCase {
	/** @dataProvider data_getTestRow */
	public function testFieldAccess(array $data) {
		$row = new Row($data);

		foreach($row as $key => $value) {
			if(is_float($row->$key)) {
				self::assertEqualsWithDelta($row->$key, $value, 0.0001);
			}
			else {
				self::assertEquals($row->$key, $value);
			}
		}
	}

	public function testIsSet() {
		$row = new Row(["col1" => "item"]);
		self::assertTrue(isset($row->col1));
	}

	public function testIsNotSet() {
		$row = new Row(["col1" => "item"]);
		self::assertFalse(isset($row->col2));
	}

	public function testGetNonExistentProperty() {
		$row = new Row(["col1" => "item"]);
		self::assertNull($row->get("doink"));
	}

	public function testEmpty() {
		$row = new Row(["col1" => "item"]);
		$isEmpty = empty($row->col1);
		self::assertFalse($isEmpty);
		$isEmpty = empty($row->col2);
		self::assertTrue($isEmpty);
	}

	public function testNullCoalesce() {
		$row = new Row(["col1" => "item"]);
		$value = $row->getString("col2") ?? "DEFAULT!";
		self::assertEquals("DEFAULT!", $value);
	}

	public function testContains() {
		$row = new Row(["col1" => "item"]);
		self::assertTrue($row->contains("col1"));
		self::assertFalse($row->contains("col2"));
	}

	public function testGetFieldList() {
		$row = new Row([
			"id" => 123,
			"name" => "Example",
			"enabled" => 1,
		]);

		self::assertSame(
			["id", "name", "enabled"],
			$row->getFieldList()
		);
	}

	/** @dataProvider data_getTestRow */
	public function testIteration(array $data) {
		$row = new Row($data);

		foreach($row as $colName => $value) {
			if(is_float($data[$colName])) {
				self::assertEqualsWithDelta($data[$colName], $value, 0.0001);
			}
			else {
				self::assertEquals($data[$colName], $value);
			}
		}
	}

	/** @dataProvider data_getTestRow */
	public function testGetString(array $data) {
		$row = new Row($data);
		foreach($data as $key => $expected) {
			$actual = $row->getString($key);
			self::assertIsString($actual);
			self::assertSame((string)$expected, $actual);
		}
	}

	/** @dataProvider data_getTestRow */
	public function testGetInt(array $data) {
		$row = new Row($data);
		$id = $row->getInt("id");
		self::assertIsInt($id);
		self::assertSame((int)$data["id"], $id);
	}

	/** @dataProvider data_getTestRow */
	public function testGetFloat(array $data) {
		$row = new Row($data);
		$float = $row->getFloat("exampleFloat");
		self::assertIsFloat($float);
		self::assertSame(round($data["exampleFloat"], 4), round($float, 4));
	}

	/** @dataProvider data_getTestRow */
	public function testGetBool(array $data) {
		$row = new Row($data);
		$bool = $row->getBool("exampleBool");
		self::assertIsBool($bool);
		self::assertSame((bool)$data["exampleBool"], $bool);
	}

	/** @dataProvider data_getTestRow */
	public function testGetDateTime(array $data) {
		$row = new Row($data);
		$dateTime = $row->getDateTime("exampleDateTime");
		self::assertInstanceOf(DateTimeInterface::class, $dateTime);
		self::assertEquals(
			$dateTime->format("Y-m-d H:i:s"),
			$data["exampleDateTime"]
		);
	}

	public function testGetIntNullable() {
		$key = uniqid();
		$value = 123;
		$row = new Row([$key => $value]);
		self::assertEquals($value, $row->getInt($key));
		self::assertNull($row->getInt("does_not_exist"));
	}

	public static function data_getTestRow():array {
		$data = [];

		$columns = ["id", "name", "example", "exampleFloat", "exampleDateTime", "exampleBool"];
		$rowNum = rand(2, 50);
		for($i = 0; $i < $rowNum; $i++) {
			$row = [];
			foreach($columns as $columnIndex => $columnName) {
				switch($columnName) {
				case "id":
					$value = $columnIndex;
					break;

				case "exampleFloat":
					$value = rand(100, 1000000) / 3.141;
					break;

				case "exampleDateTime":
					$timestamp = rand(0, 4260560700);
					$dateTime = new DateTimeImmutable();
					$dateTime = $dateTime->setTimestamp($timestamp);
					$value = $dateTime->format("Y-m-d H:i:s");
					break;

				case "exampleBool":
					$value = rand(0, 1);
					break;

				default:
					$value = uniqid();
				}

				$row[$columnName] = $value;
			}

			$data []= [$row];
		}

		return $data;
	}
}
