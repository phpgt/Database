<?php
namespace GT\Database\Test;

use GT\Database\MissingParameterException;
use GT\Database\PlaceholderValidator;
use PHPUnit\Framework\TestCase;

class PlaceholderValidatorTest extends TestCase {
	public function testValidateAllowsSqlWithoutPlaceholders():void {
		PlaceholderValidator::validate(
			"select 1",
			[]
		);

		self::assertTrue(true);
	}

	public function testValidateAllowsRepeatedNamedPlaceholders():void {
		PlaceholderValidator::validate(
			"select * from test_table where name = :name or :name is null",
			["name" => "one"]
		);

		self::assertTrue(true);
	}

	public function testValidateAllowsSequentialBindingsForNamedPlaceholders():void {
		PlaceholderValidator::validate(
			"select * from test_table where name = :name and created_at > :date",
			["one", "2026-04-15 00:00:00"]
		);

		self::assertTrue(true);
	}

	public function testValidateReportsMissingSequentialNamedBindings():void {
		$this->expectException(MissingParameterException::class);
		$this->expectExceptionMessage(
			"Too few parameters were bound - missing `date`, `status`"
		);

		PlaceholderValidator::validate(
			"select * from test_table where name = :name and created_at > :date and status = :status",
			["one"]
		);
	}

	public function testValidateReportsMissingIndexedBindings():void {
		$this->expectException(MissingParameterException::class);
		$this->expectExceptionMessage(
			"Too few parameters were bound - expected 3, received 1"
		);

		PlaceholderValidator::validate(
			"select * from test_table where id = ? and name = ? and created_at > ?",
			[1]
		);
	}

	public function testValidateAllowsCompleteIndexedBindings():void {
		PlaceholderValidator::validate(
			"select * from test_table where id = ? and name = ? and created_at > ?",
			[1, "one", "2026-04-15 00:00:00"]
		);

		self::assertTrue(true);
	}

	public function testValidateReportsMissingAssociativeNamedBindings():void {
		$this->expectException(MissingParameterException::class);
		$this->expectExceptionMessage(
			"Too few parameters were bound - missing `date`, `status`"
		);

		PlaceholderValidator::validate(
			"select * from test_table where name = :name and created_at > :date and status = :status",
			["name" => "one"]
		);
	}

	public function testValidateIgnoresQuotedStringsCommentsAndCastSyntax():void {
		PlaceholderValidator::validate(
			implode("\n", [
				"select ':ignored', \":ignoredToo\", `:ignoredThree`, column::text",
				"from test_table",
				"# :ignoredFour",
				"where name = :name -- :ignoredFive",
				"and flag = 1 /* :ignoredSix */",
			]),
			["name" => "one"]
		);

		self::assertTrue(true);
	}

	public function testValidateIgnoresEscapedQuotedStrings():void {
		PlaceholderValidator::validate(
			"select 'it''s still a string :ignored', \"say \"\"hi :stillIgnored\"\"\" from test_table where name = :name",
			["name" => "one"]
		);

		self::assertTrue(true);
	}

	public function testValidateIgnoresInvalidPlaceholderSyntax():void {
		PlaceholderValidator::validate(
			'select 1:2 as ratio, :validPlaceholder as ok, ::text as casted, :$nope as nope',
			["validPlaceholder" => "ok"]
		);

		self::assertTrue(true);
	}

	public function testValidateHandlesUnterminatedQuotedString():void {
		PlaceholderValidator::validate(
			"select ':ignored",
			[]
		);

		self::assertTrue(true);
	}

	public function testValidateHandlesUnterminatedHashComment():void {
		PlaceholderValidator::validate(
			"select 1 # :ignored",
			[]
		);

		self::assertTrue(true);
	}

	public function testValidateHandlesUnterminatedBlockComment():void {
		PlaceholderValidator::validate(
			"select 1 /* :ignored",
			[]
		);

		self::assertTrue(true);
	}
}
