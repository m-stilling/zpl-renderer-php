<?php

use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Orientation;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;
use Zebra\Zpl\Builder;
use Zebra\Zpl\GdDecoder;
use Zebra\Zpl\Image;

/**
 * Build one field per call: ^FO, the field commands, then ^FS.
 *
 * @param list<list<string|int|bool>> $commands
 */
function zebraField(Builder $builder, int $x, int $y, array $commands): Builder {
	$builder->command("FO", $x, $y);

	foreach ($commands as $command) {
		$builder->command(...$command);
	}

	return $builder->command("FS");
}

function zebraImage(GdImage $image): string {
	return (new Builder())->command("FO", 0, 0)->gf(new Image(GdDecoder::fromString(pngBytes($image))))->command("FS")->toZpl();
}

test("commands take string, number and boolean parameters", function () {
	$zpl = (new Builder())->command("BC", "N", 100, true, false)->toZpl();

	expect($zpl)->toBe("^XA^BCN,100,Y,N^XZ");
});

test("magic methods become upper-case commands", function () {
	$builder = new Builder();
	$builder->__call("fo", [50, 60]);
	$builder->__call("fd", ["magic"]);
	$builder->__call("fs", []);

	$text = builderElement(builderLabel($builder->toZpl()), 0, TextElement::class);

	expect($builder->toZpl())->toBe("^XA^FO50,60^FDmagic^FS^XZ")
		->and([$text->x, $text->y, $text->text])->toBe([50, 60, "magic"]);
});

test("the output with and without newlines gives the same labels", function () {
	$builder = new Builder();
	zebraField($builder, 10, 20, [["A0", "N", 30, 30], ["FD", "one"]]);
	zebraField($builder, 10, 60, [["GB", 100, 50, 2]]);

	expect($builder->toZpl(true))->toContain("\n")
		->and(builderLabels($builder->toZpl(true)))->toEqual(builderLabels($builder->toZpl()))
		->and((string) $builder)->toBe($builder->toZpl());
});

test("label setup commands set the size, quantity, home and orientation", function () {
	$builder = (new Builder())
		->command("PW", 400)
		->command("LL", 300)
		->command("PQ", 3)
		->command("PO", "I")
		->command("LH", 15, 25)
		->command("FW", "R");
	zebraField($builder, 10, 20, [["FD", "rotated"]]);

	$label = builderLabel($builder->toZpl());
	$text = builderElement($label, 0, TextElement::class);

	expect([$label->width, $label->height, $label->quantity, $label->inverted])->toBe([400, 300, 3, true])
		->and([$text->x, $text->y, $text->orientation])->toBe([25, 45, Orientation::Rotated]);
});

test("text fields keep their font, reverse print and field block", function () {
	$builder = (new Builder())->command("CI", 28)->command("CF", "0", 40);
	zebraField($builder, 10, 10, [["FD", "default font"]]);
	zebraField($builder, 10, 60, [["A", "DB", 36, 20], ["FR"], ["FD", "reversed"]]);
	zebraField($builder, 10, 120, [["FB", 300, 3, 5, "C", 0], ["FD", "a centered block of text"]]);
	zebraField($builder, 10, 250, [["FH"], ["FD", "caf_C3_A9"]]);
	zebraField($builder, 10, 300, [["FD", "æøå"]]);

	$label = builderLabel($builder->toZpl());
	$default = builderElement($label, 0, TextElement::class);
	$reversed = builderElement($label, 1, TextElement::class);
	$block = builderElement($label, 2, TextElement::class);
	$hex = builderElement($label, 3, TextElement::class);
	$utf8 = builderElement($label, 4, TextElement::class);

	expect([$default->font->id, $default->font->height])->toBe(["0", 40])
		->and([$reversed->font->id, $reversed->font->height, $reversed->font->width])->toBe(["D", 36, 20])
		->and([$reversed->orientation, $reversed->reverse])->toBe([Orientation::BottomUp, true])
		->and([$block->block?->width, $block->block?->maxLines, $block->block?->lineSpacing])->toBe([300, 3, 5])
		->and($block->block?->justification)->toBe(TextJustification::Center)
		->and($hex->text)->toBe("café")
		->and($utf8->text)->toBe("æøå");
});

test("graphic commands keep their size, thickness and color", function () {
	$builder = new Builder();
	zebraField($builder, 10, 10, [["GB", 200, 100, 4, "W", 3]]);
	zebraField($builder, 300, 10, [["GC", 80, 5, "B"]]);

	$label = builderLabel($builder->toZpl());
	$box = builderElement($label, 0, BoxElement::class);
	$circle = builderElement($label, 1, CircleElement::class);

	expect([$box->x, $box->y, $box->width, $box->height, $box->thickness, $box->black, $box->rounding])->toBe([10, 10, 200, 100, 4, false, 3])
		->and([$circle->x, $circle->y, $circle->diameter, $circle->thickness, $circle->black])->toBe([300, 10, 80, 5, true]);
});

