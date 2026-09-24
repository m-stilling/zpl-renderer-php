<?php

namespace Stilling\Zpl\Graphics;

use Stilling\Zpl\Exceptions\ParseException;

/**
 * Decodes the data part of ~DG and ^GF: plain hex, Zebra run-length compressed
 * hex, or :B64: / :Z64: wrapped binary.
 */
class GraphicDecoder {
	public function decode(string $data, int $bytesPerRow, int $totalBytes): Bitmap {
		if ($bytesPerRow < 1) {
			throw new ParseException("Graphic data needs a positive bytes-per-row value.");
		}

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
			return Silencer::call(fn () => gzuncompress($binary)) ?? throw new ParseException("Invalid zlib graphic data.");
		}

		return $binary;
	}

	/**
	 * Zebra's compressed hex: G-Y repeat the next nibble 1-19 times, g-z add
	 * multiples of 20, "," fills the rest of the row with 0, "!" fills it with
	 * 1 and ":" repeats the previous row.
	 */
	private function decodeHex(string $data, int $bytesPerRow): string {
		$nibblesPerRow = $bytesPerRow * 2;
		$rows = [];
		$row = "";
		$repeat = 0;
		$length = strlen($data);

		for ($i = 0; $i < $length; $i++) {
			$char = $data[$i];

			if (ctype_xdigit($char)) {
				$row .= str_repeat(strtoupper($char), max(1, $repeat));
				$repeat = 0;
			} elseif ($char >= "G" && $char <= "Y") {
				$repeat += ord($char) - ord("G") + 1;
			} elseif ($char >= "g" && $char <= "z") {
				$repeat += (ord($char) - ord("g") + 1) * 20;
			} elseif ($char === "," || $char === "!") {
				$row = str_pad($row, $nibblesPerRow, $char === "," ? "0" : "F");
				$repeat = 0;
			} elseif ($char === ":") {
				$rows[] = $rows === [] ? str_repeat("0", $nibblesPerRow) : end($rows);
				$row = "";
				$repeat = 0;
				continue;
			} else {
				continue;
			}

			while (strlen($row) >= $nibblesPerRow) {
				$rows[] = substr($row, 0, $nibblesPerRow);
				$row = substr($row, $nibblesPerRow);
			}
		}

		if ($row !== "") {
			$rows[] = str_pad($row, $nibblesPerRow, "0");
		}

		$hex = implode("", $rows);

		return $hex === "" ? "" : (string) hex2bin($hex);
	}
}
