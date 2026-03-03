<?php
use Gt\Database\Connection\Settings;
use Gt\Database\Database;

function createExampleWorkspace(string $exampleName):array {
	$workspace = implode(DIRECTORY_SEPARATOR, [
		sys_get_temp_dir(),
		"phpgt-database-$exampleName-" . uniqid(),
	]);
	$queryPath = $workspace . "/query";
	$databasePath = $workspace . "/example.sqlite";
	mkdir($queryPath, 0775, true);

	return [$workspace, $queryPath, $databasePath];
}

function createExampleDatabase(string $queryPath, string $databasePath):Database {
	$settings = new Settings(
		$queryPath,
		Settings::DRIVER_SQLITE,
		$databasePath
	);

	return new Database($settings);
}

function writeSqlQuery(
	string $queryPath,
	string $collection,
	string $queryName,
	string $sql
):void {
	$collectionPath = "$queryPath/$collection";
	if(!is_dir($collectionPath)) {
		mkdir($collectionPath, 0775, true);
	}
	file_put_contents("$collectionPath/$queryName.sql", $sql);
}

function writePhpQueryCollection(
	string $queryPath,
	string $collectionName,
	string $namespace,
	string $classCode
):void {
	file_put_contents(
		"$queryPath/$collectionName.php",
		"<?php\nnamespace $namespace;\n\n$classCode\n"
	);
}

function removeDirectory(string $directory):void {
	if(!is_dir($directory)) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach($iterator as $path) {
		if($path->isDir()) {
			rmdir($path->getPathname());
		}
		else {
			unlink($path->getPathname());
		}
	}

	rmdir($directory);
}
