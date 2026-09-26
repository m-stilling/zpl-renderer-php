<?php

use Stilling\ZplRenderer\Renderer\GlyphRaster;

/**
 * @param list<array{float, float}> $points
 * @return list<array{float, float, bool}>
 */
function contour(array $points): array {
	return array_map(fn (array $point): array => [$point[0], $point[1], true], $points);
}

/**
 * The pixels covered at least by half, as runs: row, first column, last column.
 *
 * @param list<list<array{float, float, bool}>> $contours
 * @return list<array{int, int, int}>
 */
function runs(array $contours, float $scaleX, float $scaleY): array {
	$runs = [];

	foreach (pixels($contours, $scaleX, $scaleY) as $key => $coverage) {
		[$row, $column] = array_map(intval(...), explode(",", $key));
		$last = array_key_last($runs);

		if ($coverage < 0.5) {
			continue;
		}

		if ($last !== null && $runs[$last][0] === $row && $runs[$last][2] === $column - 1) {
			$runs[$last][2] = $column;
		} else {
			$runs[] = [$row, $column, $column];
		}
	}

	return $runs;
}

/**
 * The covered fraction of every covered pixel, keyed by "row,column", in row order.
 *
 * @param list<list<array{float, float, bool}>> $contours
 * @param array{int, int, int, int}|null $clip
 * @return array<string, float>
 */
function pixels(array $contours, float $scaleX, float $scaleY, ?array $clip = null): array {
	$pixels = [];

	foreach (GlyphRaster::coverage($contours, $scaleX, $scaleY, $clip) as [$row, $first, $values]) {
		foreach ($values as $offset => $coverage) {
			if ($coverage > 0.0) {
				$pixels[$row . "," . ($first + $offset)] = $coverage;
			}
		}
	}

	return $pixels;
}

test("a square fills the pixels it covers above the baseline", function () {
	$runs = runs([contour([[0, 0], [1, 0], [1, 1], [0, 1]])], 10, 10);

	expect($runs)->toHaveCount(10)
		->and($runs[0])->toBe([-10, 0, 9])
		->and($runs[9])->toBe([-1, 0, 9]);
});

test("a contour that runs the other way cuts a hole", function () {
	$outer = contour([[0, 0], [1, 0], [1, 1], [0, 1]]);
	$hole = contour([[0.3, 0.3], [0.3, 0.7], [0.7, 0.7], [0.7, 0.3]]);
	$runs = runs([$outer, $hole], 10, 10);

	expect(array_values(array_filter($runs, fn (array $run): bool => $run[0] === -5)))->toBe([[-5, 0, 2], [-5, 7, 9]]);
});

test("overlapping contours that run the same way stay filled", function () {
	$left = contour([[0, 0], [0.6, 0], [0.6, 1], [0, 1]]);
	$right = contour([[0.4, 0], [1, 0], [1, 1], [0.4, 1]]);

	expect(runs([$left, $right], 10, 10)[0])->toBe([-10, 0, 9]);
});

test("a pixel is ink only when the outline covers at least half of it", function () {
	$runs = runs([contour([[0.04, 0], [0.96, 0], [0.96, 1], [0.04, 1]])], 10, 10);

	expect($runs[0])->toBe([-10, 0, 9]);

	$runs = runs([contour([[0.06, 0], [0.94, 0], [0.94, 1], [0.06, 1]])], 10, 10);

	expect($runs[0])->toBe([-10, 1, 8]);
});

test("curves are flattened through their off-curve control points", function () {
	$circle = [[0.5, 0, true], [1, 0, false], [1, 0.5, true], [1, 1, false], [0.5, 1, true], [0, 1, false], [0, 0.5, true], [0, 0, false]];
	$runs = runs([$circle], 20, 20);
	$middle = array_values(array_filter($runs, fn (array $run): bool => $run[0] === -10))[0];
	$top = $runs[0];

	expect($middle)->toBe([-10, 0, 19])
		->and($top[1])->toBeGreaterThan(3)
		->and($top[2])->toBeLessThan(16);
});

test("coverage is the covered fraction of each pixel", function () {
	$byPixel = pixels([contour([[0.25, 0], [1, 0], [1, 0.5], [0.25, 0.5]])], 10, 10);

	expect($byPixel["-5,2"])->toEqualWithDelta(0.5, 0.001)
		->and($byPixel["-5,5"])->toEqualWithDelta(1.0, 0.001)
		->and($byPixel)->not->toHaveKey("-5,1")
		->and($byPixel)->not->toHaveKey("-6,5");
});

test("a row holds 0 for the pixels in a hole", function () {
	$outer = contour([[0, 0], [1, 0], [1, 1], [0, 1]]);
	$hole = contour([[0.3, 0.3], [0.3, 0.7], [0.7, 0.7], [0.7, 0.3]]);
	$row = array_values(array_filter(GlyphRaster::coverage([$outer, $hole], 10, 10), fn (array $row): bool => $row[0] === -5))[0];

	expect($row[1])->toBe(0)
		->and(array_map(fn (float $coverage): float => round($coverage, 6), $row[2]))->toBe([1.0, 1.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0]);
});

test("a clip measures the same pixels inside it and none outside it", function () {
	$circle = [[[0.5, 0, true], [1, 0, false], [1, 0.5, true], [1, 1, false], [0.5, 1, true], [0, 1, false], [0, 0.5, true], [0, 0, false]]];
	$whole = pixels($circle, 40, 40);
	$clipped = pixels($circle, 40, 40, [-30, -10, 5, 25]);
	$inside = array_filter($whole, function (string $key): bool {
		[$row, $column] = array_map(intval(...), explode(",", $key));

		return $row >= -30 && $row < -10 && $column >= 5 && $column < 25;
	}, ARRAY_FILTER_USE_KEY);

	expect($clipped)->toBe($inside)
		->and($clipped)->not->toBeEmpty();
});

test("the extent encloses every covered pixel", function () {
	$circle = [[[0.5, 0, true], [1, 0, false], [1, 0.5, true], [1, 1, false], [0.5, 1, true], [0, 1, false], [0, 0.5, true], [0, 0, false]]];
	[$top, $bottom, $left, $right] = GlyphRaster::extent($circle, 40, 30) ?? [0, 0, 0, 0];

	foreach (array_keys(pixels($circle, 40, 30)) as $key) {
		[$row, $column] = array_map(intval(...), explode(",", $key));

		expect($row)->toBeGreaterThanOrEqual($top)->toBeLessThan($bottom)
			->and($column)->toBeGreaterThanOrEqual($left)->toBeLessThan($right);
	}

	expect(GlyphRaster::extent([], 40, 30))->toBeNull();
});
