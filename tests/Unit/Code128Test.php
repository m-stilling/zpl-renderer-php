<?php

use Com\Tecnick\Barcode\Barcode;
use Stilling\Zpl\Barcode\Code128;
use Stilling\Zpl\Barcode\Code128Mode;
use Stilling\Zpl\Exceptions\RenderException;

function referencePattern(string $type, string $data): string {
	$rows = (new Barcode())->getBarcodeObj($type, $data, -1, -1)->getGridArray();

	return implode("", $rows[0]);
}

test("subset B matches the reference encoder", function () {
	[$matrix, $text] = (new Code128())->encode("Hello-128", Code128Mode::Normal);

	expect(implode("", $matrix->toRows()))->toBe(referencePattern("C128B", "Hello-128"))
		->and($text)->toBe("Hello-128");
});

test("start code invocation selects subset C", function () {
	[$matrix, $text] = (new Code128())->encode(">;12345678", Code128Mode::Normal);

	expect(implode("", $matrix->toRows()))->toBe(referencePattern("C128C", "12345678"))
		->and($text)->toBe("12345678");
});

test("start code invocation selects subset A", function () {
	[$matrix] = (new Code128())->encode(">9ABC", Code128Mode::Normal);

	expect(implode("", $matrix->toRows()))->toBe(referencePattern("C128A", "ABC"));
});

test("invocation codes are left out of the interpretation line", function () {
	[, $text] = (new Code128())->encode(">:AB>5123456>6CD><", Code128Mode::Normal);

	expect($text)->toBe("AB123456CD>");
});

test("a control character in subset B switches to subset A", function () {
	[$matrix] = (new Code128())->encode("A\tB", Code128Mode::Normal);

	expect($matrix->columns)->toBeGreaterThan(0);
});

test("a lower-case letter in subset A switches to subset B", function () {
	[$matrix, $text] = (new Code128())->encode(">9abc", Code128Mode::Normal);

	expect($text)->toBe("abc")->and($matrix->columns)->toBe(7 * 11 + 2);
});

test("automatic mode picks subset C for digit runs", function () {
	[$matrix, $text] = (new Code128())->encode("12345678", Code128Mode::Automatic);

	expect(implode("", $matrix->toRows()))->toBe(referencePattern("C128C", "12345678"))
		->and($text)->toBe("12345678");
});

test("UCC case mode needs nineteen digits and appends a check digit", function () {
	[$matrix, $text] = (new Code128())->encode("0034567890123456789", Code128Mode::Ucc);

	expect($text)->toHaveLength(20)
		->and($text)->toStartWith("0034567890123456789")
		->and($matrix->columns)->toBe(11 * 14 + 2);
});

test("UCC case mode rejects other lengths", function () {
	(new Code128())->encode("1234", Code128Mode::Ucc);
})->throws(RenderException::class);

test("UCC/EAN mode keeps the parentheses in the interpretation line only", function () {
	[$matrix, $text] = (new Code128())->encode("(01)09501101530003", Code128Mode::Ean);

	expect($text)->toBe("(01)09501101530003")
		->and(implode("", $matrix->toRows()))->toBe(referencePattern("GS1128", "(01)09501101530003"));
});

test("the mod-10 check digit option appends a digit", function () {
	[, $text] = (new Code128())->encode("1234567", Code128Mode::Normal, true);

	expect($text)->toBe("12345670");
});

test("every symbol ends with the stop pattern", function () {
	[$matrix] = (new Code128())->encode("X", Code128Mode::Normal);

	expect(substr($matrix->toRows()[0], -13))->toBe("1100011101011");
});
