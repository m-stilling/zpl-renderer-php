<?php

namespace Stilling\Zpl\Renderer;

use Stilling\Zpl\Exceptions\RenderException;
use Stilling\Zpl\Exceptions\UnsupportedException;
use Stilling\Zpl\Font\FontMetrics;
use Stilling\Zpl\Font\ResolvedFont;
use Stilling\Zpl\Font\ZebraFont;
use Stilling\Zpl\Graphics\Bitmap;
use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\DiagonalElement;
use Stilling\Zpl\Model\Element;
use Stilling\Zpl\Model\EllipseElement;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Label;
use Stilling\Zpl\Model\Orientation;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Options;

/**
 * Draws labels as one-bit PNG images with GD, one image per label, at
 * pixelsPerDot pixels for every printer dot.
 *
 * Every field is drawn on its own layer and then merged into the label:
 * painted over it, or XOR-ed into it for reverse fields, the way the printer does.
 */
class PngRenderer {
	/** Layer color index that stays transparent when the layer is merged. */
	private const int CLEAR = 0;

	private const int BLACK = 1;

	private const int WHITE = 2;

	/** GD sizes TrueType text in points at 96 dpi, so one pixel of em is 0.75 points. */
	private const float POINTS_PER_PIXEL = 0.75;

	/** @var array<string, float> cap height as a fraction of the em, by font file */
	private static array $capHeights = [];

	private int $scale = 1;

	private int $width = 0;

	private int $height = 0;

	/** @var array{int, int, int, int}|null pixel bounds drawn on the current layer: x0, y0, x1, y1 */
	private ?array $drawn = null;

	public function __construct(
		private readonly Options $options,
	) {
		if (!function_exists("imagecreate") || !function_exists("imagettftext")) {
			throw new UnsupportedException("Rendering PNG requires the GD extension with FreeType support.");
		}
	}

	/**
	 * @param list<Label> $labels
	 * @return list<string> one PNG per label
	 */
	public function render(array $labels): array {
		return array_map($this->renderLabel(...), $labels);
	}

