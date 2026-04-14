<?php
use GT\Database\Connection\Settings;
use GT\Database\Database;

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

$workspace = implode(DIRECTORY_SEPARATOR, [
	sys_get_temp_dir(),
	"phpgt-database-06-multiple-connections-" . uniqid(),
]);

try {
	$catalogQueryPath = "$workspace/catalog-query";
	$analyticsQueryPath = "$workspace/analytics-query";
	$catalogDbPath = "$workspace/catalog.sqlite";
	$analyticsDbPath = "$workspace/analytics.sqlite";

	mkdir($catalogQueryPath, 0775, true);
	mkdir($analyticsQueryPath, 0775, true);

	writeSqlQuery($catalogQueryPath, "product", "insert", "insert into product(name) values(?)");
	writeSqlQuery($catalogQueryPath, "product", "count", "select count(*) from product");
	writeSqlQuery($analyticsQueryPath, "event", "insert", "insert into event(action) values(?)");
	writeSqlQuery($analyticsQueryPath, "event", "count", "select count(*) from event");

	$catalogSettings = (new Settings(
		$catalogQueryPath,
		Settings::DRIVER_SQLITE,
		$catalogDbPath
	))->withConnectionName("catalog");

	$analyticsSettings = (new Settings(
		$analyticsQueryPath,
		Settings::DRIVER_SQLITE,
		$analyticsDbPath
	))->withConnectionName("analytics");

	$db = new Database($catalogSettings, $analyticsSettings);

	$db->executeSql("create table product(id integer primary key autoincrement, name text)", [], "catalog");
	$db->executeSql("create table event(id integer primary key autoincrement, action text)", [], "analytics");

	$catalog = $db->queryCollection("product", "catalog");
	$analytics = $db->queryCollection("event", "analytics");

	$catalog->insert("insert", "Keyboard");
	$catalog->insert("insert", "Mouse");
	echo "Catalog count: " . $catalog->fetchInt("count") . "\n";

	$analytics->insert("insert", "login");
	echo "Analytics count: " . $analytics->fetchInt("count") . "\n";
}
finally {
	removeDirectory($workspace);
}
