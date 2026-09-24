<?php

namespace Stilling\ZplRenderer\Renderer;

/**
 * A 2D affine transform in the PDF operand order: x' = a x + c y + e, y' = b x + d y + f.
 */
class Matrix {
	public function __construct(
		public readonly float $a,
		public readonly float $b,
		public readonly float $c,
		public readonly float $d,
		public readonly float $e,
		public readonly float $f,
	) {
	}

	/**
	 * @return array{float, float}
	 */
	public function apply(float $x, float $y): array {
		return [$this->a * $x + $this->c * $y + $this->e, $this->b * $x + $this->d * $y + $this->f];
	}

	/**
	 * The transform that applies this one and then scales the result.
	 */
	public function scaled(float $factor): self {
		return new self(
			$this->a * $factor,
			$this->b * $factor,
			$this->c * $factor,
			$this->d * $factor,
			$this->e * $factor,
			$this->f * $factor,
		);
	}

	/**
	 * The axis-aligned bounding box of a rectangle after the transform.
	 *
	 * @return array{float, float, float, float} min x, min y, max x, max y
	 */
	public function bounds(float $x, float $y, float $width, float $height): array {
		$minX = INF;
		$minY = INF;
		$maxX = -INF;
		$maxY = -INF;

		foreach ([[$x, $y], [$x + $width, $y], [$x, $y + $height], [$x + $width, $y + $height]] as [$px, $py]) {
			[$tx, $ty] = $this->apply($px, $py);
			$minX = min($minX, $tx);
			$minY = min($minY, $ty);
			$maxX = max($maxX, $tx);
			$maxY = max($maxY, $ty);
		}

		return [$minX, $minY, $maxX, $maxY];
	}
}
