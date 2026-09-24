<?php

namespace Stilling\ZplRenderer\Model;

/**
 * ^GE graphic ellipse.
 */
class EllipseElement extends Element {
	public function __construct(
		int $x,
		int $y,
		public readonly int $width,
		public readonly int $height,
		public readonly int $thickness,
		public readonly bool $black = true,
		Orientation $orientation = Orientation::Normal,
		bool $reverse = false,
		bool $typeset = false,
		Justification $justification = Justification::Left,
	) {
		parent::__construct($x, $y, $orientation, $reverse, $typeset, $justification);
	}
}
