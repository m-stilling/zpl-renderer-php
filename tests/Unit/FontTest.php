<?php

use Stilling\Zpl\Font\Encoding;
use Stilling\Zpl\Font\Typeface;
use Stilling\Zpl\Font\ZebraFont;
use Stilling\Zpl\Model\FieldBlock;
use Stilling\Zpl\Model\FontSpec;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;
use Stilling\Zpl\Renderer\TextLayout;

test("the scalable font keeps the requested height as its cell and condenses the width", function () {
	$font = ZebraFont::resolve(new FontSpec("0", 40, 40));

	expect($font->typeface)->toBe(Typeface::Scalable)
		->and($font->lineHeight)->toBe(40.0)
		->and($font->ascent + $font->descent)->toBe(40.0)
		->and($font->capHeight())->toEqualWithDelta(32.0, 0.001)
		->and($font->width("H"))->toEqualWithDelta($font->face->widths[72] / 1000 * $font->size * 0.887, 0.001);
});

test("a wider request stretches the scalable font", function () {
	$narrow = ZebraFont::resolve(new FontSpec("0", 40, 40));
	$wide = ZebraFont::resolve(new FontSpec("0", 40, 80));

	expect($wide->width("W"))->toEqualWithDelta(2 * $narrow->width("W"), 0.001);
});

test("bitmap fonts magnify in whole steps and keep their pitch", function () {
	$one = ZebraFont::resolve(new FontSpec("A", 9, 5));
	$two = ZebraFont::resolve(new FontSpec("A", 18, 10));
	$rounded = ZebraFont::resolve(new FontSpec("A", 20, 12));

	expect($one->typeface)->toBe(Typeface::Mono)
		->and($one->lineHeight)->toBe(9.0)
		->and($one->width("AB"))->toEqualWithDelta(12.0, 0.001)
		->and($two->lineHeight)->toBe(18.0)
		->and($two->width("AB"))->toEqualWithDelta(24.0, 0.001)
		->and($rounded->lineHeight)->toBe(18.0);
});

test("fonts B and H are bold or upper-case only", function () {
	expect(ZebraFont::resolve(new FontSpec("B", 11))->typeface)->toBe(Typeface::MonoBold)
		->and(ZebraFont::resolve(new FontSpec("B", 11))->uppercase)->toBeTrue()
		->and(ZebraFont::resolve(new FontSpec("H", 21))->uppercase)->toBeTrue()
		->and(ZebraFont::resolve(new FontSpec("D", 18))->uppercase)->toBeFalse();
});

test("unknown font ids fall back to the scalable font", function () {
	expect(ZebraFont::resolve(new FontSpec("Q", 0))->typeface)->toBe(Typeface::Scalable)
		->and(ZebraFont::resolve(new FontSpec("Q", 0))->lineHeight)->toBe(15.0);
});

test("text is converted to WinAnsi and escaped for PDF literals", function () {
	expect(Encoding::toWinAnsi("Ål (x) \\"))->toBe("\xC5l (x) \\")
		->and(Encoding::pdfLiteral("a(b)\\c"))->toBe("(a\\(b\\)\\\\c)")
		->and(Encoding::toWinAnsi("\u{4E2D}"))->toBe("?");
});

test("a plain field is one line per newline", function () {
	$font = ZebraFont::resolve(new FontSpec("0", 20));
	$layout = TextLayout::layout(new TextElement(0, 0, "ab\ncd", new FontSpec("0", 20)), $font);

	expect($layout->lines)->toHaveCount(2)
		->and($layout->lines[1]->baseline)->toBe($layout->lines[0]->baseline + 20)
		->and($layout->height)->toBe(40.0);
});

test("a field block wraps words, limits lines and justifies", function () {
	$spec = new FontSpec("A", 9, 5);
	$font = ZebraFont::resolve($spec);
	$block = new FieldBlock(width: 60, maxLines: 2, lineSpacing: 1, justification: TextJustification::Right);
	$layout = TextLayout::layout(new TextElement(0, 0, "aaaa bbbb cccc dddd", $spec, block: $block), $font);

	expect(array_map(fn ($line) => $line->text, $layout->lines))->toBe(["aaaa bbbb", "cccc dddd"])
		->and($layout->lines[0]->x)->toEqualWithDelta(60 - 9 * 6, 0.001)
		->and($layout->lines[1]->baseline - $layout->lines[0]->baseline)->toBe(10.0)
		->and($layout->width)->toBe(60.0);
});

test("a word longer than the block is broken between characters", function () {
	$spec = new FontSpec("A", 9, 5);
	$block = new FieldBlock(width: 30, maxLines: 5);
	$layout = TextLayout::layout(new TextElement(0, 0, "abcdefghij", $spec, block: $block), ZebraFont::resolve($spec));

	expect(array_map(fn ($line) => $line->text, $layout->lines))->toBe(["abcde", "fghij"]);
});

test("justified lines spread the extra space over the spaces except on the last line", function () {
	$spec = new FontSpec("A", 9, 5);
	$block = new FieldBlock(width: 70, maxLines: 3, justification: TextJustification::Justified);
	$layout = TextLayout::layout(new TextElement(0, 0, "aa bb cc dd ee", $spec, block: $block), ZebraFont::resolve($spec));

	expect($layout->lines[0]->wordSpacing)->toBeGreaterThan(0)
		->and($layout->lines[count($layout->lines) - 1]->wordSpacing)->toBe(0.0);
});

test("hanging indent shifts every line but the first", function () {
	$spec = new FontSpec("A", 9, 5);
	$block = new FieldBlock(width: 40, maxLines: 3, hangingIndent: 12);
	$layout = TextLayout::layout(new TextElement(0, 0, "aaaaaa bbb ccc", $spec, block: $block), ZebraFont::resolve($spec));

	expect($layout->lines[0]->x)->toBe(0.0)->and($layout->lines[1]->x)->toBe(12.0);
});
