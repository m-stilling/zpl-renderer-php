<?php

namespace Stilling\ZplRenderer\Graphics;

/**
 * A one-bit image, packed one row per bytesPerRow bytes, most significant bit
 * first. A set bit is a black dot.
 */
class Bitmap {
	public readonly int $width;

	public readonly int $height;

	public function __construct(
		public readonly int $bytesPerRow,
		/** Packed rows, exactly bytesPerRow * height bytes. */
		public readonly string $data,
	) {
		if ($bytesPerRow < 1) {
			throw new \InvalidArgumentException("bytesPerRow must be at least 1.");
		}

		$this->width = $bytesPerRow * 8;
		$this->height = intdiv(strlen($data), $bytesPerRow);
	}

	public function pixel(int $x, int $y): bool {
		if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
			return false;
		}

		$byte = ord($this->data[$y * $this->bytesPerRow + ($x >> 3)]);

		return ($byte & (0x80 >> ($x & 7))) !== 0;
	}
}
