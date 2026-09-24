<?php

namespace Stilling\ZplRenderer\Barcode;

/**
 * The ^BC mode parameter.
 */
enum Code128Mode: string {
	/** Subset B unless the data switches subsets with invocation codes. */
	case Normal = "N";
	/** UCC case mode: 19 digits in subset C with a mod-10 check digit. */
	case Ucc = "U";
	/** Automatic subset selection. */
	case Automatic = "A";
	/** UCC/EAN-128 with application identifiers in parentheses. */
	case Ean = "D";

	public static function fromZpl(string $value): self {
		return self::tryFrom(strtoupper(substr($value, 0, 1))) ?? self::Normal;
	}
}
