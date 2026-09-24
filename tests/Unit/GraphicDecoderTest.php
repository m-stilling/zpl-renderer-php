<?php

use Stilling\ZplRenderer\Exceptions\ParseException;
use Stilling\ZplRenderer\Graphics\Bitmap;
use Stilling\ZplRenderer\Graphics\GraphicDecoder;
use Stilling\ZplRenderer\Graphics\GraphicStore;
use Stilling\ZplRenderer\Graphics\PngDecoder;

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

test("a run that spans rows continues on the next row", function () {
	$bitmap = (new GraphicDecoder())->decode("KF:", 1, 3);

	expect(rows($bitmap))->toBe(["########", "########", "########"]);
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

test("rejects a graphic larger than the byte limit", function (string $data, int $bytesPerRow, int $total) {
	(new GraphicDecoder(16))->decode($data, $bytesPerRow, $total);
})->with([
	"total byte count" => ["FF", 1, 17],
	"bytes per row" => ["FF", 17, 0],
	"plain hex" => [str_repeat("FF", 17), 1, 0],
	"run length" => ["zzzzF", 1, 0],
	"repeated rows" => ["FF" . str_repeat(":", 16), 1, 0],
	"filled rows" => [str_repeat("!", 17), 1, 0],
	":B64:" => [":B64:" . base64_encode(str_repeat("\0", 17)) . ":0000", 1, 0],
	":Z64:" => [":Z64:" . base64_encode((string) gzcompress(str_repeat("\0", 1000))) . ":0000", 1, 0],
])->throws(ParseException::class);

test("accepts a graphic at the byte limit", function () {
	$decoder = new GraphicDecoder(16);

	expect($decoder->decode(str_repeat("FF", 16), 1, 16)->height)->toBe(16)
		->and($decoder->decode("FF" . str_repeat(":", 15), 1, 0)->height)->toBe(16)
		->and($decoder->decode(":Z64:" . base64_encode((string) gzcompress(str_repeat("\0", 16))) . ":0000", 1, 0)->height)->toBe(16);
});

/**
 * A PNG of the given size with a black left half, as bytes.
 *
 * @param positive-int $width
 * @param positive-int $height
 */
function halfBlackPng(int $width, int $height): string {
	$image = imagecreatetruecolor($width, $height);
	imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
	imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 0));

	return pngBytes($image);
}

test("a PNG becomes a one-bit bitmap by luminance", function () {
	$bitmap = (new PngDecoder())->decode(halfBlackPng(16, 2));

	expect(rows($bitmap))->toBe(["########........", "########........"]);
});

test("rejects a PNG whose bitmap would be larger than the byte limit", function () {
	(new PngDecoder(16))->decode(halfBlackPng(64, 3));
})->throws(ParseException::class, "more than the limit of 16 bytes");

test("accepts a PNG whose bitmap is at the byte limit", function () {
	expect((new PngDecoder(16))->decode(halfBlackPng(64, 2))->height)->toBe(2);
});

test("graphic names ignore device, extension and case", function () {
	expect(GraphicStore::normalize("R:LOGO.GRF"))->toBe("LOGO")
		->and(GraphicStore::normalize("logo"))->toBe("LOGO")
		->and(GraphicStore::normalize("E:Logo.PNG"))->toBe("LOGO");
});
