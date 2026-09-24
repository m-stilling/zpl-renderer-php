<?php

use Stilling\Zpl\Exceptions\UnsupportedException;
use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Orientation as ModelOrientation;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;
use Zpl\Commands\GraphicField;
use Zpl\Enums\Align;
use Zpl\Enums\Barcode;
use Zpl\Enums\Orientation;
use Zpl\Enums\Unit;
use Zpl\Fonts\Bematech\Lb1000;
use Zpl\Fonts\Generic;
use Zpl\ZplBuilder;

/**
 * Write the image to a temporary PNG file, pass its path to the callback and delete it after.
 *
 * @template T
 * @param callable(string): T $callback
 * @return T
 */
function withPngFile(GdImage $image, callable $callback): mixed {
	$path = (string) tempnam(sys_get_temp_dir(), "zpl");
	file_put_contents($path, pngBytes($image));

	try {
		return $callback($path);
	} finally {
		unlink($path);
	}
}

describe("units and resolution", function () {
	test("the page size in millimeters becomes ^PW and ^LL in dots", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setPageSize(50, 100);

		$label = builderLabel($builder->toZpl());

		expect([$label->width, $label->height])->toBe([799, 400]);
	});

	test("the page size carries over to the pages that newPage starts", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setPageSize(50, 100);
		$builder->newPage();

		$sizes = array_map(fn ($label): array => [$label->width, $label->height], builderLabels($builder->toZpl()));

		expect($sizes)->toBe([[799, 400], [799, 400]]);
	});

	test("setWidth and setHeight each set one side", function () {
		$builder = new ZplBuilder();
		$builder->setWidth(600);
		$builder->setHeight(350);

		$label = builderLabel($builder->toZpl());

		expect([$label->width, $label->height])->toBe([600, 350]);
	});

	test("dots are passed through as they are", function () {
		$builder = new ZplBuilder(Unit::DOTS);
		$builder->drawRect(12, 34, 56, 78, 2);

		$box = builderElement(builderLabel($builder->toZpl()), 0, BoxElement::class);

		expect([$box->x, $box->y, $box->width, $box->height, $box->thickness])->toBe([12, 34, 56, 78, 2]);
	});

	test("millimeters follow the resolution set with setDpi and setDpmm", function () {
		$dpi = new ZplBuilder(Unit::MM);
		$dpi->setDpi(300);
		$dpi->drawRect(10, 20, 30, 40, 1);

		$dpmm = new ZplBuilder(Unit::MM, 203);
		$dpmm->setDpmm(12);
		$dpmm->drawRect(10, 20, 30, 40, 1);

		$byDpi = builderElement(builderLabel($dpi->toZpl()), 0, BoxElement::class);
		$byDpmm = builderElement(builderLabel($dpmm->toZpl()), 0, BoxElement::class);

		expect([$dpi->getDpi(), $dpmm->getDpi(), $dpmm->getDpmm()])->toBe([300, 305, 12])
			->and([$byDpi->x, $byDpi->y, $byDpi->width, $byDpi->height, $byDpi->thickness])->toBe([118, 236, 354, 472, 12])
			->and([$byDpmm->x, $byDpmm->y, $byDpmm->width, $byDpmm->height])->toBe([120, 240, 360, 480]);
	});
});

