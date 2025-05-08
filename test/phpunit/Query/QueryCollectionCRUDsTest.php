<?php
namespace Gt\Database\Test\Query;

use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Driver;
use Gt\Database\Query\Query;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Query\QueryCollectionDirectory;
use Gt\Database\Query\QueryFactory;
use Gt\Database\Result\ResultSet;
use Gt\Database\Result\Row;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueryCollectionCRUDsTest extends TestCase {
	/** @var  QueryCollection */
	private $queryCollection;
	/** @var Query|MockObject */
	private $mockQuery;

	protected function setUp():void {
		$mockQueryFactory = $this->createMock(QueryFactory::class);
		$this->mockQuery = $this->createMock(Query::class);
		$mockQueryFactory
			->expects(static::once())
			->method("create")
			->with("something")
			->willReturn($this->mockQuery);

		/** @var QueryFactory $mockQueryFactory */
		$this->queryCollection = new QueryCollectionDirectory(
			__DIR__,
			new Driver(new DefaultSettings()),
			$mockQueryFactory
		);
	}

// CREATE /////////////////////////////////////////////////////////////////////
	public function testCreate() {
		$placeholderVars = ["nombre" => "hombre"];
		$lastInsertID = "1234";
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("lastInsertId")
			->willReturn($lastInsertID);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars])
			->willReturn($mockResultSet);

		static::assertEquals(
			$lastInsertID,
			$this->queryCollection->insert(
				"something",
				$placeholderVars
			)
		);
	}

	public function testCreateNoParams() {
		$lastInsertID = "1234";
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("lastInsertId")
			->willReturn($lastInsertID);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		static::assertEquals(
			$lastInsertID,
			$this->queryCollection->insert("something"));
	}

// RETRIEVE ///////////////////////////////////////////////////////////////////
	public function testRetrieve() {
		$placeholderVars = ["nombre" => "hombre"];
		$expected = new Row(["col1" => "that"]);

		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("current")
			->willReturn($expected);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars])
			->willReturn($mockResultSet);

		$actual = $this->queryCollection->fetch("something", $placeholderVars);
		static::assertInstanceOf(Row::class, $actual);
		static::assertCount(1, $actual);
		static::assertEquals($expected, $actual);
	}

	public function testRetrieveNoParams() {
		$expected = new Row(["col1" => "that"]);

		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("current")
			->willReturn($expected);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		static::assertEquals(
			$expected,
			$this->queryCollection->fetch("something"));
	}

	public function testRetrieveNoResults() {
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("current")
			->willReturn(null);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		static::assertNull($this->queryCollection->fetch("something"));
	}

	public function testRetrieveAll() {
		$placeholderVars = ["nombre" => "hombre"];
		$expected = [new Row(["col1" => "that"]), new Row(["col1" => "theother"])];

		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("fetchAll")
			->willReturn($expected);
		$mockResultSet
			->method("count")
			->willReturn(count($expected));
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars])
			->willReturn($mockResultSet);

		$actual = $this->queryCollection->fetchAll("something", $placeholderVars);
		static::assertCount(2, $actual);
		static::assertEquals($expected, $actual->fetchAll());
	}

	public function testRetrieveAllNoParams() {
		$expected = [new Row(["col1" => "that"]), new Row(["col1" => "theother"])];

		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("fetchAll")
			->willReturn($expected);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		$resultSet = $this->queryCollection->fetchAll("something");

		static::assertEquals(
			$expected,
			$resultSet->fetchAll());
	}

	public function testRetrieveAllNoResults() {
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("fetchAll")
			->willReturn([]);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		$resultSet = $this->queryCollection->fetchAll("something");
		static::assertIsArray($resultSet->fetchAll());
		static::assertCount(0, $resultSet);
	}

// UPDATE ///////////////////////////////////////////////////////////////////
	public function testUpdate() {
		$placeholderVars = ["nombre" => "hombre"];
		$recordsUpdatedCount = 1;
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("affectedRows")
			->willReturn($recordsUpdatedCount);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars])
			->willReturn($mockResultSet);

		static::assertEquals($recordsUpdatedCount, $this->queryCollection->update("something", $placeholderVars));
	}

	public function testUpdateNoParams() {
		$recordsUpdatedCount = 2;
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("affectedRows")
			->willReturn($recordsUpdatedCount);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		static::assertEquals(
			$recordsUpdatedCount,
			$this->queryCollection->update("something"));
	}


// DELETE ///////////////////////////////////////////////////////////////////
	public function testDelete() {
		$placeholderVars = ["nombre" => "hombre"];
		$recordsDeletedCount = 0;
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("affectedRows")
			->willReturn($recordsDeletedCount);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars])
			->willReturn($mockResultSet);

		static::assertEquals(
			$recordsDeletedCount,
			$this->queryCollection->delete("something", $placeholderVars));
	}

	public function testDeleteNoParams() {
		$recordsDeletedCount = 2;
		$mockResultSet = $this->createMock(ResultSet::class);
		$mockResultSet
			->method("affectedRows")
			->willReturn($recordsDeletedCount);
		$this->mockQuery
			->expects(static::once())
			->method("execute")
			->with()
			->willReturn($mockResultSet);

		static::assertEquals(
			$recordsDeletedCount,
			$this->queryCollection->delete("something"));
	}
}
