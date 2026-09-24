<?php

use Stilling\Zpl\Exceptions\UnsupportedException;
use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\Element;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Justification;
use Stilling\Zpl\Model\Label;
use Stilling\Zpl\Model\Orientation;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;
use Stilling\Zpl\Options;
use Stilling\Zpl\Zpl;

function label(string $zpl, ?Options $options = null): Label {
	$labels = Zpl::parse($zpl, $options);

	expect($labels)->toHaveCount(1);

	return $labels[0];
}

/**
 * @template T of Element
 * @param class-string<T> $class
 * @return T
 */
function element(string $zpl, string $class, int $index = 0): Element {
	$element = label($zpl)->elements[$index] ?? null;

	if (!$element instanceof $class) {
		throw new RuntimeException("Element {$index} is " . ($element === null ? "missing" : $element::class) . ", expected {$class}.");
	}

	return $element;
}

function text(string $zpl, int $index = 0): TextElement {
	return element($zpl, TextElement::class, $index);
}

function barcode(string $zpl, int $index = 0): BarcodeElement {
	return element($zpl, BarcodeElement::class, $index);
}

test("the page size comes from the options unless ^PW and ^LL override it", function () {
	$options = new Options(dpmm: 8, widthMm: 50, heightMm: 25);
	$fixed = new Options(dpmm: 8, widthMm: 50, heightMm: 25, honorLabelSize: false);

	expect(label("^XA^XZ", $options)->width)->toBe(400)
		->and(label("^XA^XZ", $options)->height)->toBe(200)
		->and(label("^XA^PW300^LL150^XZ", $options)->width)->toBe(300)
		->and(label("^XA^PW300^LL150^XZ", $options)->height)->toBe(150)
		->and(label("^XA^PW300^LL150^XZ", $fixed)->width)->toBe(400);
});

test("every ^XA ^XZ pair is one label and text outside is ignored", function () {
	expect(Zpl::parse("noise^XA^FDa^FS^XZ more ^XA^FDb^FS^XZ"))->toHaveCount(2);
});

test("a text field takes its position, font and orientation", function () {
	$text = text("^XA^FO50,60^A0R,30,20^FDHello^FS^XZ");

	expect($text->x)->toBe(50)
		->and($text->y)->toBe(60)
		->and($text->text)->toBe("Hello")
		->and($text->font->id)->toBe("0")
		->and($text->font->height)->toBe(30)
		->and($text->font->width)->toBe(20)
		->and($text->orientation)->toBe(Orientation::Rotated)
		->and($text->typeset)->toBeFalse();
});

test("^A stays in effect for later fields and ^CF replaces it", function () {
	$zpl = "^XA^FO0,0^A0N,30,30^FDa^FS^FO0,50^FDb^FS^CFB,11^FO0,100^FDc^FS^XZ";

	expect(text($zpl, 1)->font->id)->toBe("0")
		->and(text($zpl, 1)->font->height)->toBe(30)
		->and(text($zpl, 2)->font->id)->toBe("B")
		->and(text($zpl, 2)->font->height)->toBe(11);
});

test("the default font is A at 9 x 5 dots", function () {
	$font = text("^XA^FO0,0^FDx^FS^XZ")->font;

	expect($font->id)->toBe("A")->and($font->height)->toBe(9)->and($font->width)->toBe(5);
});

test("^FT marks the origin as typeset and ^FW sets the default orientation and justification", function () {
	$zpl = "^XA^FWB,1^FT10,20^A0,30^FDx^FS^FO10,20,0^FDy^FS^XZ";

	expect(text($zpl)->typeset)->toBeTrue()
		->and(text($zpl)->orientation)->toBe(Orientation::BottomUp)
		->and(text($zpl)->justification)->toBe(Justification::Right)
		->and(text($zpl, 1)->justification)->toBe(Justification::Left);
});

test("^LH, ^LS and ^LT shift every field", function () {
	$text = text("^XA^LH10,20^LS5^LT7^FO100,100^FDx^FS^XZ");

	expect($text->x)->toBe(115)->and($text->y)->toBe(127);
});

test("^FR, ^FH and ^FB apply to the current field only", function () {
	$zpl = "^XA^FO0,0^FR^FH^FB200,3,2,C,10^FDA_41 B^FS^FO0,50^FDplain^FS^XZ";
	$first = text($zpl);
	$second = text($zpl, 1);

	expect($first->reverse)->toBeTrue()
		->and($first->text)->toBe("AA B")
		->and($first->block?->width)->toBe(200)
		->and($first->block?->maxLines)->toBe(3)
		->and($first->block?->lineSpacing)->toBe(2)
		->and($first->block?->justification)->toBe(TextJustification::Center)
		->and($first->block?->hangingIndent)->toBe(10)
		->and($second->reverse)->toBeFalse()
		->and($second->block)->toBeNull();
});

