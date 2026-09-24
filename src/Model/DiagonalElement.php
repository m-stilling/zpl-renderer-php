<?php

namespace Stilling\ZplRenderer\Model;

/**
 * ^GD diagonal line inside a box of width x height.
 */
class DiagonalElement extends Element {
	public function __construct(
		int $x,
		int $y,
		public readonly int $width,
		public readonly int $height,
		public readonly int $thickness,
		public readonly bool $black = true,
		/** True for a line from bottom-left to top-right (like a slash), false for top-left to bottom-right. */
		public readonly bool $rightLeaning = true,
		Orientation $orientation = Orientation::Normal,
		bool $reverse = false,
		bool $typeset = false,
		Justification $justification = Justification::Left,
	) {
		parent::__construct($x, $y, $orientation, $reverse, $typeset, $justification);
	}
}
