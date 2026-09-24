<?php

namespace Stilling\ZplRenderer\Graphics;

use Stilling\ZplRenderer\Exceptions\ParseException;
use Stilling\ZplRenderer\Exceptions\UnsupportedException;

/**
 * Turns a PNG (or any format GD reads) into a one-bit Bitmap by thresholding
 * the luminance. Transparent pixels count as white.
 *
 * An image whose one-bit form would be larger than maxBytes is rejected
 * before GD decodes it.
 */
class PngDecoder {
	public function __construct(
		private readonly int $maxBytes = GraphicDecoder::DEFAULT_MAX_BYTES,
	) {
		if ($maxBytes < 1) {
			throw new \InvalidArgumentException("maxBytes must be at least 1.");
		}
	}

	public function decode(string $imageData): Bitmap {
		if (!function_exists("imagecreatefromstring")) {
			throw new UnsupportedException("Decoding PNG graphics requires the GD extension.");
		}

		$size = Silencer::call(fn () => getimagesizefromstring($imageData))
			?? throw new UnsupportedException("The graphic data is not an image GD can read.");
		$bytes = intdiv($size[0] + 7, 8) * $size[1];

		if ($bytes > $this->maxBytes) {
			throw new ParseException("A {$size[0]} x {$size[1]} image needs {$bytes} bytes, more than the limit of {$this->maxBytes} bytes.");
		}

		$image = Silencer::call(fn () => imagecreatefromstring($imageData))
			?? throw new UnsupportedException("The graphic data is not an image GD can read.");

		$width = imagesx($image);
		$height = imagesy($image);
		$bytesPerRow = intdiv($width + 7, 8);
		$rows = [];

		for ($y = 0; $y < $height; $y++) {
			$bits = "";

			for ($x = 0; $x < $width; $x++) {
				$color = imagecolorat($image, $x, $y);
				$alpha = ($color >> 24) & 0x7F;
				$red = ($color >> 16) & 0xFF;
				$green = ($color >> 8) & 0xFF;
				$blue = $color & 0xFF;
				$luminance = 0.299 * $red + 0.587 * $green + 0.114 * $blue;
				$bits .= ($alpha < 64 && $luminance < 128) ? "1" : "0";
			}

			$bits = str_pad($bits, $bytesPerRow * 8, "0");
			$rows[] = implode("", array_map(fn (string $byte): string => chr((int) bindec($byte) & 0xFF), str_split($bits, 8)));
		}

		return new Bitmap($bytesPerRow, implode("", $rows));
	}
}