describe("label setup", function () {
	test("setHome shifts every field", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setHome(5, 10);
		$builder->drawText(1, 2, "shifted");

		$text = builderElement(builderLabel($builder->toZpl()), 0, TextElement::class);

		expect([$text->x, $text->y])->toBe([40 + 8, 80 + 16]);
	});

	test("setQuantity sets the label quantity", function () {
		$builder = new ZplBuilder();
		$builder->setQuantity(3, 1, 0);

		expect(builderLabel($builder->toZpl())->quantity)->toBe(3);
	});

	test("invertLabelOrientation turns the label upside down, and false turns it back", function () {
		$inverted = new ZplBuilder();
		$inverted->invertLabelOrientation();

		$normal = new ZplBuilder();
		$normal->invertLabelOrientation(false);

		expect(builderLabel($inverted->toZpl())->inverted)->toBeTrue()
			->and(builderLabel($normal->toZpl())->inverted)->toBeFalse();
	});

	test("setDarkness is accepted and does not change the fields", function () {
		$builder = new ZplBuilder();
		$builder->setDarkness(20.5);
		$builder->drawText(10, 10, "dark");

		expect($builder->toZpl())->toContain("~SD20.5")
			->and(builderElement(builderLabel($builder->toZpl()), 0, TextElement::class)->text)->toBe("dark");
	});

	test("setEncoding 28 keeps UTF-8 text", function () {
		$builder = new ZplBuilder();
		$builder->setEncoding(28);
		$builder->drawText(10, 10, "Æblegrød på Ærø");

		expect(builderElement(builderLabel($builder->toZpl()), 0, TextElement::class)->text)->toBe("Æblegrød på Ærø");
	});
});

describe("fonts", function () {
	test("setFont converts the size by the resolution", function () {
		$builder = new ZplBuilder();
		$builder->setFont("A", 10, 5);
		$builder->drawText(0, 0, "a");

		$hiRes = new ZplBuilder(Unit::DOTS, 300);
		$hiRes->setFont("0", 16);
		$hiRes->drawText(0, 0, "b");

		$text = builderElement(builderLabel($builder->toZpl()), 0, TextElement::class);
		$large = builderElement(builderLabel($hiRes->toZpl()), 0, TextElement::class);

		expect([$text->font->id, $text->font->height, $text->font->width])->toBe(["A", 28, 14])
			->and([$large->font->id, $large->font->height])->toBe(["0", 67]);
	});

	test("the font mapper translates font names", function () {
		$builder = new ZplBuilder();
		$builder->setFontMapper(new Lb1000());
		$builder->setFont("Arial", 16);
		$builder->drawText(0, 0, "mapped");
		$builder->setFontMapper(new Generic());
		$builder->setFont("D", 16);
		$builder->drawText(0, 50, "generic");

		$label = builderLabel($builder->toZpl());

		expect(builderElement($label, 0, TextElement::class)->font->id)->toBe("0")
			->and(builderElement($label, 1, TextElement::class)->font->id)->toBe("D");
	});
});

describe("text", function () {
	test("drawText sets the orientation and reverse print of one field", function (Orientation $orientation, ModelOrientation $expected) {
		$builder = new ZplBuilder();
		$builder->drawText(10, 20, "turned", $orientation, true);
		$builder->drawText(10, 100, "plain");

		$label = builderLabel($builder->toZpl());
		$turned = builderElement($label, 0, TextElement::class);
		$plain = builderElement($label, 1, TextElement::class);

		expect([$turned->x, $turned->y, $turned->orientation, $turned->reverse])->toBe([10, 20, $expected, true])
			->and([$plain->orientation, $plain->reverse])->toBe([ModelOrientation::Normal, false]);
	})->with([
		[Orientation::NORMAL, ModelOrientation::Normal],
		[Orientation::ROTATED, ModelOrientation::Rotated],
		[Orientation::INVERTED, ModelOrientation::Inverted],
		[Orientation::BOTTOM_UP, ModelOrientation::BottomUp],
	]);

	test("setOrientation applies to the fields that follow", function () {
		$builder = new ZplBuilder();
		$builder->setOrientation(Orientation::INVERTED);
		$builder->setXY(10, 10);
		$builder->drawCell(100, 40, "cell");

		expect(builderElement(builderLabel($builder->toZpl()), 0, TextElement::class)->orientation)->toBe(ModelOrientation::Inverted);
	});

	test("control characters escaped with ^FH come back as the original text", function () {
		$builder = new ZplBuilder();
		$builder->drawText(0, 0, "a_b^c~d{e}f[g]h#i%j");

		expect(builderElement(builderLabel($builder->toZpl()), 0, TextElement::class)->text)->toBe("a_b^c~d{e}f[g]h#i%j");
	});

	test("a serial number field prints its start value", function () {
		$builder = new ZplBuilder();
		$builder->drawSerialNumber(8, 9, "A001", 2, false, true);

		$text = builderElement(builderLabel($builder->toZpl()), 0, TextElement::class);

		expect([$text->x, $text->y, $text->text, $text->reverse])->toBe([8, 9, "A001", true]);
	});
});

