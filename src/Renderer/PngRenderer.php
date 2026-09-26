<?php

namespace Stilling\ZplRenderer\Renderer;

use Stilling\ZplRenderer\Exceptions\RenderException;
use Stilling\ZplRenderer\Exceptions\UnsupportedException;
use Stilling\ZplRenderer\Font\ResolvedFont;
use Stilling\ZplRenderer\Font\ZebraFont;
use Stilling\ZplRenderer\Graphics\Bitmap;
use Stilling\ZplRenderer\Model\BarcodeElement;
use Stilling\ZplRenderer\Model\BoxElement;
use Stilling\ZplRenderer\Model\CircleElement;
use Stilling\ZplRenderer\Model\DiagonalElement;
use Stilling\ZplRenderer\Model\Element;
use Stilling\ZplRenderer\Model\EllipseElement;
use Stilling\ZplRenderer\Model\ImageElement;
use Stilling\ZplRenderer\Model\Label;
use Stilling\ZplRenderer\Model\Orientation;
use Stilling\ZplRenderer\Model\TextElement;
use Stilling\ZplRenderer\Options;

/**
 * Draws labels as grayscale PNG images with GD, one image per label, at
 * pixelsPerDot pixels for every printer dot. Shapes, barcodes and images are
 * black or white; text edges are gray unless antialiasing is off.
 *
 * Every field is drawn on a transparent layer and then merged into the label:
 * painted over it, or inverted into it for reverse fields, the way the printer does.
 */
class PngRenderer {
	/** Layer color that stays transparent when the layer is merged. */
	private const int CLEAR = 0xFF00FF;

	private const int BLACK = 0x000000;

	/** Layer color that erases to white. Black ink with partial coverage is a gray between BLACK and this. */
	private const int WHITE = 0xFFFFFF;

	/** 8192 x 8192, enough for the default label size at 8 pixels per dot. */
	public const int DEFAULT_MAX_PIXELS = 67108864;

	/** Most glyph pixels kept for reuse, summed over the kept rasters. */
	private const int GLYPH_CACHE_PIXELS = 1048576;

	/** Pixels that a field is drawn beyond the image edge, so that rounding in its placement cannot leave a gap. */
	private const int CLIP_MARGIN = 2;

	/** How many times over the fields of one label may cover the image, summed over the fields. */
	private const int MAX_COVERAGE = 8;

	/** The pixels that the fields of one label may cover on a small image, where MAX_COVERAGE gives fewer. */
	private const int MIN_COVERAGE_PIXELS = 1048576;

	/** @var array<string, list<array{int, int, list<float>}>> glyph rasters by font file, character and scale */
	private static array $glyphs = [];

	/** The pixels that the glyph rasters in the cache can cover. */
	private static int $glyphPixels = 0;

	private int $scale = 1;

	private int $width = 0;

	private int $height = 0;

	/** The transparent layer every field of the current label is drawn on, in turn. */
	private \GdImage $layer;

	/** @var array{int, int, int, int}|null pixel bounds drawn on the current layer: x0, y0, x1, y1 */
	private ?array $drawn = null;

	/** The pixels that the fields of the current label cover, summed over the fields. */
	private int $covered = 0;

	/** The most pixels that the fields of the current label may cover. */
	private int $maxCovered = 0;

	public function __construct(
		private readonly Options $options,
	) {
		if (!function_exists("imagecreate")) {
			throw new UnsupportedException("Rendering PNG requires the GD extension.");
		}
	}

	/**
	 * @param list<Label> $labels
	 * @return list<string> one PNG per label, for at most maxPages labels
	 */
	public function render(array $labels): array {
		return array_map($this->renderLabel(...), array_slice($labels, 0, $this->options->getMaxPages()));
	}

