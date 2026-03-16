<?php
namespace Gt\Database\Query;

use Gt\Database\Connection\Driver;
use Stringable;

class PhpQuery extends Query {
	private string $className;
	private string $functionName;
	private string $appNamespace = "\\App\\Query";
	private mixed $instance;

	public function __construct(string $filePathWithFunction, Driver $driver) {
		[$filePath, $functionName] = explode("::", $filePathWithFunction);
		if(!is_file($filePath)) {
			throw new QueryNotFoundException($filePath);
		}

		$this->filePath = $filePath;
		$this->className = pathinfo($filePath, PATHINFO_FILENAME);
		$this->functionName = $functionName;
		$this->connection = $driver->getConnection();

		require_once($filePath);
	}

	public function setAppNamespace(string $namespace):void {
		if(!str_starts_with($namespace, "\\")) {
			$namespace = "\\$namespace";
		}

		$this->appNamespace = $namespace;
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	public function getSql(array &$bindings = []):string {
		$fqClassName = $this->appNamespace . "\\" . $this->className;
		if(!class_exists($fqClassName)) {
			throw new PhpQueryClassNotFoundException($fqClassName);
		}

		if(!isset($this->instance)) {
			$this->instance = new $fqClassName();
		}

		$sql = $this->instance->{$this->functionName}();
		if($sql instanceof Stringable) {
			return (string)$sql;
		}

		return $sql;
	}
}