describe("cells", function () {
	test("a cell with a border and alignment becomes a box and a field block", function (Align $align, TextJustification $expected) {
		$builder = new ZplBuilder(Unit::MM);
		$builder->setFont("0", 16);
		$builder->drawCell(100, 10, "Hello World", true, true, $align);

		$label = builderLabel($builder->toZpl());
		$box = builderElement($label, 0, BoxElement::class);
		$text = builderElement($label, 1, TextElement::class);

		expect([$box->x, $box->y, $box->width, $box->height])->toBe([0, 0, 799, 80])
			->and([$text->x, $text->y, $text->text])->toBe([10, 20, "Hello World"])
			->and($text->block?->width)->toBe(789)
			->and($text->block?->justification)->toBe($expected);
	})->with([
		[Align::LEFT, TextJustification::Left],
		[Align::CENTER, TextJustification::Center],
		[Align::RIGHT, TextJustification::Right],
		[Align::JUSTIFIED, TextJustification::Justified],
	]);

	test("cells without a line break sit side by side, and a line break returns to the margin", function () {
		$builder = new ZplBuilder();
		$builder->setMargin(30);
		$builder->setXY(30, 0);
		$builder->drawCell(100, 40, "one");
		$builder->drawCell(100, 40, "two", false, true);
		$builder->drawCell(100, 40, "three");

		$label = builderLabel($builder->toZpl());
		$positions = array_map(fn (int $index): array => [
			builderElement($label, $index, TextElement::class)->x,
			builderElement($label, $index, TextElement::class)->y,
		], [0, 1, 2]);

		expect($label->elements)->toHaveCount(3)
			->and($positions)->toBe([[40, 10], [140, 10], [40, 50]])
			->and(builderElement($label, 0, TextElement::class)->block)->toBeNull();
	});

	test("a cell with only a border draws a box and no text", function () {
		$builder = new ZplBuilder();
		$builder->drawCell(100, 40, "", true);

		$label = builderLabel($builder->toZpl());

		expect($label->elements)->toHaveCount(1)
			->and(builderElement($label, 0, BoxElement::class)->width)->toBe(100);
	});
});

describe("shapes", function () {
	test("a rectangle keeps its thickness, color, rounding and reverse print", function () {
		$builder = new ZplBuilder();
		$builder->drawRect(10, 20, 200, 100);
		$builder->drawRect(10, 200, 200, 100, 5, "W", 4, true);

		$label = builderLabel($builder->toZpl());
		$default = builderElement($label, 0, BoxElement::class);
		$styled = builderElement($label, 1, BoxElement::class);

		expect([$default->thickness, $default->black, $default->rounding, $default->reverse])->toBe([3, true, 0, false])
			->and([$styled->x, $styled->y, $styled->width, $styled->height])->toBe([10, 200, 200, 100])
			->and([$styled->thickness, $styled->black, $styled->rounding, $styled->reverse])->toBe([5, false, 4, true]);
	});

	test("a circle keeps its diameter, thickness, color and reverse print", function () {
		$builder = new ZplBuilder();
		$builder->drawCircle(10, 20, 150);
		$builder->drawCircle(200, 20, 80, 6, "W", true);

		$label = builderLabel($builder->toZpl());
		$default = builderElement($label, 0, CircleElement::class);
		$styled = builderElement($label, 1, CircleElement::class);

		expect([$default->x, $default->y, $default->diameter, $default->thickness, $default->black])->toBe([10, 20, 150, 3, true])
			->and([$styled->x, $styled->y, $styled->diameter, $styled->thickness, $styled->black, $styled->reverse])->toBe([200, 20, 80, 6, false, true]);
	});

	test("a line starts at the current position and spans the given distance", function () {
		$builder = new ZplBuilder();
		$builder->setXY(30, 40);
		$builder->drawLine(0, 0, 300, 0, 4);

		$box = builderElement(builderLabel($builder->toZpl()), 0, BoxElement::class);

		expect([$box->x, $box->y, $box->width, $box->thickness])->toBe([30, 40, 300, 4]);
	});
});

