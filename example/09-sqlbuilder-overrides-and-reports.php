<?php
use Gt\Database\Connection\Settings;
use Gt\Database\Database;

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("09-sqlbuilder-overrides-and-reports");

try {
	$classCode = <<<PHP
use Gt\SqlBuilder\SelectBuilder;

class Report {
	public function categoryTotals():SelectBuilder {
		return (new SelectBuilder())
			->select(
				"category.name as categoryName",
				"count(product.id) as productCount",
				"round(sum(product.price), 2) as totalValue"
			)
			->from("product")
			->innerJoin("category on category.id = product.categoryId")
			->where(
				"product.price >= :minPrice",
				"product.name != :excludedName"
			)
			->groupBy("category.name")
			->having("count(product.id) >= 1")
			->orderBy("totalValue desc", "category.name");
	}
}
PHP;

	writePhpQueryCollection(
		$queryPath,
		"Report",
		"Demo\\Query",
		$classCode
	);
	writeSqlQuery($queryPath, "Report", "getByCode", implode("\n", [
		"select product.code, product.name, category.name as categoryName, product.price",
		"from product",
		"inner join category on category.id = product.categoryId",
		"where product.code = :code",
		"limit 1",
	]));

	$settings = new Settings(
		$queryPath,
		Settings::DRIVER_SQLITE,
		$databasePath
	);
	$db = new Database($settings);

	$db->executeSql(implode("\n", [
		"create table category(",
		"\tid integer primary key autoincrement,",
		"\tname text not null",
		")",
	]));
	$db->executeSql(implode("\n", [
		"create table product(",
		"\tid integer primary key autoincrement,",
		"\tcategoryId integer not null,",
		"\tcode text not null,",
		"\tname text not null,",
		"\tprice decimal(10,2) not null,",
		"\tforeign key(categoryId) references category(id)",
		")",
	]));
	$db->executeSql("insert into category(name) values('Peripherals'), ('Displays')");
	$db->executeSql(implode("\n", [
		"insert into product(categoryId, code, name, price) values",
		"(1, 'KEY-01', 'Keyboard', 59.99),",
		"(1, 'MOU-01', 'Mouse', 19.99),",
		"(2, 'MON-01', 'Monitor', 249.99),",
		"(2, 'MON-02', 'Portable Display', 179.99)",
	]));

	$reportQueries = $db->queryCollection("Report");
	$reportQueries->setAppNamespace("Demo\\Query");

	$product = $reportQueries->fetch("getByCode", [
		"code" => "MON-01",
	]);
	printf(
		"Override query: %s in %s costs %.2f\n",
		$product->name,
		$product->categoryName,
		$product->getFloat("price")
	);

	$totals = $reportQueries->fetchAll("categoryTotals", [
		"minPrice" => 20,
		"excludedName" => "Discontinued",
	]);
	foreach($totals as $row) {
		printf(
			"Category: %-12s Count: %d Total: %.2f\n",
			$row->categoryName,
			$row->getInt("productCount"),
			$row->getFloat("totalValue")
		);
	}
}
finally {
	removeDirectory($workspace);
}
