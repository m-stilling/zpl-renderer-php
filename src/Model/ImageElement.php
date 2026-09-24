<?php

namespace Stilling\ZplRenderer\Model;

use Stilling\ZplRenderer\Graphics\Bitmap;

/**
 * A one-bit image from ^GF, ^XG, ^IM or ~DY.
 */
class ImageElement extends Element {
	public function __construct(
		int $x,
		int $y,
		public readonly Bitmap $bitmap,
		/** Horizontal magnification, 1 to 10. */
		public readonly int $magnificationX = 1,
		/** Vertical magnification, 1 to 10. */
		public readonly int $magnificationY = 1,
		Orientation $orientation = Orientation::Normal,
		bool $reverse = false,
		bool $typeset = false,
		Justification $justification = Justification::Left,
	) {
		parent::__construct($x, $y, $orientation, $reverse, $typeset, $justification);
	}

	public function width(): int {
		return $this->bitmap->width * $this->magnificationX;
	}

	public function height(): int {
		return $this->bitmap->height * $this->magnificationY;
	}
}
