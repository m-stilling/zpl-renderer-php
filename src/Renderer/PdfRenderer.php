<?php

namespace Stilling\Zpl\Renderer;

use Stilling\Zpl\Font\Encoding;
use Stilling\Zpl\Font\FontMetrics;
use Stilling\Zpl\Font\ResolvedFont;
use Stilling\Zpl\Font\ZebraFont;
use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\DiagonalElement;
use Stilling\Zpl\Model\Element;
use Stilling\Zpl\Model\EllipseElement;
use Stilling\Zpl\Model\FontSpec;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Justification;
use Stilling\Zpl\Model\Label;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Options;
use Stilling\Zpl\Pdf\Document;

/**
 * Draws labels as vector PDF pages, one page per label copy.
 */
class PdfRenderer {
	/** Bezier control distance for a quarter circle. */
	private const float KAPPA = 0.5523;

	/** Gap between bars and the interpretation line, as a multiple of the module width. */
	private const int INTERPRETATION_GAP_PER_MODULE = 2;

	private Document $document;

	public function __construct(
		private readonly Options $options,
	) {
		$this->document = new Document();
	}

	/**
	 * @param list<Label> $labels
	 */
	public function render(array $labels): string {
		$this->document = new Document();

		foreach ($labels as $label) {
			$content = $this->renderLabel($label);
			$copies = $this->options->honorQuantity ? $label->quantity : 1;
			$scale = 72 / $this->options->dpi();

			for ($copy = 0; $copy < $copies; $copy++) {
				if ($this->document->pageCount() >= $this->options->maxPages) {
					break 2;
				}

				$this->document->addPage($label->width * $scale, $label->height * $scale, $content);
			}
		}

		return $this->document->render();
	}

	private function renderLabel(Label $label): string {
		$scale = 72 / $this->options->dpi();
		$s = Document::number($scale);
		$height = Document::number($label->height * $scale);
		$out = "q\n{$s} 0 0 -{$s} 0 {$height} cm\n";

		if ($label->mirrored) {
			$out .= "-1 0 0 1 {$label->width} 0 cm\n";
		}

		if ($label->inverted) {
			$out .= "-1 0 0 -1 {$label->width} {$label->height} cm\n";
		}

		foreach ($label->elements as $element) {
			$out .= $this->renderElement($element, $label->reversed);
		}

		return $out . "Q\n";
	}

	private function renderElement(Element $element, bool $labelReversed): string {
		[$width, $height, $body, $ascent] = match (true) {
			$element instanceof TextElement => $this->text($element),
			$element instanceof BoxElement => $this->box($element),
			$element instanceof CircleElement => $this->circle($element),
			$element instanceof EllipseElement => $this->ellipse($element),
			$element instanceof DiagonalElement => $this->diagonal($element),
			$element instanceof ImageElement => $this->image($element),
			$element instanceof BarcodeElement => $this->barcode($element),
			default => [0, 0, "", 0],
		};

		if ($body === "") {
			return "";
		}

		$reverse = $element->reverse !== $labelReversed;
		$out = "q\n" . $this->placement($element, $width, $height, $ascent) . "\n";

		if ($reverse) {
			$out .= "/" . Document::REVERSE_STATE . " gs 1 g 1 G\n";
		} else {
			$out .= "0 g 0 G\n";
		}

		return $out . $body . "Q\n";
	}

	/**
	 * The cm operator that maps the local box (0,0)-(width,height) onto the page.
	 * ^FO puts the top-left of the rotated box at the origin; ^FT rotates the
	 * field around its anchor line: the first baseline of text, the bottom of
	 * the bars of a barcode, the bottom edge of anything else.
	 */
	private function placement(Element $element, float $width, float $height, float $anchor): string {
		$orientation = $element->orientation;
		[$a, $b, $c, $d] = $orientation->matrix();
		$shiftX = $element->justification === Justification::Right ? -$width : 0.0;
		$shiftY = 0.0;

		if ($element->typeset) {
			$shiftY = -($anchor > 0 ? $anchor : $height);
			$offsetX = $element->x;
			$offsetY = $element->y;
		} else {
			$minX = INF;
			$minY = INF;

			foreach ([[0, 0], [$width, 0], [0, $height], [$width, $height]] as [$px, $py]) {
				[$rx, $ry] = $orientation->rotate($px, $py);
				$minX = min($minX, $rx);
				$minY = min($minY, $ry);
			}

			$offsetX = $element->x - $minX;
			$offsetY = $element->y - $minY;
		}

		[$sx, $sy] = $orientation->rotate($shiftX, $shiftY);

		return implode(" ", array_map(Document::number(...), [$a, $b, $c, $d, $offsetX + $sx, $offsetY + $sy])) . " cm";
	}

