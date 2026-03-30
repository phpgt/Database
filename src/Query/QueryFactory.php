<?php
namespace Gt\Database\Query;

use SplFileInfo;
use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
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
			$queryFilePath = $this->findQueryFilePathInDirectory(
				$this->queryHolder,
				$name
			);
			if($queryFilePath) {
				return $queryFilePath;
			}
		}
		elseif(is_file($this->queryHolder)) {
			$overrideDirectory = $this->locateOverrideDirectory($this->queryHolder);
			if($overrideDirectory && is_dir($overrideDirectory)) {
				$queryFilePath = $this->findQueryFilePathInDirectory(
					$overrideDirectory,
					$name
				);
				if($queryFilePath) {
					if($this->classDefinesPublicMethod($this->queryHolder, $name)) {
						throw new QueryOverrideConflictException(
							"Query override conflicts with class method: "
							. pathinfo($this->queryHolder, PATHINFO_FILENAME)
							. "::$name"
						);
					}

					return $queryFilePath;
				}
			}

			return "$this->queryHolder::$name";
		}

		throw new QueryNotFoundException($this->queryHolder . ", " . $name);
	}

	protected function findQueryFilePathInDirectory(
		string $directory,
		string $name,
	):?string {
		$invalidMatchExtension = null;

		foreach(new DirectoryIterator($directory) as $fileInfo) {
			if($fileInfo->isDot()
				|| $fileInfo->isDir()) {
				continue;
			}

			$fileNameNoExtension = strtok($fileInfo->getFilename(), ".");
			if($fileNameNoExtension !== $name) {
				continue;
			}

			try {
				$this->getExtensionIfValid($fileInfo);
				return $fileInfo->getRealPath();
			}
			catch(QueryFileExtensionException) {
				$invalidMatchExtension = strtolower($fileInfo->getExtension());
			}
		}

		if(!is_null($invalidMatchExtension)) {
			throw new QueryFileExtensionException($invalidMatchExtension);
		}

		return null;
	}

	protected function locateOverrideDirectory(string $classFilePath):?string {
		$baseName = pathinfo($classFilePath, PATHINFO_FILENAME);
		foreach(new DirectoryIterator(dirname($classFilePath)) as $fileInfo) {
			if($fileInfo->isDot() || !$fileInfo->isDir()) {
				continue;
			}

			if(strtolower($fileInfo->getFilename()) === strtolower($baseName)) {
				return $fileInfo->getRealPath();
			}
		}

		return null;
	}

	protected function classDefinesPublicMethod(
		string $classFilePath,
		string $methodName,
	):bool {
		$fqClassName = $this->resolveDeclaredClassName($classFilePath);
		if(!$fqClassName) {
			return false;
		}

		require_once($classFilePath);
		$reflectionClass = new ReflectionClass($fqClassName);
		if(!$reflectionClass->hasMethod($methodName)) {
			return false;
		}

		return $reflectionClass->getMethod($methodName)->isPublic();
	}

	protected function resolveDeclaredClassName(string $classFilePath):?string {
		$tokens = token_get_all(file_get_contents($classFilePath));
		$namespace = "";
		$className = null;

		foreach($tokens as $i => $token) {
			if(!is_array($token)) {
				continue;
			}

			if($token[0] === T_NAMESPACE) {
				$namespace = $this->parseNamespaceTokens($tokens, $i + 1);
			}
			elseif($token[0] === T_CLASS) {
				$className = $this->parseClassNameTokens($tokens, $i + 1);
				if($className) {
					break;
				}
			}
		}

		if(!$className) {
			return null;
		}

		return ltrim("$namespace\\$className", "\\");
	}

	/** @param array<int, array{0:int, 1:string, 2:int}|string> $tokens */
	protected function parseNamespaceTokens(array $tokens, int $offset):string {
		$namespace = "";

		for($i = $offset; isset($tokens[$i]); $i++) {
			$token = $tokens[$i];
			if(is_string($token)) {
				if($token === ";" || $token === "{") {
					break;
				}
				continue;
			}

			if(in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED])) {
				$namespace .= $token[1];
			}
		}

		return $namespace;
	}

	/** @param array<int, array{0:int, 1:string, 2:int}|string> $tokens */
	protected function parseClassNameTokens(array $tokens, int $offset):?string {
		for($i = $offset; isset($tokens[$i]); $i++) {
			$token = $tokens[$i];
			if(is_array($token) && $token[0] === T_STRING) {
				return $token[1];
			}
		}

		return null;
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
		$matches = [];
		if(1 !== preg_match("/Database \[(.+)\] not configured/", $message, $matches)) {
			throw $exception;
		}

		$connectionName = $matches[1];
		throw new ConnectionNotConfiguredException($connectionName);
	}
}
