<?php

namespace Stilling\Zpl\Renderer;

/**
 * Turns a glyph outline into one-bit pixels. A pixel is ink when the outline
 * covers at least half of it, under the nonzero winding rule that TrueType uses.
 */
class GlyphRaster {
	/** Sample rows per pixel row; across a row, coverage is exact. */
	private const int SUBROWS = 4;

	/** Longest straight piece a curve is flattened into, in pixels. */
	private const float FLATNESS = 1.5;

	/**
	 * The ink of a glyph as horizontal runs of pixels, relative to the glyph
	 * origin on the baseline: row (negative above the baseline), first column,
	 * last column.
	 *
	 * @param list<list<array{float, float, bool}>> $contours outline in em, y up
	 * @param float $scaleX pixels per em horizontally
	 * @param float $scaleY pixels per em vertically
	 * @return list<array{int, int, int}>
	 */
	public static function runs(array $contours, float $scaleX, float $scaleY): array {
		$edges = [];

		foreach ($contours as $contour) {
			$points = array_map(fn (array $point): array => [$point[0] * $scaleX, -$point[1] * $scaleY, $point[2]], $contour);
			$polygon = self::flatten($points);
			$count = count($polygon);

			for ($i = 0; $i < $count; $i++) {
				[$x0, $y0] = $polygon[$i];
				[$x1, $y1] = $polygon[($i + 1) % $count];

				if ($y0 !== $y1) {
					$edges[] = $y0 < $y1 ? [$x0, $y0, $x1, $y1, 1] : [$x1, $y1, $x0, $y0, -1];
				}
			}
		}

		if ($edges === []) {
			return [];
		}

		$top = (int) floor(min(array_column($edges, 1)));
		$bottom = (int) ceil(max(array_column($edges, 3)));
		$runs = [];

		for ($row = $top; $row < $bottom; $row++) {
			/** @var array<int, float> $coverage */
			$coverage = [];

			for ($sub = 0; $sub < self::SUBROWS; $sub++) {
				$y = $row + ($sub + 0.5) / self::SUBROWS;

				foreach (self::spans($edges, $y) as [$from, $to]) {
					for ($column = (int) floor($from); $column < $to; $column++) {
						$overlap = min($to, $column + 1) - max($from, $column);
						$coverage[$column] = ($coverage[$column] ?? 0.0) + $overlap / self::SUBROWS;
					}
				}
			}

			$ink = array_keys(array_filter($coverage, fn (float $amount): bool => $amount >= 0.5));
			sort($ink);

			$start = null;

			foreach ($ink as $index => $column) {
				$start ??= $column;

				if (($ink[$index + 1] ?? null) !== $column + 1) {
					$runs[] = [$row, $start, $column];
					$start = null;
				}
			}
		}

		return $runs;
	}

	/**
	 * The stretches of a sample row inside the outline.
	 *
	 * @param list<array{float, float, float, float, int}> $edges x0, y0, x1, y1 with y0 < y1, and the winding direction
	 * @return list<array{float, float}>
	 */
	private static function spans(array $edges, float $y): array {
		$crossings = [];

		foreach ($edges as [$x0, $y0, $x1, $y1, $direction]) {
			if ($y >= $y0 && $y < $y1) {
				$crossings[] = [$x0 + ($y - $y0) / ($y1 - $y0) * ($x1 - $x0), $direction];
			}
		}

		usort($crossings, fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$spans = [];
		$winding = 0;
		$from = 0.0;

		foreach ($crossings as [$x, $direction]) {
			if ($winding === 0) {
				$from = $x;
			}

			$winding += $direction;

			if ($winding === 0 && $x > $from) {
				$spans[] = [$from, $x];
			}
		}

		return $spans;
	}

	/**
	 * A closed TrueType contour as a polygon. Off-curve points are quadratic
	 * control points; two in a row have an on-curve point halfway between them.
	 *
	 * @param list<array{float, float, bool}> $points
	 * @return list<array{float, float}>
	 */
	private static function flatten(array $points): array {
		$count = count($points);
		$first = 0;

		while ($first < $count && !$points[$first][2]) {
			$first++;
		}

		if ($first === $count) {
			$start = [($points[0][0] + $points[1][0]) / 2, ($points[0][1] + $points[1][1]) / 2];
			$first = 0;
		} else {
			$start = [$points[$first][0], $points[$first][1]];
			$first++;
		}

		$polygon = [$start];
		$current = $start;
		$control = null;

		for ($i = 0; $i < $count; $i++) {
			[$x, $y, $onCurve] = $points[($first + $i) % $count];

			if ($onCurve) {
				$polygon = [...$polygon, ...self::curve($current, $control, [$x, $y])];
				$current = [$x, $y];
				$control = null;
			} elseif ($control === null) {
				$control = [$x, $y];
			} else {
				$middle = [($control[0] + $x) / 2, ($control[1] + $y) / 2];
				$polygon = [...$polygon, ...self::curve($current, $control, $middle)];
				$current = $middle;
				$control = [$x, $y];
			}
		}

		return [...$polygon, ...self::curve($current, $control, $start)];
	}

	/**
	 * Points along a line or a quadratic curve, without its start point.
	 *
	 * @param array{float, float} $from
	 * @param array{float, float}|null $control
	 * @param array{float, float} $to
	 * @return list<array{float, float}>
	 */
	private static function curve(array $from, ?array $control, array $to): array {
		if ($control === null) {
			return [$to];
		}

		$length = hypot($control[0] - $from[0], $control[1] - $from[1]) + hypot($to[0] - $control[0], $to[1] - $control[1]);
		$steps = max(1, min(32, (int) ceil($length / self::FLATNESS)));
		$points = [];

		for ($step = 1; $step <= $steps; $step++) {
			$t = $step / $steps;
			$u = 1 - $t;
			$points[] = [
				$u * $u * $from[0] + 2 * $u * $t * $control[0] + $t * $t * $to[0],
				$u * $u * $from[1] + 2 * $u * $t * $control[1] + $t * $t * $to[1],
			];
		}

		return $points;
	}
}
