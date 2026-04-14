<?php
use GT\Database\Connection\Settings;
use GT\Database\Database;

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("07-php-query-collections");

try {
	$classCode = <<<PHP
class Product {
	public function allByPrefix():string {
		return "select id, name from product where name like :prefix order by id";
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
		"\tname text not null",
		")",
	]));
	$db->executeSql("insert into product(name) values('Keyboard'), ('Mouse'), ('Monitor')");

	$productQueries = $db->queryCollection("Product");
	$productQueries->setAppNamespace("Demo\\Query");
	$rows = $productQueries->fetchAll("allByPrefix", ["prefix" => "M%"]);
	foreach($rows as $row) {
		echo "{$row->id} {$row->name}\n";
	}
}
finally {
	removeDirectory($workspace);
}