	public function renderLabel(Label $label): string {
		$this->scale = $this->options->getPixelsPerDot();
		$this->width = $label->width * $this->scale;
		$this->height = $label->height * $this->scale;
		$maxPixels = $this->options->getMaxPngPixels();

		if ($this->width * $this->height > $maxPixels) {
			throw new RenderException("A {$this->width} x {$this->height} image has more pixels than the limit of {$maxPixels}.");
		}

		$canvas = $this->canvas($this->width, $this->height);
		$this->layer = $this->layer($this->width, $this->height);
		$this->covered = 0;
		$this->maxCovered = max(self::MAX_COVERAGE * $this->width * $this->height, self::MIN_COVERAGE_PIXELS);

		foreach ($label->elements as $element) {
			$this->renderElement($canvas, $element);
		}

		if ($label->mirrored) {
			imageflip($canvas, IMG_FLIP_HORIZONTAL);
		}

		if ($label->inverted) {
			imageflip($canvas, IMG_FLIP_BOTH);
		}

		ob_start();
		imagepng($canvas);

		return (string) ob_get_clean();
	}

	private function renderElement(\GdImage $canvas, Element $element): void {
		$layer = $this->layer;
		$this->drawn = null;

		match (true) {
			$element instanceof TextElement => $this->text($layer, $element),
			$element instanceof BoxElement => $this->shape($layer, $element, Shapes::box($element), $element->width, $element->height, $element->black),
			$element instanceof CircleElement => $this->shape($layer, $element, Shapes::circle($element), $element->diameter, $element->diameter, $element->black),
			$element instanceof EllipseElement => $this->shape($layer, $element, Shapes::ellipse($element), $element->width, $element->height, $element->black),
			$element instanceof DiagonalElement => $this->shape($layer, $element, Shapes::diagonal($element), $element->width, $element->height, $element->black),
			$element instanceof ImageElement => $this->image($layer, $element),
			$element instanceof BarcodeElement => $this->barcode($layer, $element),
			default => null,
		};

		if ($this->drawn === null) {
			return;
		}

		[$x0, $y0, $x1, $y1] = $this->drawn;
		$x0 = max(0, $x0);
		$y0 = max(0, $y0);
		$x1 = min($this->width, $x1);
		$y1 = min($this->height, $y1);

		if ($x1 <= $x0 || $y1 <= $y0) {
			return;
		}

		$this->covered += ($x1 - $x0) * ($y1 - $y0);

		if ($this->covered > $this->maxCovered) {
			throw new RenderException("The fields of the label cover more than the limit of {$this->maxCovered} pixels in total.");
		}

		$this->merge($canvas, $layer, $x0, $y0, $x1, $y1, $element->reverse);
		imagefilledrectangle($layer, $x0, $y0, $x1 - 1, $y1 - 1, self::CLEAR);
	}

	/**
	 * Paint the layer into the canvas. Black ink darkens the canvas by its
	 * coverage and white ink erases it. In a reverse field every inked pixel
	 * inverts the canvas by its coverage instead, so black on black comes out white.
	 */
	private function merge(\GdImage $canvas, \GdImage $layer, int $x0, int $y0, int $x1, int $y1, bool $reverse): void {
		for ($y = $y0; $y < $y1; $y++) {
			for ($x = $x0; $x < $x1; $x++) {
				$color = imagecolorat($layer, $x, $y);

				if ($color === self::CLEAR || $color === false) {
					continue;
				}

				$ink = $color === self::WHITE ? 255 : 255 - ($color & 0xFF);
				$gray = (int) imagecolorat($canvas, $x, $y);

				if ($reverse) {
					$gray = abs($gray - $ink);
				} elseif ($color === self::WHITE) {
					$gray = 255;
				} else {
					$gray = intdiv($gray * (255 - $ink) + 127, 255);
				}

				imagesetpixel($canvas, $x, $y, $gray);
			}
		}
	}

	private function mark(float $x0, float $y0, float $x1, float $y1): void {
		$box = [(int) floor($x0), (int) floor($y0), (int) ceil($x1), (int) ceil($y1)];
		$this->drawn = $this->drawn === null ? $box : [
			min($this->drawn[0], $box[0]),
			min($this->drawn[1], $box[1]),
			max($this->drawn[2], $box[2]),
			max($this->drawn[3], $box[3]),
		];
	}

	/**
	 * A white image with a 256-step gray palette, in which the color index is the gray level.
	 */
	private function canvas(int $width, int $height): \GdImage {
		$canvas = imagecreate(max(1, $width), max(1, $height));

		if ($canvas === false) {
			throw new RenderException("Cannot create a {$width} x {$height} image.");
		}

		for ($gray = 0; $gray < 256; $gray++) {
			imagecolorallocate($canvas, $gray, $gray, $gray);
		}

		imagefilledrectangle($canvas, 0, 0, imagesx($canvas) - 1, imagesy($canvas) - 1, 255);

		return $canvas;
	}

