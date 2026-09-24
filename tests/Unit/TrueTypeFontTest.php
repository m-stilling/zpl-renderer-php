<?php

use Stilling\Zpl\Exceptions\RenderException;
use Stilling\Zpl\Font\FontMetrics;
use Stilling\Zpl\Options;
use Stilling\Zpl\Pdf\TrueTypeFont;

test("reads the name, metrics and widths of Roboto Mono", function () {
	$font = new TrueTypeFont(Options::FONT_DIRECTORY . "/RobotoMono-Regular.ttf");

	expect($font->postScriptName)->toBe("RobotoMono-Regular")
		->and($font->unitsPerEm)->toBe(2048)
		->and($font->fixedPitch)->toBeTrue()
		->and($font->bold)->toBeFalse()
		->and($font->widths[65])->toBe(FontMetrics::MONO_ADVANCE)
		->and($font->widths[229])->toBe(FontMetrics::MONO_ADVANCE)
		->and($font->capHeight / 1000)->toEqualWithDelta(FontMetrics::CAP_HEIGHT[FontMetrics::MONO], 0.01)
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
