<?php

use Stilling\ZplRenderer\Exceptions\ParseException;
use Stilling\ZplRenderer\Parser\Tokenizer;

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
})->throws(ParseException::class, 'Invalid command name "X-" at offset 1.');

test("shows unprintable bytes in a command name as hex", function () {
	(new Tokenizer())->tokenize("^XA^\x80\x30");
})->throws(ParseException::class, 'Invalid command name "\x800" at offset 4.');

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

test("~DY takes binary data by the byte count in its header, so prefix bytes in the data do not start a command", function () {
	$commands = (new Tokenizer())->tokenize("~DYR:LOGO.PNG,B,P,6,0,^X\r\n~Z^XA^XZ");

	expect(array_map(fn ($c) => $c->name, $commands))->toBe(["DY", "XA", "XZ"])
		->and($commands[0]->params)->toBe("R:LOGO.PNG,B,P,6,0")
		->and($commands[0]->data)->toBe("^X\r\n~Z");
});

test("~DY data in ASCII, :B64: or :Z64: form, or without a byte count, runs to the next command", function (string $zpl, string $params) {
	$commands = (new Tokenizer())->tokenize($zpl);

	expect(array_map(fn ($c) => $c->name, $commands))->toBe(["DY", "XA"])
		->and($commands[0]->params)->toBe($params)
		->and($commands[0]->data)->toBeNull();
})->with([
	"ASCII" => ["~DYR:X.GRF,A,G,2,1,FF00^XA", "R:X.GRF,A,G,2,1,FF00"],
	":B64:" => ["~DYR:X.PNG,P,P,9,0,:B64:AAAA:1234^XA", "R:X.PNG,P,P,9,0,:B64:AAAA:1234"],
	":Z64:" => ["~DYR:X.PNG,P,P,9,0,:Z64:AAAA:1234^XA", "R:X.PNG,P,P,9,0,:Z64:AAAA:1234"],
	"no byte count" => ["~DYR:X.PNG,B,P,0,0,0011^XA", "R:X.PNG,B,P,0,0,0011"],
]);

test("~DY binary data shorter than the byte count takes what is there", function () {
	$commands = (new Tokenizer())->tokenize("~DYR:X.PNG,B,P,100,0,abc");

	expect($commands)->toHaveCount(1)
		->and($commands[0]->data)->toBe("abc");
});

test("~DY binary data follows a delimiter changed with ^CD", function () {
	$commands = (new Tokenizer())->tokenize("^CD;~DYR:X.PNG;B;P;3;0;,^;^XA");

	expect(array_map(fn ($c) => $c->name, $commands))->toBe(["CD", "DY", "XA"])
		->and($commands[1]->data)->toBe(",^;");
});
