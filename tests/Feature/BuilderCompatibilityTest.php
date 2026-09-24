<?php

use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\Element;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Label;
use Stilling\Zpl\Model\Orientation as ModelOrientation;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;
use Stilling\Zpl\Zpl;
use Zebra\Zpl\Builder as ZebraBuilder;
use Zebra\Zpl\GdDecoder;
use Zebra\Zpl\Image as ZebraImage;
use Zpl\Commands\GraphicField;
use Zpl\Enums\Align;
use Zpl\Enums\Barcode;
use Zpl\Enums\Orientation;
use Zpl\Enums\Unit;
use Zpl\ZplBuilder;

/**
 * A black and white test pattern that exercises every ^GF compression code:
 * repeated rows, trailing zeros and ones, and runs longer than 20 and 400 nibbles.
 */
function builderPattern(): GdImage {
	$image = imagecreatetruecolor(1700, 12);
	$white = (int) imagecolorallocate($image, 255, 255, 255);
	$black = (int) imagecolorallocate($image, 0, 0, 0);

	imagefilledrectangle($image, 0, 0, 1699, 11, $white);
	imagefilledrectangle($image, 0, 1, 1699, 1, $black);
	imagefilledrectangle($image, 0, 2, 1699, 3, $black);
	imagefilledrectangle($image, 5, 5, 900, 5, $black);
	imagefilledrectangle($image, 800, 6, 1699, 6, $black);

	for ($x = 0; $x < 1700; $x += 3) {
		imagesetpixel($image, $x, 8, $black);
	}

	imagefilledrectangle($image, 0, 10, 12, 10, $black);

	return $image;
}

function pngBytes(GdImage $image): string {
	ob_start();
	imagepng($image);

	return (string) ob_get_clean();
}

/**
 * @return list<Label>
 */
function builderLabels(string $zpl): array {
	$labels = Zpl::parse($zpl);

	expect(Zpl::toPdf($zpl))->toStartWith("%PDF-")
		->and(Zpl::toPngs($zpl))->toHaveCount(count($labels));

	return $labels;
}

/**
 * @template T of Element
 * @param class-string<T> $class
 * @return T
 */
function builderElement(Label $label, int $index, string $class): Element {
	$element = $label->elements[$index] ?? null;

	expect($element)->toBeInstanceOf($class);

	/** @var T $element */
	return $element;
}

function expectBitmapMatches(ImageElement $element, GdImage $source): void {
	$width = imagesx($source);
	$height = imagesy($source);
	$wrong = 0;

	for ($y = 0; $y < $height; $y++) {
		for ($x = 0; $x < $width; $x++) {
			$black = (imagecolorat($source, $x, $y) & 0xFF) < 128;

			if ($element->bitmap->pixel($x, $y) !== $black) {
				$wrong++;
			}
		}
	}

	expect($element->bitmap->width)->toBe((int) ceil($width / 8) * 8)
		->and($element->bitmap->height)->toBe($height)
		->and($wrong)->toBe(0);
}

describe("robgridley/zebra", function () {
	test("text, a barcode and a box keep their positions and sizes", function () {
		$zpl = (new ZebraBuilder())
			->command("FO", 50, 50)->command("A0", "", 50, 40)->command("FD", "Zebra")->command("FS")
			->command("FO", 50, 150)->command("BY", 2)->command("BC", "N", 100, true, false)->command("FD", "12345")->command("FS")
			->command("FO", 10, 10)->command("GB", 200, 100, 3)->command("FS")
			->toZpl(true);

		[$label] = builderLabels($zpl);
		$text = builderElement($label, 0, TextElement::class);
		$barcode = builderElement($label, 1, BarcodeElement::class);
		$box = builderElement($label, 2, BoxElement::class);

		expect($label->elements)->toHaveCount(3)
			->and([$text->x, $text->y, $text->text])->toBe([50, 50, "Zebra"])
			->and([$text->font->id, $text->font->height, $text->font->width])->toBe(["0", 50, 40])
			->and([$barcode->x, $barcode->y, $barcode->text])->toBe([50, 150, "12345"])
			->and([$barcode->moduleWidth, $barcode->moduleHeight])->toEqual([2, 100])
			->and([$box->x, $box->y, $box->width, $box->height, $box->thickness])->toBe([10, 10, 200, 100, 3]);
	});

	test("booleans become Y and N parameters", function () {
		$zpl = (new ZebraBuilder())
			->command("FO", 0, 0)->command("BC", "N", 50, false, true)->command("FD", "A1")->command("FS")
			->toZpl();

		$barcode = builderElement(builderLabels($zpl)[0], 0, BarcodeElement::class);

		expect($zpl)->toContain("^BCN,50,N,Y")
			->and($barcode->text)->toBeNull();
	});

	test("an image sent with ^GF decodes to the same pixels", function () {
		$source = builderPattern();
		$zpl = (new ZebraBuilder())
			->command("FO", 0, 0)->gf(new ZebraImage(GdDecoder::fromString(pngBytes($source))))->command("FS")
			->toZpl();

		expectBitmapMatches(builderElement(builderLabels($zpl)[0], 0, ImageElement::class), $source);
	});
});

