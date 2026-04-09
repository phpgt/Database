<?php
namespace Gt\Database\Test\Migration;

use Gt\Database\Migration\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

class SqlStatementSplitterTest extends TestCase {
	public function testSplitSeparatesMultipleStatements():void {
		$splitter = new SqlStatementSplitter();

		$statements = $splitter->split(implode("\n", [
			"create table test(id int);",
			"insert into test values(1);",
			"update test set id = 2 where id = 1;",
		]));

		self::assertSame([
			"create table test(id int)",
			"insert into test values(1)",
			"update test set id = 2 where id = 1",
		], $statements);
	}

	public function testSplitIgnoresEmptyStatements():void {
		$splitter = new SqlStatementSplitter();

		$statements = $splitter->split(" ;  ;\nselect 1;; ");

		self::assertSame([
			"select 1",
		], $statements);
	}
}
