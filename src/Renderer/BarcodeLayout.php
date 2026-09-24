<?php

namespace Stilling\Zpl\Renderer;

use Stilling\Zpl\Font\Encoding;
use Stilling\Zpl\Font\ResolvedFont;
use Stilling\Zpl\Font\ZebraFont;
use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\FontSpec;

/**
 * The geometry of a barcode field in its local box: where the bars go and
 * where the interpretation line goes. All lengths are in dots.
 */
class BarcodeLayout {
	/** Gap between bars and the interpretation line, as a multiple of the module width. */
	private const int GAP_PER_MODULE = 2;

	public readonly float $barsTop;

	public readonly float $barsWidth;

	public readonly float $barsHeight;

	public readonly float $width;

	public readonly float $height;

	/** The bottom of the bars, which ^FT anchors on. */
	public readonly float $anchor;

	public readonly ?ResolvedFont $font;

	/** The interpretation line as WinAnsi bytes, or null when none is printed. */
	public readonly ?string $text;

	public readonly float $textX;

	public readonly float $textBaseline;

	public function __construct(BarcodeElement $element) {
		$this->barsWidth = $element->width();
		$this->barsHeight = $element->height();
		$textHeight = 0.0;
		$barsTop = 0.0;
		$font = null;
		$text = null;
		$textX = 0.0;
		$textBaseline = 0.0;

		if ($element->text !== null && $element->text !== "") {
			$magnification = max(1, (int) round($element->moduleWidth));
			$font = ZebraFont::resolve(new FontSpec("A", 9 * $magnification, 5 * $magnification));
			$gap = self::GAP_PER_MODULE * $magnification;
			$text = Encoding::toWinAnsi($element->text);
			$textHeight = $font->lineHeight + $gap;
			$textX = ($this->barsWidth - $font->width($text)) / 2;
			$textTop = $element->textAbove ? 0 : $this->barsHeight + $gap;
			$barsTop = $element->textAbove ? $textHeight : 0;
			$textBaseline = $textTop + $font->ascent;
		}

		$this->barsTop = $barsTop;
		$this->width = $this->barsWidth;
		$this->height = $this->barsHeight + $textHeight;
		$this->anchor = $barsTop + $this->barsHeight;
		$this->font = $font;
		$this->text = $text;
		$this->textX = $textX;
		$this->textBaseline = $textBaseline;
	}
}
