<?php

namespace Stilling\ZplRenderer\Graphics;

use Stilling\ZplRenderer\Exceptions\ParseException;

/**
 * Decodes the data part of ~DG and ^GF: plain hex, Zebra run-length compressed
 * hex, or :B64: / :Z64: wrapped binary.
 *
 * A graphic larger than maxBytes is rejected, whether the size comes from the
 * total byte count, the bytes per row, the data itself or its expansion.
 */
class GraphicDecoder {
	/** 8 MiB, enough for a 20 by 30 cm label at 24 dots per millimeter. */
	public const int DEFAULT_MAX_BYTES = 8388608;

	public function __construct(
		private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
	) {
		if ($maxBytes < 1) {
			throw new \InvalidArgumentException("maxBytes must be at least 1.");
		}
	}

	public function decode(string $data, int $bytesPerRow, int $totalBytes): Bitmap {
		if ($bytesPerRow < 1) {
			throw new ParseException("Graphic data needs a positive bytes-per-row value.");
		}

		$this->limit($bytesPerRow);
		$this->limit($totalBytes);
		$data = trim($data);

		if (preg_match('/^:([BZ])64:(.*?)(?::([0-9A-Fa-f]{4}))?$/s', $data, $match) === 1) {
			$binary = $this->decodeBase64($match[1], $match[2]);
		} else {
			$binary = $this->decodeHex($data, $bytesPerRow);
		}

		if ($totalBytes > 0) {
			$binary = str_pad(substr($binary, 0, $totalBytes), $totalBytes, "\0");
		}

		$remainder = strlen($binary) % $bytesPerRow;

		if ($remainder !== 0) {
			$binary .= str_repeat("\0", $bytesPerRow - $remainder);
		}

		return new Bitmap($bytesPerRow, $binary);
	}

	private function decodeBase64(string $kind, string $payload): string {
		$payload = preg_replace('/\s+/', "", $payload) ?? "";
		$binary = base64_decode($payload, true);

		if ($binary === false || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $payload) !== 1) {
			throw new ParseException("Invalid base64 graphic data.");
		}

		if ($kind === "Z") {
			$binary = Silencer::call(fn () => gzuncompress($binary, $this->maxBytes + 1))
				?? throw new ParseException("Invalid zlib graphic data, or more than {$this->maxBytes} bytes of it.");
		}

		$this->limit(strlen($binary));

		return $binary;
	}

	/**
	 * Zebra's compressed hex: G-Y repeat the next nibble 1-19 times, g-z add
	 * multiples of 20, "," fills the rest of the row with 0, "!" fills it with
	 * 1 and ":" repeats the previous row.
	 */
	private function decodeHex(string $data, int $bytesPerRow): string {
		$nibblesPerRow = $bytesPerRow * 2;
		/** The complete rows so far, as hex. */
		$hex = "";
		/** The row being filled, shorter than a row. */
		$row = "";
		$repeat = 0;
		$length = strlen($data);

		for ($i = 0; $i < $length; $i++) {
			$char = $data[$i];

			if (ctype_xdigit($char)) {
				$this->limit(intdiv(strlen($hex) + strlen($row) + max(1, $repeat) + 1, 2));
				$row .= str_repeat(strtoupper($char), max(1, $repeat));
				$repeat = 0;
			} elseif ($char >= "G" && $char <= "Y") {
				$repeat += ord($char) - ord("G") + 1;
			} elseif ($char >= "g" && $char <= "z") {
				$repeat += (ord($char) - ord("g") + 1) * 20;
			} elseif ($char === "," || $char === "!") {
				$this->limit(intdiv(strlen($hex), 2) + $bytesPerRow);
				$row = str_pad($row, $nibblesPerRow, $char === "," ? "0" : "F");
				$repeat = 0;
			} elseif ($char === ":") {
				$this->limit(intdiv(strlen($hex), 2) + $bytesPerRow);
				$hex .= $hex === "" ? str_repeat("0", $nibblesPerRow) : substr($hex, -$nibblesPerRow);
				$row = "";
				$repeat = 0;
				continue;
			} else {
				continue;
			}

			$complete = strlen($row) - strlen($row) % $nibblesPerRow;

			if ($complete > 0) {
				$hex .= substr($row, 0, $complete);
				$row = substr($row, $complete);
			}
		}

		if ($row !== "") {
			$hex .= str_pad($row, $nibblesPerRow, "0");
		}

		return $hex === "" ? "" : (string) hex2bin($hex);
	}

	private function limit(int $bytes): void {
		if ($bytes > $this->maxBytes) {
			throw new ParseException("The graphic needs {$bytes} bytes, more than the limit of {$this->maxBytes} bytes.");
		}
	}
}
