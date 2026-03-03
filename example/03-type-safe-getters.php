<?php

chdir(dirname(__DIR__));
require "vendor/autoload.php";
require __DIR__ . "/bootstrap.php";

[$workspace, $queryPath, $databasePath] = createExampleWorkspace("03-type-safe-getters");

try {
	$db = createExampleDatabase($queryPath, $databasePath);
	$db->executeSql(implode("\n", [
		"create table metric(",
		"\tid integer primary key autoincrement,",
		"\tname text not null,",
		"\tisHealthy integer not null,",
		"\tcounter integer not null,",
		"\tratio float not null,",
		"\trecordedAt integer not null",
		")",
	]));

	$db->executeSql(implode("\n", [
		"insert into metric(name, isHealthy, counter, ratio, recordedAt)",
		"values",
		"('api', 1, 200, 0.98, strftime('%s', '2026-02-01 00:00:00')),",
		"('jobs', 0, 12, 0.31, strftime('%s', '2026-02-01 00:05:00'))",
	]));

	writeSqlQuery($queryPath, "metric", "getHealthByName", "select isHealthy from metric where name = ?");
	writeSqlQuery($queryPath, "metric", "getAllCounters", "select counter from metric order by id");
	writeSqlQuery($queryPath, "metric", "getRatioByName", "select ratio from metric where name = ?");
	writeSqlQuery($queryPath, "metric", "getLatestTime", "select recordedAt from metric order by recordedAt desc limit 1");
	writeSqlQuery($queryPath, "metric", "getRowByName", "select * from metric where name = ?");

	$isApiHealthy = $db->fetchBool("metric/getHealthByName", "api");
	$counters = $db->fetchAllInt("metric/getAllCounters");
	$jobsRatio = $db->fetchFloat("metric/getRatioByName", "jobs");
	$latestTime = $db->fetchDateTime("metric/getLatestTime");
	$row = $db->fetch("metric/getRowByName", "api");

	echo "fetchBool: " . ($isApiHealthy ? "true" : "false") . "\n";
	echo "fetchAllInt: " . implode(", ", $counters) . "\n";
	echo "fetchFloat: $jobsRatio\n";
	echo "fetchDateTime: " . $latestTime->format(DATE_ATOM) . "\n";
	echo "Row getters: name={$row->getString("name")} counter={$row->getInt("counter")}\n";
}
finally {
	removeDirectory($workspace);
}
