<?php

use Stilling\Zpl\Options;
use Stilling\Zpl\Zpl;

/**
 * Decompress every content stream of a PDF, leaving out images and font files.
 */
function contentStreams(string $pdf): string {
	preg_match_all('/<<([^>]*)>>\s*stream\n(.*?)\nendstream/s', $pdf, $matches, PREG_SET_ORDER);
	$streams = "";

	foreach ($matches as $match) {
		if (!str_contains($match[1], "/Image") && !str_contains($match[1], "/Length1")) {
			$streams .= gzuncompress($match[2]) . "\n";
		}
	}

	return $streams;
}

/**
 * @return array{float, float} width and height in points of the first page
 */
function mediaBox(string $pdf): array {
	preg_match('/\/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]/', $pdf, $match);

	return [(float) ($match[1] ?? 0), (float) ($match[2] ?? 0)];
}

function pageCount(string $pdf): int {
	preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/', $pdf, $match);

	return (int) ($match[1] ?? 0);
}

test("the output is a PDF with one page per label", function () {
	$pdf = Zpl::toPdf("^XA^FO10,10^FDa^FS^XZ^XA^FO10,10^FDb^FS^XZ");

	expect($pdf)->toStartWith("%PDF-1.4")
		->and($pdf)->toEndWith("%%EOF\n")
		->and(pageCount($pdf))->toBe(2);
});

test("the page size follows the label size and density", function () {
	[$width, $height] = mediaBox(Zpl::toPdf("^XA^PW406^LL203^XZ", new Options(dpmm: 8)));

	expect($width)->toEqualWithDelta(406 / 203.2 * 72, 0.01)
		->and($height)->toEqualWithDelta(203 / 203.2 * 72, 0.01);
});

test("^PQ repeats the page unless quantities are ignored, and maxPages caps it", function () {
	$zpl = "^XA^PQ4^FO0,0^FDx^FS^XZ";

	expect(pageCount(Zpl::toPdf($zpl)))->toBe(4)
		->and(pageCount(Zpl::toPdf($zpl, new Options(honorQuantity: false))))->toBe(1)
		->and(pageCount(Zpl::toPdf($zpl, new Options(maxPages: 2))))->toBe(2);
});

test("a label with no fields still gives a blank page", function () {
	expect(pageCount(Zpl::toPdf("^XA^XZ")))->toBe(1);
});

test("text is placed with a flipped text matrix at the baseline", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FO100,200^A0N,40,40^FDHi^FS^XZ"));

	expect($content)->toContain("1 0 0 1 100 200 cm")
		->and($content)->toContain("/F1 45.007 Tf")
		->and($content)->toContain("1 0 0 -1 0 32 Tm (Hi) Tj");
});

test("^FT puts the baseline on the origin", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FT100,200^A0N,40,40^FDHi^FS^XZ"));

	expect($content)->toContain("1 0 0 1 100 168 cm");
});

test("rotated fields keep the top-left of their rotated box at the origin", function () {
	$rotated = contentStreams(Zpl::toPdf("^XA^FO100,200^GB50,20,1^FS^XZ^XA^FO100,200^A0R,10,10^FDx^FS^XZ"));

	expect($rotated)->toContain("1 0 0 1 100 200 cm")
		->and($rotated)->toContain("0 1 -1 0 110 200 cm");
});

test("right-justified fields end at the origin", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FO100,200,1^GB50,20,1^FS^XZ"));

	expect($content)->toContain("1 0 0 1 50 200 cm");
});

test("reverse fields paint white in difference mode", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FO0,0^FR^GB10,10,10^FS^XZ"));

	expect($content)->toContain("/GSr gs 1 g 1 G");
});

test("^LR reverses every field, and a field ^FR under it stays reversed", function () {
	$content = contentStreams(Zpl::toPdf("^XA^LRY^FO0,0^GB10,10,10^FS^FO0,0^FR^GB10,10,10^FS^XZ"));

	expect(substr_count($content, "/GSr gs"))->toBe(2);
});