test("^FH honors a custom indicator", function () {
	expect(text("^XA^FO0,0^FH#^FD#41#5E^FS^XZ")->text)->toBe("A^");
});

test("backslash escapes give a newline and a backslash", function () {
	expect(text("^XA^FO0,0^FDone\\&two\\\\^FS^XZ")->text)->toBe("one\ntwo\\");
});

test("^CI28 keeps UTF-8 and the default code page converts CP850", function () {
	expect(text("^XA^CI28^FO0,0^FDæøå^FS^XZ")->text)->toBe("æøå")
		->and(text("^XA^FO0,0^FD\x86\x9B\x91^FS^XZ")->text)->toBe("åø\u{00E6}");
});

test("graphic boxes and circles read their parameters", function () {
	$zpl = "^XA^FO10,10^GB100,50,3,W,2^FS^FO0,0^GC40,5^FS^XZ";
	$box = element($zpl, BoxElement::class);
	$circle = element($zpl, CircleElement::class, 1);

	expect($box->width)->toBe(100)
		->and($box->height)->toBe(50)
		->and($box->thickness)->toBe(3)
		->and($box->black)->toBeFalse()
		->and($box->rounding)->toBe(2)
		->and($circle->diameter)->toBe(40)
		->and($circle->thickness)->toBe(5);
});

test("a box smaller than its thickness grows to the thickness", function () {
	$box = element("^XA^FO0,0^GB1,1,10^FS^XZ", BoxElement::class);

	expect($box->width)->toBe(10)->and($box->height)->toBe(10);
});

test("^BY sets the module width and default height for the following barcodes", function () {
	$zpl = "^XA^BY4,3,120^FO0,0^BCN^FD123^FS^FO0,0^BCN,50^FD123^FS^XZ";

	expect(barcode($zpl)->moduleWidth)->toBe(4.0)
		->and(barcode($zpl)->moduleHeight)->toBe(120.0)
		->and(barcode($zpl, 1)->moduleHeight)->toBe(50.0);
});

test("the interpretation line follows the ^BC flags", function () {
	$zpl = "^XA^FO0,0^BCN,50,Y,Y^FDabc^FS^FO0,0^BCN,50,N^FDabc^FS^XZ";

	expect(barcode($zpl)->text)->toBe("abc")
		->and(barcode($zpl)->textAbove)->toBeTrue()
		->and(barcode($zpl, 1)->text)->toBeNull();
});

test("a barcode without data is dropped", function () {
	expect(label("^XA^FO0,0^BCN^FS^XZ")->elements)->toBe([]);
});

test("every supported symbology encodes", function (string $command, string $data) {
	$element = barcode("^XA^FO0,0{$command}^FD{$data}^FS^XZ");

	expect($element->matrix->columns)->toBeGreaterThan(0)
		->and($element->matrix->bars)->not->toBeEmpty();
})->with([
	["^B3N,N,50", "CODE39"],
	["^B3N,Y,50", "check"],
	["^BAN,50", "CODE93"],
	["^BEN,50", "590123412345"],
	["^B8N,50", "1234567"],
	["^BUN,50", "01234567890"],
	["^B9N,50", "425261"],
	["^B2N,50", "1234567890"],
	["^BIN,50", "12345"],
	["^BJN,50", "12345"],
	["^BKN,N,50", "A123456A"],
	["^BKN,N,50", "123456"],
	["^BMN,N,50", "123456"],
	["^BLN,50", "LOGMARS"],
	["^BPN,N,50", "12345"],
	["^B1N,N,50", "123-45"],
	["^BZN,50", "12345"],
	["^B5N,50", "12345678901"],
	["^BSN,50", "12345"],
	["^BRN,1,4,,25", "0123456789012"],
	["^B4N,50", "CODE49"],
	["^BQN,2,4", "QA,https://example.com"],
	["^BQN,2,4", "HM,N0123"],
	["^BQN,2,4", "plain data"],
	["^BXN,6,200", "Data Matrix"],
	["^BXN,6,200,,,,,2", "Rectangular"],
	["^B7N,4,3", "PDF417"],
	["^B7N,4,3,,,Y", "Truncated"],
	["^BON,4", "Aztec"],
	["^B0N,4", "Aztec"],
	["^BCN,50,Y,N,N,A", "auto 12345678"],
	["^BCN,50,Y,N,N,U", "0034567890123456789"],
	["^BCN,50,Y,N,N,D", "(01)09501101530003"],
]);

