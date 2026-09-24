<?php

namespace Stilling\Zpl\Font;

use Stilling\Zpl\Exceptions\RenderException;

/**
 * Reads a TrueType file: the name and metrics a PDF font descriptor needs,
 * the advance width and the outline of every WinAnsi character, and a copy
 * of the file that keeps only the glyphs a document uses.
 */
class TrueTypeFont {
	/** Tables a subset keeps. The rest (layout, kerning, bitmaps, signatures) is not used by a PDF viewer. */
	private const array SUBSET_TABLES = ["OS/2", "cmap", "cvt ", "fpgm", "glyf", "head", "hhea", "hmtx", "loca", "maxp", "name", "post", "prep"];

	/** Composite glyph flags. */
	private const int ARGS_ARE_WORDS = 0x0001;

	private const int ARGS_ARE_OFFSETS = 0x0002;

	private const int HAS_SCALE = 0x0008;

	private const int MORE_COMPONENTS = 0x0020;

	private const int HAS_XY_SCALE = 0x0040;

	private const int HAS_TWO_BY_TWO = 0x0080;

	/** Deepest nesting of composite glyphs that is followed. */
	private const int MAX_COMPOSITE_DEPTH = 8;

	/** @var array<string, self> */
	private static array $loaded = [];

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

	/** @var array<int, int> glyph index by WinAnsi code 32 to 255, 0 when the font has no glyph */
	private array $glyphs = [];

	/** @var array<string, array{int, int}> table offset and length by tag */
	private array $tables = [];

	/** @var array<int, list<list<array{float, float, bool}>>> outlines by WinAnsi code */
	private array $outlines = [];

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
	 * Read a font file once per process.
	 */
	public static function load(string $file): self {
		return self::$loaded[$file] ??= new self($file);
	}

	/**
	 * The PDF font descriptor flags: fixed pitch and nonsymbolic, plus bold weight.
	 */
	public function flags(): int {
		return ($this->fixedPitch ? 1 : 0) | 32 | ($this->bold ? 1 << 18 : 0);
	}

	/**
	 * Advance width of a WinAnsi string in em.
	 */
	public function stringWidth(string $winAnsi): float {
		$total = 0;

		foreach (unpack("C*", $winAnsi) ?: [] as $code) {
			$total += $this->widths[$code] ?? $this->widths[32];
		}

		return $total / 1000;
	}

	/**
	 * The outline of a WinAnsi character in em, y up, origin on the baseline:
	 * closed contours of points, each flagged on or off the curve. Two off-curve
	 * points in a row have an implied on-curve point halfway between them.
	 *
	 * @return list<list<array{float, float, bool}>>
	 */
	public function outline(int $code): array {
		return $this->outlines[$code] ??= $this->glyphOutline($this->glyphs[$code] ?? 0, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0);
	}

