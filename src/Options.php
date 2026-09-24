<?php

namespace Stilling\Zpl;

/**
 * Printer and label settings that the ZPL itself does not carry.
 */
class Options {
	public const string FONT_DIRECTORY = __DIR__ . "/../resources/fonts";

	public function __construct(
		/** Print density in dots per millimeter: 6, 8, 12 or 24. */
		public readonly int $dpmm = 8,
		/** Label width in millimeters. ^PW overrides it when honorLabelSize is true. */
		public readonly float $widthMm = 101.6,
		/** Label height in millimeters. ^LL overrides it when honorLabelSize is true. */
		public readonly float $heightMm = 152.4,
		/** Let ^PW and ^LL in the label change the page size. */
		public readonly bool $honorLabelSize = true,
		/** Repeat a PDF page as many times as ^PQ asks for. */
		public readonly bool $honorQuantity = true,
		/** Upper bound on pages per PDF document, so a stray ^PQ99999 cannot exhaust memory. */
		public readonly int $maxPages = 1000,
		/** Horizontal scale applied to the scalable font 0, relative to the natural width of scalableFontFile. */
		public readonly float $scalableFontCondense = 0.887,
		/** Pixels per printer dot in PNG output. */
		public readonly int $pixelsPerDot = 1,
		/** Smooth text edges in PNG output with gray pixels. When false, every pixel is black or white, like the printer prints it. */
		public readonly bool $antialias = true,
		/** TrueType file drawn for the scalable font 0. */
		public readonly string $scalableFontFile = self::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf",
		/** TrueType file drawn for the bitmap fonts A and C to H. */
		public readonly string $monoFontFile = self::FONT_DIRECTORY . "/RobotoMono-Regular.ttf",
		/** TrueType file drawn for the bold bitmap font B. */
		public readonly string $monoBoldFontFile = self::FONT_DIRECTORY . "/RobotoMono-Bold.ttf",
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

		if ($this->pixelsPerDot < 1 || $this->pixelsPerDot > 8) {
			throw new \InvalidArgumentException("pixelsPerDot must be between 1 and 8.");
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
