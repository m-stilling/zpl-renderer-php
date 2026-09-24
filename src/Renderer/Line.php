<?php

namespace Stilling\ZplRenderer\Renderer;

/**
 * One laid-out line of text: WinAnsi bytes and the position of its baseline start.
 */
class Line {
	public function __construct(
		public readonly string $text,
		public readonly float $x,
		public readonly float $baseline,
		/** Extra dots added after every space, for justified blocks. */
		public readonly float $wordSpacing = 0.0,
	) {
	}
}
