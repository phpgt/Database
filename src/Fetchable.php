<?php
namespace GT\Database;

use DateTimeImmutable;
use DateTimeInterface;
use GT\Database\Result\ResultSet;
use GT\Database\Result\Row;

trait Fetchable {
	public function fetch(string $queryName, mixed...$bindings):?Row {
		/** @var ResultSet $result */
		$result = $this->query($queryName, ...$bindings);
		return $result->current();
	}

	public function fetchAll(string $queryName, mixed...$bindings):ResultSet {
		return $this->query($queryName, ...$bindings);
	}

	public function fetchBool(string $queryName, mixed...$bindings):?bool {
		return $this->fetchTyped(
			Type::BOOL,
			$queryName,
			$bindings
		);
	}

	public function fetchString(string $queryName, mixed...$bindings):?string {
		return $this->fetchTyped(
			Type::STRING,
			$queryName,
			$bindings
		);
	}

	public function fetchInt(string $queryName, mixed...$bindings):?int {
		return $this->fetchTyped(
			Type::INT,
			$queryName,
			$bindings
		);
	}

	public function fetchFloat(string $queryName, mixed...$bindings):?float {
		return $this->fetchTyped(
			Type::FLOAT,
			$queryName,
			$bindings
		);
	}

	public function fetchDateTime(string $queryName, mixed...$bindings):?DateTimeInterface {
		return $this->fetchTyped(
			Type::DATETIME,
			$queryName,
			$bindings
		);
	}

	/** @return bool[] */
	public function fetchAllBool(string $queryName, mixed...$bindings):array {
		return $this->fetchAllTyped(
			Type::BOOL,
			$queryName,
			$bindings
		);
	}

	/** @return string[] */
	public function fetchAllString(string $queryName, mixed...$bindings):array {
		return $this->fetchAllTyped(
			Type::STRING,
			$queryName,
			$bindings
		);
	}

	/** @return int[] */
	public function fetchAllInt(string $queryName, mixed...$bindings):array {
		return $this->fetchAllTyped(
			Type::INT,
			$queryName,
			$bindings
		);
	}

	/** @return float[] */
	public function fetchAllFloat(string $queryName, mixed...$bindings):array {
		return $this->fetchAllTyped(
			Type::FLOAT,
			$queryName,
			$bindings
		);
	}

	/** @return DateTimeInterface[] */
	public function fetchAllDateTime(string $queryName, mixed...$bindings):array {
		return $this->fetchAllTyped(
			Type::DATETIME,
			$queryName,
			$bindings
		);
	}

	protected function fetchTyped(
		string $type,
		string $queryName,
		mixed...$bindings
	):null|string|int|bool|float|DateTimeInterface {
		$row = $this->fetch($queryName, ...$bindings);
		if($row) {
			$row->rewind();
		}

		if(is_null($row) || is_null($row->current())) {
			return null;
		}

		return $this->castRow($type, $row);
	}

	/** @return array<null|string|int|bool|float|DateTimeInterface> */
	protected function fetchAllTyped(
		string $type,
		string $queryName,
		mixed...$bindings
	):array {
		$array = [];

		$resultSet = $this->fetchAll($queryName, ...$bindings);
		foreach($resultSet as $row) {
			$array []= $this->castRow($type, $row);
		}

		return $array;
	}

	protected function castRow(
		string $type,
		Row $row
	):null|string|int|bool|float|DateTimeInterface {
		$assocArray = $row->asArray();
		reset($assocArray);
		$key = key($assocArray);
		$value = $assocArray[$key];

		if($type === Type::DATETIME && is_numeric($value)) {
			$value = "@$value";
		}

		return match ($type) {
			Type::BOOL, "boolean" => (bool)$value,
			Type::INT, "integer" => (int)$value,
			Type::FLOAT => (float)$value,
			Type::DATETIME => new DateTimeImmutable($value),
			default => (string)$value,
		};
	}
}
