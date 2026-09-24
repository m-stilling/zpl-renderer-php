<?php

use Stilling\Zpl\Cli;

/**
 * @param list<string> $args
 * @return array{int, string, string} exit code, stdout, stderr
 */
function runCli(array $args): array {
	$stdout = fopen("php://memory", "w+");
	$stderr = fopen("php://memory", "w+");

	if ($stdout === false || $stderr === false) {
		throw new RuntimeException("Cannot open memory streams.");
	}

	$code = (new Cli())->run(["zpl", ...$args], $stdout, $stderr);
	rewind($stdout);
	rewind($stderr);

	return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
}

/**
 * A temporary directory holding label.zpl, emptied before every test.
 */
function cliDir(): string {
	static $dir = null;

	if ($dir === null) {
		$dir = sys_get_temp_dir() . "/zpl-cli-" . bin2hex(random_bytes(4));
		mkdir($dir);
	}

	return $dir;
}

/**
 * @return array{float, float}
 */
function mediaBoxOf(string $file): array {
	preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', (string) file_get_contents($file), $match);

	return [(float) ($match[1] ?? 0), (float) ($match[2] ?? 0)];
}

beforeEach(function () {
	array_map(unlink(...), glob(cliDir() . "/*") ?: []);
	file_put_contents(cliDir() . "/label.zpl", "^XA^FO10,10^FDcli^FS^XZ");
});

afterAll(function () {
	array_map(unlink(...), glob(cliDir() . "/*") ?: []);
	rmdir(cliDir());
});

test("writes a PDF next to the input by default", function () {
	[$code, , $stderr] = runCli([cliDir() . "/label.zpl", "pdf"]);

	expect($code)->toBe(0)
		->and($stderr)->toContain("Wrote")
		->and((string) file_get_contents(cliDir() . "/label.pdf"))->toStartWith("%PDF");
});

test("refuses to replace an existing file without --overwrite", function () {
	file_put_contents(cliDir() . "/label.pdf", "keep");

	[$code, , $stderr] = runCli([cliDir() . "/label.zpl", "pdf"]);
	[$overwritten] = runCli([cliDir() . "/label.zpl", "pdf", "--overwrite"]);

	expect($code)->toBe(73)
		->and($stderr)->toContain("--overwrite")
		->and($overwritten)->toBe(0)
		->and((string) file_get_contents(cliDir() . "/label.pdf"))->toStartWith("%PDF");
});

test("writes to standard output when the output is -", function () {
	[$code, $stdout] = runCli([cliDir() . "/label.zpl", "pdf", "-"]);

	expect($code)->toBe(0)->and($stdout)->toStartWith("%PDF");
});

test("takes the label size in inches or millimeters", function () {
	[$code] = runCli([cliDir() . "/label.zpl", "pdf", cliDir() . "/a.pdf", "--size=2x1", "--dpmm=12"]);
	[$mm] = runCli([cliDir() . "/label.zpl", "pdf", cliDir() . "/b.pdf", "--width=25.4", "--height=25.4"]);
	[$bad, , $stderr] = runCli([cliDir() . "/label.zpl", "pdf", cliDir() . "/c.pdf", "--size=wide"]);
	[$width, $height] = mediaBoxOf(cliDir() . "/a.pdf");
	[$mmWidth] = mediaBoxOf(cliDir() . "/b.pdf");

	expect($code)->toBe(0)
		->and($width)->toEqualWithDelta(144, 0.2)
		->and($height)->toEqualWithDelta(72, 0.2)
		->and($mm)->toBe(0)
		->and($mmWidth)->toEqualWithDelta(72, 0.2)
		->and($bad)->toBe(64)
		->and($stderr)->toContain("--size");
});

test("rejects an unknown format and a missing input", function () {
	[$format, , $formatError] = runCli([cliDir() . "/label.zpl", "png"]);
	[$missing] = runCli([cliDir() . "/nope.zpl", "pdf"]);

	expect($format)->toBe(64)
		->and($formatError)->toContain("Unsupported format")
		->and($missing)->toBe(66);
});

test("prints usage with --help and without arguments", function () {
	[$help, $stdout] = runCli(["--help"]);
	[$none, , $stderr] = runCli([]);

	expect($help)->toBe(0)
		->and($stdout)->toContain("Usage:")
		->and($none)->toBe(64)
		->and($stderr)->toContain("Usage:");
});
