<?php
namespace Gt\Database\Query;

use Gt\Database\Connection\Connection;
use Gt\Database\Result\ResultSet;
use PDO;
use PDOException;
use PDOStatement;
use PHPSQLParser\lexer\PHPSQLLexer;

abstract class Query {
	const SPECIAL_BINDINGS = [
		"field" => ["groupBy", "orderBy"],
		"int" => ["limit", "offset"],
		"string" => ["infileName"],
	];

	protected string $filePath;
	protected Connection $connection;



	public function getFilePath():string {
		return $this->filePath;
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	abstract public function getSql(array &$bindings = []):string;

	/** @param array<string, mixed>|array<mixed> $bindings */
	public function execute(array $bindings = []):ResultSet {
		$bindings = $this->flattenBindings($bindings);

		$pdo = $this->preparePdo();
		$totalSql = $this->getSql($bindings);

		$lexer = new PHPSQLLexer();
		$splitSqlQueryList = [];
		$currentQuery = "";
		foreach($lexer->split($totalSql) as $token) {
			if($token === ";") {
				array_push($splitSqlQueryList, $currentQuery);
				$currentQuery = "";
				continue;
			}

			$currentQuery .= $token;
		}
		if($currentQuery) {
			array_push($splitSqlQueryList, $currentQuery);
		}

		$statement = $lastInsertId = null;
		foreach($splitSqlQueryList as $sql) {
			$sql = trim($sql);
			if(!$sql) {
				continue;
			}
			$statement = $this->prepareStatement($pdo, $sql);
			$preparedBindings = $this->prepareBindings($bindings);
			$preparedBindings = $this->ensureParameterCharacter($preparedBindings);
			$preparedBindings = $this->removeUnusedBindings($preparedBindings, $sql);

			try {
				$statement->execute($preparedBindings);
				$lastInsertId = $pdo->lastInsertId();
			}
			catch(PDOException $exception) {
				throw new PreparedStatementException(
					$exception->getMessage() . " (" . $exception->getCode(),
					0,
					$exception
				);
			}
		}


		return new ResultSet($statement, $lastInsertId);
	}

	public function prepareStatement(PDO $pdo, string $sql):PDOStatement {
		try {
			return $pdo->prepare($sql);
		}
		catch(PDOException $exception) {
			throw new PreparedStatementException(
				$exception->getMessage(),
				(int)$exception->getCode(),
				$exception
			);
		}
	}

	/**
	 * Certain words are reserved for use by different SQL engines, such as "limit"
	 * and "offset", and can't be used by the driver as bound parameters. This
	 * function returns the SQL for the query after replacing the bound parameters
	 * manually using string replacement.
	 *
	 * @param array<string, mixed>|array<mixed> $bindings
	 */
	public function injectSpecialBindings(
		string $sql,
		array $bindings
	):string {
		foreach(self::SPECIAL_BINDINGS as $type => $specialList) {
			foreach($specialList as $special) {
				$specialPlaceholder = ":" . $special;

				if(!array_key_exists($special, $bindings)) {
					continue;
				}

				$replacement = "";
				if($type !== "string") {
					$replacement = $this->escapeSpecialBinding(
						$bindings[$special],
						$special
					);
				}

				if($type === "field") {
					$words = explode(" ", $bindings[$special]);
					$words[0] = "`" . $words[0] . "`";
					$replacement = implode(" ", $words);
				}
				elseif($type === "string") {
					$replacement = "'" . $bindings[$special] . "'";
				}

				$sql = str_replace(
					$specialPlaceholder,
					$replacement,
					$sql
				);
				unset($bindings[$special]);
			}
		}

		foreach($bindings as $key => $value) {
			if(is_array($value)) {
				$inString = "";

				foreach(array_keys($value) as $innerKey) {
					$newKey = $key . "__" . $innerKey;
					$keyParamString = ":$newKey";
					$inString .= "$keyParamString, ";
				}

				$inString = rtrim($inString, " ,");
				$sql = str_replace(
					":$key",
					$inString,
					$sql
				);
			}
		}

		return $sql;
	}

	/** @param array<string, string|array<string, string>> $data */
	public function injectDynamicBindings(string $sql, array &$data):string {
		$sql = $this->injectDynamicBindingsValueSet($sql, $data);
		$sql = $this->injectDynamicIn($sql, $data);
		$sql = $this->injectDynamicOr($sql, $data);
		return trim($sql);
	}

	/** @param array<string, string|array<string, string|array<string>>> $data */
	private function injectDynamicBindingsValueSet(string $sql, array &$data):string {
		$pattern = '/\(\s*:__dynamicValueSet\s\)/';
		if(!preg_match($pattern, $sql, $matches)) {
			return $sql;
		}
		if(!isset($data["__dynamicValueSet"])) {
			return $sql;
		}

		$replacementRowList = [];
		foreach($data["__dynamicValueSet"] as $i => $kvp) {
			$indexedRow = [];
			foreach($kvp as $key => $value) {
				$indexedKey = $key . "_" . str_pad($i, 5, "0", STR_PAD_LEFT);
				array_push($indexedRow, $indexedKey);

				$data[$indexedKey] = $value;
			}
			unset($data[$i]);
			array_push($replacementRowList, $indexedRow);
		}
		unset($data["__dynamicValueSet"]);

		$replacementString = "";
		foreach($replacementRowList as $i => $indexedKeyList) {
			if($i > 0) {
				$replacementString .= ",\n";
			}
			$replacementString .= "(";
			foreach($indexedKeyList as $j => $key) {
				if($j > 0) {
					$replacementString .= ",";
				}
				$replacementString .= "\n\t:$key";
			}
			$replacementString .= "\n)";
		}

		return str_replace($matches[0], $replacementString, $sql);
	}

	/** @param array<string, string|array<string, string>> $data */
	private function injectDynamicIn(string $sql, array &$data):string {
		$pattern = '/\(\s*:__dynamicIn\s\)/';
		if(!preg_match($pattern, $sql, $matches)) {
			return $sql;
		}
		if(!isset($data["__dynamicIn"])) {
			return $sql;
		}

		foreach($data["__dynamicIn"] as $i => $value) {
			if(is_string($value)) {
				$value = str_replace("'", "''", $value);
				$data["__dynamicIn"][$i] = "'$value'";
			}
		}

		$replacementString = implode(", ", $data["__dynamicIn"]);
		unset($data["__dynamicIn"]);
		return str_replace($matches[0], "( $replacementString )", $sql);
	}

	/** @param array<string, string|array<string, array<string>>> $data */
	private function injectDynamicOr(string $sql, array &$data):string {
		$pattern = '/:__dynamicOr/';
		if(!preg_match($pattern, $sql, $matches)) {
			return $sql;
		}
		if(!isset($data["__dynamicOr"])) {
			return $sql;
		}

		$replacementString = "";
		foreach($data["__dynamicOr"] as $kvp) {
			$conditionString = "";
			foreach($kvp as $key => $value) {
				if(is_string($value)) {
					$value = str_replace("'", "''", $value);
					$value = "'$value'";
				}

				if($conditionString) {
					$conditionString .= " and ";
				}
				$conditionString .= "`$key` = $value";
			}

			if($replacementString) {
				$replacementString .= " or\n";
			}
			$replacementString .= "\t($conditionString)";
		}

		$replacementString = "\n(\n$replacementString\n)\n";
		return str_replace($matches[0], $replacementString, $sql);
	}

	/**
	 * $bindings can either be :
	 * 1) An array of individual values for binding to the question mark placeholder,
	 * passed in as variable arguments.
	 * 2) An array containing subarrays containing key-value-pairs for binding to
	 * named placeholders.
	 *
	 * Due to the use of variable arguments on the Database and QueryCollection classes,
	 * key-value-pair bindings may be double or triple nested at this point.
	 *
	 * @param array<string, mixed>|array<mixed> $bindings
	 * @return array<string, mixed>|array<mixed>
	 */
	protected function flattenBindings(array $bindings):array {
		if(!isset($bindings[0])) {
			return $bindings;
		}

		if(is_object($bindings[0])
		&& method_exists($bindings[0], "toArray")) {
			$bindings = array_map(function($element) {
				if(method_exists($element, "toArray")) {
					return $element->toArray();
				}

				return $element;
			}, $bindings);
		}

		$flatArray = [];
		foreach($bindings as $binding) {
			while(isset($binding[0])
			&& is_array($binding[0])) {
				$merged = [];
				foreach($binding as $innerValue) {
					$merged = array_merge(
						$merged,
						$innerValue
					);
				}

				$binding = $merged;
			}

			if(!is_array($binding)) {
				$binding = [$binding];
			}

			$flatArray = array_merge($flatArray, $binding);
		}

		return $flatArray;
	}

	/**
	 * @param array<string, mixed>|array<mixed> $bindings
	 * @return array<string, string>|array<string>
	 */
	public function prepareBindings(array $bindings):array {
		foreach($bindings as $key => $value) {
			if(is_bool($value)) {
				$bindings[$key] = (int)$value;
			}
			if($value instanceof DateTimeInterface) {
				$bindings[$key] = $value->format("Y-m-d H:i:s");
			}
			if(is_array($value)) {
				foreach($value as $i => $innerValue) {
					$newKey = $key . "__" . $i;
					$bindings[$newKey] = $innerValue;
				}
				unset($bindings[$key]);
			}
		}

		return $bindings;
	}

	/**
	 * @param array<string, mixed>|array<mixed> $bindings
	 * @return array<string, mixed>|array<mixed>
	 */
	public function ensureParameterCharacter(array $bindings):array {
		if($this->bindingsEmptyOrNonAssociative($bindings)) {
			return $bindings;
		}

		foreach($bindings as $key => $value) {
			if(substr($key, 0, 1) !== ":") {
				$bindings[":" . $key] = $value;
				unset($bindings[$key]);
			}
		}

		return $bindings;
	}

	/**
	 * @param array<string, mixed>|array<mixed> $bindings
	 * @return array<string, mixed>|array<mixed>
	 */
	public function removeUnusedBindings(array $bindings, string $sql):array {
		if($this->bindingsEmptyOrNonAssociative($bindings)) {
			return $bindings;
		}

		foreach(array_keys($bindings) as $key) {
			if(!preg_match("/$key(\W|\$)/", $sql)) {
				unset($bindings[$key]);
			}
		}

		return $bindings;
	}

	/**  @param array<string, mixed>|array<mixed> $bindings */
	public function bindingsEmptyOrNonAssociative(array $bindings):bool {
		return $bindings === []
			|| array_keys($bindings) === range(
				0,
				count($bindings) - 1);
	}

	protected function preparePdo():PDO {
		$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $this->connection;
	}

	/** @noinspection PhpUnusedParameterInspection */
	protected function escapeSpecialBinding(
		string $value,
		string $type,
	):string {
		$value = preg_replace(
			"/[^0-9a-z,'\"`\s]/i",
			"",
			$value
		);

// TODO: In v2 we will properly parse the different parts of the special bindings.
// See https://github.com/PhpGt/Database/issues/117
//		switch($type) {
// [GROUP BY {col_name | expr | position}, ... [WITH ROLLUP]]
//		case "groupBy":
//			break;
//
// [ORDER BY {col_name | expr | position}
//		case "orderBy":
//			break;
//
// [LIMIT {[offset,] row_count | row_count OFFSET offset}]
//		case "limit":
//			break;
//
// [LIMIT {[offset,] row_count | row_count OFFSET offset}]
//		case "offset":
//			break;
//		}

		return (string)$value;
	}
}
