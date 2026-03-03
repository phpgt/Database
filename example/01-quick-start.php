<?php
use Gt\Database\Result\Row;

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("01-quick-start");

try {
	$db = createExampleDatabase($queryPath, $databasePath);
	$db->executeSql(implode("\n", [
		"create table product(",
		"\tid integer primary key autoincrement,",
		"\tname text not null,",
		"\tprice decimal(10,2) not null",
		")",
	]));

	writeSqlQuery($queryPath, "product", "insert", implode("\n", [
		"insert into product(name, price)",
		"values(:name, :price)",
	]));
	writeSqlQuery($queryPath, "product", "getById", implode("\n", [
		"select id, name, price",
		"from product",
		"where id = ?",
	]));
	writeSqlQuery($queryPath, "product", "getAll", implode("\n", [
		"select id, name, price",
		"from product",
		"order by id",
	]));
	writeSqlQuery($queryPath, "product", "raisePrices", implode("\n", [
		"update product",
		"set price = price + :delta",
	]));
	writeSqlQuery($queryPath, "product", "removeOverPrice", implode("\n", [
		"delete from product",
		"where price > :maxPrice",
	]));

	$firstId = $db->insert("product/insert", [
		"name" => "Keyboard",
		"price" => 59.99,
	]);
	$db->insert("product/insert", [
		"name" => "Mouse",
		"price" => 19.99,
	]);
	$db->insert("product/insert", [
		"name" => "Monitor",
		"price" => 249.99,
	]);

	$firstProduct = $db->fetch("product/getById", (int)$firstId);
	printf("Inserted first product: %s (#%d)\n", $firstProduct->name, $firstProduct->id);

	$allProducts = $db->fetchAll("product/getAll");
	/** @var Row $row */
	foreach($allProducts as $row) {
		printf("Product: %-10s  Price: %.2f\n", $row->name, $row->getFloat("price"));
	}

	$updatedRows = $db->update("product/raisePrices", ["delta" => 5.00]);
	echo "Rows updated: $updatedRows\n";

	$deletedRows = $db->delete("product/removeOverPrice", ["maxPrice" => 200]);
	echo "Rows deleted: $deletedRows\n";
}
finally {
	removeDirectory($workspace);
}