	/**
	 * @return array{float, float, string, float} width, height, content, ascent
	 */
	private function text(TextElement $element): array {
		$font = ZebraFont::resolve($element->font, $this->options->scalableFontCondense);
		$layout = TextLayout::layout($element, $font);
		$body = "";

		foreach ($layout->lines as $line) {
			if ($line->text === "") {
				continue;
			}

			$body .= $this->textLine($font, $line->text, $line->x, $line->baseline, $line->wordSpacing);
		}

		return [$layout->width, $layout->height, $body, $layout->ascent];
	}

	private function textLine(ResolvedFont $font, string $winAnsi, float $x, float $baseline, float $wordSpacing = 0.0): string {
		$resource = match ($font->pdfFont) {
			FontMetrics::COURIER_BOLD => Document::FONT_COURIER_BOLD,
			FontMetrics::COURIER => Document::FONT_COURIER,
			default => Document::FONT_HELVETICA_BOLD,
		};
		$size = Document::number($font->size);
		$scale = Document::number($font->horizontalScale * 100);
		$spacing = $wordSpacing > 0 ? Document::number($wordSpacing / $font->horizontalScale) . " Tw " : "";
		$tx = Document::number($x);
		$ty = Document::number($baseline);

		return "BT /{$resource} {$size} Tf {$scale} Tz {$spacing}1 0 0 -1 {$tx} {$ty} Tm " . Encoding::pdfLiteral($winAnsi) . " Tj ET\n";
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function box(BoxElement $element): array {
		$w = $element->width;
		$h = $element->height;
		$t = min($element->thickness, intdiv(min($w, $h) + 1, 2));
		$radius = $element->rounding > 0 ? $element->rounding / 8 * min($w, $h) / 2 : 0.0;
		$body = $this->fill($element->black);
		$body .= $this->roundedRect(0, 0, $w, $h, $radius);

		if ($t < min($w, $h) / 2) {
			$body .= $this->roundedRect($t, $t, $w - 2 * $t, $h - 2 * $t, max(0, $radius - $t));
		}

		return [$w, $h, $body . "f*\n", 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function circle(CircleElement $element): array {
		$d = $element->diameter;
		$t = min($element->thickness, intdiv($d + 1, 2));
		$body = $this->fill($element->black) . $this->ellipsePath(0, 0, $d, $d);

		if ($t < $d / 2) {
			$body .= $this->ellipsePath($t, $t, $d - 2 * $t, $d - 2 * $t);
		}

		return [$d, $d, $body . "f*\n", 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function ellipse(EllipseElement $element): array {
		$w = $element->width;
		$h = $element->height;
		$t = min($element->thickness, intdiv(min($w, $h) + 1, 2));
		$body = $this->fill($element->black) . $this->ellipsePath(0, 0, $w, $h);

		if ($t < min($w, $h) / 2) {
			$body .= $this->ellipsePath($t, $t, $w - 2 * $t, $h - 2 * $t);
		}

		return [$w, $h, $body . "f*\n", 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function diagonal(DiagonalElement $element): array {
		$w = $element->width;
		$h = $element->height;
		$t = Document::number($element->thickness);
		$color = $element->black ? "0 G" : "1 G";
		[$x0, $y0, $x1, $y1] = $element->rightLeaning ? [0, $h, $w, 0] : [0, 0, $w, $h];
		$body = "{$color} {$t} w 0 J " . Document::number($x0) . " " . Document::number($y0) . " m "
			. Document::number($x1) . " " . Document::number($y1) . " l S\n";

		return [$w, $h, $body, 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function image(ImageElement $element): array {
		$name = $this->document->addImage($element->bitmap);
		$w = Document::number($element->width());
		$h = Document::number($element->height());

		return [$element->width(), $element->height(), "q {$w} 0 0 -{$h} 0 {$h} cm /{$name} Do Q\n", 0];
	}

	/**
	 * @return array{float, float, string, float}
	 */
	private function barcode(BarcodeElement $element): array {
		$barsWidth = $element->width();
		$barsHeight = $element->height();
		$textHeight = 0.0;
		$body = "";
		$barsTop = 0.0;

		if ($element->text !== null && $element->text !== "") {
			$magnification = max(1, (int) round($element->moduleWidth));
			$font = ZebraFont::resolve(new FontSpec("A", 9 * $magnification, 5 * $magnification));
			$gap = self::INTERPRETATION_GAP_PER_MODULE * $magnification;
			$winAnsi = Encoding::toWinAnsi($element->text);
			$textWidth = $font->width($winAnsi);
			$textHeight = $font->lineHeight + $gap;
			$textX = ($barsWidth - $textWidth) / 2;
			$textTop = $element->textAbove ? 0 : $barsHeight + $gap;
			$barsTop = $element->textAbove ? $textHeight : 0;
			$body .= $this->textLine($font, $winAnsi, $textX, $textTop + $font->ascent);
		}

		foreach ($element->matrix->bars as [$x, $y, $w, $h]) {
			$body .= implode(" ", array_map(Document::number(...), [
				$x * $element->moduleWidth,
				$barsTop + $y * $element->moduleHeight,
				$w * $element->moduleWidth,
				$h * $element->moduleHeight,
			])) . " re\n";
		}

		return [$barsWidth, $barsHeight + $textHeight, $body . "f\n", $barsTop + $barsHeight];
	}

	private function fill(bool $black): string {
		return $black ? "0 g\n" : "1 g\n";
	}

	private function roundedRect(float $x, float $y, float $w, float $h, float $r): string {
		$n = Document::number(...);

		if ($r <= 0) {
			return "{$n($x)} {$n($y)} {$n($w)} {$n($h)} re\n";
		}

		$r = min($r, $w / 2, $h / 2);
		$k = $r * self::KAPPA;
		$x1 = $x + $w;
		$y1 = $y + $h;

		return "{$n($x + $r)} {$n($y)} m "
			. "{$n($x1 - $r)} {$n($y)} l {$n($x1 - $r + $k)} {$n($y)} {$n($x1)} {$n($y + $r - $k)} {$n($x1)} {$n($y + $r)} c "
			. "{$n($x1)} {$n($y1 - $r)} l {$n($x1)} {$n($y1 - $r + $k)} {$n($x1 - $r + $k)} {$n($y1)} {$n($x1 - $r)} {$n($y1)} c "
			. "{$n($x + $r)} {$n($y1)} l {$n($x + $r - $k)} {$n($y1)} {$n($x)} {$n($y1 - $r + $k)} {$n($x)} {$n($y1 - $r)} c "
			. "{$n($x)} {$n($y + $r)} l {$n($x)} {$n($y + $r - $k)} {$n($x + $r - $k)} {$n($y)} {$n($x + $r)} {$n($y)} c h\n";
	}

	private function ellipsePath(float $x, float $y, float $w, float $h): string {
		$n = Document::number(...);
		$rx = $w / 2;
		$ry = $h / 2;
		$cx = $x + $rx;
		$cy = $y + $ry;
		$kx = $rx * self::KAPPA;
		$ky = $ry * self::KAPPA;

		return "{$n($cx + $rx)} {$n($cy)} m "
			. "{$n($cx + $rx)} {$n($cy + $ky)} {$n($cx + $kx)} {$n($cy + $ry)} {$n($cx)} {$n($cy + $ry)} c "
			. "{$n($cx - $kx)} {$n($cy + $ry)} {$n($cx - $rx)} {$n($cy + $ky)} {$n($cx - $rx)} {$n($cy)} c "
			. "{$n($cx - $rx)} {$n($cy - $ky)} {$n($cx - $kx)} {$n($cy - $ry)} {$n($cx)} {$n($cy - $ry)} c "
			. "{$n($cx + $kx)} {$n($cy - $ry)} {$n($cx + $rx)} {$n($cy - $ky)} {$n($cx + $rx)} {$n($cy)} c h\n";
	}
}
