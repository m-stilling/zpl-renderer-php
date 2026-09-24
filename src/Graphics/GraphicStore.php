<?php

namespace Stilling\ZplRenderer\Graphics;

/**
 * Graphics downloaded with ~DG or ~DY, keyed by name, so ^XG and ^IM can recall them.
 */
class GraphicStore {
	/** @var array<string, Bitmap> */
	private array $graphics = [];

	public function put(string $name, Bitmap $bitmap): void {
		$this->graphics[self::normalize($name)] = $bitmap;
	}

	public function get(string $name): ?Bitmap {
		return $this->graphics[self::normalize($name)] ?? null;
	}

	public function has(string $name): bool {
		return isset($this->graphics[self::normalize($name)]);
	}

	/**
	 * Strip the device ("R:") and the extension (".GRF"), and ignore case, so
	 * "R:LOGO.GRF", "logo.grf" and "LOGO" name the same graphic.
	 */
	public static function normalize(string $name): string {
		$name = trim($name);

		if (preg_match('/^[A-Za-z]:(.*)$/', $name, $match) === 1) {
			$name = $match[1];
		}

		$dot = strrpos($name, ".");

		if ($dot !== false) {
			$name = substr($name, 0, $dot);
		}

		return strtoupper($name);
	}
}
