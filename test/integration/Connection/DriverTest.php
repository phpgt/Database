<?php
namespace GT\Database\Test\Integration\Connection;

use PHPUnit\Framework\TestCase;

class DriverTest extends TestCase {
	public function testConstruct_mysqlWithoutExtensionReportsAnActionableError():void {
		$autoload = var_export(dirname(__DIR__, 3) . "/vendor/autoload.php", true);
		$script = "require $autoload; " . <<<'PHP'
if(!class_exists(PDO::class)) { exit(77); }
if(in_array('mysql', PDO::getAvailableDrivers(), true)) { exit(78); }
$settings = (new GT\Database\Connection\DefaultSettings())->withDriver('mysql');
try {
	new GT\Database\Connection\Driver($settings);
	exit(1);
}
catch(GT\Database\Connection\MissingDriverException $exception) {
	echo $exception->getMessage();
}
PHP;
		$sut = [PHP_BINARY, "-n", "-r", $script];
		$process = proc_open($sut, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		if($status === 77) {
			self::markTestSkipped("This PHP build requires a shared PDO extension when using -n.");
		}

		self::assertSame(0, $status, $output . $errors);
		self::assertStringContainsString("PDO's mysql driver installed", $output);
		self::assertStringContainsString("https://www.php.gt/docs/Database/Missing-PDO-drivers/", $output);
		self::assertSame("", $errors);
	}
}
