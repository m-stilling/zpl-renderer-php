<?php

namespace Stilling\Zpl;

use Stilling\Zpl\Graphics\GraphicDecoder;

/**
 * Printer and label settings that the ZPL itself does not carry.
 *
 * Every setter returns an Options instance, so the settings chain:
 * (new Options())->dpmm(12)->widthMm(50)->heightMm(25).
 * This class changes the instance in place and returns it. OptionsImmutable
 * leaves the instance as it is and returns a changed copy.
 */
class Options {
	public const string FONT_DIRECTORY = __DIR__ . "/../resources/fonts";

	/** When true, every setter works on a clone and returns the clone. */
	protected bool $immutable = false;

	private int $dpmm;
	private float $widthMm;
	private float $heightMm;
	private bool $honorLabelSize;
	private bool $honorQuantity;
	private int $maxPages;
	private int $maxGraphicBytes;
	private float $scalableFontCondense;
	private int $pixelsPerDot;
	private bool $antialias;
	private string $scalableFontFile;
	private string $monoFontFile;
	private string $monoBoldFontFile;

	final public function __construct(
		int $dpmm = 8,
		float $widthMm = 101.6,
		float $heightMm = 152.4,
		bool $honorLabelSize = true,
		bool $honorQuantity = true,
		int $maxPages = 1000,
		int $maxGraphicBytes = GraphicDecoder::DEFAULT_MAX_BYTES,
		float $scalableFontCondense = 0.887,
		int $pixelsPerDot = 1,
		bool $antialias = true,
		string $scalableFontFile = self::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf",
		string $monoFontFile = self::FONT_DIRECTORY . "/RobotoMono-Regular.ttf",
		string $monoBoldFontFile = self::FONT_DIRECTORY . "/RobotoMono-Bold.ttf",
	) {
		// Nothing else holds the instance yet, so the setters fill it in place.
		$immutable = $this->immutable;
		$this->immutable = false;

		$this->dpmm($dpmm)
			->widthMm($widthMm)
			->heightMm($heightMm)
			->honorLabelSize($honorLabelSize)
			->honorQuantity($honorQuantity)
			->maxPages($maxPages)
			->maxGraphicBytes($maxGraphicBytes)
			->scalableFontCondense($scalableFontCondense)
			->pixelsPerDot($pixelsPerDot)
			->antialias($antialias)
			->scalableFontFile($scalableFontFile)
			->monoFontFile($monoFontFile)
			->monoBoldFontFile($monoBoldFontFile);

		$this->immutable = $immutable;
	}

	/**
	 * Build options from a label size in inches.
	 */
	public static function inches(int $dpmm, float $width, float $height): static {
		return new static($dpmm, $width * 25.4, $height * 25.4);
	}

	/** Print density in dots per millimeter: 6, 8, 12 or 24. */
	public function dpmm(int $dpmm): static {
		if (!in_array($dpmm, [6, 8, 12, 24], true)) {
			throw new \InvalidArgumentException("dpmm must be 6, 8, 12 or 24, got {$dpmm}.");
		}

		$options = $this->target();
		$options->dpmm = $dpmm;

		return $options;
	}

	public function getDpmm(): int {
		return $this->dpmm;
	}

	/** Label width in millimeters. ^PW overrides it when honorLabelSize is true. */
	public function widthMm(float $widthMm): static {
		if ($widthMm <= 0) {
			throw new \InvalidArgumentException("Label width must be positive.");
		}

		$options = $this->target();
		$options->widthMm = $widthMm;

		return $options;
	}

	public function getWidthMm(): float {
		return $this->widthMm;
	}

	/** Label height in millimeters. ^LL overrides it when honorLabelSize is true. */
	public function heightMm(float $heightMm): static {
		if ($heightMm <= 0) {
			throw new \InvalidArgumentException("Label height must be positive.");
		}

		$options = $this->target();
		$options->heightMm = $heightMm;

		return $options;
	}

