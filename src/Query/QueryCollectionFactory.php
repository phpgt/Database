<?php
namespace GT\Database\Query;

use DirectoryIterator;
use GT\Database\Connection\Driver;
use GT\Database\Database;
use SplFileInfo;

class QueryCollectionFactory {
	protected Driver $driver;
	protected string $basePath;
	/** @var array<string, QueryCollection> */
	protected array $queryCollectionCache;

	public function __construct(Driver $driver) {
		$this->driver = $driver;
		$this->basePath = $this->driver->getBaseDirectory();
		$this->queryCollectionCache = [];
	}

	public function create(string $name):QueryCollection {
		if(!isset($this->queryCollectionCache[$name])) {
			$this->queryCollectionCache[$name] = $this->findQueryCollection(
				$name,
				$this->driver,
			);
		}

		return $this->queryCollectionCache[$name];
	}

	public function directoryExists(string $name):bool {
		return !is_null($this->locateDirectory($name));
	}

	/**
	 * Case-insensitive attempt to match the provided directory name with a
	 * directory within the basePath.
	 * @param  string $name Name of the QueryCollection
	 * @return string       Absolute path to directory
	 */
	protected function locateDirectory(string $name):?string {
		$parts = [$name];

		foreach(Database::COLLECTION_SEPARATOR_CHARACTERS as $char) {
			if(!strstr($name, $char)) {
				continue;
			}

			$parts = explode($char, $name);
			break;
		}

		return $this->recurseLocateDirectory($parts);
	}

	/** @param array<string> $parts */
	protected function recurseLocateDirectory(
		array $parts,
		?string $basePath = null
	):?string {
		$part = array_shift($parts);
		$basePath = $this->resolveBasePath($basePath);
		[$matchingFilePath, $matchingDirectoryPath] = $this->findMatchingPaths(
			$basePath,
			$part,
		);

		if(empty($parts)) {
			return $matchingFilePath ?? $matchingDirectoryPath;
		}

		if($matchingDirectoryPath) {
			return $this->recurseLocateDirectory(
				$parts,
				$matchingDirectoryPath
			);
		}

		return null;
	}

	protected function resolveBasePath(?string $basePath):string {
		$basePath ??= $this->basePath;

		if(!is_dir($basePath)) {
			throw new BaseQueryPathDoesNotExistException($basePath);
		}

		return $basePath;
	}

	/** @return array{0:?string, 1:?string} */
	protected function findMatchingPaths(
		string $basePath,
		string $part,
	):array {
		$matchingFilePath = null;
		$matchingDirectoryPath = null;
		foreach(new DirectoryIterator($basePath) as $fileInfo) {
			if($fileInfo->isDot()) {
				continue;
			}

			if(strtolower($part) !== strtolower($fileInfo->getBasename(".php"))) {
				continue;
			}

			if($fileInfo->isDir() && !$matchingDirectoryPath) {
				$matchingDirectoryPath = $fileInfo->getRealPath();
				continue;
			}

			if($fileInfo->isFile() && !$matchingFilePath) {
				$matchingFilePath = $fileInfo->getRealPath();
			}
		}

		return [$matchingFilePath, $matchingDirectoryPath];
	}

	protected function getDefaultBasePath():string {
		return getcwd();
	}

	private function findQueryCollection(
		string $name,
		Driver $driver,
	):QueryCollection {
		$path = $this->locateDirectory($name);

		if($path && is_dir($path)) {
			$this->queryCollectionCache[$name] = new QueryCollectionDirectory(
				$path,
				$driver,
			);
		}
		elseif($path && is_file($path)) {
			$this->queryCollectionCache[$name] = new QueryCollectionClass(
				$path,
				$driver,
			);
		}
		else {
			throw new QueryCollectionNotFoundException($name);
		}

		return $this->queryCollectionCache[$name];
	}

}
