<?php

namespace Stilling\ZplRenderer\Model;

/**
 * Field justification from ^FO, ^FT and ^FW.
 */
enum Justification: int {
	case Left = 0;
	case Right = 1;
	case Auto = 2;

	public static function fromZpl(string $value, self $default = self::Left): self {
		return is_numeric($value) ? (self::tryFrom((int) $value) ?? $default) : $default;
	}
}