	/**
	 * A font file that holds only the glyphs of the given WinAnsi codes, the
	 * glyphs they are built from, and the missing-glyph box. The glyphs are
	 * numbered anew, and the character map covers the given codes only.
	 *
	 * @param list<int> $codes
	 */
	public function subset(array $codes): string {
		foreach (["glyf", "loca", "maxp"] as $required) {
			$this->table($required);
		}

		$keep = [0 => true];

		foreach ($codes as $code) {
			$this->keepGlyph($this->glyphs[$code] ?? 0, $keep, 0);
		}

		ksort($keep);
		$numbers = array_flip(array_keys($keep));
		$longOffsets = $this->int16($this->table("head") + 50) === 1;
		$glyf = "";
		$hmtx = "";
		$offsets = [];

		foreach (array_keys($numbers) as $glyph) {
			$offsets[] = strlen($glyf);
			[$start, $end] = $this->glyphRange($glyph);
			$data = substr($this->data, $start, $end - $start);

			foreach ($this->components($glyph) as [$component, , $at]) {
				$data = substr_replace($data, pack("n", $numbers[$component] ?? 0), $at - $start, 2);
			}

			$glyf .= $data . str_repeat("\0", (4 - strlen($data) % 4) % 4);
			$hmtx .= $this->horizontalMetric($glyph);
		}

		$offsets[] = strlen($glyf);
		$loca = $longOffsets
			? pack("N*", ...$offsets)
			: pack("n*", ...array_map(fn (int $offset): int => intdiv($offset, 2), $offsets));
		$map = [];

		foreach ($codes as $code) {
			$glyph = $this->glyphs[$code] ?? 0;

			if ($glyph !== 0) {
				$map[self::winAnsiToUnicode($code)] = $numbers[$glyph];
			}
		}

		$count = count($numbers);
		$tables = [];

		foreach (self::SUBSET_TABLES as $tag) {
			if (!isset($this->tables[$tag])) {
				continue;
			}

			[$offset, $length] = $this->tables[$tag];
			$table = substr($this->data, $offset, $length);
			$tables[$tag] = match ($tag) {
				"glyf" => $glyf,
				"loca" => $loca,
				"hmtx" => $hmtx,
				"cmap" => self::characterMap($map),
				"post" => "\x00\x03\x00\x00" . substr($table, 4, 28),
				"head" => substr_replace($table, "\0\0\0\0", 8, 4),
				"hhea" => substr_replace($table, pack("n", $count), 34, 2),
				"maxp" => substr_replace($table, pack("n", $count), 4, 2),
				default => $table,
			};
		}

		return self::assemble($tables);
	}

	/**
	 * The advance width and left side bearing of a glyph, as the four bytes of an hmtx entry.
	 */
	private function horizontalMetric(int $glyph): string {
		$hmtx = $this->table("hmtx");
		$metricCount = max(1, $this->uint16($this->table("hhea") + 34));

		if ($glyph < $metricCount) {
			return substr($this->data, $hmtx + $glyph * 4, 4);
		}

		return substr($this->data, $hmtx + ($metricCount - 1) * 4, 2)
			. substr($this->data, $hmtx + $metricCount * 4 + ($glyph - $metricCount) * 2, 2);
	}

	/**
	 * A cmap table with one Windows Unicode subtable in format 4, one segment per character.
	 *
	 * @param array<int, int> $map glyph number by Unicode code point
	 */
	private static function characterMap(array $map): string {
		ksort($map);
		$map[0xFFFF] = 0;
		$segments = count($map);
		$power = 1;

		while ($power * 2 <= $segments) {
			$power *= 2;
		}

		$characters = array_keys($map);
		$deltas = array_map(fn (int $character, int $glyph): int => $character === 0xFFFF ? 1 : ($glyph - $character) & 0xFFFF, $characters, $map);
		$body = pack("n*", ...$characters) . "\0\0" . pack("n*", ...$characters) . pack("n*", ...$deltas) . str_repeat("\0\0", $segments);
		$subtable = pack("nnnnnnn", 4, 14 + strlen($body), 0, $segments * 2, $power * 2, (int) log($power, 2), ($segments - $power) * 2) . $body;

		return pack("nnnnN", 0, 1, 3, 1, 12) . $subtable;
	}

	/**
	 * Build a font file from its tables, with the checksums a strict reader wants.
	 *
	 * @param array<string, string> $tables
	 */
	private static function assemble(array $tables): string {
		ksort($tables, SORT_STRING);
		$count = count($tables);
		$power = 1;

		while ($power * 2 <= $count) {
			$power *= 2;
		}

		$directory = pack("Nnnnn", 0x00010000, $count, $power * 16, (int) log($power, 2), ($count - $power) * 16);
		$offset = 12 + 16 * $count;
		$body = "";
		$headOffset = 0;

		foreach ($tables as $tag => $table) {
			if ($tag === "head") {
				$headOffset = $offset;
			}

			$directory .= $tag . pack("NNN", self::checksum($table), $offset, strlen($table));
			$padded = $table . str_repeat("\0", (4 - strlen($table) % 4) % 4);
			$body .= $padded;
			$offset += strlen($padded);
		}

		$font = $directory . $body;

		if ($headOffset > 0) {
			$adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
			$font = substr_replace($font, pack("N", $adjustment), $headOffset + 8, 4);
		}

		return $font;
	}

