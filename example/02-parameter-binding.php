<?php

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("02-parameter-binding");

try {
	$db = createExampleDatabase($queryPath, $databasePath);
	$db->executeSql(implode("\n", [
		"create table purchase(",
		"\tid integer primary key autoincrement,",
		"\tcustomer text not null,",
		"\ttotal decimal(10,2) not null,",
		"\tplacedAt text not null,",
		"\tisPaid integer not null",
		")",
	]));

	writeSqlQuery($queryPath, "purchase", "insert", implode("\n", [
		"insert into purchase(customer, total, placedAt, isPaid)",
		"values(:customer, :total, :placedAt, :isPaid)",
	]));
	writeSqlQuery($queryPath, "purchase", "findById", "select * from purchase where id = ?");
	writeSqlQuery($queryPath, "purchase", "findByCustomer", implode("\n", [
		"select * from purchase",
		"where customer = :customer and isPaid = :isPaid",
	]));
	writeSqlQuery($queryPath, "purchase", "listByIds", implode("\n", [
		"select id, customer from purchase",
		"where id in (:idList)",
		"order by :orderBy",
		"limit :limit offset :offset",
	]));

	$db->insert("purchase/insert", [
		"customer" => "alice",
		"total" => 12.99,
		"placedAt" => new DateTimeImmutable("2026-01-01 10:30:00"),
		"isPaid" => true,
	]);
	$db->insert("purchase/insert", [
		"customer" => "bob",
		"total" => 42.00,
		"placedAt" => new DateTimeImmutable("2026-01-02 09:00:00"),
		"isPaid" => false,
	]);
	$db->insert("purchase/insert", [
		"customer" => "alice",
		"total" => 5.75,
		"placedAt" => new DateTimeImmutable("2026-01-03 16:20:00"),
		"isPaid" => true,
	]);

	$first = $db->fetch("purchase/findById", 1);
	echo "Question-mark binding -> customer #1: {$first->customer}\n";

	$alicePaid = $db->fetchAll("purchase/findByCustomer", [
		"customer" => "alice",
		"isPaid" => true,
	]);
	echo "Named placeholders -> paid rows for alice: " . count($alicePaid) . "\n";

	$subset = $db->fetchAll("purchase/listByIds", [
		"idList" => [1, 2, 3],
		"orderBy" => "id desc",
		"limit" => 2,
		"offset" => 0,
	]);
	foreach($subset as $row) {
		echo "Special bindings row: {$row->id} {$row->customer}\n";
	}
}
finally {
	removeDirectory($workspace);
}
