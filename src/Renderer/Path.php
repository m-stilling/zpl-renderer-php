<?php

namespace Stilling\Zpl\Renderer;

use Stilling\Zpl\Pdf\Document;

/**
 * A filled outline made of one or more closed subpaths. Subpaths that overlap
 * cut holes into each other (even-odd rule), which is how boxes and circles
 * get their hollow middle.
 */
class Path {
	/** Bezier control distance for a quarter circle. */
	private const float KAPPA = 0.5523;

	/** @var list<list<array{float, float}|array{float, float, float, float, float, float}>> subpaths of line points and curve control points */
	private array $subpaths = [];

	public function rect(float $x, float $y, float $width, float $height): self {
		$this->subpaths[] = [[$x, $y], [$x + $width, $y], [$x + $width, $y + $height], [$x, $y + $height]];

		return $this;
	}

	public function roundedRect(float $x, float $y, float $width, float $height, float $radius): self {
		$r = min($radius, $width / 2, $height / 2);

		if ($r <= 0) {
			return $this->rect($x, $y, $width, $height);
		}

		$k = $r * self::KAPPA;
		$x1 = $x + $width;
		$y1 = $y + $height;
		$this->subpaths[] = [
			[$x + $r, $y],
			[$x1 - $r, $y],
			[$x1 - $r + $k, $y, $x1, $y + $r - $k, $x1, $y + $r],
			[$x1, $y1 - $r],
			[$x1, $y1 - $r + $k, $x1 - $r + $k, $y1, $x1 - $r, $y1],
			[$x + $r, $y1],
			[$x + $r - $k, $y1, $x, $y1 - $r + $k, $x, $y1 - $r],
			[$x, $y + $r],
			[$x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y],
		];

		return $this;
	}

	public function ellipse(float $x, float $y, float $width, float $height): self {
		$rx = $width / 2;
		$ry = $height / 2;
		$cx = $x + $rx;
		$cy = $y + $ry;
		$kx = $rx * self::KAPPA;
		$ky = $ry * self::KAPPA;
		$this->subpaths[] = [
			[$cx + $rx, $cy],
			[$cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry],
			[$cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy],
			[$cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry],
			[$cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy],
		];

		return $this;
	}

	/**
	 * @param list<array{float, float}> $points
	 */
	public function polygon(array $points): self {
		$this->subpaths[] = $points;

		return $this;
	}

	/**
	 * The PDF path construction operators, without the painting operator.
	 */
	public function toPdf(): string {
		$n = Document::number(...);
		$out = "";

		foreach ($this->subpaths as $subpath) {
			if (count($subpath) === 4 && $this->isRect($subpath)) {
				[[$x, $y], , [$x1, $y1]] = $subpath;
				$out .= "{$n($x)} {$n($y)} {$n($x1 - $x)} {$n($y1 - $y)} re\n";
				continue;
			}

			foreach ($subpath as $index => $point) {
				$values = implode(" ", array_map($n, $point));
				$out .= $values . ($index === 0 ? " m " : (count($point) === 6 ? " c " : " l "));
			}

			$out .= "h\n";
		}

		return $out;
	}

	/**
	 * Every subpath as a polygon, with curves broken into straight segments.
	 *
	 * @return list<list<array{float, float}>>
	 */
	public function flatten(int $segments = 8): array {
		$polygons = [];

		foreach ($this->subpaths as $subpath) {
			$points = [];

			foreach ($subpath as $point) {
				if (count($point) === 2) {
					$points[] = $point;
					continue;
				}

				[$x0, $y0] = $points[count($points) - 1];
				[$x1, $y1, $x2, $y2, $x3, $y3] = $point;

				for ($i = 1; $i <= $segments; $i++) {
					$t = $i / $segments;
					$u = 1 - $t;
					$points[] = [
						$u * $u * $u * $x0 + 3 * $u * $u * $t * $x1 + 3 * $u * $t * $t * $x2 + $t * $t * $t * $x3,
						$u * $u * $u * $y0 + 3 * $u * $u * $t * $y1 + 3 * $u * $t * $t * $y2 + $t * $t * $t * $y3,
					];
				}
			}

			$polygons[] = $points;
		}

		return $polygons;
	}

	/**
	 * @param list<array{float, float}|array{float, float, float, float, float, float}> $subpath
	 */
	private function isRect(array $subpath): bool {
		foreach ($subpath as $point) {
			if (count($point) !== 2) {
				return false;
			}
		}

		[$p0, $p1, $p2, $p3] = $subpath;

		return $p0[1] === $p1[1] && $p1[0] === $p2[0] && $p2[1] === $p3[1] && $p3[0] === $p0[0];
	}
}
