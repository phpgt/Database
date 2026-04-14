<?php
use GT\Database\Connection\Settings;
use GT\Database\Database;

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("08-sqlbuilder-query-collections");

try {
	$classCode = <<<PHP
use Gt\SqlBuilder\InsertBuilder;
use Gt\SqlBuilder\SelectBuilder;

class Product {
	public function insert():InsertBuilder {
		return (new InsertBuilder())
			->into("product")
			->columns("name", "price")
			->values(":name", ":price");
	}

	public function getByMinPrice():SelectBuilder {
		return (new SelectBuilder())
			->select("id", "name", "price")
			->from("product")
			->where("price >= :minPrice")
			->orderBy("price desc", "id");
	}
}
PHP;

	writePhpQueryCollection(
		$queryPath,
		"Product",
		"Demo\\Query",
		$classCode
	);

	$settings = new Settings(
		$queryPath,
		Settings::DRIVER_SQLITE,
		$databasePath
	);
	$db = new Database($settings);

	$db->executeSql(implode("\n", [
		"create table product(",
		"\tid integer primary key autoincrement,",
		"\tname text not null,",
		"\tprice decimal(10,2) not null",
		")",
	]));

	$productQueries = $db->queryCollection("Product");
	$productQueries->setAppNamespace("Demo\\Query");

	$productQueries->insert("insert", [
		"name" => "Keyboard",
		"price" => 59.99,
	]);
	$productQueries->insert("insert", [
		"name" => "Mouse",
		"price" => 19.99,
	]);
	$productQueries->insert("insert", [
		"name" => "Monitor",
		"price" => 249.99,
	]);

	$rows = $productQueries->fetchAll("getByMinPrice", [
		"minPrice" => 50,
	]);
	foreach($rows as $row) {
		printf("%d %-10s %.2f\n", $row->id, $row->name, $row->getFloat("price"));
	}
}
finally {
	removeDirectory($workspace);
}
