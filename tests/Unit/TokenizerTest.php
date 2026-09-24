<?php

use Stilling\Zpl\Exceptions\ParseException;
use Stilling\Zpl\Parser\Tokenizer;

/**
 * @return list<array{string, string}> command names and parameters
 */
function tokenize(string $zpl): array {
	return array_map(
		fn ($command) => [$command->name, $command->params],
		(new Tokenizer())->tokenize($zpl),
	);
}

test("splits commands on the format and control prefixes", function () {
	expect(tokenize("^XA^FO10,20^FDHello^FS~DGR:X.GRF,1,1,FF^XZ"))->toBe([
		["XA", ""],
		["FO", "10,20"],
		["FD", "Hello"],
		["FS", ""],
		["DG", "R:X.GRF,1,1,FF"],
		["XZ", ""],
	]);
});

test("treats ^A as a one-letter command so the font id stays in the parameters", function () {
	expect(tokenize("^A0N,30,30^AAN^A@N,20,20,E:FONT.TTF"))->toBe([
		["A", "0N,30,30"],
		["A", "AN"],
		["A@", "N,20,20,E:FONT.TTF"],
	]);
});

test("drops line breaks and ignores text outside commands", function () {
	expect(tokenize("junk\r\n^XA\n^FO10,\r\n20\n^FDtwo\nlines^FS\n^XZ\n"))->toBe([
		["XA", ""],
		["FO", "10,20"],
		["FD", "twolines"],
		["FS", ""],
		["XZ", ""],
	]);
});

test("lower-case command names are accepted", function () {
	expect(tokenize("^xa^fo1,2^fdx^fs^xz"))->toBe([
		["XA", ""],
		["FO", "1,2"],
		["FD", "x"],
		["FS", ""],
		["XZ", ""],
	]);
});

test("^CC, ^CT and ^CD change the prefixes and the delimiter for the rest of the stream", function () {
	$commands = (new Tokenizer())->tokenize("^CC//CT!/CD;/FO10;20/FDa,b/FS!DGX;1;1;FF/XZ");

	expect(array_map(fn ($c) => $c->name, $commands))->toBe(["CC", "CT", "CD", "FO", "FD", "FS", "DG", "XZ"])
		->and($commands[3]->args())->toBe(["10", "20"])
		->and($commands[4]->args())->toBe(["a,b"])
		->and($commands[6]->args())->toBe(["X", "1", "1", "FF"]);
});

test("rejects a command name that is not alphanumeric", function () {
	(new Tokenizer())->tokenize("^X-");
})->throws(ParseException::class);

test("command accessors give typed values with defaults", function () {
	$command = (new Tokenizer())->tokenize("^BCN,,Y,N,,A")[0];

	expect($command->arg(0))->toBe("N")
		->and($command->int(1, 10))->toBe(10)
		->and($command->bool(2, false))->toBeTrue()
		->and($command->bool(3, true))->toBeFalse()
		->and($command->bool(4, true))->toBeTrue()
		->and($command->char(5, "N"))->toBe("A")
		->and($command->argsLimited(2))->toBe(["N", ",Y,N,,A"]);
});