	public function renderLabel(Label $label): string {
		$this->scale = $this->options->pixelsPerDot;
		$this->width = $label->width * $this->scale;
		$this->height = $label->height * $this->scale;
		$canvas = $this->create($this->width, $this->height);
		imagecolorallocate($canvas, 255, 255, 255);
		imagecolorallocate($canvas, 0, 0, 0);

		foreach ($label->elements as $element) {
			$this->renderElement($canvas, $element, $label->reversed);
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

	private function renderElement(\GdImage $canvas, Element $element, bool $labelReversed): void {
		$layer = $this->layer($this->width, $this->height);
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

		if ($element->reverse !== $labelReversed) {
			$this->mergeReversed($canvas, $layer, $x0, $y0, $x1, $y1);
		} else {
			imagecopy($canvas, $layer, $x0, $y0, $x0, $y0, $x1 - $x0, $y1 - $y0);
		}
	}

	/**
	 * XOR the layer into the canvas: every inked pixel flips the canvas pixel.
	 */
	private function mergeReversed(\GdImage $canvas, \GdImage $layer, int $x0, int $y0, int $x1, int $y1): void {
		for ($y = $y0; $y < $y1; $y++) {
			for ($x = $x0; $x < $x1; $x++) {
				if (imagecolorat($layer, $x, $y) === self::CLEAR) {
					continue;
				}

				imagesetpixel($canvas, $x, $y, imagecolorat($canvas, $x, $y) === 0 ? 1 : 0);
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

	private function create(int $width, int $height): \GdImage {
		$image = imagecreate(max(1, $width), max(1, $height));

		if ($image === false) {
			throw new RenderException("Cannot create a {$width} x {$height} image.");
		}

		return $image;
	}

	/**
	 * A transparent image with the black and white inks allocated.
	 */
	private function layer(int $width, int $height): \GdImage {
		$layer = $this->create($width, $height);
		imagecolorallocate($layer, 255, 0, 255);
		imagecolorallocate($layer, 0, 0, 0);
		imagecolorallocate($layer, 255, 255, 255);
		imagecolortransparent($layer, self::CLEAR);

		return $layer;
	}

	private function text(\GdImage $layer, TextElement $element): void {
		$font = ZebraFont::resolve($element->font, $this->options->scalableFontCondense);
		$layout = TextLayout::layout($element, $font);
		$matrix = Placement::matrix($element, $layout->width, $layout->height, $layout->ascent)->scaled($this->scale);

		foreach ($layout->lines as $line) {
			$this->textLine($layer, $matrix, $element->orientation, $font, $line->text, $line->x, $line->baseline, $line->wordSpacing);
		}
	}

	/**
	 * Draw a line word by word, each word squeezed to the width the metrics
	 * give it, so the PNG lines up exactly like the PDF.
	 */
	private function textLine(\GdImage $layer, Matrix $matrix, Orientation $orientation, ResolvedFont $font, string $winAnsi, float $x, float $baseline, float $wordSpacing): void {
		$space = $font->width(" ") + $wordSpacing;

		foreach (explode(" ", $winAnsi) as $word) {
			if ($word !== "") {
				$this->word($layer, $matrix, $orientation, $font, $word, $x, $baseline);
			}

			$x += $font->width($word) + $space;
		}
	}

	private function word(\GdImage $layer, Matrix $matrix, Orientation $orientation, ResolvedFont $font, string $winAnsi, float $x, float $baseline): void {
		$file = $this->fontFile($font);
		$utf8 = mb_convert_encoding($winAnsi, "UTF-8", "Windows-1252");
		$em = $font->ascent * $this->scale / $this->capHeight($file);
		$points = $em * self::POINTS_PER_PIXEL;
		$box = imagettfbbox($points, 0, $file, $utf8);

		if ($box === false) {
			throw new RenderException("Cannot measure text with font {$file}.");
		}

		$left = (int) $box[0];
		$naturalWidth = max(1, (int) $box[2] - $left);
		$ascent = max(1, (int) -$box[7]);
		$descent = max(0, (int) $box[1]);
		$rows = $ascent + $descent + 2;
		$targetWidth = max(1, (int) round($font->width($winAnsi) * $this->scale));
		$glyphs = imagecreatetruecolor($naturalWidth + 2, $rows);
		$squeezed = imagecreatetruecolor($targetWidth, $rows);

		if ($glyphs === false || $squeezed === false) {
			throw new RenderException("Cannot create an image for text.");
		}

		$white = (int) imagecolorallocate($glyphs, 255, 255, 255);
		$black = (int) imagecolorallocate($glyphs, 0, 0, 0);
		imagefill($glyphs, 0, 0, $white);
		imagettftext($glyphs, $points, 0, 1 - $left, $ascent + 1, $black, $file, $utf8);
		imagecopyresampled($squeezed, $glyphs, 0, 0, 0, 0, $targetWidth, $rows, $naturalWidth + 2, $rows);

		$this->place($layer, $this->threshold($squeezed), $matrix, $orientation, $x, $baseline - ($ascent + 1) / $this->scale);
	}

	/**
	 * Cap height of a TrueType file as a fraction of its em, measured once.
	 */
	private function capHeight(string $file): float {
		if (!isset(self::$capHeights[$file])) {
			$box = imagettfbbox(75, 0, $file, "H");
			$cap = $box === false ? 0 : ($box[1] - $box[7]) / 100;
			self::$capHeights[$file] = $cap > 0 ? $cap : FontMetrics::CAP_HEIGHT[FontMetrics::SCALABLE];
		}

		return self::$capHeights[$file];
	}

	/**
	 * Turn a gray image into a layer-compatible one: dark pixels become black ink, the rest clear.
	 */
	private function threshold(\GdImage $gray): \GdImage {
		$width = imagesx($gray);
		$height = imagesy($gray);
		$result = $this->layer($width, $height);

		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				$color = imagecolorat($gray, $x, $y);
				$luminance = 0.299 * (($color >> 16) & 0xFF) + 0.587 * (($color >> 8) & 0xFF) + 0.114 * ($color & 0xFF);

				if ($luminance < 128) {
					imagesetpixel($result, $x, $y, self::BLACK);
				}
			}
		}

		return $result;
	}

	/**
	 * Rotate a layer-compatible image for the field orientation and paste it
	 * so that its local top-left corner lands where the matrix puts it.
	 */
	private function place(\GdImage $layer, \GdImage $image, Matrix $matrix, Orientation $orientation, float $localX, float $localY): void {
		$width = imagesx($image) / $this->scale;
		$height = imagesy($image) / $this->scale;
		[$minX, $minY, $maxX, $maxY] = $matrix->bounds($localX, $localY, $width, $height);
		$image = $this->rotate($image, $orientation);
		$x = (int) round($minX);
		$y = (int) round($minY);
		imagecopy($layer, $image, $x, $y, 0, 0, imagesx($image), imagesy($image));
		$this->mark($x, $y, $x + imagesx($image), $y + imagesy($image));
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

	private function fontFile(ResolvedFont $font): string {
		$file = match ($font->pdfFont) {
			FontMetrics::MONO_BOLD => $this->options->monoBoldFontFile,
			FontMetrics::MONO => $this->options->monoFontFile,
			default => $this->options->scalableFontFile,
		};

		if (!is_file($file)) {
			throw new RenderException("Font file {$file} does not exist.");
		}

		return $file;
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

	private function image(\GdImage $layer, ImageElement $element): void {
		$width = $element->width();
		$height = $element->height();
		$matrix = Placement::matrix($element, $width, $height, 0)->scaled($this->scale);
		$bitmap = $this->bitmapImage($element->bitmap);
		$scaled = $this->layer((int) ($width * $this->scale), (int) ($height * $this->scale));
		imagecopyresized($scaled, $bitmap, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), imagesx($bitmap), imagesy($bitmap));
		$this->place($layer, $scaled, $matrix, $element->orientation, 0, 0);
	}

	private function bitmapImage(Bitmap $bitmap): \GdImage {
		$image = $this->layer($bitmap->width, $bitmap->height);

		for ($y = 0; $y < $bitmap->height; $y++) {
			for ($x = 0; $x < $bitmap->width; $x++) {
				if ($bitmap->pixel($x, $y)) {
					imagesetpixel($image, $x, $y, self::BLACK);
				}
			}
		}

		return $image;
	}

	private function barcode(\GdImage $layer, BarcodeElement $element): void {
		$layout = new BarcodeLayout($element);
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
