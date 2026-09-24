<?php

namespace Stilling\Zpl\Graphics;

use Stilling\Zpl\Exceptions\UnsupportedException;

/**
 * Turns a PNG (or any format GD reads) into a one-bit Bitmap by thresholding
 * the luminance. Transparent pixels count as white.
 */
class PngDecoder {
	public function decode(string $imageData): Bitmap {
		if (!function_exists("imagecreatefromstring")) {
			throw new UnsupportedException("Decoding PNG graphics requires the GD extension.");
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
			$rows[] = implode("", array_map(fn (string $byte): string => chr((int) bindec($byte)), str_split($bits, 8)));
		}

		return new Bitmap($bytesPerRow, implode("", $rows));
	}
}
