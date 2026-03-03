<?php

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("04-dynamic-bindings");

try {
	$db = createExampleDatabase($queryPath, $databasePath);
	$db->executeSql(implode("\n", [
		"create table purchase(",
		"\tid integer primary key,",
		"\tcustomerId text not null,",
		"\tproductId integer not null,",
		"\tcreatedAt text not null",
		")",
	]));

	writeSqlQuery($queryPath, "purchase", "bulkInsert", implode("\n", [
		"insert into purchase(id, customerId, productId, createdAt)",
		"values (?, ?, ?, ?)",
	]));
	writeSqlQuery($queryPath, "purchase", "findIn", implode("\n", [
		"select id, customerId from purchase",
		"where id in (:__dynamicIn)",
		"order by :orderBy",
		"limit :limit offset :offset",
	]));
	writeSqlQuery($queryPath, "purchase", "findByPairs", implode("\n", [
		"select id, customerId, productId from purchase",
		"where :__dynamicOr",
		"order by id",
	]));

	$db->insert("purchase/bulkInsert", 1, "cust_1", 100, "2026-01-01");
	$db->insert("purchase/bulkInsert", 2, "cust_2", 101, "2026-01-02");
	$db->insert("purchase/bulkInsert", 3, "cust_2", 102, "2026-01-03");
	$db->insert("purchase/bulkInsert", 4, "cust_3", 103, "2026-01-04");

	$dynamicInRows = $db->fetchAll("purchase/findIn", [
		"__dynamicIn" => [1, 3, 4],
		"orderBy" => "id desc",
		"limit" => 10,
		"offset" => 0,
	]);
	echo "Dynamic IN results:\n";
	foreach($dynamicInRows as $row) {
		echo "{$row->id} {$row->customerId}\n";
	}

	$dynamicOrRows = $db->fetchAll("purchase/findByPairs", [
		"__dynamicOr" => [
			["customerId" => "cust_2", "productId" => 101],
			["customerId" => "cust_3", "productId" => 103],
		],
	]);
	echo "Dynamic OR results:\n";
	foreach($dynamicOrRows as $row) {
		echo "{$row->id} {$row->customerId} {$row->productId}\n";
	}
}
finally {
	removeDirectory($workspace);
}
