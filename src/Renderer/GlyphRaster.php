<?php

namespace Stilling\ZplRenderer\Renderer;

/**
 * Measures how much of each pixel a glyph outline covers, under the nonzero
 * winding rule that TrueType uses.
 */
class GlyphRaster {
	/** Sample rows per pixel row; across a row, coverage is exact. */
	private const int SUBROWS = 8;

	/** Longest straight piece a curve is flattened into, in pixels. */
	private const float FLATNESS = 1.5;

	/**
	 * The pixels a glyph can cover, relative to the glyph origin on the
	 * baseline: top row, bottom row, left column and right column, the bottom
	 * and right excluded. Null for a blank glyph. The control points of a
	 * curve enclose the curve, so the box of all points encloses the outline.
	 *
	 * @param list<list<array{float, float, bool}>> $contours outline in em, y up
	 * @return array{int, int, int, int}|null
	 */
	public static function extent(array $contours, float $scaleX, float $scaleY): ?array {
		$minX = INF;
		$minY = INF;
		$maxX = -INF;
		$maxY = -INF;

		foreach ($contours as $contour) {
			foreach ($contour as [$x, $y]) {
				$minX = min($minX, $x * $scaleX);
				$maxX = max($maxX, $x * $scaleX);
				$minY = min($minY, -$y * $scaleY);
				$maxY = max($maxY, -$y * $scaleY);
			}
		}

		if ($minX === INF) {
			return null;
		}

		return [(int) floor($minY), (int) ceil($maxY), (int) floor($minX), (int) ceil($maxX)];
	}

	/**
	 * How much of each pixel a glyph covers, relative to the glyph origin on
	 * the baseline, row by row: the row (negative above the baseline), the
	 * first covered column, and the covered fraction of each pixel from that
	 * column to the last covered one, 0 for a pixel in between that the glyph
	 * does not cover.
	 *
	 * @param list<list<array{float, float, bool}>> $contours outline in em, y up
	 * @param float $scaleX pixels per em horizontally
	 * @param float $scaleY pixels per em vertically
	 * @param array{int, int, int, int}|null $clip the only pixels to measure: top row, bottom row, left column and right column, the bottom and right excluded
	 * @return list<array{int, int, list<float>}>
	 */
	public static function coverage(array $contours, float $scaleX, float $scaleY, ?array $clip = null): array {
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
		$left = -INF;
		$right = INF;

		if ($clip !== null) {
			[$clipTop, $clipBottom, $left, $right] = $clip;
			$top = max($top, $clipTop);
			$bottom = min($bottom, $clipBottom);
		}

		$rows = [];

		for ($row = $top; $row < $bottom; $row++) {
			/** @var array<int, float> $coverage */
			$coverage = [];

			for ($sub = 0; $sub < self::SUBROWS; $sub++) {
				$y = $row + ($sub + 0.5) / self::SUBROWS;

				foreach (self::spans($edges, $y) as [$from, $to]) {
					$from = max($from, $left);
					$to = min($to, $right);

					for ($column = (int) floor($from); $column < $to; $column++) {
						$overlap = min($to, $column + 1) - max($from, $column);
						$coverage[$column] = ($coverage[$column] ?? 0.0) + $overlap / self::SUBROWS;
					}
				}
			}

			ksort($coverage);
			$first = null;
			$values = [];

			foreach ($coverage as $column => $amount) {
				if ($amount <= 0.001) {
					continue;
				}

				$first ??= $column;
				$values = array_pad($values, $column - $first, 0.0);
				$values[] = min(1.0, $amount);
			}

			if ($first !== null) {
				$rows[] = [$row, $first, $values];
			}
		}

		return $rows;
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
