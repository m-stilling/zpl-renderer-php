<?php

namespace Stilling\ZplRenderer\Model;

use Stilling\ZplRenderer\Barcode\BarcodeMatrix;

/**
 * An encoded barcode, ready to draw: a matrix of bars in module units plus the
 * size of one module in dots.
 */
class BarcodeElement extends Element {
	public function __construct(
		int $x,
		int $y,
		public readonly BarcodeMatrix $matrix,
		/** Width of one module (column unit) in dots. */
		public readonly float $moduleWidth,
		/** Height of one row unit in dots. For a linear barcode this is the bar height. */
		public readonly float $moduleHeight,
		/** Human-readable interpretation line, or null when none is printed. */
		public readonly ?string $text = null,
		/** Print the interpretation line above the bars instead of below. */
		public readonly bool $textAbove = false,
		Orientation $orientation = Orientation::Normal,
		bool $reverse = false,
		bool $typeset = false,
		Justification $justification = Justification::Left,
	) {
		parent::__construct($x, $y, $orientation, $reverse, $typeset, $justification);
	}

	public function width(): float {
		return $this->matrix->columns * $this->moduleWidth;
	}

	public function height(): float {
		return $this->matrix->rows * $this->moduleHeight;
	}
}