	public function getHeightMm(): float {
		return $this->heightMm;
	}

	/** Let ^PW and ^LL in the label change the page size. */
	public function honorLabelSize(bool $honorLabelSize = true): static {
		$options = $this->target();
		$options->honorLabelSize = $honorLabelSize;

		return $options;
	}

	public function getHonorLabelSize(): bool {
		return $this->honorLabelSize;
	}

	/** Repeat a PDF page as many times as ^PQ asks for. */
	public function honorQuantity(bool $honorQuantity = true): static {
		$options = $this->target();
		$options->honorQuantity = $honorQuantity;

		return $options;
	}

	public function getHonorQuantity(): bool {
		return $this->honorQuantity;
	}

	/** Upper bound on pages per PDF document, so a stray ^PQ99999 cannot exhaust memory. */
	public function maxPages(int $maxPages): static {
		if ($maxPages < 1) {
			throw new \InvalidArgumentException("maxPages must be at least 1.");
		}

		$options = $this->target();
		$options->maxPages = $maxPages;

		return $options;
	}

	public function getMaxPages(): int {
		return $this->maxPages;
	}

	/** Upper bound on the decoded size in bytes of one ^GF, ~DG or ~DY graphic, so a stray size field cannot exhaust memory. */
	public function maxGraphicBytes(int $maxGraphicBytes): static {
		if ($maxGraphicBytes < 1) {
			throw new \InvalidArgumentException("maxGraphicBytes must be at least 1.");
		}

		$options = $this->target();
		$options->maxGraphicBytes = $maxGraphicBytes;

		return $options;
	}

	public function getMaxGraphicBytes(): int {
		return $this->maxGraphicBytes;
	}

	/** Horizontal scale applied to the scalable font 0, relative to the natural width of scalableFontFile. */
	public function scalableFontCondense(float $scalableFontCondense): static {
		$options = $this->target();
		$options->scalableFontCondense = $scalableFontCondense;

		return $options;
	}

	public function getScalableFontCondense(): float {
		return $this->scalableFontCondense;
	}

	/** Pixels per printer dot in PNG output, 1 to 8. */
	public function pixelsPerDot(int $pixelsPerDot): static {
		if ($pixelsPerDot < 1 || $pixelsPerDot > 8) {
			throw new \InvalidArgumentException("pixelsPerDot must be between 1 and 8.");
		}

		$options = $this->target();
		$options->pixelsPerDot = $pixelsPerDot;

		return $options;
	}

	public function getPixelsPerDot(): int {
		return $this->pixelsPerDot;
	}

	/** Smooth text edges in PNG output with gray pixels. When false, every pixel is black or white, like the printer prints it. */
	public function antialias(bool $antialias = true): static {
		$options = $this->target();
		$options->antialias = $antialias;

		return $options;
	}

	public function getAntialias(): bool {
		return $this->antialias;
	}

	/** TrueType file drawn for the scalable font 0. */
	public function scalableFontFile(string $scalableFontFile): static {
		$options = $this->target();
		$options->scalableFontFile = $scalableFontFile;

		return $options;
	}

	public function getScalableFontFile(): string {
		return $this->scalableFontFile;
	}

	/** TrueType file drawn for the bitmap fonts A and C to H. */
	public function monoFontFile(string $monoFontFile): static {
		$options = $this->target();
		$options->monoFontFile = $monoFontFile;

		return $options;
	}

	public function getMonoFontFile(): string {
		return $this->monoFontFile;
	}

	/** TrueType file drawn for the bold bitmap font B. */
	public function monoBoldFontFile(string $monoBoldFontFile): static {
		$options = $this->target();
		$options->monoBoldFontFile = $monoBoldFontFile;

		return $options;
	}

	public function getMonoBoldFontFile(): string {
		return $this->monoBoldFontFile;
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

	/**
	 * The instance a setter writes to: a clone when immutable, else this one.
	 */
	private function target(): static {
		return $this->immutable ? clone $this : $this;
	}
}
