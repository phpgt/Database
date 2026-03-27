<?php
namespace Gt\Database\Test\Result;

use Gt\Database\Result\Row;
use PDOStatement;
use Gt\Database\Result\ResultSet;
use PHPUnit\Framework\TestCase;

class ResultSetTest extends TestCase {
	const FAKE_DATA = [
		["id" => 1, "name" => "Alice", "timestamp" => 576264240, "date" => "1988-04-05 17:24"],
		["id" => 2, "name" => "Bob", "timestamp" => 554900700, "date" => "1987-08-02 11:05"],
		["id" => 3, "name" => "Charlie", "timestamp" => 1433548800, "date" => "2015-06-06"],
	];
	private $fake_data_index = 0;

	public function testCount() {
		$resultSet = new ResultSet($this->getStatementMock());
		self::assertCount(3, $resultSet);
	}

	public function testCountMidIteration() {
		$resultSet = new ResultSet($this->getStatementMock());
// Subsequent calls to count should not have side effects:
		self::assertCount(
			3,
			$resultSet
		);
		self::assertCount(
			3,
			$resultSet
		);

		foreach($resultSet as $i => $row) {
			$expectedId = $i + 1;
			self::assertEquals(
				$expectedId,
				$row->id,
				"Row does not match"
			);

			if($i > 0) {
				self::assertCount(
					3,
					$resultSet
				);
			}
		}

// We know that $i should be 0, 1, 2 ... but we need to check it, in case
// the manipulation of the iteration index breaks things.
		self::assertEquals(2, $i);
	}

	public function testFirstRowArrayAccess() {
		$resultSet = new ResultSet($this->getStatementMock());
		$firstRow = self::FAKE_DATA[0];
		$row = $resultSet->fetch();
		self::assertEquals($firstRow["id"], $row->id);
		self::assertEquals($firstRow["name"], $row->name);
	}

	public function testIteration() {
		$resultSet = new ResultSet($this->getStatementMock());
		$iterationCount = 0;

		foreach($resultSet as $row) {
			$fakeRow = self::FAKE_DATA[$iterationCount];

			foreach($row as $key => $value) {
				self::assertEquals($value, $fakeRow[$key]);
			}

			$iterationCount++;
		}

		self::assertEquals(3, $iterationCount);
	}

	public function testLastInsertId() {
		$resultSet = new ResultSet($this->getStatementMock(), "123");
		self::assertEquals(123, $resultSet->lastInsertId());
	}

	public function testCountTwice() {
		$resultSet = new ResultSet($this->getStatementMock());
		$count = count($resultSet);
		self::assertCount($count, $resultSet);
	}

	public function testGetFieldList() {
		$resultSet = new ResultSet($this->getStatementMock());
		self::assertSame(
			["id", "name", "timestamp", "date"],
			$resultSet->getFieldList()
		);
	}

	public function testGetFieldListFallbackToFirstRow() {
		$resultSet = new ResultSet($this->getStatementMock(false));
		self::assertSame(
			["id", "name", "timestamp", "date"],
			$resultSet->getFieldList()
		);
	}

	public function testGetFieldListNoRows() {
		$statement = $this->createMock(PDOStatement::class);
		$statement->method("fetch")->willReturn(null);
		$statement->method("execute")->willReturn(true);
		$statement->method("columnCount")->willReturn(0);
		$resultSet = new ResultSet($statement);

		self::assertSame([], $resultSet->getFieldList());
	}

	public function testGetFieldListMidIteration() {
		$resultSet = new ResultSet($this->getStatementMock());
		$resultSet->rewind();
		$resultSet->next();

		self::assertSame(
			["id", "name", "timestamp", "date"],
			$resultSet->getFieldList()
		);
		self::assertEquals(2, $resultSet->current()->id);
	}

	public function testAccessByRowIndex() {
		$resultSet = new ResultSet($this->getStatementMock());

		$rowList = $resultSet->fetchAll();

		self::assertEquals(self::FAKE_DATA[1]["name"], $rowList[1]->name);
		self::assertEquals(self::FAKE_DATA[0]["name"], $rowList[0]->name);
		self::assertEquals(self::FAKE_DATA[2]["name"], $rowList[2]->name);
	}

	public function testNoRows() {
		$statement = $this->createMock(PDOStatement::class);
		$statement->method("fetch")->willReturn(null);
		$resultSet = new ResultSet($statement);

		self::assertNull($resultSet->fetch());
	}

	public function testFetchAll() {
		$resultSet = new ResultSet($this->getStatementMock());
		$rows = $resultSet->fetchAll();

		foreach(self::FAKE_DATA as $index => $fakeValue) {
			self::assertArrayHasKey($index, $rows);
			$row = $rows[$index];
			self::assertInstanceOf(Row::class, $row);
			self::assertEquals($fakeValue["id"], $row->id);
			self::assertEquals($fakeValue["name"], $row->name);
		}
	}

	public function testAsArray() {
		$resultSet = new ResultSet($this->getStatementMock());
		$array = $resultSet->asArray();
		foreach($array as $i => $rowArray) {
			self::assertIsArray($rowArray);
			self::assertArrayHasKey("id", $rowArray);
			self::assertArrayHasKey("name", $rowArray);
			self::assertIsInt($rowArray["id"]);
		}
	}

	public function testAsDateTime() {
		$resultSet = new ResultSet($this->getStatementMock());
		$row = $resultSet->fetch();
		self::assertEquals("1988-04-05 17:24", $row->getDateTime("date")->format("Y-m-d H:i"));
		self::assertEquals("1988-04-05 17:24", $row->getDateTime("timestamp")->format("Y-m-d H:i"));
	}

	private function getStatementMock(bool $withMetadata = true):PDOStatement {
		$statement = $this->createMock(PDOStatement::class);
		$statement->method("fetch")
			->will(self::returnCallback([$this, "getNextFakeData"]));
		$statement->method("execute")
			->willReturnCallback([$this, "rewindFakeData"]);
		if($withMetadata) {
			$statement->method("columnCount")
				->willReturn(count(self::FAKE_DATA[0]));
			$statement->method("getColumnMeta")
				->willReturnCallback(function(int $index):array {
					$name = array_keys(self::FAKE_DATA[0])[$index] ?? null;
					return ["name" => $name];
				});
		}
		else {
			$statement->method("columnCount")
				->willReturn(0);
		}

		return $statement;
	}

	public function getNextFakeData() {
		if($this->fake_data_index > count(self::FAKE_DATA)) {
			$this->fake_data_index = 0;
		}
		$data = self::FAKE_DATA[$this->fake_data_index] ?? null;
		$this->fake_data_index++;

		return $data;

	}

	public function rewindFakeData():bool {
		$this->fake_data_index = 0;
		return true;
	}
}