test("^POI and ^PMY flip the whole page", function () {
	$content = contentStreams(Zpl::toPdf("^XA^PW100^LL50^POI^PMY^XZ"));

	expect($content)->toContain("-1 0 0 1 100 0 cm")
		->and($content)->toContain("-1 0 0 -1 100 50 cm");
});

test("a box is an outer and an inner rectangle filled even-odd", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FO0,0^GB100,50,5^FS^XZ"));

	expect($content)->toContain("0 0 100 50 re")
		->and($content)->toContain("5 5 90 40 re")
		->and($content)->toContain("f*");
});

test("a solid box has no inner rectangle", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FO0,0^GB100,50,50^FS^XZ"));

	expect($content)->toContain("0 0 100 50 re")
		->and(substr_count($content, " re"))->toBe(1);
});

test("a white box paints white", function () {
	expect(contentStreams(Zpl::toPdf("^XA^FO0,0^GB10,10,10,W^FS^XZ")))->toContain("1 g\n0 0 10 10 re");
});

test("images are image masks drawn top-down", function () {
	$pdf = Zpl::toPdf("^XA^FO0,0^GFA,2,2,1,FF00^FS^XZ");

	expect($pdf)->toContain("/ImageMask true /Decode [1 0]")
		->and($pdf)->toContain("/Width 8 /Height 2")
		->and(contentStreams($pdf))->toContain("q 8 0 0 -2 0 2 cm /Im1 Do Q");
});

test("fonts are embedded as subsets, and only when a page uses them", function () {
	$scalable = Zpl::toPdf("^XA^FO0,0^A0N,20,20^FDx^FS^XZ");
	$mono = Zpl::toPdf("^XA^FO0,0^AAN,9,5^FDx^FS^XZ");
	$monoBold = Zpl::toPdf("^XA^FO0,0^ABN,11,7^FDx^FS^XZ");

	expect($scalable)->toMatch('~/Subtype /TrueType /BaseFont /[A-Z]{6}\+RobotoCondensed-Bold ~')
		->and($scalable)->toContain("/FontFile2")
		->and($scalable)->not->toContain("RobotoMono")
		->and($mono)->toMatch('~/Subtype /TrueType /BaseFont /[A-Z]{6}\+RobotoMono-Regular ~')
		->and($mono)->toContain("/Flags 33 ")
		->and($mono)->not->toContain("RobotoCondensed")
		->and($mono)->not->toContain("RobotoMono-Bold")
		->and($monoBold)->toContain("+RobotoMono-Bold")
		->and(strlen($scalable))->toBeLessThan(20000)
		->and(strlen($mono))->toBeLessThan(20000)
		->and(Zpl::toPdf("^XA^FO0,0^GB10,10,10^FS^XZ"))->not->toContain("/FontFile2");
});

test("font 0 is drawn with the horizontal scale of the requested width", function () {
	$content = contentStreams(Zpl::toPdf("^XA^FO0,0^A0N,40,80^FDx^FS^XZ"));

	expect($content)->toContain("/F1 ")
		->and($content)->toContain("177.4 Tz");
});

test("barcodes are rectangles in dots with the interpretation line below", function () {
	$content = contentStreams(Zpl::toPdf("^XA^BY2^FO0,0^BCN,50,Y,N,N^FDA^FS^XZ"));

	expect($content)->toContain("0 0 4 50 re")
		->and($content)->toContain("/F3 ")
		->and($content)->toContain("(A) Tj");
});

test("the example labels render", function (string $file) {
	$pdf = Zpl::toPdf((string) file_get_contents($file));

	expect($pdf)->toStartWith("%PDF-1.4")->and(pageCount($pdf))->toBeGreaterThan(0);
})->with(array_map(fn ($f) => [$f], glob(__DIR__ . "/../../examples/*.zpl") ?: []));
