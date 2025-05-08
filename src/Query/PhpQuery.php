<?php
namespace Gt\Database\Query;

use Gt\Database\Connection\Driver;

class PhpQuery extends Query {
	private string $functionName;

	public function __construct(string $filePathWithFunction, Driver $driver) {
		[$filePath, $functionName] = explode("::", $filePathWithFunction);
// TODO: Allow PHP files with :: separators to function names
		if(!is_file($filePath)) {
			throw new QueryNotFoundException($filePath);
		}

		$this->filePath = $filePath;
		$this->functionName = $functionName;
		$this->connection = $driver->getConnection();
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	public function getSql(array &$bindings = []):string {
// TODO: Include similarly to page logic files, with optional namespacing (I think...)
	}
}
