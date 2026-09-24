<?php

namespace Stilling\Zpl\Model;

/**
 * Line justification inside a ^FB field block.
 */
enum TextJustification: string {
	case Left = "L";
	case Center = "C";
	case Right = "R";
	case Justified = "J";

	public static function fromZpl(string $value, self $default = self::Left): self {
		return self::tryFrom(strtoupper(substr($value, 0, 1))) ?? $default;
	}
}