	/**
	 * A transparent image to draw one field on.
	 */
	private function layer(int $width, int $height): \GdImage {
		$layer = imagecreatetruecolor(max(1, $width), max(1, $height));

		if ($layer === false) {
			throw new RenderException("Cannot create a {$width} x {$height} image.");
		}

		imagefilledrectangle($layer, 0, 0, imagesx($layer) - 1, imagesy($layer) - 1, self::CLEAR);

		return $layer;
	}

	private function text(\GdImage $layer, TextElement $element): void {
		$font = ZebraFont::resolve($element->font, $this->options);
		$layout = TextLayout::layout($element, $font);
		$matrix = Placement::matrix($element, $layout->width, $layout->height, $layout->ascent)->scaled($this->scale);

		foreach ($layout->lines as $line) {
			$this->textLine($layer, $matrix, $element->orientation, $font, $line->text, $line->x, $line->baseline, $line->wordSpacing);
		}
	}

	/**
	 * Draw a line glyph by glyph at the positions the metrics give, the same
	 * positions the PDF uses. Every glyph starts on a whole pixel and the
	 * capital letters are a whole number of pixels tall, so the same
	 * character looks the same wherever it appears. Only the part of a glyph
	 * that lands on the image is measured.
	 */
	private function textLine(\GdImage $layer, Matrix $matrix, Orientation $orientation, ResolvedFont $font, string $winAnsi, float $x, float $baseline, float $wordSpacing): void {
		$capHeight = $font->capHeight() * $this->scale;
		$fit = max(1, round($capHeight)) / $capHeight;
		$scaleY = $font->size * $this->scale * $fit;
		$scaleX = $scaleY * $font->horizontalScale;
		$file = $font->typeface->file($this->options);
		$origin = (int) floor($x * $this->scale);
		[$minX, $minY, $maxX, $maxY] = $matrix->inverse()->bounds(0, 0, $this->width, $this->height);
		$clipTop = (int) floor(($minY - $baseline) * $this->scale) - self::CLIP_MARGIN;
		$clipBottom = (int) ceil(($maxY - $baseline) * $this->scale) + self::CLIP_MARGIN;
		$clipLeft = (int) floor($minX * $this->scale) - $origin - self::CLIP_MARGIN;
		$clipRight = (int) ceil($maxX * $this->scale) - $origin + self::CLIP_MARGIN;
		$pen = $x;
		$glyphs = [];

		foreach (unpack("C*", $winAnsi) ?: [] as $code) {
			if ($code !== 32) {
				$column = (int) round($pen * $this->scale) - $origin;
				$rows = $this->glyphCoverage($font, $file, $code, $scaleX, $scaleY, [$clipTop, $clipBottom, $clipLeft - $column, $clipRight - $column]);

				if ($rows !== []) {
					$glyphs[] = [$column, $rows];
				}
			}

			$pen += $font->width(chr($code)) + ($code === 32 ? $wordSpacing : 0);
		}

		if ($glyphs === []) {
			return;
		}

		$top = PHP_INT_MAX;
		$bottom = PHP_INT_MIN;
		$left = PHP_INT_MAX;
		$right = PHP_INT_MIN;

		foreach ($glyphs as [$column, $rows]) {
			$top = min($top, $rows[0][0]);
			$bottom = max($bottom, $rows[count($rows) - 1][0]);

			foreach ($rows as [, $first, $values]) {
				$left = min($left, $column + $first);
				$right = max($right, $column + $first + count($values) - 1);
			}
		}

		$image = $this->layer($right - $left + 1, $bottom - $top + 1);

		foreach ($glyphs as [$column, $rows]) {
			foreach ($rows as [$row, $first, $values]) {
				foreach ($values as $offset => $coverage) {
					if ($coverage <= 0.0) {
						continue;
					}

					$px = $column + $first + $offset - $left;
					$color = $this->ink($coverage, imagecolorat($image, $px, $row - $top));

					if ($color !== null) {
						imagesetpixel($image, $px, $row - $top, $color);
					}
				}
			}
		}

		$this->place($layer, $image, $matrix, $orientation, ($origin + $left) / $this->scale, $baseline + $top / $this->scale);
	}

