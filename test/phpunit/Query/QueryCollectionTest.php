<?php
namespace GT\Database\Test\Query;

use DateTime;
use GT\Database\Connection\DefaultSettings;
use GT\Database\Connection\Driver;
use GT\Database\Query\PhpQuery;
use GT\Database\Query\Query;
use GT\Database\Query\QueryCollection;
use GT\Database\Query\QueryCollectionClass;
use GT\Database\Query\QueryCollectionDirectory;
use GT\Database\Query\QueryFactory;
use GT\Database\Result\ResultSet;
use GT\Database\Test\Helper\Helper;
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

	public function testQueryCollectionClass_sqlBuilderMethod():void {
		$projectDir = Helper::getTmpDir();
		$baseQueryDirectory = implode(DIRECTORY_SEPARATOR, [
			$projectDir,
			"query",
		]);
		$queryCollectionClassPath = "$baseQueryDirectory/BuilderUser.php";
		mkdir($baseQueryDirectory, 0775, true);
		$php = <<<PHP
		<?php
		namespace App\Query;

		use Gt\SqlBuilder\SelectBuilder;

		class BuilderUser {
			public function getById():SelectBuilder {
				return (new SelectBuilder())
					->select(
						":id as id",
						":name as name"
					);
			}
		}
		PHP;
		file_put_contents($queryCollectionClassPath, $php);

		try {
			$sut = new QueryCollectionClass(
				$queryCollectionClassPath,
				new Driver(new DefaultSettings()),
			);

			$resultSet = $sut->query("getById", [
				"id" => 42,
				"name" => "Greg",
			]);

			self::assertInstanceOf(ResultSet::class, $resultSet);
			$row = $resultSet->fetch();
			self::assertSame(42, $row->getInt("id"));
			self::assertSame("Greg", $row->getString("name"));
		}
		finally {
			Helper::deleteDir($projectDir);
		}
	}

	public function testQueryCollectionClass_sqlOverrideConflictThrows():void {
		$projectDir = Helper::getTmpDir();
		$baseQueryDirectory = implode(DIRECTORY_SEPARATOR, [
			$projectDir,
			"query",
		]);
		$queryCollectionClassPath = "$baseQueryDirectory/OverrideUser.php";
		$overrideDirectory = "$baseQueryDirectory/OverrideUser";
		mkdir($overrideDirectory, 0775, true);
		$php = <<<PHP
		<?php
		namespace App\Query;

		use Gt\SqlBuilder\SelectBuilder;

		class OverrideUser {
			public function getSource():SelectBuilder {
				return (new SelectBuilder())
					->select(
						"'class' as source"
					);
			}
		}
		PHP;
		file_put_contents($queryCollectionClassPath, $php);
		file_put_contents(
			"$overrideDirectory/getSource.sql",
			"select 'override' as source"
		);

		try {
			$sut = new QueryCollectionClass(
				$queryCollectionClassPath,
				new Driver(new DefaultSettings()),
			);

			self::expectException(\GT\Database\Query\QueryOverrideConflictException::class);
			$sut->query("getSource");
		}
		finally {
			Helper::deleteDir($projectDir);
		}
	}
}
