<?php
namespace Gt\Database\Test\Helper;

class Helper {
	const COUNT_PATH_PROVIDER = 10;

	public static function getTmpDir() {
		$dir = implode(DIRECTORY_SEPARATOR, [
			sys_get_temp_dir(),
			"phpgt",
			"database",
			uniqid()
		]);
		return $dir;
	}

	public static function deleteDir(string $dir) {
		self::recursiveRemove($dir);
	}

	public static function recursiveRemove(string $dir) {
		if(!file_exists($dir)) {
			return;
		}
		$scanDir = array_diff(
			scandir($dir),
			array('.', '..')
		);

		foreach($scanDir as $file) {
			if(is_dir("$dir/$file")) {
				self::recursiveRemove("$dir/$file");
			}
			else {
				@unlink("$dir/$file");
			}
		}
		@rmdir($dir);
	}


	public static function queryPathExistsProvider() {
		return self::queryPathProvider(true);
	}

	public static function queryPathNotExistsProvider() {
		return self::queryPathProvider(false);
	}

	public static function queryPathExtensionNotValidProvider() {
		return self::queryPathProvider(true, null);
	}

	private static function queryPathProvider(bool $exists, $extension = "sql") {
		$data = [];

		foreach(self::queryCollectionPathProvider(true) as $qcName => $qcData) {
			$queryCollectionPath = $qcData[1];

			if(is_null($extension)) {
				$extension = uniqid();
			}

			$queryName = uniqid("query");
			$filename = "$queryName.$extension";
			$filePath = implode(DIRECTORY_SEPARATOR, [
				$queryCollectionPath,
				$filename,
			]);

			if($exists) {
				touch($filePath);
			}

			$data [] = [
				$queryName,
				$queryCollectionPath,
				$filePath,
			];
		}

		return $data;
	}

	public static function queryPathNestedProvider() {
		$data = [];

		$n = rand(2, 6);

		foreach(self::queryCollectionPathProvider(true, $n)
		as $qcName => $qcData) {
			$queryCollectionPath = $qcData[1];

			$queryName = uniqid("query");
			$filename = "$queryName.sql";
			$filePath = implode(DIRECTORY_SEPARATOR, [
				$queryCollectionPath,
				$filename,
			]);


			touch($filePath);

			$basePos = strpos($filePath, DIRECTORY_SEPARATOR . "query" . DIRECTORY_SEPARATOR)
				+ strlen("/query/");
			$nameParts = [];
			$nameString = substr(
				$filePath,
				$basePos
			);
			foreach(explode(DIRECTORY_SEPARATOR, $nameString) as $n) {
				$nameParts []= strtok($n, ".");
			}
			$basePath = substr($filePath, 0, $basePos - 1);

			$data [] = [
				$nameParts,
				$filePath,
				$basePath,
			];
		}

		return $data;
	}

	public static function queryCollectionPathExistsProvider() {
		return self::queryCollectionPathProvider(true);
	}

	public static function queryCollectionPathNotExistsProvider() {
		return self::queryCollectionPathProvider(false);
	}

	private static function queryCollectionPathProvider(
		bool $exists,
		int $nested = 1
	) {
		$data = [];

		for($i = 0; $i < self::COUNT_PATH_PROVIDER; ++$i) {
			$nameParts = [];

			for($n = 0; $n < $nested; $n++) {
				$uniqid = uniqid();
				if(is_numeric($uniqid[0])) {
					$uniqid = chr(rand(97, 102)) . $uniqid; // Add a random lowercase letter (a-f) to the beginning
				}
				$nameParts []= $uniqid;
			}

			$name = implode(DIRECTORY_SEPARATOR, $nameParts);
			$path = implode(DIRECTORY_SEPARATOR, [
				self::getTmpDir(),
				"query",
				$name,
			]);

			if($exists) {
				mkdir($path, 0775, true);
			}

			$data [] = [
				$name,
				$path
			];
		}

		return $data;
	}
}
