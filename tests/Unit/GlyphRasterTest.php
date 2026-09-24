<?php

use Stilling\Zpl\Renderer\GlyphRaster;

/**
 * @param list<array{float, float}> $points
 * @return list<array{float, float, bool}>
 */
function contour(array $points): array {
	return array_map(fn (array $point): array => [$point[0], $point[1], true], $points);
}

test("a square fills the pixels it covers above the baseline", function () {
	$runs = GlyphRaster::runs([contour([[0, 0], [1, 0], [1, 1], [0, 1]])], 10, 10);

	expect($runs)->toHaveCount(10)
		->and($runs[0])->toBe([-10, 0, 9])
		->and($runs[9])->toBe([-1, 0, 9]);
});

test("a contour that runs the other way cuts a hole", function () {
	$outer = contour([[0, 0], [1, 0], [1, 1], [0, 1]]);
	$hole = contour([[0.3, 0.3], [0.3, 0.7], [0.7, 0.7], [0.7, 0.3]]);
	$runs = GlyphRaster::runs([$outer, $hole], 10, 10);

	expect(array_values(array_filter($runs, fn (array $run): bool => $run[0] === -5)))->toBe([[-5, 0, 2], [-5, 7, 9]]);
});

test("overlapping contours that run the same way stay filled", function () {
	$left = contour([[0, 0], [0.6, 0], [0.6, 1], [0, 1]]);
	$right = contour([[0.4, 0], [1, 0], [1, 1], [0.4, 1]]);

	expect(GlyphRaster::runs([$left, $right], 10, 10)[0])->toBe([-10, 0, 9]);
});

test("a pixel is ink only when the outline covers at least half of it", function () {
	$runs = GlyphRaster::runs([contour([[0.04, 0], [0.96, 0], [0.96, 1], [0.04, 1]])], 10, 10);

	expect($runs[0])->toBe([-10, 0, 9]);

	$runs = GlyphRaster::runs([contour([[0.06, 0], [0.94, 0], [0.94, 1], [0.06, 1]])], 10, 10);

	expect($runs[0])->toBe([-10, 1, 8]);
});

test("curves are flattened through their off-curve control points", function () {
	$circle = [[0.5, 0, true], [1, 0, false], [1, 0.5, true], [1, 1, false], [0.5, 1, true], [0, 1, false], [0, 0.5, true], [0, 0, false]];
	$runs = GlyphRaster::runs([$circle], 20, 20);
	$middle = array_values(array_filter($runs, fn (array $run): bool => $run[0] === -10))[0];
	$top = $runs[0];

	expect($middle)->toBe([-10, 0, 19])
		->and($top[1])->toBeGreaterThan(3)
		->and($top[2])->toBeLessThan(16);
});
