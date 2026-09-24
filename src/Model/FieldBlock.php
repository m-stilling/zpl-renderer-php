<?php

namespace Stilling\ZplRenderer\Model;

/**
 * ^FB parameters: wrap the field text inside a block.
 */
class FieldBlock {
	public function __construct(
		/** Block width in dots. */
		public readonly int $width,
		/** Maximum number of lines. */
		public readonly int $maxLines = 1,
		/** Dots added to (or removed from) the line spacing. */
		public readonly int $lineSpacing = 0,
		public readonly TextJustification $justification = TextJustification::Left,
		/** Indent in dots for every line after the first. */
		public readonly int $hangingIndent = 0,
	) {
	}
}
