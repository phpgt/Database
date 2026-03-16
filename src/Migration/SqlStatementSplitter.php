<?php
namespace Gt\Database\Migration;

use PHPSQLParser\lexer\PHPSQLLexer;

class SqlStatementSplitter {
	/** @return array<string> */
	public function split(string $sql):array {
		$lexer = new PHPSQLLexer();
		$statementList = [];
		$currentStatement = "";

		foreach($lexer->split($sql) as $token) {
			if($token === ";") {
				$this->appendStatement($statementList, $currentStatement);
				$currentStatement = "";
				continue;
			}

			$currentStatement .= $token;
		}

		$this->appendStatement($statementList, $currentStatement);
		return $statementList;
	}

	/** @param array<string> $statementList */
	private function appendStatement(
		array &$statementList,
		string $statement
	):void {
		$statement = trim($statement);
		if(!$statement) {
			return;
		}

		$statementList []= $statement;
	}
}
