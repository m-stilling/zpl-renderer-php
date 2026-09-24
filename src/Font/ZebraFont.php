<?php

namespace Stilling\ZplRenderer\Font;

use Stilling\ZplRenderer\Model\FontSpec;
use Stilling\ZplRenderer\Options;

/**
 * Maps the built-in printer fonts to the font files they are drawn with.
 *
 * Font 0 is the scalable CG Triumvirate Bold Condensed; it is drawn with the
 * scalable font file, its capital letters reaching from the baseline to the
 * top of the cell, and scaled horizontally by the requested width. Fonts A to
 * H are fixed-pitch bitmap fonts that magnify in whole steps; they are drawn
 * with the fixed-pitch font file scaled to the same cell size and pitch. Fonts
 * B and H hold capital letters only.
 */
class ZebraFont {
	/** Fraction of the requested height at which the baseline of the scalable font sits. */
	public const float SCALABLE_ASCENT = 0.8;

	/** Fraction of the cell height of a bitmap font that the capital letters use. */
	public const float BITMAP_CAP_FRACTION = 0.85;

	/** Default font when the label sets none: font A at 9 x 5 dots. */
	public const string DEFAULT_FONT = "A";

	/** @var array<string, array{int, int, int}> height, width, intercharacter gap in dots */
	private const array BITMAP_FONTS = [
		"A" => [9, 5, 1],
		"B" => [11, 7, 2],
		"C" => [18, 10, 2],
		"D" => [18, 10, 2],
		"E" => [28, 15, 5],
		"F" => [26, 13, 3],
		"G" => [60, 40, 8],
		"H" => [21, 13, 6],
	];

	/** @var list<string> */
	private const array UPPERCASE_FONTS = ["B", "H"];

	public static function isBitmap(string $id): bool {
		return isset(self::BITMAP_FONTS[strtoupper($id)]);
	}

	/**
	 * The natural height of a font when the label gives none.
	 */
	public static function defaultHeight(string $id): int {
		return self::BITMAP_FONTS[strtoupper($id)][0] ?? 15;
	}

	/**
	 * The natural width of a font when the label gives none.
	 */
	public static function defaultWidth(string $id): int {
		return self::BITMAP_FONTS[strtoupper($id)][1] ?? 12;
	}

	public static function resolve(FontSpec $spec, ?Options $options = null): ResolvedFont {
		$options ??= new Options();
		$id = strtoupper($spec->id);
		$height = $spec->height > 0 ? $spec->height : self::defaultHeight($id);

		if (isset(self::BITMAP_FONTS[$id])) {
			return self::resolveBitmap($id, $height, $spec->width, $options);
		}

		$width = $spec->width > 0 ? $spec->width : $height;
		$face = TrueTypeFont::load(Typeface::Scalable->file($options));
		$ascent = $height * self::SCALABLE_ASCENT;

		return new ResolvedFont(
			typeface: Typeface::Scalable,
			face: $face,
			size: $ascent / ($face->capHeight / 1000),
			horizontalScale: $options->getScalableFontCondense() * $width / $height,
			ascent: $ascent,
			descent: $height - $ascent,
			lineHeight: $height,
		);
	}

	private static function resolveBitmap(string $id, int $height, int $width, Options $options): ResolvedFont {
		[$baseHeight, $baseWidth, $gap] = self::BITMAP_FONTS[$id];
		$scaleY = max(1, (int) round($height / $baseHeight));
		$scaleX = $width > 0 ? max(1, (int) round($width / $baseWidth)) : $scaleY;
		$cellHeight = $baseHeight * $scaleY;
		$advance = ($baseWidth + $gap) * $scaleX;
		$typeface = $id === "B" ? Typeface::MonoBold : Typeface::Mono;
		$face = TrueTypeFont::load($typeface->file($options));
		$capHeight = $cellHeight * self::BITMAP_CAP_FRACTION;
		$size = $capHeight / ($face->capHeight / 1000);

		return new ResolvedFont(
			typeface: $typeface,
			face: $face,
			size: $size,
			horizontalScale: $advance / ($face->widths[32] / 1000 * $size),
			ascent: $capHeight,
			descent: $cellHeight - $capHeight,
			lineHeight: $cellHeight,
			uppercase: in_array($id, self::UPPERCASE_FONTS, true),
		);
	}
}
