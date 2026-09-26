<?php

namespace Stilling\ZplRenderer;

use Stilling\ZplRenderer\Graphics\GraphicDecoder;
use Stilling\ZplRenderer\Renderer\PngRenderer;

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
	private int $maxLabelDots;
	private bool $honorQuantity;
	private int $maxPages;
	private int $maxGraphicBytes;
	private float $scalableFontCondense;
	private int $pixelsPerDot;
	private bool $antialias;
	private string $scalableFontFile;
	private string $monoFontFile;
	private string $monoBoldFontFile;
	private int $maxPngPixels;
	private int $maxTotalGraphicBytes;

	final public function __construct(
		int $dpmm = 8,
		float $widthMm = 101.6,
		float $heightMm = 152.4,
		bool $honorLabelSize = true,
		int $maxLabelDots = 32000,
		bool $honorQuantity = true,
		int $maxPages = 1000,
		int $maxGraphicBytes = GraphicDecoder::DEFAULT_MAX_BYTES,
		float $scalableFontCondense = 0.887,
		int $pixelsPerDot = 1,
		bool $antialias = true,
		string $scalableFontFile = self::FONT_DIRECTORY . "/RobotoCondensed-Bold.ttf",
		string $monoFontFile = self::FONT_DIRECTORY . "/RobotoMono-Regular.ttf",
		string $monoBoldFontFile = self::FONT_DIRECTORY . "/RobotoMono-Bold.ttf",
		int $maxPngPixels = PngRenderer::DEFAULT_MAX_PIXELS,
		int $maxTotalGraphicBytes = GraphicDecoder::DEFAULT_MAX_TOTAL_BYTES,
	) {
		// Nothing else holds the instance yet, so the setters fill it in place.
		$immutable = $this->immutable;
		$this->immutable = false;

		$this->dpmm($dpmm)
			->widthMm($widthMm)
			->heightMm($heightMm)
			->honorLabelSize($honorLabelSize)
			->maxLabelDots($maxLabelDots)
			->honorQuantity($honorQuantity)
			->maxPages($maxPages)
			->maxGraphicBytes($maxGraphicBytes)
			->scalableFontCondense($scalableFontCondense)
			->pixelsPerDot($pixelsPerDot)
			->antialias($antialias)
			->scalableFontFile($scalableFontFile)
			->monoFontFile($monoFontFile)
			->monoBoldFontFile($monoBoldFontFile)
			->maxPngPixels($maxPngPixels)
			->maxTotalGraphicBytes($maxTotalGraphicBytes);

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

	/** Largest width or length in dots that ^PW and ^LL can set. A larger value is clamped to it, like a printhead clamps ^PW. */
	public function maxLabelDots(int $maxLabelDots): static {
		if ($maxLabelDots < 1) {
			throw new \InvalidArgumentException("maxLabelDots must be at least 1.");
		}

		$options = $this->target();
		$options->maxLabelDots = $maxLabelDots;

		return $options;
	}

	public function getMaxLabelDots(): int {
		return $this->maxLabelDots;
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

	/**
	 * Upper bound on the bytes of all graphics in one call: every ^GF, ~DG and
	 * ~DY graphic when it is decoded, and every ^XG and ^IM again when it
	 * places one. A ~DY PNG counts four bytes per pixel.
	 */
	public function maxTotalGraphicBytes(int $maxTotalGraphicBytes): static {
		if ($maxTotalGraphicBytes < 1) {
			throw new \InvalidArgumentException("maxTotalGraphicBytes must be at least 1.");
		}

		$options = $this->target();
		$options->maxTotalGraphicBytes = $maxTotalGraphicBytes;

		return $options;
	}

	public function getMaxTotalGraphicBytes(): int {
		return $this->maxTotalGraphicBytes;
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

	/** Upper bound on the pixels of one PNG image, width times height, so that ^PW and ^LL cannot exhaust memory. */
	public function maxPngPixels(int $maxPngPixels): static {
		if ($maxPngPixels < 1) {
			throw new \InvalidArgumentException("maxPngPixels must be at least 1.");
		}

		$options = $this->target();
		$options->maxPngPixels = $maxPngPixels;

		return $options;
	}

	public function getMaxPngPixels(): int {
		return $this->maxPngPixels;
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