describe("barcodes", function () {
	test("every supported symbology becomes a barcode at its origin", function (Barcode $type, string $data) {
		$builder = new ZplBuilder();
		$builder->drawBarcode($type, 25, 35, 60, $data, true);

		$label = builderLabel($builder->toZpl());
		$barcode = builderElement($label, 0, BarcodeElement::class);

		expect($label->elements)->toHaveCount(1)
			->and([$barcode->x, $barcode->y])->toBe([25, 35])
			->and($barcode->matrix->columns)->toBeGreaterThan(0);
	})->with([
		[Barcode::AZTEK, "Aztec"],
		[Barcode::CODE11, "123-45"],
		[Barcode::INTERLEAVED2, "123456"],
		[Barcode::CODE39, "CODE39"],
		[Barcode::CODE49, "CODE49"],
		[Barcode::PLANET, "12345678901"],
		[Barcode::PDF417, "PDF417"],
		[Barcode::EAN8, "1234567"],
		[Barcode::UPCE, "425261"],
		[Barcode::CODE93, "CODE93"],
		[Barcode::CODE128, "CODE128"],
		[Barcode::EAN13, "590123412345"],
		[Barcode::INDUSTRIAL2, "12345"],
		[Barcode::STANDARD2, "12345"],
		[Barcode::ANSI, "A123456A"],
		[Barcode::LOGMARS, "LOGMARS"],
		[Barcode::MSI, "123456"],
		[Barcode::PLESSEY, "12345"],
		[Barcode::QR, "https://example.com"],
		[Barcode::UPC_EAN, "12345"],
		[Barcode::UPCA, "12345678901"],
		[Barcode::DATAMATRIX, "Data Matrix"],
		[Barcode::POSTAL, "12345"],
	]);

	test("unsupported symbologies throw", function (Barcode $type) {
		$builder = new ZplBuilder();
		$builder->drawBarcode($type, 0, 0, 60, "12345");

		builderLabels($builder->toZpl());
	})->with([
		Barcode::CODABLOCK,
		Barcode::UPS,
		Barcode::MICROPDF417,
		Barcode::TLC39,
		"GS1 DataBar, which gets the height where ^BR takes the type" => Barcode::GS1,
	])->throws(UnsupportedException::class);

	test("a linear barcode keeps its height, module width, orientation and interpretation line", function () {
		$builder = new ZplBuilder();
		$builder->drawBarcode(Barcode::CODE128, 10, 20, 90, "ABC123", true, true, Orientation::INVERTED, 3);
		$builder->drawBarcode(Barcode::CODE93, 10, 200, 70, "ABC123");

		$label = builderLabel($builder->toZpl());
		$above = builderElement($label, 0, BarcodeElement::class);
		$hidden = builderElement($label, 1, BarcodeElement::class);

		expect([$above->text, $above->textAbove, $above->orientation])->toBe(["ABC123", true, ModelOrientation::Inverted])
			->and([$above->moduleWidth, $above->moduleHeight])->toEqual([3, 90])
			->and([$hidden->text, $hidden->moduleHeight])->toEqual([null, 70]);
	});

	test("the Code 128, Code 39 and QR shortcuts keep their data and size", function () {
		$builder = new ZplBuilder(Unit::MM);
		$builder->drawCode128(5, 50, 20, "ABC123456789", true, Orientation::ROTATED, 2);
		$builder->drawCode39(5, 80, 20, "ABC123", true);
		$builder->drawQrCode(50, 50, "https://example.com", 6);

		$label = builderLabel($builder->toZpl());
		$code128 = builderElement($label, 0, BarcodeElement::class);
		$code39 = builderElement($label, 1, BarcodeElement::class);
		$qr = builderElement($label, 2, BarcodeElement::class);

		expect([$code128->x, $code128->y, $code128->text, $code128->orientation])->toBe([40, 400, "ABC123456789", ModelOrientation::Rotated])
			->and($code128->moduleHeight)->toEqual(160)
			->and([$code39->x, $code39->y, $code39->text])->toBe([40, 639, "*ABC123*"])
			->and([$qr->x, $qr->y, $qr->moduleWidth, $qr->text])->toEqual([400, 400, 6, null])
			->and([$qr->matrix->columns, $qr->matrix->rows])->toBe([25, 25]);
	});
});

