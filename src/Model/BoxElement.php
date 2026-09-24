<?php

namespace Stilling\ZplRenderer\Model;

/**
 * ^GB graphic box.
 */
class BoxElement extends Element {
	public function __construct(
		int $x,
		int $y,
		public readonly int $width,
		public readonly int $height,
		public readonly int $thickness,
		/** Corner rounding 0 (square) to 8 (fully rounded). */
		public readonly int $rounding = 0,
		/** True for a black box, false for a white one. */
		public readonly bool $black = true,
		Orientation $orientation = Orientation::Normal,
		bool $reverse = false,
		bool $typeset = false,
		Justification $justification = Justification::Left,
	) {
		parent::__construct($x, $y, $orientation, $reverse, $typeset, $justification);
	}
}
