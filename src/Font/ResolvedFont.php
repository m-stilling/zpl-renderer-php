<?php

namespace Stilling\ZplRenderer\Font;

/**
 * A ZPL font request turned into drawing parameters. All lengths are in dots.
 */
class ResolvedFont {
	public function __construct(
		public readonly Typeface $typeface,
		/** The font file the glyphs and advance widths come from. */
		public readonly TrueTypeFont $face,
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
		return $this->face->stringWidth($winAnsi) * $this->size * $this->horizontalScale;
	}

	/**
	 * Height of the capital letters in dots.
	 */
	public function capHeight(): float {
		return $this->face->capHeight / 1000 * $this->size;
	}
}