describe("graphics", function () {
	test("drawGraphic decodes to the same pixels", function () {
		$source = builderPattern();
		$builder = new ZplBuilder();
		withPngFile($source, fn (string $path) => $builder->drawGraphic(15, 25, $path));

		$element = builderElement(builderLabel($builder->toZpl()), 0, ImageElement::class);

		expect([$element->x, $element->y])->toBe([15, 25]);
		expectBitmapMatches($element, $source);
	});

	test("drawGraphic scales the image to the given width", function () {
		$builder = new ZplBuilder(Unit::MM);
		withPngFile(builderPattern(), fn (string $path) => $builder->drawGraphic(0, 0, $path, 25));

		$element = builderElement(builderLabel($builder->toZpl()), 0, ImageElement::class);

		expect([$element->bitmap->width, $element->bitmap->height])->toBe([200, 1]);
	});

	test("drawGraphic with dithering keeps the size of the image", function () {
		$source = imagecreatetruecolor(64, 16);

		for ($x = 0; $x < 64; $x++) {
			$level = $x * 4;
			imagefilledrectangle($source, $x, 0, $x, 15, (int) imagecolorallocate($source, $level, $level, $level));
		}

		$builder = new ZplBuilder();
		withPngFile($source, fn (string $path) => $builder->drawGraphic(0, 0, $path, 0, true));

		$element = builderElement(builderLabel($builder->toZpl()), 0, ImageElement::class);

		expect([$element->bitmap->width, $element->bitmap->height])->toBe([64, 16])
			->and($element->bitmap->pixel(0, 0))->toBeTrue()
			->and($element->bitmap->pixel(63, 0))->toBeFalse();
	});

	test("uncompressed image data decodes to the same pixels", function () {
		$source = builderPattern();
		$zpl = "^XA^FO0,0" . (new GraphicField())->encodeImage(pngBytes($source), 0, false) . "^FS^XZ";

		expectBitmapMatches(builderElement(builderLabel($zpl), 0, ImageElement::class), $source);
	});

	test("the black threshold decides which gray levels print", function () {
		$source = imagecreatetruecolor(2, 1);
		imagesetpixel($source, 0, 0, (int) imagecolorallocate($source, 100, 100, 100));
		imagesetpixel($source, 1, 0, (int) imagecolorallocate($source, 200, 200, 200));

		$field = new GraphicField();
		$default = "^XA^FO0,0" . $field->encodeImage(pngBytes($source), 0) . "^FS^XZ";
		$field->setBlackThreshold(700);
		$raised = "^XA^FO0,0" . $field->encodeImage(pngBytes($source), 0) . "^FS^XZ";

		expectBitmap(builderElement(builderLabel($default), 0, ImageElement::class), 2, 1, fn (int $x): bool => $x === 0);
		expectBitmap(builderElement(builderLabel($raised), 0, ImageElement::class), 2, 1, fn (): bool => true);
	});
});

