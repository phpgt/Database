<?php
namespace GT\Database\Result;

use DateTimeImmutable;
use DateTimeInterface;
use Iterator;

/** @implements Iterator<string, string> */
class Row implements Iterator {
	/** @var array<string, string> */
	protected array $data;
	protected int $iteratorIndex;
	/** @var array<string> */
	protected array $iteratorDataKeyList;

	/** @param array<string, string> $data */
	public function __construct(array $data = []) {
		$this->data = $data;
		$this->iteratorIndex = 0;
		$this->iteratorDataKeyList = [];
	}

	public function __get(string $name):?string {
		return $this->getString($name);
	}

	public function __isset(string $name):bool {
		return array_key_exists($name, $this->data);
	}

	public function get(string $columnName):?string {
		return $this->data[$columnName] ?? null;
	}

	public function getString(string $columnName):?string {
		return $this->getAsNullablePrimitive($columnName, "string");
	}

	public function getInt(string $columnName):?int {
		return $this->getAsNullablePrimitive($columnName, "int");
	}

	public function getFloat(string $columnName):?float {
		return $this->getAsNullablePrimitive($columnName, "float");
	}

	public function getBool(string $columnName):?bool {
		return $this->getAsNullablePrimitive($columnName, "bool");
	}

	public function getDateTime(string $columnName):?DateTimeInterface {
		$dateString = $this->data[$columnName] ?? null;
		if(is_null($dateString)) {
			return null;
		}

		if(is_numeric($dateString)) {
			$dateTime = new DateTimeImmutable();
			return $dateTime->setTimestamp((int)$dateString);
		}

		return new DateTimeImmutable($dateString);
	}

	/** @return array<string, string> */
	public function asArray():array {
		return $this->data;
	}

	/** @return array<string> */
	public function getFieldList():array {
		return array_keys($this->data);
	}

	public function contains(string $name):bool {
		return $this->__isset($name);
	}

	public function rewind():void {
		$this->iteratorIndex = 0;
		$this->iteratorDataKeyList = array_keys($this->data);
	}

	public function key():?string {
		return $this->iteratorDataKeyList[
			$this->iteratorIndex
		] ?? null;
	}

	public function next():void {
		$this->iteratorIndex++;
	}

	public function valid():bool {
		return isset($this->iteratorDataKeyList[
			$this->iteratorIndex
		]);
	}

	public function current():?string {
		$key = $this->key();
		return $this->$key;
	}

	private function getAsNullablePrimitive(
		string $key,
		string $type,
	):null|string|int|bool|float {
		$value = $this->get($key);
		if(is_null($value)) {
			return null;
		}

		switch($type) {
		case "string":
			return $value;

		case "int":
		case "integer":
			return (int)$value;

		case "float":
		case "double":
		case "decimal":
			return (float)$value;

		case "bool":
		case "boolean":
			return (bool)$value;
		}

		throw new InvalidNullablePrimitiveException($type);
	}
}