	private static function checksum(string $data): int {
		$data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
		$sum = 0;

		foreach (unpack("N*", $data) ?: [] as $word) {
			$sum = ($sum + $word) & 0xFFFFFFFF;
		}

		return $sum;
	}

	/**
	 * @param array<int, true> $keep
	 */
	private function keepGlyph(int $glyph, array &$keep, int $depth): void {
		$keep[$glyph] = true;

		if ($depth >= self::MAX_COMPOSITE_DEPTH) {
			return;
		}

		foreach ($this->components($glyph) as [$component]) {
			$this->keepGlyph($component, $keep, $depth + 1);
		}
	}

	/**
	 * The parts of a composite glyph: the glyph number, the transform (a, b, c,
	 * d, dx, dy in font units), and where in the file the glyph number is written.
	 *
	 * @return list<array{int, array{float, float, float, float, float, float}, int}>
	 */
	private function components(int $glyph): array {
		[$start, $end] = $this->glyphRange($glyph);

		if ($end - $start < 10 || $this->int16($start) >= 0) {
			return [];
		}

		$components = [];
		$at = $start + 10;

		do {
			$flags = $this->uint16($at);
			$component = $this->uint16($at + 2);
			$numberAt = $at + 2;
			$at += 4;

			if ($flags & self::ARGS_ARE_WORDS) {
				$dx = $this->int16($at);
				$dy = $this->int16($at + 2);
				$at += 4;
			} else {
				$dx = $this->int8($at);
				$dy = $this->int8($at + 1);
				$at += 2;
			}

			if (!($flags & self::ARGS_ARE_OFFSETS)) {
				$dx = 0;
				$dy = 0;
			}

			[$a, $b, $c, $d] = [1.0, 0.0, 0.0, 1.0];

			if ($flags & self::HAS_SCALE) {
				$a = $d = $this->f2dot14($at);
				$at += 2;
			} elseif ($flags & self::HAS_XY_SCALE) {
				$a = $this->f2dot14($at);
				$d = $this->f2dot14($at + 2);
				$at += 4;
			} elseif ($flags & self::HAS_TWO_BY_TWO) {
				$a = $this->f2dot14($at);
				$b = $this->f2dot14($at + 2);
				$c = $this->f2dot14($at + 4);
				$d = $this->f2dot14($at + 6);
				$at += 8;
			}

			$components[] = [$component, [$a, $b, $c, $d, (float) $dx, (float) $dy], $numberAt];
		} while ($flags & self::MORE_COMPONENTS && $at < $end);

		return $components;
	}

