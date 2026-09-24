<?php

namespace Stilling\ZplRenderer\Model;

/**
 * Field rotation, clockwise, as ZPL names it.
 */
enum Orientation: string {
	case Normal = "N";
	case Rotated = "R";
	case Inverted = "I";
	case BottomUp = "B";

	public static function fromZpl(string $value, self $default = self::Normal): self {
		return self::tryFrom(strtoupper(substr($value, 0, 1))) ?? $default;
	}

	public function degrees(): int {
		return match ($this) {
			self::Normal => 0,
			self::Rotated => 90,
			self::Inverted => 180,
			self::BottomUp => 270,
		};
	}

	/**
	 * Rotate a vector in a y-down coordinate system, clockwise on the page.
	 *
	 * @return array{float, float}
	 */
	public function rotate(float $x, float $y): array {
		return match ($this) {
			self::Normal => [$x, $y],
			self::Rotated => [-$y, $x],
			self::Inverted => [-$x, -$y],
			self::BottomUp => [$y, -$x],
		};
	}

	/**
	 * The 2x2 matrix [a, b, c, d] that rotate() applies, in PDF operand order.
	 *
	 * @return array{float, float, float, float}
	 */
	public function matrix(): array {
		return match ($this) {
			self::Normal => [1, 0, 0, 1],
			self::Rotated => [0, 1, -1, 0],
			self::Inverted => [-1, 0, 0, -1],
			self::BottomUp => [0, -1, 1, 0],
		};
	}
}
