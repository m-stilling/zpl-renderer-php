<?php

namespace Stilling\Zpl;

use Stilling\Zpl\Model\Label;
use Stilling\Zpl\Parser\Interpreter;
use Stilling\Zpl\Parser\Tokenizer;
use Stilling\Zpl\Renderer\PdfRenderer;

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
	 * Parse and interpret the input without rendering it.
	 *
	 * @return list<Label>
	 */
	public static function parse(string $zpl, ?Options $options = null): array {
		$options ??= new Options();

		return (new Interpreter($options))->interpret((new Tokenizer())->tokenize($zpl));
	}
}
