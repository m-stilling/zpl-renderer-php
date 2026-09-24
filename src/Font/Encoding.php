<?php

namespace Stilling\Zpl\Font;

/**
 * Text conversion between the ZPL input and the WinAnsi bytes that the fonts are drawn with.
 */
class Encoding {
	/**
	 * Turn the raw bytes of a ^FD field into UTF-8. ^CI28 declares UTF-8; the
	 * legacy code pages are treated as CP850, which is the Zebra default.
	 */
	public static function toUtf8(string $bytes, int $characterSet): string {
		if ($characterSet === 28 || mb_check_encoding($bytes, "UTF-8")) {
			return $bytes;
		}

		return mb_convert_encoding($bytes, "UTF-8", "CP850");
	}

	/**
	 * Map UTF-8 to Windows-1252 bytes. Characters outside the code page become "?".
	 */
	public static function toWinAnsi(string $utf8): string {
		return mb_convert_encoding($utf8, "Windows-1252", "UTF-8");
	}

	/**
	 * Escape a WinAnsi string for use inside a PDF literal string.
	 */
	public static function pdfLiteral(string $winAnsi): string {
		return "(" . strtr($winAnsi, ["\\" => "\\\\", "(" => "\\(", ")" => "\\)", "\r" => "\\r", "\n" => "\\n"]) . ")";
	}
}
