<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Builder helpers
|--------------------------------------------------------------------------
|
| Shared by the tests that render the ZPL of third-party label builders.
|
*/

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
 * Parse the ZPL and check that every label renders to PDF and PNG.
 *
 * @return list<Stilling\ZplRenderer\Model\Label>
 */
function builderLabels(string $zpl): array {
	$labels = Stilling\ZplRenderer\Zpl::parse($zpl);

	expect(Stilling\ZplRenderer\Zpl::toPdf($zpl))->toStartWith("%PDF-")
		->and(Stilling\ZplRenderer\Zpl::toPngs($zpl))->toHaveCount(count($labels));

	return $labels;
}

function builderLabel(string $zpl): Stilling\ZplRenderer\Model\Label {
	$labels = builderLabels($zpl);

	expect($labels)->toHaveCount(1);

	return $labels[0];
}

/**
 * @template T of Stilling\ZplRenderer\Model\Element
 * @param class-string<T> $class
 * @return T
 */
function builderElement(Stilling\ZplRenderer\Model\Label $label, int $index, string $class): Stilling\ZplRenderer\Model\Element {
	$element = $label->elements[$index] ?? null;

	expect($element)->toBeInstanceOf($class);

	/** @var T $element */
	return $element;
}

/**
 * @param callable(int, int): bool $black whether the source pixel at x, y is black
 */
function expectBitmap(Stilling\ZplRenderer\Model\ImageElement $element, int $width, int $height, callable $black): void {
	$wrong = 0;

	for ($y = 0; $y < $height; $y++) {
		for ($x = 0; $x < $width; $x++) {
			if ($element->bitmap->pixel($x, $y) !== $black($x, $y)) {
				$wrong++;
			}
		}
	}

	expect($element->bitmap->width)->toBe((int) ceil($width / 8) * 8)
		->and($element->bitmap->height)->toBe($height)
		->and($wrong)->toBe(0);
}

function expectBitmapMatches(Stilling\ZplRenderer\Model\ImageElement $element, GdImage $source): void {
	expectBitmap($element, imagesx($source), imagesy($source), fn (int $x, int $y): bool => (imagecolorat($source, $x, $y) & 0xFF) < 128);
}
