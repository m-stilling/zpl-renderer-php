<?php

use Stilling\Zpl\Exceptions\ParseException;
use Stilling\Zpl\Graphics\Bitmap;
use Stilling\Zpl\Graphics\GraphicDecoder;
use Stilling\Zpl\Graphics\GraphicStore;

/**
 * @return list<string>
 */
function rows(Bitmap $bitmap): array {
	$rows = [];

	for ($y = 0; $y < $bitmap->height; $y++) {
		$row = "";

		for ($x = 0; $x < $bitmap->width; $x++) {
			$row .= $bitmap->pixel($x, $y) ? "#" : ".";
		}

		$rows[] = $row;
	}

	return $rows;
}

test("decodes plain hex", function () {
	$bitmap = (new GraphicDecoder())->decode("FF0081", 1, 3);

	expect($bitmap->width)->toBe(8)
		->and($bitmap->height)->toBe(3)
		->and(rows($bitmap))->toBe(["########", "........", "#......#"]);
});

test("pads short data and cuts long data to the total byte count", function () {
	$decoder = new GraphicDecoder();

	expect($decoder->decode("FF", 1, 3)->height)->toBe(3)
		->and($decoder->decode("FF00FF00", 1, 3)->height)->toBe(3);
});

test("expands the run-length compression", function () {
	$bitmap = (new GraphicDecoder())->decode("JF8001:::::JF", 2, 16);

	expect(rows($bitmap))->toBe([
		"################",
		"#..............#",
		"#..............#",
		"#..............#",
		"#..............#",
		"#..............#",
		"#..............#",
		"################",
	]);
});

test("fills the rest of a row with comma and exclamation mark", function () {
	$bitmap = (new GraphicDecoder())->decode("F,!8,", 2, 6);

	expect(rows($bitmap))->toBe([
		"####............",
		"################",
		"#...............",
	]);
});

test("counts repeats with the lower-case letters in steps of twenty", function () {
	$bitmap = (new GraphicDecoder())->decode("gF", 10, 10);

	expect(rows($bitmap)[0])->toBe(str_repeat("#", 80));
});

test("decodes :B64: and :Z64: wrapped data", function () {
	$raw = "\xFF\x00\x81";
	$decoder = new GraphicDecoder();
	$b64 = ":B64:" . base64_encode($raw) . ":0000";
	$z64 = ":Z64:" . base64_encode((string) gzcompress($raw)) . ":0000";

	expect(rows($decoder->decode($b64, 1, 3)))->toBe(["########", "........", "#......#"])
		->and(rows($decoder->decode($z64, 1, 3)))->toBe(["########", "........", "#......#"]);
});

test("rejects invalid base64", function (string $data) {
	(new GraphicDecoder())->decode($data, 1, 1);
})->with([":B64:@@@@:0000", ":B64:A:0000", ":Z64:AAAA:0000"])->throws(ParseException::class);

test("graphic names ignore device, extension and case", function () {
	expect(GraphicStore::normalize("R:LOGO.GRF"))->toBe("LOGO")
		->and(GraphicStore::normalize("logo"))->toBe("LOGO")
		->and(GraphicStore::normalize("E:Logo.PNG"))->toBe("LOGO");
});
