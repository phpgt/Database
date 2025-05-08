<?php
namespace Gt\Database\Query;

use Gt\Database\Connection\Driver;

class SqlQuery extends Query {
	public function __construct(string $filePath, Driver $driver) {
		if(!is_file($filePath)) {
			throw new QueryNotFoundException($filePath);
		}

		$this->filePath = $filePath;
		$this->connection = $driver->getConnection();
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	public function getSql(array &$bindings = []):string {
		$sql = file_get_contents($this->getFilePath());
		$sql = $this->injectDynamicBindings(
			$sql,
			$bindings
		);
		$sql = $this->injectSpecialBindings(
			$sql,
			$bindings
		);
		return $sql;
	}
}