test("QR field data prefixes select the error correction level and are stripped", function () {
	$plain = barcode("^XA^FO0,0^BQN,2,4^FDdata^FS^XZ");
	$prefixed = barcode("^XA^FO0,0^BQN,2,4^FDQA,data^FS^XZ");

	expect($prefixed->matrix->toRows())->toBe($plain->matrix->toRows());
});

test("QR mask parameter selects the mask pattern and defaults to 7", function () {
	$default = barcode("^XA^FO0,0^BQN,2,4^FDdata^FS^XZ");
	$seven = barcode("^XA^FO0,0^BQN,2,4,Q,7^FDdata^FS^XZ");
	$two = barcode("^XA^FO0,0^BQN,2,4,Q,2^FDdata^FS^XZ");

	expect($default->matrix->toRows())->toBe($seven->matrix->toRows())
		->and($two->matrix->toRows())->not->toBe($seven->matrix->toRows());
});

test("2D symbols scale by their own magnification", function () {
	$zpl = "^XA^BY3^FO0,0^BQN,2,5^FDx^FS^FO0,0^BXN,7^FDx^FS^FO0,0^B7N,9^FDx^FS^XZ";

	expect(barcode($zpl)->moduleWidth)->toBe(5.0)
		->and(barcode($zpl)->moduleHeight)->toBe(5.0)
		->and(barcode($zpl, 1)->moduleWidth)->toBe(7.0)
		->and(barcode($zpl, 2)->moduleWidth)->toBe(3.0)
		->and(barcode($zpl, 2)->moduleHeight)->toBe(3.0);
});

test("unsupported barcodes throw", function () {
	label("^XA^FO0,0^BDN^FDx^FS^XZ");
})->throws(UnsupportedException::class);

test("unknown commands are ignored", function () {
	expect(label("^XA^MMT^MNY^JUS^ZZ1,2^FO0,0^FDx^FS^XZ")->elements)->toHaveCount(1);
});

test("~DG stores a graphic that ^XG recalls with magnification", function () {
	$zpl = "~DGR:BOX.GRF,3,1,FF00FF^XA^FO5,6^XGR:BOX.GRF,2,3^FS^FO0,0^IMR:BOX^FS^XZ";
	$image = element($zpl, ImageElement::class);

	expect($image->bitmap->width)->toBe(8)
		->and($image->bitmap->height)->toBe(3)
		->and($image->magnificationX)->toBe(2)
		->and($image->magnificationY)->toBe(3)
		->and($image->width())->toBe(16)
		->and($image->height())->toBe(9)
		->and(element($zpl, ImageElement::class, 1)->magnificationX)->toBe(1);
});

test("^GF draws an inline graphic and keeps commas in compressed data", function () {
	$image = element("^XA^FO0,0^GFA,4,4,2,F,:^FS^XZ", ImageElement::class);

	expect($image->bitmap->pixel(0, 0))->toBeTrue()
		->and($image->bitmap->pixel(8, 0))->toBeFalse()
		->and($image->bitmap->pixel(0, 1))->toBeTrue();
});

test("^XG of an unknown graphic draws nothing", function () {
	expect(label("^XA^FO0,0^XGR:NOPE.GRF^FS^XZ")->elements)->toBe([]);
});

test("^DF stores a format that ^XF recalls with ^FN values filled in", function () {
	$zpl = "^XA^DFR:F.ZPL^FS^FO10,10^FN1^FS^FO10,50^FN2^FDdefault^FS^XZ^XA^XFR:F.ZPL^FS^FN1^FDone^FS^XZ";

	expect(Zpl::parse($zpl))->toHaveCount(1)
		->and(label($zpl)->elements)->toHaveCount(2)
		->and(text($zpl)->text)->toBe("one")
		->and(text($zpl)->x)->toBe(10)
		->and(text($zpl, 1)->text)->toBe("default");
});

test("^PQ, ^PO, ^PM and ^LR set the label flags", function () {
	$label = label("^XA^PQ3^POI^PMY^LRY^XZ");

	expect($label->quantity)->toBe(3)
		->and($label->inverted)->toBeTrue()
		->and($label->mirrored)->toBeTrue()
		->and($label->reversed)->toBeTrue();
});

test("a field without ^FS before ^XZ is still printed", function () {
	expect(label("^XA^FO0,0^FDlast^XZ")->elements)->toHaveCount(1);
});

test("^SN prints its start value", function () {
	expect(text("^XA^FO0,0^SN001,1,Y^FS^XZ")->text)->toBe("001");
});
