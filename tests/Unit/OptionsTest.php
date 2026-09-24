<?php

use Stilling\ZplRenderer\Options;
use Stilling\ZplRenderer\OptionsImmutable;

test("the constructor takes every option as a named argument", function () {
	$options = new Options(dpmm: 12, widthMm: 50, heightMm: 25, pixelsPerDot: 2, antialias: false);

	expect($options->getDpmm())->toBe(12)
		->and($options->getWidthMm())->toBe(50.0)
		->and($options->getHeightMm())->toBe(25.0)
		->and($options->getPixelsPerDot())->toBe(2)
		->and($options->getAntialias())->toBeFalse()
		->and($options->widthDots())->toBe(600)
		->and($options->heightDots())->toBe(300)
		->and($options->dpi())->toEqualWithDelta(304.8, 0.001);
});

test("Options setters change the instance and return it", function () {
	$options = new Options();
	$result = $options->dpmm(12)->widthMm(50)->antialias(false);

	expect($result)->toBe($options)
		->and($options->getDpmm())->toBe(12)
		->and($options->getWidthMm())->toBe(50.0)
		->and($options->getAntialias())->toBeFalse();
});

test("OptionsImmutable setters return a copy and leave the instance as it is", function () {
	$options = new OptionsImmutable(dpmm: 8);
	$result = $options->dpmm(12)->widthMm(50);

	expect($result)->toBeInstanceOf(OptionsImmutable::class)
		->and($result)->not->toBe($options)
		->and($result->getDpmm())->toBe(12)
		->and($result->getWidthMm())->toBe(50.0)
		->and($options->getDpmm())->toBe(8)
		->and($options->getWidthMm())->toBe(101.6);
});

test("OptionsImmutable is filled by its constructor", function () {
	$options = OptionsImmutable::inches(12, 4, 6);

	expect($options)->toBeInstanceOf(OptionsImmutable::class)
		->and($options->getDpmm())->toBe(12)
		->and($options->getWidthMm())->toEqualWithDelta(101.6, 0.001)
		->and($options->getHeightMm())->toEqualWithDelta(152.4, 0.001);
});

test("setters reject invalid values", function (callable $call, string $message) {
	expect($call)->toThrow(InvalidArgumentException::class, $message);
})->with([
	[fn () => (new Options())->dpmm(10), "dpmm must be 6, 8, 12 or 24"],
	[fn () => (new Options())->widthMm(0), "Label width must be positive"],
	[fn () => (new Options())->heightMm(-1), "Label height must be positive"],
	[fn () => (new Options())->maxPages(0), "maxPages must be at least 1"],
	[fn () => (new Options())->maxGraphicBytes(0), "maxGraphicBytes must be at least 1"],
	[fn () => (new Options())->maxLabelDots(0), "maxLabelDots must be at least 1"],
	[fn () => (new Options())->pixelsPerDot(9), "pixelsPerDot must be between 1 and 8"],
	[fn () => new Options(dpmm: 7), "dpmm must be 6, 8, 12 or 24"],
]);
