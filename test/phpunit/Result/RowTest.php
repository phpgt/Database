<?php
namespace GT\Database\Test\Result;

use DateTime;
use GT\Database\Result\Row;
use GT\Json\JSONDecodeException;
use GT\Json\JSONKvpObject;
use GT\Json\JSONPrimitive\JSONArrayPrimitive;
use GT\Json\JSONPrimitive\JSONBoolPrimitive;
use GT\Json\JSONPrimitive\JSONFloatPrimitive;
use GT\Json\JSONPrimitive\JSONIntPrimitive;
use GT\Json\JSONPrimitive\JSONNullPrimitive;
use GT\Json\JSONPrimitive\JSONStringPrimitive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RowTest extends TestCase {
	public function testGetJsonObject():void {
		$value = '{"name":"Example","count":3,"enabled":true,"tags":["one","two"]}';
		$row = new Row(["settings" => $value]);
		$json = $row->getJson("settings");

		self::assertInstanceOf(JSONKvpObject::class, $json);
		self::assertSame("Example", $json->getString("name"));
		self::assertSame(3, $json->getInt("count"));
		self::assertTrue($json->getBool("enabled"));
		self::assertSame(["one", "two"], $json->getArray("tags", "string"));
		self::assertSame($value, $row->getString("settings"));
	}

	public function testGetJsonEmptyObject():void {
		$row = new Row(["settings" => "{}"]);
		self::assertInstanceOf(JSONKvpObject::class, $row->getJson("settings"));
	}

	#[DataProvider("jsonPrimitives")]
	public function testGetJsonPrimitive(string $value, string $type, mixed $expected):void {
		$row = new Row(["value" => $value]);
		$json = $row->getJson("value");

		self::assertInstanceOf($type, $json);
		self::assertSame($expected, $json->getPrimitiveValue());
	}

	public static function jsonPrimitives():iterable {
		yield "array" => ['["one","two"]', JSONArrayPrimitive::class, ["one", "two"]];
		yield "empty array" => ["[]", JSONArrayPrimitive::class, []];
		yield "integer" => ["123", JSONIntPrimitive::class, 123];
		yield "zero" => ["0", JSONIntPrimitive::class, 0];
		yield "float" => ["1.25", JSONFloatPrimitive::class, 1.25];
		yield "true" => ["true", JSONBoolPrimitive::class, true];
		yield "false" => ["false", JSONBoolPrimitive::class, false];
		yield "string" => ['"example"', JSONStringPrimitive::class, "example"];
		yield "empty string" => ['""', JSONStringPrimitive::class, ""];
		yield "JSON null" => ["null", JSONNullPrimitive::class, null];
	}

	public function testGetJsonNullable():void {
		$row = new Row(["value" => null]);
		self::assertNull($row->getJson("value"));
		self::assertNull($row->getJson("missing"));
	}

	#[DataProvider("invalidJson")]
	public function testGetJsonInvalid(string $value):void {
		$row = new Row(["value" => $value]);
		self::expectException(JSONDecodeException::class);
		$row->getJson("value");
	}

	public static function invalidJson():iterable {
		yield "empty column" => [""];
		yield "malformed JSON" => ['{"incomplete":'];
		yield "invalid UTF-8" => ["\"\xB1\""];
	}

	#[DataProvider("data_getTestRow")]
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

	#[DataProvider("data_getTestRow")]
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

	#[DataProvider("data_getTestRow")]
	public function testGetString(array $data) {
		$row = new Row($data);
		foreach($data as $key => $expected) {
			$actual = $row->getString($key);
			self::assertIsString($actual);
			self::assertSame((string)$expected, $actual);
		}
	}

	#[DataProvider("data_getTestRow")]
	public function testGetInt(array $data) {
		$row = new Row($data);
		$id = $row->getInt("id");
		self::assertIsInt($id);
		self::assertSame((int)$data["id"], $id);
	}

	#[DataProvider("data_getTestRow")]
	public function testGetFloat(array $data) {
		$row = new Row($data);
		$float = $row->getFloat("exampleFloat");
		self::assertIsFloat($float);
		self::assertSame(round($data["exampleFloat"], 4), round($float, 4));
	}

	#[DataProvider("data_getTestRow")]
	public function testGetBool(array $data) {
		$row = new Row($data);
		$bool = $row->getBool("exampleBool");
		self::assertIsBool($bool);
		self::assertSame((bool)$data["exampleBool"], $bool);
	}

	#[DataProvider("data_getTestRow")]
	public function testGetDateTime(array $data) {
		$row = new Row($data);
		$dateTime = $row->getDateTime("exampleDateTime");
		self::assertInstanceOf(DateTime::class, $dateTime);
		self::assertEquals(
			$dateTime->format("Y-m-d H:i:s"),
			$data["exampleDateTime"]
		);
	}

	#[DataProvider("timestamps")]
	public function testGetDateTime_timestamp(string $timestamp):void {
		$sut = new Row(["date" => $timestamp]);
		$dateTime = $sut->getDateTime("date");

		self::assertInstanceOf(DateTime::class, $dateTime);
		self::assertSame((int)$timestamp, $dateTime->getTimestamp());
	}

	public static function timestamps():iterable {
		yield "Unix epoch" => ["0"];
		yield "positive timestamp" => ["576264240"];
		yield "before Unix epoch" => ["-1"];
	}

	public function testGetDateTime_nullable():void {
		$sut = new Row(["date" => null]);
		self::assertNull($sut->getDateTime("date"));
		self::assertNull($sut->getDateTime("missing"));
	}

	public function testGetDateTime_returnsIndependentMutableObjects():void {
		$sut = new Row(["date" => "1988-04-05 17:24:00"]);
		$first = $sut->getDateTime("date");
		$second = $sut->getDateTime("date");
		self::assertInstanceOf(DateTime::class, $first);
		self::assertInstanceOf(DateTime::class, $second);
		self::assertSame($first, $first->modify("+1 day"));
		self::assertSame("1988-04-06", $first->format("Y-m-d"));
		self::assertSame("1988-04-05", $second->format("Y-m-d"));
		self::assertSame("1988-04-05 17:24:00", $sut->getString("date"));
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
					$dateTime = new DateTime();
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
