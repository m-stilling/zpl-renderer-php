<?php

namespace Stilling\ZplRenderer\Renderer;

use Stilling\ZplRenderer\Font\ResolvedFont;
use Stilling\ZplRenderer\Font\ZebraFont;
use Stilling\ZplRenderer\Graphics\PngEncoder;
use Stilling\ZplRenderer\Model\BarcodeElement;
use Stilling\ZplRenderer\Model\BoxElement;
use Stilling\ZplRenderer\Model\CircleElement;
use Stilling\ZplRenderer\Model\DiagonalElement;
use Stilling\ZplRenderer\Model\Element;
use Stilling\ZplRenderer\Model\EllipseElement;
use Stilling\ZplRenderer\Model\ImageElement;
use Stilling\ZplRenderer\Model\Label;
use Stilling\ZplRenderer\Model\TextElement;
use Stilling\ZplRenderer\Options;

/**
 * Draws labels as SVG images, one per label, with the printer dot as the user
 * unit. Text is drawn as glyph outlines, so the image needs no font. Each
 * glyph is defined once and reused wherever the character appears.
 */
class SvgRenderer {
	/** @var array<string, string|null> glyph ids by font file and character code, or null for a blank glyph */
	private array $glyphIds = [];

	/** @var list<string> the path definition of every glyph used on the current label */
	private array $defs = [];

	public function __construct(
		private readonly Options $options,
	) {
	}

	/**
	 * @param list<Label> $labels
	 * @return list<string> one SVG per label
	 */
	public function render(array $labels): array {
		return array_map($this->renderLabel(...), $labels);
	}