describe("pages and raw commands", function () {
	test("newPage starts a new label and returns to the top margin", function () {
		$builder = new ZplBuilder();
		$builder->setMargin(20);
		$builder->setXY(20, 300);
		$builder->drawCell(100, 40, "first");
		$builder->newPage();
		$builder->drawCell(100, 40, "second");

		$labels = builderLabels($builder->toZpl());
		$first = builderElement($labels[0], 0, TextElement::class);
		$second = builderElement($labels[1], 0, TextElement::class);

		expect($labels)->toHaveCount(2)
			->and([$first->x, $first->y, $first->text])->toBe([30, 310, "first"])
			->and([$second->x, $second->y, $second->text])->toBe([30, 10, "second"]);
	});

	test("pre and post commands wrap every page", function () {
		$builder = new ZplBuilder();
		$builder->addPreCommand("~SD15");
		$builder->addPostCommand("~SD25");
		$builder->drawText(0, 0, "first");
		$builder->newPage();
		$builder->drawText(0, 0, "second");

		$zpl = $builder->toZpl();

		expect(substr_count($zpl, "~SD15\n^XA"))->toBe(2)
			->and(substr_count($zpl, "^XZ\n~SD25"))->toBe(2)
			->and(builderLabels($zpl))->toHaveCount(2);
	});

	test("setPreCommands and setPostCommands replace the earlier ones", function () {
		$builder = new ZplBuilder();
		$builder->addPreCommand("~SD10");
		$builder->setPreCommands(["~SD15", ["~SD", 16]]);
		$builder->setPostCommands(["~SD20"]);

		$zpl = $builder->toZpl();

		expect($zpl)->toStartWith("~SD15\n~SD16\n^XA")
			->and($zpl)->toEndWith("^XZ\n~SD20\n")
			->and($zpl)->not->toContain("~SD10")
			->and(builderLabels($zpl))->toHaveCount(1);
	});

	test("addCommand and dynamic methods add commands inside the label", function () {
		$builder = new ZplBuilder();
		$builder->addCommand("PW", 500);
		$builder->addCommand("^LL250");
		$builder->__call("LH", [30, 40]);
		$builder->__call("CF", ["D", 30]);
		$builder->drawText(10, 10, "raw");

		$label = builderLabel($builder->toZpl());
		$text = builderElement($label, 0, TextElement::class);

		expect([$label->width, $label->height])->toBe([500, 250])
			->and([$text->x, $text->y, $text->font->id, $text->font->height])->toBe([40, 50, "D", 30]);
	});

	test("a macro can draw with the builder", function () {
		ZplBuilder::macro("stampForBuilderTest", function (int $x, int $y): void {
			/** @var ZplBuilder $this */
			$this->drawRect($x, $y, 100, 50);
			$this->drawText($x + 10, $y + 10, "Stamp");
		});

		$builder = new ZplBuilder();
		$builder->__call("stampForBuilderTest", [20, 30]);

		$label = builderLabel($builder->toZpl());
		$box = builderElement($label, 0, BoxElement::class);
		$text = builderElement($label, 1, TextElement::class);

		expect([$box->x, $box->y, $box->width, $box->height])->toBe([20, 30, 100, 50])
			->and([$text->x, $text->y, $text->text])->toBe([30, 40, "Stamp"]);
	});

	test("reset clears the label, and the builder converts to a string", function () {
		$builder = new ZplBuilder();
		$builder->addPreCommand("~SD15");
		$builder->drawText(0, 0, "gone");
		$builder->reset();

		expect((string) $builder)->toBe($builder->toZpl())
			->and($builder->toZpl())->toBe("^XA\n^XZ\n")
			->and(builderLabel($builder->toZpl())->elements)->toBe([]);
	});
});
