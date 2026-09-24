<?php

use Stilling\ZplRenderer\Exceptions\RenderException;
use Stilling\ZplRenderer\Font\TrueTypeFont;
use Stilling\ZplRenderer\Options;

test("reads the name, metrics and widths of Roboto Mono", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoMono-Regular.ttf");

	expect($font->postScriptName)->toBe("RobotoMono-Regular")
		->and($font->unitsPerEm)->toBe(2048)
		->and($font->fixedPitch)->toBeTrue()
		->and($font->bold)->toBeFalse()
		->and($font->widths[65])->toBe(600)
		->and($font->widths[229])->toBe(600)
		->and($font->capHeight / 1000)->toEqualWithDelta(0.71, 0.01)
		->and($font->ascent)->toBeGreaterThan(700)
		->and($font->descent)->toBeLessThan(0)
		->and($font->flags())->toBe(33);
});

test("recognizes the bold weight", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoMono-Bold.ttf");

	expect($font->bold)->toBeTrue()->and($font->flags())->toBe(33 | (1 << 18));
});

test("the condensed font is proportional", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf");

	expect($font->fixedPitch)->toBeFalse()->and($font->widths[73])->toBeLessThan($font->widths[87]);
});

test("reads glyph outlines in em, with the hole of an O as a second contour", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf");
	$outline = $font->outline(ord("O"));
	$ys = array_merge(...array_map(fn (array $contour): array => array_column($contour, 1), $outline));

	expect($outline)->toHaveCount(2)
		->and(max(-INF, ...$ys))->toEqualWithDelta($font->capHeight / 1000, 0.03)
		->and(min(INF, ...$ys))->toEqualWithDelta(0, 0.03)
		->and($font->outline(32))->toBe([]);
});

test("builds accented letters from their component glyphs", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf");
	$ring = $font->outline(0xC5);
	$ys = array_merge(...array_map(fn (array $contour): array => array_column($contour, 1), $ring));

	expect(count($ring))->toBeGreaterThan(count($font->outline(ord("A"))))
		->and(max(-INF, ...$ys))->toBeGreaterThan($font->capHeight / 1000);
});

test("a subset keeps the glyphs it is asked for and draws other characters as the missing-glyph box", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf");
	$file = sys_get_temp_dir() . "/zpl-subset-" . bin2hex(random_bytes(4)) . ".ttf";
	file_put_contents($file, $font->subset([ord("A"), 0xC5]));
	$subset = new TrueTypeFont($file);
	unlink($file);

	expect(strlen($subset->data))->toBeLessThan(5000)
		->and($subset->postScriptName)->toBe($font->postScriptName)
		->and($subset->widths[65])->toBe($font->widths[65])
		->and($subset->widths[0xC5])->toBe($font->widths[0xC5])
		->and($subset->outline(ord("A")))->toBe($font->outline(ord("A")))
		->and($subset->outline(0xC5))->toBe($font->outline(0xC5))
		->and($subset->outline(ord("B")))->not->toBe([])
		->and($subset->outline(ord("B")))->toBe($subset->outline(ord("C")))
		->and($subset->outline(ord("B")))->not->toBe($font->outline(ord("B")));
});

test("rejects a file that is not a font, and a truncated one", function (string $file) {
	new TrueTypeFont($file);
})->with([
	__FILE__,
	function (): string {
		$file = sys_get_temp_dir() . "/zpl-truncated-" . bin2hex(random_bytes(4)) . ".ttf";
		file_put_contents($file, substr((string) file_get_contents(Options::FONT_DIRECTORY . "/RobotoMono-Regular.ttf"), 0, 200));

		return $file;
	},
])->throws(RenderException::class);
