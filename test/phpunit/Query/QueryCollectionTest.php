<?php
namespace Gt\Database\Test\Query;

use DateTime;
use Gt\Database\Connection\DefaultSettings;
use Gt\Database\Connection\Driver;
use Gt\Database\Query\PhpQuery;
use Gt\Database\Query\Query;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Query\QueryCollectionClass;
use Gt\Database\Query\QueryCollectionDirectory;
use Gt\Database\Query\QueryFactory;
use Gt\Database\Result\ResultSet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class QueryCollectionTest extends TestCase {
	public function testQueryCollectionQuery() {
		$queryFactory = $this->createMock(QueryFactory::class);
		$query = $this->createMock(Query::class);
		$queryFactory
			->expects(static::once())
			->method("create")
			->with("something")
			->willReturn($query);

		$queryCollection = new QueryCollectionDirectory(
			__DIR__,
			new Driver(new DefaultSettings()),
			$queryFactory
		);

		$placeholderVars = ["nombre" => "hombre"];
		$query
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars]);

		$resultSet = $queryCollection->query(
			"something",
			$placeholderVars
		);

		static::assertInstanceOf(
			ResultSet::class,
			$resultSet
		);
	}

	public function testQueryCollectionQueryNoParams() {
		$queryFactory = $this->createMock(QueryFactory::class);
		$query = $this->createMock(Query::class);
		$queryFactory
			->expects(static::once())
			->method("create")
			->with("something")
			->willReturn($query);

		$queryCollection = new QueryCollectionDirectory(
			__DIR__,
			new Driver(new DefaultSettings()),
			$queryFactory
		);

		$query->expects(static::once())->method("execute")->with();

		$resultSet = $queryCollection->query("something");

		static::assertInstanceOf(
			ResultSet::class,
			$resultSet
		);
	}

	public function testQueryShorthand() {
		$queryFactory = $this->createMock(QueryFactory::class);
		$query = $this->createMock(Query::class);
		$queryFactory
			->expects(static::once())
			->method("create")
			->with("something")
			->willReturn($query);

		$queryCollection = new QueryCollectionDirectory(
			__DIR__,
			new Driver(new DefaultSettings()),
			$queryFactory
		);

		$placeholderVars = ["nombre" => "hombre"];
		$query
			->expects(static::once())
			->method("execute")
			->with([$placeholderVars]);

		static::assertInstanceOf(
			ResultSet::class,
			$queryCollection->something($placeholderVars)
		);
	}

	public function testQueryShorthandNoParams() {
		$queryFactory = $this->createMock(QueryFactory::class);
		$query = $this->createMock(Query::class);
		$queryFactory
			->expects(static::once())
			->method("create")
			->with("something")
			->willReturn($query);

		$queryCollection = new QueryCollectionDirectory(
			__DIR__,
			new Driver(new DefaultSettings()),
			$queryFactory
		);

		$query->expects(static::once())->method("execute")->with();

		static::assertInstanceOf(
			ResultSet::class,
			$queryCollection->something()
		);
	}

	public function testQueryCollectionClass_defaultNamespace() {
		$projectDir = implode(DIRECTORY_SEPARATOR, [
			sys_get_temp_dir(),
			"phpgt",
			"database",
			uniqid(),
		]);
		$baseQueryDirectory = implode(DIRECTORY_SEPARATOR, [
			$projectDir,
			"resultSet",
		]);
		$queryCollectionClassPath = "$baseQueryDirectory/Example.php";
		if(!is_dir($baseQueryDirectory)) {
			mkdir($baseQueryDirectory, recursive: true);
		}
		$php = <<<PHP
		<?php
		namespace App\Query;

		class Example {
			public function getTimestamp():string {
				return "select current_timestamp";
			}
		}
		PHP;

		file_put_contents($queryCollectionClassPath, $php);

		$sut = new QueryCollectionClass(
			$queryCollectionClassPath,
			new Driver(new DefaultSettings()),
		);

		$resultSet = $sut->query("getTimestamp");
		self::assertInstanceOf(ResultSet::class, $resultSet);
		$row = $resultSet->fetch();
		$actualDateTime = $row->getDateTime("current_timestamp");
		$expectedDateTime = new DateTime();
		self::assertSame(
			$expectedDateTime->format("Y-m-d H:i"),
			$actualDateTime->format("Y-m-d H:i"),
		);
	}

	public function testQueryCollectionClass_namespace() {
		$projectDir = implode(DIRECTORY_SEPARATOR, [
			sys_get_temp_dir(),
			"phpgt",
			"database",
			uniqid(),
		]);
		$baseQueryDirectory = implode(DIRECTORY_SEPARATOR, [
			$projectDir,
			"resultSet",
		]);
		$queryCollectionClassPath = "$baseQueryDirectory/Example.php";
		if(!is_dir($baseQueryDirectory)) {
			mkdir($baseQueryDirectory, recursive: true);
		}
		$php = <<<PHP
		<?php
		namespace GtTest\DatabaseExample;

		class Example {
			public function getTimestamp():string {
				return "select current_timestamp";
			}
		}
		PHP;

		file_put_contents($queryCollectionClassPath, $php);

		$sut = new QueryCollectionClass(
			$queryCollectionClassPath,
			new Driver(new DefaultSettings()),
		);
		$sut->setAppNamespace("GtTest\\DatabaseExample");

		$resultSet = $sut->query("getTimestamp");
		self::assertInstanceOf(ResultSet::class, $resultSet);
		$row = $resultSet->fetch();
		$actualDateTime = $row->getDateTime("current_timestamp");
		$expectedDateTime = new DateTime();
		self::assertSame(
			$expectedDateTime->format("Y-m-d H:i"),
			$actualDateTime->format("Y-m-d H:i"),
		);
	}
}
