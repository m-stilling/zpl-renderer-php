<?php

namespace Stilling\Zpl\Font;

/**
 * A ZPL font request turned into PDF drawing parameters. All lengths are in dots.
 */
class ResolvedFont {
	public function __construct(
		/** PDF base font name. */
		public readonly string $pdfFont,
		/** Font size in dots. */
		public readonly float $size,
		/** Horizontal scaling as a factor, 1.0 for none. */
		public readonly float $horizontalScale,
		/** Distance from the top of the character cell to the baseline. */
		public readonly float $ascent,
		/** Distance from the baseline to the bottom of the character cell. */
		public readonly float $descent,
		/** Distance between baselines of consecutive lines. */
		public readonly float $lineHeight,
		/** The printer font has capital letters only. */
		public readonly bool $uppercase = false,
	) {
	}

	/**
	 * Width of a WinAnsi string in dots, after horizontal scaling.
	 */
	public function width(string $winAnsi): float {
		return FontMetrics::stringWidth($this->pdfFont, $winAnsi) * $this->size * $this->horizontalScale;
	}
}
