<?php

namespace Stilling\Zpl\Pdf;

use Stilling\Zpl\Graphics\Bitmap;

/**
 * A minimal PDF writer: pages with content streams, the fonts, an extended
 * graphics state for reverse printing, and one-bit image masks.
 *
 * The scalable font is the core Helvetica-Bold. The fixed-pitch fonts are
 * TrueType files embedded in full when a page uses them.
 */
class Document {
	public const string FONT_SCALABLE = "F1";

	public const string FONT_MONO_BOLD = "F2";

	public const string FONT_MONO = "F3";

	/** Graphics state name that paints with the Difference blend mode, used for ^FR. */
	public const string REVERSE_STATE = "GSr";

	/** @var array<int, string> object bodies by object number */
	private array $objects = [];

	/** @var list<int> */
	private array $pages = [];

	/** @var array<string, int> image resource name => object number */
	private array $images = [];

	/** @var array<string, true> font resource names any page uses */
	private array $usedFonts = [];

	private int $resourcesId;

	private int $pagesId;

	public function __construct(
		/** TrueType file for the fixed-pitch font. */
		private readonly string $monoFontFile,
		/** TrueType file for the bold fixed-pitch font. */
		private readonly string $monoBoldFontFile,
	) {
		$this->pagesId = $this->reserve();
		$this->resourcesId = $this->reserve();
	}

	/**
	 * Note that a page draws with a font, so the font gets embedded.
	 */
	public function useFont(string $resource): void {
		$this->usedFonts[$resource] = true;
	}

	/**
	 * Register a bitmap and get the resource name to use with the Do operator.
	 */
	public function addImage(Bitmap $bitmap): string {
		$name = "Im" . (count($this->images) + 1);
		$this->images[$name] = $this->add(
			"<< /Type /XObject /Subtype /Image /Width {$bitmap->width} /Height {$bitmap->height}"
			. " /ImageMask true /Decode [1 0] /BitsPerComponent 1 /Filter /FlateDecode /Length %d >>",
			self::deflate($bitmap->data),
		);

		return $name;
	}

	public function addPage(float $widthPt, float $heightPt, string $content): void {
		$contentId = $this->add("<< /Filter /FlateDecode /Length %d >>", self::deflate($content));
		$width = self::number($widthPt);
		$height = self::number($heightPt);

		$this->pages[] = $this->add(
			"<< /Type /Page /Parent {$this->pagesId} 0 R /MediaBox [0 0 {$width} {$height}]"
			. " /Resources {$this->resourcesId} 0 R /Contents {$contentId} 0 R >>",
		);
	}

	public function pageCount(): int {
		return count($this->pages);
	}

	public function render(): string {
		$fonts = " /" . self::FONT_SCALABLE . " " . $this->add(
			"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
		) . " 0 R";

		if (isset($this->usedFonts[self::FONT_MONO])) {
			$fonts .= " /" . self::FONT_MONO . " " . $this->addTrueType($this->monoFontFile) . " 0 R";
		}

		if (isset($this->usedFonts[self::FONT_MONO_BOLD])) {
			$fonts .= " /" . self::FONT_MONO_BOLD . " " . $this->addTrueType($this->monoBoldFontFile) . " 0 R";
		}

		$reverse = $this->add("<< /Type /ExtGState /BM /Difference >>");
		$xobjects = "";

		foreach ($this->images as $name => $id) {
			$xobjects .= " /{$name} {$id} 0 R";
		}

		$this->objects[$this->resourcesId] = "<< /ProcSet [/PDF /Text /ImageB]"
			. " /Font <<{$fonts} >>"
			. " /ExtGState << /" . self::REVERSE_STATE . " {$reverse} 0 R >>"
			. " /XObject <<{$xobjects} >> >>";

		$kids = implode(" ", array_map(fn (int $id): string => "{$id} 0 R", $this->pages));
		$this->objects[$this->pagesId] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($this->pages) . " >>";
		$catalog = $this->add("<< /Type /Catalog /Pages {$this->pagesId} 0 R >>");
		$info = $this->add("<< /Producer (stilling/zpl) >>");

		$output = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		ksort($this->objects);

		foreach ($this->objects as $id => $body) {
			$offsets[$id] = strlen($output);
			$output .= "{$id} 0 obj\n{$body}\nendobj\n";
		}

		$count = count($this->objects) + 1;
		$xref = strlen($output);
		$output .= "xref\n0 {$count}\n0000000000 65535 f \n";

		for ($id = 1; $id < $count; $id++) {
			$output .= sprintf("%010d 00000 n \n", $offsets[$id]);
		}

		$output .= "trailer\n<< /Size {$count} /Root {$catalog} 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

		return $output;
	}

	/**
	 * Embed a TrueType file and return the object number of its font dictionary.
	 */
	private function addTrueType(string $file): int {
		$font = new TrueTypeFont($file);
		$program = $this->add(
			"<< /Length1 " . strlen($font->data) . " /Filter /FlateDecode /Length %d >>",
			self::deflate($font->data),
		);
		$bbox = implode(" ", $font->bbox);
		$descriptor = $this->add(
			"<< /Type /FontDescriptor /FontName /{$font->postScriptName} /Flags " . $font->flags()
			. " /FontBBox [{$bbox}] /ItalicAngle " . self::number($font->italicAngle)
			. " /Ascent {$font->ascent} /Descent {$font->descent} /CapHeight {$font->capHeight}"
			. " /StemV " . ($font->bold ? 120 : 80) . " /FontFile2 {$program} 0 R >>",
		);
		$widths = implode(" ", $font->widths);

		return $this->add(
			"<< /Type /Font /Subtype /TrueType /BaseFont /{$font->postScriptName} /FirstChar 32 /LastChar 255"
			. " /Widths [{$widths}] /Encoding /WinAnsiEncoding /FontDescriptor {$descriptor} 0 R >>",
		);
	}

	/**
	 * Format a number for a content stream: at most three decimals, no trailing zeros.
	 */
	public static function number(float $value): string {
		$text = rtrim(rtrim(sprintf("%.3F", $value), "0"), ".");

		return $text === "-0" ? "0" : $text;
	}

	private static function deflate(string $data): string {
		$compressed = gzcompress($data, 9);

		if ($compressed === false) {
			throw new \RuntimeException("zlib compression failed.");
		}

		return $compressed;
	}

	private function reserve(): int {
		$id = count($this->objects) + 1;
		$this->objects[$id] = "";

		return $id;
	}

	/**
	 * Add an object. When a stream is given, the dictionary must contain a %d for its length.
	 */
	private function add(string $dictionary, ?string $stream = null): int {
		$id = $this->reserve();

		if ($stream === null) {
			$this->objects[$id] = $dictionary;
		} else {
			$this->objects[$id] = sprintf($dictionary, strlen($stream)) . "\nstream\n{$stream}\nendstream";
		}

		return $id;
	}
}