	/**
	 * The layer color for black ink at the given coverage, on top of what the
	 * pixel already holds. Without antialiasing a pixel is ink when at least
	 * half of it is covered.
	 */
	private function ink(float $coverage, int|false $under): ?int {
		if (!$this->options->getAntialias()) {
			return $coverage >= 0.5 ? self::BLACK : null;
		}

		if ($under !== false && $under !== self::CLEAR) {
			$coverage = 1 - (1 - $coverage) * ($under & 0xFF) / 255;
		}

		$gray = (int) round(255 * (1 - $coverage));

		return $gray >= 255 ? null : $gray * 0x010101;
	}

	/**
	 * The coverage rows of a glyph inside the clip. A glyph that lies inside
	 * the clip whole is kept for reuse.
	 *
	 * @param array{int, int, int, int} $clip top row, bottom row, left column and right column relative to the glyph origin, the bottom and right excluded
	 * @return list<array{int, int, list<float>}>
	 */
	private function glyphCoverage(ResolvedFont $font, string $file, int $code, float $scaleX, float $scaleY, array $clip): array {
		$outline = $font->face->outline($code);
		$extent = GlyphRaster::extent($outline, $scaleX, $scaleY);

		if ($extent === null || $extent[1] <= $clip[0] || $extent[0] >= $clip[1] || $extent[3] <= $clip[2] || $extent[2] >= $clip[3]) {
			return [];
		}

		$pixels = ($extent[1] - $extent[0]) * ($extent[3] - $extent[2]);

		if ($extent[0] < $clip[0] || $extent[1] > $clip[1] || $extent[2] < $clip[2] || $extent[3] > $clip[3] || $pixels > self::GLYPH_CACHE_PIXELS) {
			return GlyphRaster::coverage($outline, $scaleX, $scaleY, $clip);
		}

		$key = sprintf("%s|%d|%.4F|%.4F", $file, $code, $scaleX, $scaleY);

		if (!isset(self::$glyphs[$key])) {
			if (self::$glyphPixels + $pixels > self::GLYPH_CACHE_PIXELS) {
				self::$glyphs = [];
				self::$glyphPixels = 0;
			}

			self::$glyphs[$key] = GlyphRaster::coverage($outline, $scaleX, $scaleY);
			self::$glyphPixels += $pixels;
		}

		return self::$glyphs[$key];
	}

	/**
	 * Rotate a layer-compatible image for the field orientation and paste its
	 * inked pixels so that its local top-left corner lands where the matrix puts it.
	 */
	private function place(\GdImage $layer, \GdImage $image, Matrix $matrix, Orientation $orientation, float $localX, float $localY): void {
		$width = imagesx($image) / $this->scale;
		$height = imagesy($image) / $this->scale;
		[$minX, $minY, $maxX, $maxY] = $matrix->bounds($localX, $localY, $width, $height);
		$image = $this->rotate($image, $orientation);
		$x = (int) round($minX);
		$y = (int) round($minY);
		$columns = imagesx($image);
		$rows = imagesy($image);

		for ($row = 0; $row < $rows; $row++) {
			for ($column = 0; $column < $columns; $column++) {
				$color = imagecolorat($image, $column, $row);

				if ($color !== false && $color !== self::CLEAR) {
					imagesetpixel($layer, $x + $column, $y + $row, $color);
				}
			}
		}

		$this->mark($x, $y, $x + $columns, $y + $rows);
	}

