<?php
use Gt\Database\Connection\Settings;
use Gt\Database\Migration\Migrator;

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("05-database-migrations");

try {
	$migrationPath = "$queryPath/_migration";
	mkdir($migrationPath, 0775, true);

	file_put_contents("$migrationPath/0001-create-user.sql", implode("\n", [
		"create table user(",
		"\tid integer primary key autoincrement,",
		"\temail text not null",
		")",
	]));
	file_put_contents("$migrationPath/0002-add-created-at.sql", implode("\n", [
		"alter table user add createdAt text",
	]));

	$baseSettings = new Settings(
		$queryPath,
		Settings::DRIVER_SQLITE,
		$databasePath
	);
	$db = createExampleDatabase($queryPath, $databasePath);
	$migrator = new Migrator($baseSettings, $migrationPath);
	$migrator->createMigrationTable();

	$migrationFiles = $migrator->getMigrationFileList();
	$migrator->checkFileListOrder($migrationFiles);
	$migrator->checkIntegrity($migrationFiles, $migrator->getMigrationCount());
	$migrator->performMigration($migrationFiles, $migrator->getMigrationCount());

	$db->executeSql("insert into user(email, createdAt) values(?, datetime('now'))", [
		"dev@example.com",
	]);
	$result = $db->executeSql("select id, email, createdAt from user limit 1");
	$row = $result->fetch();
	echo "Migrated row: {$row->id} {$row->email} {$row->createdAt}\n";
}
finally {
	removeDirectory($workspace);
}
