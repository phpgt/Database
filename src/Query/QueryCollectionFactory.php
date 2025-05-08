<?php
namespace Gt\Database\Query;

use DirectoryIterator;
use Gt\Database\Connection\Driver;
use Gt\Database\Database;
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
		if(is_null($basePath)) {
			$basePath = $this->basePath;
		}

		if(!is_dir($basePath)) {
			throw new BaseQueryPathDoesNotExistException($basePath);
		}

		/** @var SplFileInfo $fileInfo */
		foreach(new DirectoryIterator($basePath) as $fileInfo) {
			if($fileInfo->isDot()) {
				continue;
			}

			$basename = $fileInfo->getBasename(".php");
			if(strtolower($part) === strtolower($basename)) {
				$realPath = $fileInfo->getRealPath();

				if(empty($parts)) {
					return $realPath;
				}

				return $this->recurseLocateDirectory(
					$parts,
					$realPath
				);
			}
		}

		return null;
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
