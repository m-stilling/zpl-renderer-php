<?php

namespace Stilling\Zpl\Model;

class TextElement extends Element {
	public function __construct(
		int $x,
		int $y,
		/** The text, UTF-8, with the ZPL newline escape already turned into "\n". */
		public readonly string $text,
		public readonly FontSpec $font,
		Orientation $orientation = Orientation::Normal,
		bool $reverse = false,
		bool $typeset = false,
		Justification $justification = Justification::Left,
		public readonly ?FieldBlock $block = null,
	) {
		parent::__construct($x, $y, $orientation, $reverse, $typeset, $justification);
	}
}
