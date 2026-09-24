<?php

namespace Stilling\ZplRenderer\Renderer;

use Stilling\ZplRenderer\Font\Encoding;
use Stilling\ZplRenderer\Font\ResolvedFont;
use Stilling\ZplRenderer\Font\Typeface;
use Stilling\ZplRenderer\Font\ZebraFont;
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
use Stilling\ZplRenderer\Pdf\Document;

/**
 * Draws labels as vector PDF pages, one page per label copy.
 */
class PdfRenderer {
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

		$scale = 72 / $this->options->dpi();

		foreach ($labels as $label) {
			$copies = min(
				$this->options->getHonorQuantity() ? $label->quantity : 1,
				$this->options->getMaxPages() - $this->document->pageCount(),
			);

			if ($copies < 1) {
				break;
			}

			$this->document->addPage($label->width * $scale, $label->height * $scale, $this->renderLabel($label), $copies);
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
			$out .= $this->renderElement($element);
		}

		return $out . "Q\n";
	}

	private function renderElement(Element $element): string {
		[$width, $height, $body, $anchor] = match (true) {
			$element instanceof TextElement => $this->text($element),
			$element instanceof BoxElement => $this->shape(Shapes::box($element), $element->width, $element->height, $element->black, $element->reverse),
			$element instanceof CircleElement => $this->shape(Shapes::circle($element), $element->diameter, $element->diameter, $element->black, $element->reverse),
			$element instanceof EllipseElement => $this->shape(Shapes::ellipse($element), $element->width, $element->height, $element->black, $element->reverse),
			$element instanceof DiagonalElement => $this->shape(Shapes::diagonal($element), $element->width, $element->height, $element->black, $element->reverse),
			$element instanceof ImageElement => $this->image($element),
			$element instanceof BarcodeElement => $this->barcode($element),
			default => [0, 0, "", 0],
		};

		if ($body === "") {
			return "";
		}

		$matrix = Placement::matrix($element, $width, $height, $anchor);
		$cm = implode(" ", array_map(Document::number(...), [$matrix->a, $matrix->b, $matrix->c, $matrix->d, $matrix->e, $matrix->f]));
		$paint = $element->reverse ? "/" . Document::REVERSE_STATE . " gs 1 g 1 G" : "0 g 0 G";

		return "q\n{$cm} cm\n{$paint}\n{$body}Q\n";
	}

	/**
	 * @return array{float, float, string, float} width, height, content, anchor
	 */
	private function text(TextElement $element): array {
		$font = ZebraFont::resolve($element->font, $this->options);
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
		$resource = match ($font->typeface) {
			Typeface::Scalable => Document::FONT_SCALABLE,
			Typeface::MonoBold => Document::FONT_MONO_BOLD,
			Typeface::Mono => Document::FONT_MONO,
		};
		$this->document->useFont($resource, $font->typeface->file($this->options), $winAnsi);
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
	private function shape(Path $path, float $width, float $height, bool $black, bool $reverse): array {
		return [$width, $height, ($black && !$reverse ? "0 g\n" : "1 g\n") . $path->toPdf() . "f*\n", 0];
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
		$layout = new BarcodeLayout($element, $this->options);
		$body = "";

		if ($layout->font !== null && $layout->text !== null) {
			$body .= $this->textLine($layout->font, $layout->text, $layout->textX, $layout->textBaseline);
		}

		foreach ($element->matrix->bars as [$x, $y, $w, $h]) {
			$body .= implode(" ", array_map(Document::number(...), [
				$x * $element->moduleWidth,
				$layout->barsTop + $y * $element->moduleHeight,
				$w * $element->moduleWidth,
				$h * $element->moduleHeight,
			])) . " re\n";
		}

		return [$layout->width, $layout->height, $body . "f\n", $layout->anchor];
	}
}