	/**
	 * Rotate a layer-compatible image clockwise by the orientation, pixel by pixel.
	 */
	private function rotate(\GdImage $image, Orientation $orientation): \GdImage {
		if ($orientation === Orientation::Normal) {
			return $image;
		}

		$width = imagesx($image);
		$height = imagesy($image);
		$swap = $orientation !== Orientation::Inverted;
		$result = $this->layer($swap ? $height : $width, $swap ? $width : $height);

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color = imagecolorat($image, $x, $y);

				if ($color === false || $color === self::CLEAR) {
					continue;
				}

				[$tx, $ty] = match ($orientation) {
					Orientation::Rotated => [$height - 1 - $y, $x],
					Orientation::Inverted => [$width - 1 - $x, $height - 1 - $y],
					default => [$y, $width - 1 - $x],
				};
				imagesetpixel($result, $tx, $ty, $color);
			}
		}

		return $result;
	}

	private function shape(\GdImage $layer, Element $element, Path $path, float $width, float $height, bool $black): void {
		$matrix = Placement::matrix($element, $width, $height, 0)->scaled($this->scale);
		$ink = $black ? self::BLACK : self::WHITE;

		foreach ($path->flatten(max(8, 4 * $this->scale)) as $index => $polygon) {
			$transformed = [];
			$centerX = 0.0;
			$centerY = 0.0;

			foreach ($polygon as [$px, $py]) {
				$transformed[] = $matrix->apply($px, $py);
				$centerX += $transformed[count($transformed) - 1][0] / count($polygon);
				$centerY += $transformed[count($transformed) - 1][1] / count($polygon);
			}

			$points = [];

			foreach ($transformed as [$tx, $ty]) {
				$points[] = (int) floor($tx - 0.5 * ($tx <=> $centerX));
				$points[] = (int) floor($ty - 0.5 * ($ty <=> $centerY));
			}

			imagefilledpolygon($layer, $points, $index === 0 ? $ink : self::CLEAR);
		}

		[$x0, $y0, $x1, $y1] = $matrix->bounds(0, 0, $width, $height);
		$this->mark($x0, $y0, $x1 + 1, $y1 + 1);
	}

	/**
	 * Draw the dots of the bitmap that land on the image, and only those.
	 */
	private function image(\GdImage $layer, ImageElement $element): void {
		$bitmap = $element->bitmap;
		$magnificationX = $element->magnificationX;
		$magnificationY = $element->magnificationY;
		$matrix = Placement::matrix($element, $element->width(), $element->height(), 0)->scaled($this->scale);
		[$minX, $minY, $maxX, $maxY] = $matrix->inverse()->bounds(0, 0, $this->width, $this->height);
		$x0 = max(0, (int) floor($minX / $magnificationX) - 1);
		$y0 = max(0, (int) floor($minY / $magnificationY) - 1);
		$x1 = min($bitmap->width, (int) ceil($maxX / $magnificationX) + 1);
		$y1 = min($bitmap->height, (int) ceil($maxY / $magnificationY) + 1);

		if ($x1 <= $x0 || $y1 <= $y0) {
			return;
		}

		$source = $this->bitmapImage($bitmap, $x0, $y0, $x1 - $x0, $y1 - $y0);
		$scaled = $this->layer(($x1 - $x0) * $magnificationX * $this->scale, ($y1 - $y0) * $magnificationY * $this->scale);
		imagecopyresized($scaled, $source, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), imagesx($source), imagesy($source));
		$this->place($layer, $scaled, $matrix, $element->orientation, $x0 * $magnificationX, $y0 * $magnificationY);
	}

	/**
	 * The part of a bitmap from column x0 and row y0 on, as a layer-compatible image.
	 */
	private function bitmapImage(Bitmap $bitmap, int $x0, int $y0, int $width, int $height): \GdImage {
		$image = $this->layer($width, $height);

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				if ($bitmap->pixel($x0 + $x, $y0 + $y)) {
					imagesetpixel($image, $x, $y, self::BLACK);
				}
			}
		}

		return $image;
	}

	private function barcode(\GdImage $layer, BarcodeElement $element): void {
		$layout = new BarcodeLayout($element, $this->options);
		$matrix = Placement::matrix($element, $layout->width, $layout->height, $layout->anchor)->scaled($this->scale);

		foreach ($element->matrix->bars as [$x, $y, $w, $h]) {
			[$ax, $ay, $bx, $by] = $matrix->bounds(
				$x * $element->moduleWidth,
				$layout->barsTop + $y * $element->moduleHeight,
				$w * $element->moduleWidth,
				$h * $element->moduleHeight,
			);
			imagefilledrectangle($layer, (int) round($ax), (int) round($ay), (int) round($bx) - 1, (int) round($by) - 1, self::BLACK);
			$this->mark($ax, $ay, $bx, $by);
		}

		if ($layout->font !== null && $layout->text !== null) {
			$this->textLine($layer, $matrix, $element->orientation, $layout->font, $layout->text, $layout->textX, $layout->textBaseline, 0);
		}
	}
}
