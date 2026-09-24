<?php

namespace Stilling\Zpl\Model;

/**
 * The font a text field is set in: the ZPL font id plus the requested cell size in dots.
 */
class FontSpec {
	public function __construct(
		/** ZPL font id: "0"-"9", "A"-"Z" or a name set with ^A@. */
		public readonly string $id,
		/** Character height in dots. */
		public readonly int $height,
		/** Character width in dots, or 0 for the width that follows from the height. */
		public readonly int $width = 0,
	) {
	}

	public function withSize(int $height, int $width): self {
		return new self($this->id, $height, $width);
	}
}