describe("andersonls/zpl", function () {
	test("the page size in millimeters becomes ^PW and ^LL in dots", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setPageSize(50, 100);

		[$label] = builderLabels($builder->toZpl());

		expect([$label->width, $label->height])->toBe([799, 400]);
	});

	test("shapes, text and barcodes keep their positions and sizes", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setFont("0", 16);
		$builder->drawRect(5, 5, 50, 30);
		$builder->drawCircle(60, 5, 25);
		$builder->drawText(5, 40, "Product: ABC-123", Orientation::ROTATED);
		$builder->drawCode128(5, 50, 20, "ABC123456789", true);
		$builder->drawCode39(5, 80, 20, "ABC123", true);
		$builder->drawQrCode(50, 50, "https://example.com", 6);
		$builder->drawBarcode(Barcode::EAN13, 60, 80, 10, "590123412345", true);

		[$label] = builderLabels($builder->toZpl());
		$box = builderElement($label, 0, BoxElement::class);
		$circle = builderElement($label, 1, CircleElement::class);
		$text = builderElement($label, 2, TextElement::class);
		$code128 = builderElement($label, 3, BarcodeElement::class);
		$code39 = builderElement($label, 4, BarcodeElement::class);
		$qr = builderElement($label, 5, BarcodeElement::class);
		$ean = builderElement($label, 6, BarcodeElement::class);

		expect($label->elements)->toHaveCount(7)
			->and([$box->x, $box->y, $box->width, $box->height, $box->thickness])->toBe([40, 40, 400, 240, 3])
			->and([$circle->x, $circle->y, $circle->diameter, $circle->thickness])->toBe([480, 40, 200, 3])
			->and([$text->x, $text->y, $text->text, $text->orientation])->toBe([40, 320, "Product: ABC-123", ModelOrientation::Rotated])
			->and([$text->font->id, $text->font->height])->toBe(["0", 45])
			->and([$code128->x, $code128->y, $code128->text, $code128->moduleHeight])->toEqual([40, 400, "ABC123456789", 160])
			->and([$code39->x, $code39->y, $code39->text])->toBe([40, 639, "*ABC123*"])
			->and([$qr->x, $qr->y, $qr->moduleWidth, $qr->text])->toEqual([400, 400, 6, null])
			->and([$qr->matrix->columns, $qr->matrix->rows])->toBe([25, 25])
			->and([$ean->x, $ean->y, $ean->text])->toBe([480, 639, "5901234123457"]);
	});

	test("^FW from drawText does not leak into the next field", function () {
		$builder = new ZplBuilder();
		$builder->drawText(10, 10, "down", Orientation::INVERTED);
		$builder->drawText(10, 100, "up");

		[$label] = builderLabels($builder->toZpl());

		expect(builderElement($label, 0, TextElement::class)->orientation)->toBe(ModelOrientation::Inverted)
			->and(builderElement($label, 1, TextElement::class)->orientation)->toBe(ModelOrientation::Normal);
	});

	test("control characters escaped with ^FH come back as the original text", function () {
		$builder = new ZplBuilder();
		$builder->drawText(0, 0, "a_b^c~d{e}f[g]h#i%j");

		$text = builderElement(builderLabels($builder->toZpl())[0], 0, TextElement::class);

		expect($text->text)->toBe("a_b^c~d{e}f[g]h#i%j");
	});

	test("a cell with a border and alignment becomes a box and a field block", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setFont("0", 16);
		$builder->drawCell(100, 10, "Hello World", true, true, Align::CENTER);

		[$label] = builderLabels($builder->toZpl());
		$box = builderElement($label, 0, BoxElement::class);
		$text = builderElement($label, 1, TextElement::class);

		expect([$box->x, $box->y, $box->width, $box->height])->toBe([0, 0, 799, 80])
			->and([$text->x, $text->y, $text->text])->toBe([10, 20, "Hello World"])
			->and($text->block?->width)->toBe(789)
			->and($text->block?->justification)->toBe(TextJustification::Center);
	});

	test("setQuantity sets the label quantity and newPage starts a new label", function () {
		$builder = new ZplBuilder();
		$builder->setQuantity(3);
		$builder->drawText(0, 0, "first");
		$builder->newPage();
		$builder->drawText(0, 0, "second");

		$labels = builderLabels($builder->toZpl());

		expect($labels)->toHaveCount(2)
			->and($labels[0]->quantity)->toBe(3)
			->and(builderElement($labels[0], 0, TextElement::class)->text)->toBe("first")
			->and(builderElement($labels[1], 0, TextElement::class)->text)->toBe("second");
	});

	test("a serial number field prints its start value", function () {
		$builder = new ZplBuilder();
		$builder->drawSerialNumber(8, 8, "001");

		$text = builderElement(builderLabels($builder->toZpl())[0], 0, TextElement::class);

		expect([$text->x, $text->y, $text->text])->toBe([8, 8, "001"]);
	});

	test("an image sent with ^GF decodes to the same pixels", function () {
		$source = builderPattern();
		$zpl = "^XA^FO0,0" . (new GraphicField())->encodeImage(pngBytes($source), 0) . "^FS^XZ";

		expectBitmapMatches(builderElement(builderLabels($zpl)[0], 0, ImageElement::class), $source);
	});
});
