<?php

namespace Stilling\Zpl\Model;

/**
 * Something drawn on a label. Positions and sizes are in printer dots.
 */
abstract class Element {
	public function __construct(
		/** Horizontal position of the field origin in dots. */
		public readonly int $x,
		/** Vertical position of the field origin in dots. */
		public readonly int $y,
		public readonly Orientation $orientation = Orientation::Normal,
		/** Print the field in reverse (^FR): black becomes white where the field overlaps black. */
		public readonly bool $reverse = false,
		/** True when the origin came from ^FT (baseline/bottom-left), false for ^FO (top-left). */
		public readonly bool $typeset = false,
		public readonly Justification $justification = Justification::Left,
	) {
	}
}
