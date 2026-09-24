<?php

namespace Stilling\Zpl\Pdf;

use Stilling\Zpl\Font\TrueTypeFont;
use Stilling\Zpl\Graphics\Bitmap;

/**
 * A minimal PDF writer: pages with content streams, the fonts, an extended
 * graphics state for reverse printing, and one-bit image masks.
 *
 * Fonts are TrueType files. A document embeds a font only when a page draws
 * with it, and then only the glyphs of the characters it draws.
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

	/** @var array<string, array{string, array<int, true>}> font file and the WinAnsi codes drawn with it, by resource name */
	private array $usedFonts = [];

	private int $resourcesId;

	private int $pagesId;

	public function __construct() {
		$this->pagesId = $this->reserve();
		$this->resourcesId = $this->reserve();
	}

	/**
	 * Note that a page draws text with a font, so the glyphs of that text get embedded.
	 */
	public function useFont(string $resource, string $file, string $winAnsi): void {
		$this->usedFonts[$resource] ??= [$file, []];

		foreach (unpack("C*", $winAnsi) ?: [] as $code) {
			$this->usedFonts[$resource][1][$code] = true;
		}
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

	/**
	 * Add a page, or several pages that share one content stream.
	 */
	public function addPage(float $widthPt, float $heightPt, string $content, int $copies = 1): void {
		$contentId = $this->add("<< /Filter /FlateDecode /Length %d >>", self::deflate($content));
		$width = self::number($widthPt);
		$height = self::number($heightPt);

		for ($copy = 0; $copy < $copies; $copy++) {
			$this->pages[] = $this->add(
				"<< /Type /Page /Parent {$this->pagesId} 0 R /MediaBox [0 0 {$width} {$height}]"
				. " /Resources {$this->resourcesId} 0 R /Contents {$contentId} 0 R >>",
			);
		}
	}

	public function pageCount(): int {
		return count($this->pages);
	}

	public function render(): string {
		$fonts = "";
		ksort($this->usedFonts);

		foreach ($this->usedFonts as $resource => [$file, $codes]) {
			$fonts .= " /{$resource} " . $this->addTrueType($file, array_keys($codes)) . " 0 R";
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
		$info = $this->add("<< /Producer (stilling/zpl-renderer) >>");

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
	 * Embed the glyphs of a TrueType file that the given WinAnsi codes need,
	 * and return the object number of its font dictionary.
	 *
	 * @param list<int> $codes
	 */
	private function addTrueType(string $file, array $codes): int {
		$font = TrueTypeFont::load($file);
		sort($codes);
		$subset = $font->subset($codes);
		$name = self::subsetTag($codes) . "+" . $font->postScriptName;
		$program = $this->add(
			"<< /Length1 " . strlen($subset) . " /Filter /FlateDecode /Length %d >>",
			self::deflate($subset),
		);
		$bbox = implode(" ", $font->bbox);
		$descriptor = $this->add(
			"<< /Type /FontDescriptor /FontName /{$name} /Flags " . $font->flags()
			. " /FontBBox [{$bbox}] /ItalicAngle " . self::number($font->italicAngle)
			. " /Ascent {$font->ascent} /Descent {$font->descent} /CapHeight {$font->capHeight}"
			. " /StemV " . ($font->bold ? 120 : 80) . " /FontFile2 {$program} 0 R >>",
		);
		$widths = implode(" ", $font->widths);

		return $this->add(
			"<< /Type /Font /Subtype /TrueType /BaseFont /{$name} /FirstChar 32 /LastChar 255"
			. " /Widths [{$widths}] /Encoding /WinAnsiEncoding /FontDescriptor {$descriptor} 0 R >>",
		);
	}

	/**
	 * The six capital letters that mark a font name as a subset, derived from the glyphs it holds.
	 *
	 * @param list<int> $codes
	 */
	private static function subsetTag(array $codes): string {
		$hash = crc32(pack("C*", ...$codes));
		$tag = "";

		for ($i = 0; $i < 6; $i++) {
			$tag .= chr(ord("A") + $hash % 26);
			$hash = intdiv($hash, 26);
		}

		return $tag;
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