	public function renderLabel(Label $label): string {
		$this->glyphIds = [];
		$this->defs = [];
		$body = "";

		foreach ($label->elements as $element) {
			$body .= $this->renderElement($element);
		}

		$scaleX = $label->mirrored !== $label->inverted ? -1 : 1;
		$scaleY = $label->inverted ? -1 : 1;
		$transform = $scaleX === 1 && $scaleY === 1 ? "" : sprintf(
			' transform="matrix(%d 0 0 %d %d %d)"',
			$scaleX,
			$scaleY,
			$scaleX < 0 ? $label->width : 0,
			$scaleY < 0 ? $label->height : 0,
		);
		$widthMm = self::number($label->width / $label->dpmm);
		$heightMm = self::number($label->height / $label->dpmm);
		$defs = $this->defs === [] ? "" : "<defs>\n" . implode("\n", $this->defs) . "\n</defs>\n";

		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$widthMm}mm\" height=\"{$heightMm}mm\" viewBox=\"0 0 {$label->width} {$label->height}\">\n"
			. $defs
			. "<g{$transform} fill=\"#000\">\n"
			. "<rect width=\"{$label->width}\" height=\"{$label->height}\" fill=\"#fff\"/>\n"
			. $body
			. "</g>\n</svg>\n";
	}

	/**
	 * Draw one element in its own group. Every glyph that the element uses is
	 * added to the definitions of the label.
	 *
	 * @phpstan-impure
	 */
	private function renderElement(Element $element): string {
		[$width, $height, $body, $anchor] = match (true) {
			$element instanceof TextElement => $this->text($element),
			$element instanceof BoxElement => $this->shape($element, Shapes::box($element), $element->width, $element->height, $element->black),
			$element instanceof CircleElement => $this->shape($element, Shapes::circle($element), $element->diameter, $element->diameter, $element->black),
			$element instanceof EllipseElement => $this->shape($element, Shapes::ellipse($element), $element->width, $element->height, $element->black),
			$element instanceof DiagonalElement => $this->shape($element, Shapes::diagonal($element), $element->width, $element->height, $element->black),
			$element instanceof ImageElement => $this->image($element),
			$element instanceof BarcodeElement => $this->barcode($element),
			default => [0, 0, "", 0],
		};

		if ($body === "") {
			return "";
		}

		$matrix = Placement::matrix($element, $width, $height, $anchor);
		$transform = implode(" ", array_map(self::number(...), [$matrix->a, $matrix->b, $matrix->c, $matrix->d, $matrix->e, $matrix->f]));
		$paint = $element->reverse ? " fill=\"#fff\" style=\"mix-blend-mode:difference\"" : "";

		return "<g transform=\"matrix({$transform})\"{$paint}>\n{$body}</g>\n";
	}

	/**
	 * @return array{float, float, string, float} width, height, content, anchor
	 */
	private function text(TextElement $element): array {
		$font = ZebraFont::resolve($element->font, $this->options);
		$layout = TextLayout::layout($element, $font);
		$body = "";

		foreach ($layout->lines as $line) {
			$body .= $this->textLine($font, $line->text, $line->x, $line->baseline, $line->wordSpacing);
		}

		return [$layout->width, $layout->height, $body, $layout->ascent];
	}

	/**
	 * Place a glyph reference for every character at the position the metrics
	 * give, the same position the PDF and the PNG use.
	 */
	private function textLine(ResolvedFont $font, string $winAnsi, float $x, float $baseline, float $wordSpacing = 0.0): string {
		$file = $font->typeface->file($this->options);
		$scaleX = self::number($font->size * $font->horizontalScale);
		$scaleY = self::number(-$font->size);
		$pen = $x;
		$out = "";

		foreach (unpack("C*", $winAnsi) ?: [] as $code) {
			$id = $code === 32 ? null : $this->glyph($font, $file, $code);

			if ($id !== null) {
				$out .= "<use href=\"#{$id}\" transform=\"matrix({$scaleX} 0 0 {$scaleY} " . self::number($pen) . " " . self::number($baseline) . ")\"/>\n";
			}

			$pen += $font->width(chr($code)) + ($code === 32 ? $wordSpacing : 0);
		}

		return $out;
	}

	/**
	 * The id of the path that defines a glyph, or null when the glyph is blank.
	 */
	private function glyph(ResolvedFont $font, string $file, int $code): ?string {
		$key = "{$file}|{$code}";

		if (array_key_exists($key, $this->glyphIds)) {
			return $this->glyphIds[$key];
		}

		$d = self::outlinePath($font->face->outline($code));
		$id = $d === "" ? null : "g" . count($this->defs);

		if ($id !== null) {
			$this->defs[] = "<path id=\"{$id}\" d=\"{$d}\"/>";
		}

		return $this->glyphIds[$key] = $id;
	}

	/**
	 * A TrueType outline as SVG path data in em, y up. Off-curve points are
	 * quadratic control points; two in a row have an on-curve point halfway between them.
	 *
	 * @param list<list<array{float, float, bool}>> $contours
	 */
	private static function outlinePath(array $contours): string {
		$d = "";

		foreach ($contours as $points) {
			$count = count($points);

			if ($count < 2) {
				continue;
			}

			$first = 0;

			while ($first < $count && !$points[$first][2]) {
				$first++;
			}

			if ($first === $count) {
				$start = [($points[0][0] + $points[1][0]) / 2, ($points[0][1] + $points[1][1]) / 2];
				$first = 0;
			} else {
				$start = [$points[$first][0], $points[$first][1]];
				$first++;
			}

			$d .= "M" . self::point($start);
			$control = null;

			for ($i = 0; $i < $count; $i++) {
				[$x, $y, $onCurve] = $points[($first + $i) % $count];

				if ($onCurve) {
					$d .= self::segment($control, [$x, $y]);
					$control = null;
				} elseif ($control === null) {
					$control = [$x, $y];
				} else {
					$middle = [($control[0] + $x) / 2, ($control[1] + $y) / 2];
					$d .= self::segment($control, $middle);
					$control = [$x, $y];
				}
			}

			if ($control !== null) {
				$d .= self::segment($control, $start);
			}

			$d .= "Z";
		}

		return $d;
	}

	/**
	 * @param array{float, float}|null $control
	 * @param array{float, float} $to
	 */
	private static function segment(?array $control, array $to): string {
		return $control === null ? "L" . self::point($to) : "Q" . self::point($control) . " " . self::point($to);
	}

	/**
	 * @param array{float, float} $point
	 */
	private static function point(array $point): string {
		return self::number($point[0], 4) . " " . self::number($point[1], 4);
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function shape(Element $element, Path $path, float $width, float $height, bool $black): array {
		$fill = $black || $element->reverse ? "" : " fill=\"#fff\"";

		return [$width, $height, "<path{$fill} fill-rule=\"evenodd\" d=\"" . $path->toSvg() . "\"/>\n", 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function image(ImageElement $element): array {
		$width = $element->width();
		$height = $element->height();

		if ($width < 1 || $height < 1) {
			return [0, 0, "", 0];
		}

		$png = base64_encode(PngEncoder::encode($element->bitmap, $element->reverse));

		return [$width, $height, "<image width=\"{$width}\" height=\"{$height}\" preserveAspectRatio=\"none\" style=\"image-rendering:pixelated\" href=\"data:image/png;base64,{$png}\"/>\n", 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function barcode(BarcodeElement $element): array {
		$layout = new BarcodeLayout($element, $this->options);
		$body = "";
		$d = "";

		foreach ($element->matrix->bars as [$x, $y, $w, $h]) {
			$width = self::number($w * $element->moduleWidth);
			$d .= "M" . self::number($x * $element->moduleWidth) . " " . self::number($layout->barsTop + $y * $element->moduleHeight)
				. "h{$width}v" . self::number($h * $element->moduleHeight) . "h-{$width}Z";
		}

		if ($d !== "") {
			$body .= "<path shape-rendering=\"crispEdges\" d=\"{$d}\"/>\n";
		}

		if ($layout->font !== null && $layout->text !== null) {
			$body .= $this->textLine($layout->font, $layout->text, $layout->textX, $layout->textBaseline);
		}

		return [$layout->width, $layout->height, $body, $layout->anchor];
	}

	/**
	 * A number with at most the given decimals, without trailing zeros.
	 */
	private static function number(float $value, int $decimals = 3): string {
		$text = rtrim(rtrim(sprintf("%.{$decimals}F", $value), "0"), ".");

		return $text === "-0" ? "0" : $text;
	}
}
