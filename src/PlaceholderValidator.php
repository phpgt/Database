<?php
namespace GT\Database;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PlaceholderValidator {
	/** @param array<string, mixed>|array<mixed> $bindings */
	public static function validate(string $sql, array $bindings):void {
		$placeholderData = self::parsePlaceholders($sql);
		self::validateIndexedBindings(
			$placeholderData["indexedCount"],
			$bindings
		);
		self::validateNamedBindings(
			$placeholderData["namedPlaceholders"],
			$bindings
		);
	}

	/** @return array{indexedCount:int, namedPlaceholders:array<string>} */
	private static function parsePlaceholders(string $sql):array {
		$indexedCount = 0;
		$namedPlaceholders = [];
		$length = strlen($sql);

		for($i = 0; $i < $length; $i++) {
			$character = $sql[$i];

			if($character === "'"
			|| $character === '"'
			|| $character === "`") {
				$i = self::skipQuotedString($sql, $i, $character);
				continue;
			}

			if($character === "#") {
				$i = self::skipLineComment($sql, $i);
				continue;
			}

			if($character === "-"
			&& $i + 1 < $length
			&& $sql[$i + 1] === "-") {
				$i = self::skipLineComment($sql, $i);
				continue;
			}

			if($character === "/"
			&& $i + 1 < $length
			&& $sql[$i + 1] === "*") {
				$i = self::skipBlockComment($sql, $i);
				continue;
			}

			if($character === "?") {
				$indexedCount++;
				continue;
			}

			if($character === ":") {
				if(($i > 0 && $sql[$i - 1] === ":")
				|| $i + 1 >= $length
				|| $sql[$i + 1] === ":") {
					continue;
				}

				$placeholderName = self::parseNamedPlaceholder($sql, $i + 1);
				if($placeholderName === null) {
					continue;
				}

				$namedPlaceholders[] = $placeholderName;
				$i += strlen($placeholderName);
			}
		}

		return [
			"indexedCount" => $indexedCount,
			"namedPlaceholders" => array_values(array_unique($namedPlaceholders)),
		];
	}

	private static function skipQuotedString(
		string $sql,
		int $offset,
		string $quoteCharacter
	):int {
		$length = strlen($sql);

		for($i = $offset + 1; $i < $length; $i++) {
			if($sql[$i] !== $quoteCharacter) {
				continue;
			}

			if($quoteCharacter !== "`"
			&& $i + 1 < $length
			&& $sql[$i + 1] === $quoteCharacter) {
				$i++;
				continue;
			}

			return $i;
		}

		return $length - 1;
	}

	private static function skipLineComment(string $sql, int $offset):int {
		$length = strlen($sql);

		for($i = $offset + 1; $i < $length; $i++) {
			if($sql[$i] === "\n") {
				return $i;
			}
		}

		return $length - 1;
	}

	private static function skipBlockComment(string $sql, int $offset):int {
		$length = strlen($sql);

		for($i = $offset + 2; $i < $length; $i++) {
			if($sql[$i] === "*"
			&& $i + 1 < $length
			&& $sql[$i + 1] === "/") {
				return $i + 1;
			}
		}

		return $length - 1;
	}

	private static function parseNamedPlaceholder(
		string $sql,
		int $offset
	):?string {
		$length = strlen($sql);
		$placeholderName = "";

		if($offset >= $length
		|| !preg_match('/[a-zA-Z_]/', $sql[$offset])) {
			return null;
		}

		for($i = $offset; $i < $length; $i++) {
			if(!preg_match('/[a-zA-Z0-9_]/', $sql[$i])) {
				break;
			}

			$placeholderName .= $sql[$i];
		}

		return $placeholderName;
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	private static function validateIndexedBindings(
		int $expectedCount,
		array $bindings
	):void {
		if($expectedCount === 0) {
			return;
		}

		$receivedCount = count($bindings);
		if($receivedCount >= $expectedCount) {
			return;
		}

		throw new MissingParameterException(
			"Too few parameters were bound - expected "
			. $expectedCount
			. ", received "
			. $receivedCount
		);
	}

	/**
	 * @param array<string> $expectedPlaceholders
	 * @param array<string, mixed>|array<mixed> $bindings
	 */
	private static function validateNamedBindings(
		array $expectedPlaceholders,
		array $bindings
	):void {
		if($expectedPlaceholders === []) {
			return;
		}

		if(self::bindingsEmptyOrNonAssociative($bindings)) {
			self::validateSequentialBindingsAgainstNamedPlaceholders(
				$expectedPlaceholders,
				$bindings
			);
			return;
		}

		$bindingMap = [];
		foreach(array_keys($bindings) as $key) {
			$bindingMap[ltrim((string)$key, ":")] = true;
		}

		$missingPlaceholders = [];
		foreach($expectedPlaceholders as $placeholderName) {
			if(isset($bindingMap[$placeholderName])) {
				continue;
			}

			$missingPlaceholders[] = "`$placeholderName`";
		}

		if($missingPlaceholders === []) {
			return;
		}

		throw new MissingParameterException(
			"Too few parameters were bound - missing "
			. implode(", ", $missingPlaceholders)
		);
	}

	/**
	 * @param array<string> $expectedPlaceholders
	 * @param array<string, mixed>|array<mixed> $bindings
	 */
	private static function validateSequentialBindingsAgainstNamedPlaceholders(
		array $expectedPlaceholders,
		array $bindings
	):void {
		$receivedCount = count($bindings);
		$expectedCount = count($expectedPlaceholders);

		if($receivedCount >= $expectedCount) {
			return;
		}

		$missingPlaceholders = array_slice(
			$expectedPlaceholders,
			$receivedCount
		);
		$missingPlaceholders = array_map(
			fn(string $placeholderName):string => "`$placeholderName`",
			$missingPlaceholders
		);

		throw new MissingParameterException(
			"Too few parameters were bound - missing "
			. implode(", ", $missingPlaceholders)
		);
	}

	/** @param array<string, mixed>|array<mixed> $bindings */
	private static function bindingsEmptyOrNonAssociative(array $bindings):bool {
		return $bindings === []
			|| array_keys($bindings) === range(
				0,
				count($bindings) - 1
			);
	}
}
