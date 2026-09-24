<?php

namespace Stilling\Zpl\Pdf;

use Stilling\Zpl\Exceptions\RenderException;

/**
 * Reads the parts of a TrueType file that a PDF font descriptor needs:
 * the name, the metrics and the advance width of every WinAnsi character.
 */
class TrueTypeFont {
	public readonly string $data;

	public readonly string $postScriptName;

	public readonly int $unitsPerEm;

	/** @var array{int, int, int, int} in 1/1000 em */
	public readonly array $bbox;

	/** In 1/1000 em. */
	public readonly int $ascent;

	/** In 1/1000 em, negative. */
	public readonly int $descent;

	/** In 1/1000 em. */
	public readonly int $capHeight;

	public readonly float $italicAngle;

	public readonly bool $fixedPitch;

	public readonly bool $bold;

	/** @var array<int, int> advance width in 1/1000 em by WinAnsi code 32 to 255 */
	public readonly array $widths;

	/** @var array<string, array{int, int}> table offset and length by tag */
	private array $tables = [];

	public function __construct(string $file) {
		$data = is_file($file) ? file_get_contents($file) : false;

		if ($data === false || strlen($data) < 12) {
			throw new RenderException("Cannot read font file {$file}.");
		}

		$magic = substr($data, 0, 4);

		if ($magic !== "\x00\x01\x00\x00" && $magic !== "true") {
			throw new RenderException("{$file} is not a TrueType font file.");
		}

		$this->data = $data;
		$this->readTableDirectory();
		$this->unitsPerEm = max(16, $this->uint16($this->table("head") + 18));
		$head = $this->table("head");
		$this->bbox = [
			$this->scale($this->int16($head + 36)),
			$this->scale($this->int16($head + 38)),
			$this->scale($this->int16($head + 40)),
			$this->scale($this->int16($head + 42)),
		];
		$hhea = $this->table("hhea");
		$this->ascent = $this->scale($this->int16($hhea + 4));
		$this->descent = $this->scale($this->int16($hhea + 6));
		$this->italicAngle = isset($this->tables["post"]) ? $this->int16($this->table("post") + 4) : 0.0;
		[$this->capHeight, $this->bold] = $this->readOs2();
		$this->postScriptName = $this->readPostScriptName() ?? preg_replace('/\.[^.]+$/', "", basename($file)) ?? "Font";
		$this->widths = $this->readWidths($this->uint16($hhea + 34));
		$this->fixedPitch = count(array_unique($this->widths)) === 1;
	}

	/**
	 * The PDF font descriptor flags: fixed pitch and nonsymbolic, plus bold weight.
	 */
	public function flags(): int {
		return ($this->fixedPitch ? 1 : 0) | 32 | ($this->bold ? 1 << 18 : 0);
	}

	private function readTableDirectory(): void {
		$count = $this->uint16(4);

		for ($i = 0; $i < $count; $i++) {
			$record = 12 + $i * 16;
			$tag = substr($this->data, $record, 4);
			$this->tables[$tag] = [$this->uint32($record + 8), $this->uint32($record + 12)];
		}

		foreach (["head", "hhea", "hmtx", "cmap"] as $required) {
			if (!isset($this->tables[$required])) {
				throw new RenderException("The font file has no {$required} table.");
			}
		}
	}

	private function table(string $tag): int {
		return $this->tables[$tag][0];
	}

	/**
	 * @return array{int, bool} cap height in 1/1000 em, and whether the weight is bold
	 */
	private function readOs2(): array {
		if (!isset($this->tables["OS/2"])) {
			return [$this->scale((int) ($this->unitsPerEm * 0.7)), false];
		}

		$os2 = $this->table("OS/2");
		$version = $this->uint16($os2);
		$weight = $this->uint16($os2 + 4);
		$capHeight = $version >= 2 ? $this->int16($os2 + 88) : (int) ($this->unitsPerEm * 0.7);

		return [$this->scale($capHeight > 0 ? $capHeight : (int) ($this->unitsPerEm * 0.7)), $weight >= 600];
	}

