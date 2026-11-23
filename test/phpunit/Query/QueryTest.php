<?php
namespace GT\Database\Test\Query;

use DateTimeImmutable;
use GT\Database\Connection\Driver;
use GT\Database\Connection\DefaultSettings;
use GT\Database\Connection\Settings;
use GT\Database\DatabaseException;
use GT\Database\Query\Query;
use GT\Database\Query\QueryNotFoundException;
use GT\Database\Query\SqlQuery;
use GT\Json\JSONObjectBuilder;
use PHPUnit\Framework\TestCase;
use Stringable;

class QueryTest extends TestCase {
	public function testPrepareBindingsStringable():void {
		$query = $this->getMockBuilder(Query::class)->onlyMethods(["getSql"])->getMock();
		$query->expects(self::never())->method("getSql");
		$value = new class implements Stringable {
			public function __toString():string {
				return "example's value";
			}
		};

		self::assertSame(["value" => "example's value"], $query->prepareBindings(["value" => $value]));
		self::assertSame(["example's value"], $query->prepareBindings([$value]));
	}

	public function testPrepareBindingsJsonObject():void {
		$query = $this->getMockBuilder(Query::class)->onlyMethods(["getSql"])->getMock();
		$query->expects(self::never())->method("getSql");
		$json = (new JSONObjectBuilder())->fromJsonString('{"tags":["one","two"]}');

		self::assertSame(
			["settings" => '{"tags":["one","two"]}'],
			$query->prepareBindings(["settings" => $json]),
		);
	}

	public function testPrepareBindingsDateTimeFormattingTakesPrecedence():void {
		$query = $this->getMockBuilder(Query::class)->onlyMethods(["getSql"])->getMock();
		$query->expects(self::never())->method("getSql");
		$date = new class("2026-11-18 12:34:56") extends DateTimeImmutable implements Stringable {
			public function __toString():string {
				return "custom date representation";
			}
		};

		self::assertSame(
			["date" => "2026-11-18 12:34:56"],
			$query->prepareBindings(["date" => $date]),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProviderExternal(\GT\Database\Test\Helper\Helper::class, "queryPathNotExistsProvider")]
	public function testConstructionQueryPathNotExists(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		self::expectException(QueryNotFoundException::class);
		new SqlQuery($queryPath, new Driver(new DefaultSettings()));
	}

	#[\PHPUnit\Framework\Attributes\DataProviderExternal(\GT\Database\Test\Helper\Helper::class, "queryPathExistsProvider")]
	public function testConstructionQueryPathExists(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	) {
		try {
			$query = new SqlQuery($queryPath, new Driver(new DefaultSettings()));
			static::assertFileExists($query->getFilePath());
		}
		catch(\Exception $e) {
		}
	}

	#[\PHPUnit\Framework\Attributes\DataProviderExternal(\GT\Database\Test\Helper\Helper::class, "queryPathExistsProvider")]
	public function testExecDoesNotConnect(
		string $queryName,
		string $queryCollectionPath,
		string $queryPath
	):void {
		$host = uniqid("host.");
		$port = 3306;

		$mysqlSettings = new Settings(
			$queryCollectionPath,
			"CodyDB",
			"DoesNotExist" . uniqid(),
			$host,
			$port,
			"dev",
			"dev_pass",
		);
		self::expectException(DatabaseException::class);
		self::expectExceptionMessage("Your PHP installation does not have PDO's CodyDB driver installed.");
		new SqlQuery($queryPath, new Driver($mysqlSettings));
	}
}
