<?php

namespace Stilling\ZplRenderer;

use Stilling\ZplRenderer\Exceptions\RenderException;
use Stilling\ZplRenderer\Model\Label;
use Stilling\ZplRenderer\Parser\Interpreter;
use Stilling\ZplRenderer\Parser\Tokenizer;
use Stilling\ZplRenderer\Renderer\PdfRenderer;
use Stilling\ZplRenderer\Renderer\PngRenderer;

/**
 * Entry point: turn ZPL into labels, and labels into output formats.
 */
class Zpl {
	/**
	 * Render every ^XA ... ^XZ format in the input as one PDF page (or as many
	 * pages as ^PQ asks for). Returns the PDF bytes.
	 */
	public static function toPdf(string $zpl, ?Options $options = null): string {
		$options ??= new Options();

		return (new PdfRenderer($options))->render(self::parse($zpl, $options));
	}

	/**
	 * Render one ^XA ... ^XZ format as a PNG image. Returns the PNG bytes.
	 *
	 * @param int $index which label in the input, counted from 0
	 */
	public static function toPng(string $zpl, ?Options $options = null, int $index = 0): string {
		$options ??= new Options();
		$labels = self::parse($zpl, $options);

		if (!isset($labels[$index])) {
			throw new RenderException("The input holds " . count($labels) . " label(s); there is no label {$index}.");
		}

		return (new PngRenderer($options))->renderLabel($labels[$index]);
	}

	/**
	 * Render every ^XA ... ^XZ format in the input as a PNG image, one per label.
	 *
	 * @return list<string>
	 */
	public static function toPngs(string $zpl, ?Options $options = null): array {
		$options ??= new Options();

		return (new PngRenderer($options))->render(self::parse($zpl, $options));
	}

	/**
	 * Parse and interpret the input without rendering it.
	 *
	 * @return list<Label>
	 */
	public static function parse(string $zpl, ?Options $options = null): array {
		$options ??= new Options();

		return (new Interpreter($options))->interpret((new Tokenizer())->tokenize($zpl));
	}
}