	private function readPostScriptName(): ?string {
		if (!isset($this->tables["name"])) {
			return null;
		}

		$name = $this->table("name");
		$count = $this->uint16($name + 2);
		$strings = $name + $this->uint16($name + 4);

		for ($i = 0; $i < $count; $i++) {
			$record = $name + 6 + $i * 12;

			if ($this->uint16($record + 6) !== 6) {
				continue;
			}

			$platform = $this->uint16($record);
			$value = substr($this->data, $strings + $this->uint16($record + 10), $this->uint16($record + 8));

			if ($platform === 3 || $platform === 0) {
				$value = mb_convert_encoding($value, "UTF-8", "UTF-16BE");
			}

			$value = preg_replace('/[^A-Za-z0-9\-]/', "", $value) ?? "";

			if ($value !== "") {
				return $value;
			}
		}

		return null;
	}

	/**
	 * @return array<int, int>
	 */
	private function readWidths(int $metricCount): array {
		$glyphs = $this->readCmap();
		$hmtx = $this->table("hmtx");
		$widths = [];

		for ($code = 32; $code <= 255; $code++) {
			$unicode = self::winAnsiToUnicode($code);
			$glyph = $glyphs[$unicode] ?? 0;
			$index = min($glyph, max(0, $metricCount - 1));
			$widths[$code] = $this->scale($this->uint16($hmtx + $index * 4));
		}

		return $widths;
	}

	/**
	 * Unicode code point to glyph index, from the format 4 Unicode subtable.
	 *
	 * @return array<int, int>
	 */
	private function readCmap(): array {
		$cmap = $this->table("cmap");
		$count = $this->uint16($cmap + 2);
		$subtable = null;

		for ($i = 0; $i < $count; $i++) {
			$record = $cmap + 4 + $i * 8;
			$platform = $this->uint16($record);
			$encoding = $this->uint16($record + 2);

			if (($platform === 3 && $encoding === 1) || ($platform === 0 && $subtable === null)) {
				$subtable = $cmap + $this->uint32($record + 4);
			}
		}

		if ($subtable === null || $this->uint16($subtable) !== 4) {
			return [];
		}

		$segments = intdiv($this->uint16($subtable + 6), 2);
		$ends = $subtable + 14;
		$starts = $ends + $segments * 2 + 2;
		$deltas = $starts + $segments * 2;
		$rangeOffsets = $deltas + $segments * 2;
		$map = [];

		for ($s = 0; $s < $segments; $s++) {
			$end = $this->uint16($ends + $s * 2);
			$start = $this->uint16($starts + $s * 2);
			$delta = $this->uint16($deltas + $s * 2);
			$rangeOffset = $this->uint16($rangeOffsets + $s * 2);

			for ($code = $start; $code <= $end && $code < 0xFFFF; $code++) {
				if ($rangeOffset === 0) {
					$glyph = ($code + $delta) & 0xFFFF;
				} else {
					$address = $rangeOffsets + $s * 2 + $rangeOffset + ($code - $start) * 2;
					$glyph = $this->uint16($address);
					$glyph = $glyph === 0 ? 0 : (($glyph + $delta) & 0xFFFF);
				}

				if ($glyph !== 0) {
					$map[$code] = $glyph;
				}
			}
		}

		return $map;
	}

	private static function winAnsiToUnicode(int $code): int {
		if ($code < 128 || $code >= 160) {
			return $code;
		}

		$character = mb_convert_encoding(chr($code), "UTF-8", "Windows-1252");

		return $character === "" || $character === "?" ? $code : (mb_ord($character, "UTF-8") ?: $code);
	}

	private function scale(int $units): int {
		return (int) round($units * 1000 / $this->unitsPerEm);
	}

	private function uint16(int $offset): int {
		if ($offset < 0 || $offset + 2 > strlen($this->data)) {
			throw new RenderException("The font file is truncated.");
		}

		return (int) (unpack("n", $this->data, $offset)[1] ?? 0);
	}

	private function int16(int $offset): int {
		$value = $this->uint16($offset);

		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	private function uint32(int $offset): int {
		if ($offset < 0 || $offset + 4 > strlen($this->data)) {
			throw new RenderException("The font file is truncated.");
		}

		return (int) (unpack("N", $this->data, $offset)[1] ?? 0);
	}
}