test("barcodes keep their module width, height and interpretation line", function () {
	$builder = (new Builder())->command("BY", 3, 2.5, 80);
	zebraField($builder, 10, 10, [["BC", "N", 100, true, false], ["FD", "12345"]]);
	zebraField($builder, 10, 150, [["BC", "N", "", false, false], ["FD", "12345"]]);
	zebraField($builder, 10, 300, [["B3", "N", false, 60, true, true], ["FD", "ABC"]]);
	zebraField($builder, 10, 400, [["BE", "R", 70, true], ["FD", "590123412345"]]);
	zebraField($builder, 400, 10, [["BQ", "N", 2, 5], ["FD", "QA,https://example.com"]]);
	zebraField($builder, 400, 300, [["BX", "N", 6, 200], ["FD", "Data Matrix"]]);

	$label = builderLabel($builder->toZpl());
	$code128 = builderElement($label, 0, BarcodeElement::class);
	$defaultHeight = builderElement($label, 1, BarcodeElement::class);
	$code39 = builderElement($label, 2, BarcodeElement::class);
	$ean = builderElement($label, 3, BarcodeElement::class);
	$qr = builderElement($label, 4, BarcodeElement::class);
	$dataMatrix = builderElement($label, 5, BarcodeElement::class);

	expect([$code128->x, $code128->y, $code128->text, $code128->textAbove])->toBe([10, 10, "12345", false])
		->and([$code128->moduleWidth, $code128->moduleHeight])->toEqual([3, 100])
		->and([$defaultHeight->moduleHeight, $defaultHeight->text])->toEqual([80, null])
		->and([$code39->text, $code39->textAbove, $code39->moduleHeight])->toEqual(["*ABC*", true, 60])
		->and([$ean->text, $ean->orientation])->toBe(["5901234123457", Orientation::Rotated])
		->and([$qr->x, $qr->y, $qr->moduleWidth, $qr->text])->toEqual([400, 10, 5, null])
		->and([$dataMatrix->moduleWidth, $dataMatrix->text])->toEqual([6, null]);
});

test("an image decodes to the same pixels", function () {
	$source = builderPattern();

	expectBitmapMatches(builderElement(builderLabel(zebraImage($source)), 0, ImageElement::class), $source);
});

test("an image loaded from a file decodes to the same pixels", function () {
	$source = builderPattern();
	$path = tempnam(sys_get_temp_dir(), "zebra");
	file_put_contents((string) $path, pngBytes($source));

	try {
		$zpl = (new Builder())->command("FO", 0, 0)->gf(new Image(GdDecoder::fromPath((string) $path)))->command("FS")->toZpl();
	} finally {
		unlink((string) $path);
	}

	expectBitmapMatches(builderElement(builderLabel($zpl), 0, ImageElement::class), $source);
});

test("an image with a width that is not a whole byte keeps its size", function () {
	$source = imagecreatetruecolor(13, 5);
	imagefilledrectangle($source, 0, 0, 12, 4, (int) imagecolorallocate($source, 255, 255, 255));
	imagefilledrectangle($source, 12, 0, 12, 4, (int) imagecolorallocate($source, 0, 0, 0));

	$image = new Image(GdDecoder::fromString(pngBytes($source)));
	$element = builderElement(builderLabel(zebraImage($source)), 0, ImageElement::class);

	expect([$image->width(), $image->height()])->toBe([2, 5]);
	expectBitmapMatches($element, $source);
});

test("gray and colored pixels darker than middle gray print black", function () {
	$levels = [0, 60, 126, 128, 200, 255];
	$source = imagecreate(count($levels) + 2, 1);

	foreach ($levels as $x => $level) {
		imagesetpixel($source, $x, 0, (int) imagecolorallocate($source, $level, $level, $level));
	}

	imagesetpixel($source, count($levels), 0, (int) imagecolorallocate($source, 0, 0, 255));
	imagesetpixel($source, count($levels) + 1, 0, (int) imagecolorallocate($source, 255, 255, 0));

	$element = builderElement(builderLabel(zebraImage($source)), 0, ImageElement::class);
	$expected = [true, true, true, false, false, false, true, false];

	expectBitmap($element, count($expected), 1, fn (int $x): bool => $expected[$x]);
});

test("^GF also takes raw arguments", function () {
	$zpl = (new Builder())->command("FO", 5, 5)->gf("A", 4, 4, 1, "F0F00F0F")->command("FS")->toZpl();

	$element = builderElement(builderLabel($zpl), 0, ImageElement::class);

	expect([$element->x, $element->y])->toBe([5, 5]);
	expectBitmap($element, 8, 4, fn (int $x, int $y): bool => $y < 2 ? $x < 4 : $x >= 4);
});
