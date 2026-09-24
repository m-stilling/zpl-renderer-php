<?php

namespace Stilling\ZplRenderer\Graphics;

use Stilling\ZplRenderer\Exceptions\RenderException;

/**
 * Writes a one-bit bitmap as a PNG without GD.
 */
class PngEncoder {
	/**
	 * A 1-bit grayscale PNG in which every set dot of the bitmap is black, or
	 * white when $whiteInk is set, and every other dot is transparent.
	 */
	public static function encode(Bitmap $bitmap, bool $whiteInk = false): string {
		$rows = "";

		for ($y = 0; $y < $bitmap->height; $y++) {
			$row = substr($bitmap->data, $y * $bitmap->bytesPerRow, $bitmap->bytesPerRow);
			$rows .= "\0" . ($whiteInk ? $row : ~$row);
		}

		$compressed = gzcompress($rows, 9);

		if ($compressed === false) {
			throw new RenderException("zlib compression failed.");
		}

		return "\x89PNG\r\n\x1a\n"
			. self::chunk("IHDR", pack("NNCCCCC", $bitmap->width, $bitmap->height, 1, 0, 0, 0, 0))
			. self::chunk("tRNS", pack("n", $whiteInk ? 0 : 1))
			. self::chunk("IDAT", $compressed)
			. self::chunk("IEND", "");
	}

	private static function chunk(string $type, string $data): string {
		return pack("N", strlen($data)) . $type . $data . pack("N", crc32($type . $data));
	}
}
