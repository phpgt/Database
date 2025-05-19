<?php
namespace Gt\Database\Test;

use Gt\Database\Connection\Settings;
use Gt\Database\Database;
use Gt\Database\Query\QueryCollection;
use Gt\Database\Query\QueryCollectionClass;
use Gt\Database\Query\QueryCollectionNotFoundException;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase {
	public function testInterface() {
		$db = new Database();
		static::assertInstanceOf(Database::class, $db);
	}

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryCollectionPathExistsProvider */
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

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathNotExistsProvider */
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

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryPathNestedProvider */
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

	/** @dataProvider \Gt\Database\Test\Helper\Helper::queryCollectionPathNotExistsProvider() */
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
}
