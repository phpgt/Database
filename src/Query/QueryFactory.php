<?php
namespace Gt\Database\Query;

use SplFileInfo;
use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use Gt\Database\Connection\Driver;
use Gt\Database\Connection\ConnectionNotConfiguredException;

class QueryFactory {
	const CLASS_FOR_EXTENSION = [
		"sql" => SqlQuery::class,
		"php" => PhpQuery::class,
	];

	public function __construct(
		protected string $queryHolder,
		protected Driver $driver
	) {}

	public function findQueryFilePath(string $name):string {
		if(is_dir($this->queryHolder)) {
			foreach(new DirectoryIterator($this->queryHolder) as $fileInfo) {
				if($fileInfo->isDot()
					|| $fileInfo->isDir()) {
					continue;
				}

				$this->getExtensionIfValid($fileInfo);
				$fileNameNoExtension = strtok($fileInfo->getFilename(), ".");
				if($fileNameNoExtension !== $name) {
					continue;
				}

				return $fileInfo->getRealPath();
			}
		}
		elseif(is_file($this->queryHolder)) {
			return "$this->queryHolder::$name";
		}

		throw new QueryNotFoundException($this->queryHolder . ", " . $name);
	}

	public function create(string $name):Query {
		$query = null;

		try {
			$queryFilePath = $this->findQueryFilePath($name);
			$queryClass = $this->getQueryClassForFilePath($queryFilePath);
			$query = new $queryClass($queryFilePath, $this->driver);
		}
		catch(InvalidArgumentException $exception) {
			$this->throwCorrectException($exception);
		}

		return $query;
	}

	public function getQueryClassForFilePath(string $filePath):string {
		$fileInfo = new SplFileInfo($filePath);
		$ext = $this->getExtensionIfValid($fileInfo);

		return self::CLASS_FOR_EXTENSION[$ext];
	}

	protected function getExtensionIfValid(SplFileInfo $fileInfo):string {
		$ext = strtolower($fileInfo->getExtension());
		$ext = strstr($ext, ":", true) ?: $ext;

		if(!array_key_exists($ext, self::CLASS_FOR_EXTENSION)) {
			throw new QueryFileExtensionException($ext);
		}

		return $ext;
	}

	protected function throwCorrectException(Exception $exception):void {
		$message = $exception->getMessage();

		switch(get_class($exception)) {
		case InvalidArgumentException::class:
			$matches = [];
			if(1 !== preg_match(
					"/Database \[(.+)\] not configured/", $message, $matches)) {
				throw $exception;
			}

			$connectionName = $matches[1];
			throw new ConnectionNotConfiguredException($connectionName);

		default:
			throw $exception;
		}
	}
}
