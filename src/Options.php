<?php

namespace Stilling\Zpl;

/**
 * Printer and label settings that the ZPL itself does not carry.
 */
class Options {
	public function __construct(
		/** Print density in dots per millimeter: 6, 8, 12 or 24. */
		public readonly int $dpmm = 8,
		/** Label width in millimeters. ^PW overrides it when honorLabelSize is true. */
		public readonly float $widthMm = 101.6,
		/** Label height in millimeters. ^LL overrides it when honorLabelSize is true. */
		public readonly float $heightMm = 152.4,
		/** Let ^PW and ^LL in the label change the page size. */
		public readonly bool $honorLabelSize = true,
		/** Repeat a label as many times as ^PQ asks for. */
		public readonly bool $honorQuantity = true,
		/** Upper bound on pages per document, so a stray ^PQ99999 cannot exhaust memory. */
		public readonly int $maxPages = 1000,
		/** Horizontal scale applied to the scalable font 0, relative to Helvetica-Bold. */
		public readonly float $scalableFontCondense = 0.85,
	) {
		if (!in_array($this->dpmm, [6, 8, 12, 24], true)) {
			throw new \InvalidArgumentException("dpmm must be 6, 8, 12 or 24, got {$this->dpmm}.");
		}

		if ($this->widthMm <= 0 || $this->heightMm <= 0) {
			throw new \InvalidArgumentException("Label width and height must be positive.");
		}

		if ($this->maxPages < 1) {
			throw new \InvalidArgumentException("maxPages must be at least 1.");
		}
	}

	/**
	 * Build options from a label size in inches.
	 */
	public static function inches(int $dpmm, float $width, float $height): self {
		return new self($dpmm, $width * 25.4, $height * 25.4);
	}

	public function dpi(): float {
		return $this->dpmm * 25.4;
	}

	public function widthDots(): int {
		return (int) round($this->widthMm * $this->dpmm);
	}

	public function heightDots(): int {
		return (int) round($this->heightMm * $this->dpmm);
	}
}