	/**
	 * @param array{float, float, float, float, float, float} $transform a, b, c, d, dx, dy in font units
	 * @return list<list<array{float, float, bool}>>
	 */
	private function glyphOutline(int $glyph, array $transform, int $depth): array {
		[$start, $end] = $this->glyphRange($glyph);

		if ($end - $start < 10) {
			return [];
		}

		$contourCount = $this->int16($start);

		if ($contourCount < 0) {
			if ($depth >= self::MAX_COMPOSITE_DEPTH) {
				return [];
			}

			$contours = [];

			foreach ($this->components($glyph) as [$component, [$a, $b, $c, $d, $dx, $dy]]) {
				[$ta, $tb, $tc, $td, $tx, $ty] = $transform;
				$combined = [
					$ta * $a + $tc * $b,
					$tb * $a + $td * $b,
					$ta * $c + $tc * $d,
					$tb * $c + $td * $d,
					$ta * $dx + $tc * $dy + $tx,
					$tb * $dx + $td * $dy + $ty,
				];
				array_push($contours, ...$this->glyphOutline($component, $combined, $depth + 1));
			}

			return $contours;
		}

		$ends = [];

		for ($i = 0; $i < $contourCount; $i++) {
			$ends[] = $this->uint16($start + 10 + $i * 2);
		}

		$pointCount = $ends === [] ? 0 : max($ends) + 1;
		$at = $start + 10 + $contourCount * 2;
		$at += 2 + $this->uint16($at);
		$flags = [];

		while (count($flags) < $pointCount) {
			$flag = $this->uint8($at++);
			$repeat = $flag & 0x08 ? $this->uint8($at++) : 0;

			for ($i = 0; $i <= $repeat; $i++) {
				$flags[] = $flag;
			}
		}

		$xs = [];
		$ys = [];
		$value = 0;

		foreach (array_slice($flags, 0, $pointCount) as $flag) {
			if ($flag & 0x02) {
				$delta = $this->uint8($at++);
				$value += $flag & 0x10 ? $delta : -$delta;
			} elseif (!($flag & 0x10)) {
				$value += $this->int16($at);
				$at += 2;
			}

			$xs[] = $value;
		}

		$value = 0;

		foreach (array_slice($flags, 0, $pointCount) as $flag) {
			if ($flag & 0x04) {
				$delta = $this->uint8($at++);
				$value += $flag & 0x20 ? $delta : -$delta;
			} elseif (!($flag & 0x20)) {
				$value += $this->int16($at);
				$at += 2;
			}

			$ys[] = $value;
		}

		[$a, $b, $c, $d, $dx, $dy] = $transform;
		$em = $this->unitsPerEm;
		$contours = [];
		$first = 0;

		foreach ($ends as $last) {
			$contour = [];

			for ($i = $first; $i <= $last && $i < $pointCount; $i++) {
				$contour[] = [
					($a * $xs[$i] + $c * $ys[$i] + $dx) / $em,
					($b * $xs[$i] + $d * $ys[$i] + $dy) / $em,
					($flags[$i] & 0x01) === 0x01,
				];
			}

			if (count($contour) > 1) {
				$contours[] = $contour;
			}

			$first = $last + 1;
		}

		return $contours;
	}

	/**
	 * @return array{int, int} start and end offset of a glyph in the file
	 */
	private function glyphRange(int $glyph): array {
		if ($glyph < 0 || $glyph >= $this->glyphCount()) {
			return [0, 0];
		}

		$glyf = $this->table("glyf");
		$loca = $this->table("loca");

		if ($this->int16($this->table("head") + 50) === 1) {
			$start = $this->uint32($loca + $glyph * 4);
			$end = $this->uint32($loca + $glyph * 4 + 4);
		} else {
			$start = $this->uint16($loca + $glyph * 2) * 2;
			$end = $this->uint16($loca + $glyph * 2 + 2) * 2;
		}

		if ($end <= $start || $glyf + $end > strlen($this->data)) {
			return [0, 0];
		}

		return [$glyf + $start, $glyf + $end];
	}

	private function glyphCount(): int {
		return $this->uint16($this->table("maxp") + 4);
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
		if (!isset($this->tables[$tag])) {
			throw new RenderException("The font file has no {$tag} table.");
		}

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
	 * Read the glyph index and advance width of every WinAnsi character.
	 *
	 * @return array<int, int>
	 */
	private function readWidths(int $metricCount): array {
		$glyphs = $this->readCmap();
		$hmtx = $this->table("hmtx");
		$widths = [];

		for ($code = 32; $code <= 255; $code++) {
			$unicode = self::winAnsiToUnicode($code);
			$glyph = $glyphs[$unicode] ?? 0;
			$this->glyphs[$code] = $glyph;
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

	private function uint8(int $offset): int {
		if ($offset < 0 || $offset + 1 > strlen($this->data)) {
			throw new RenderException("The font file is truncated.");
		}

		return ord($this->data[$offset]);
	}

	private function int8(int $offset): int {
		$value = $this->uint8($offset);

		return $value >= 0x80 ? $value - 0x100 : $value;
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

	private function f2dot14(int $offset): float {
		return $this->int16($offset) / 16384;
	}

	private function uint32(int $offset): int {
		if ($offset < 0 || $offset + 4 > strlen($this->data)) {
			throw new RenderException("The font file is truncated.");
		}

		return (int) (unpack("N", $this->data, $offset)[1] ?? 0);
	}
}
