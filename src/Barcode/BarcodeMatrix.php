<?php

namespace Stilling\ZplRenderer\Barcode;

/**
 * The dark cells of a barcode as rectangles in module units: [x, y, width, height].
 * A linear barcode has one row; a 2D symbol has one row per module row.
 */
class BarcodeMatrix {
	/**
	 * @param list<array{int, int, int, int}> $bars
	 */
	public function __construct(
		public readonly int $columns,
		public readonly int $rows,
		public readonly array $bars,
	) {
	}

	/**
	 * Build a one-row matrix from a string of "1" (bar) and "0" (space) modules.
	 */
	public static function fromPattern(string $pattern): self {
		$bars = [];
		$length = strlen($pattern);
		$start = null;

		for ($i = 0; $i <= $length; $i++) {
			$dark = $i < $length && $pattern[$i] === "1";

			if ($dark && $start === null) {
				$start = $i;
			} elseif (!$dark && $start !== null) {
				$bars[] = [$start, 0, $i - $start, 1];
				$start = null;
			}
		}

		return new self($length, 1, $bars);
	}

	/**
	 * Build a matrix from rows of "1" and "0" modules, one run of dark modules per bar.
	 *
	 * @param list<string> $rows
	 */
	public static function fromRows(array $rows): self {
		$bars = [];
		$columns = 0;

		foreach ($rows as $y => $row) {
			$rowMatrix = self::fromPattern($row);
			$columns = max($columns, $rowMatrix->columns);

			foreach ($rowMatrix->bars as [$x, , $width]) {
				$bars[] = [$x, $y, $width, 1];
			}
		}

		return new self($columns, count($rows), $bars);
	}

	/**
	 * Render the matrix as rows of "1" and "0", mostly for tests.
	 *
	 * @return list<string>
	 */
	public function toRows(): array {
		$rows = array_fill(0, $this->rows, str_repeat("0", $this->columns));

		foreach ($this->bars as [$x, $y, $width, $height]) {
			for ($row = $y; $row < $y + $height; $row++) {
				$rows[$row] = substr_replace($rows[$row], str_repeat("1", $width), $x, $width);
			}
		}

		return array_values($rows);
	}
}
