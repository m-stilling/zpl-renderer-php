<?php

namespace Stilling\ZplRenderer\Barcode;

use Stilling\ZplRenderer\Exceptions\RenderException;

/**
 * Code 128 with the ZPL ^BC semantics: subsets A, B and C, the ">" invocation
 * codes, UCC case mode and UCC/EAN-128.
 */
class Code128 {
	private const int FNC3 = 96;

	private const int FNC2 = 97;

	private const int SHIFT = 98;

	private const int CODE_C = 99;

	private const int CODE_B = 100;

	private const int CODE_A = 101;

	private const int FNC1 = 102;

	private const int START_A = 103;

	private const int START_B = 104;

	private const int START_C = 105;

	private const int STOP = 106;

	/** @var list<string> bar/space widths for values 0 to 106 */
	private const array PATTERNS = [
		"212222", "222122", "222221", "121223", "121322", "131222", "122213", "122312", "132212", "221213",
		"221312", "231212", "112232", "122132", "122231", "113222", "123122", "123221", "223211", "221132",
		"221231", "213212", "223112", "312131", "311222", "321122", "321221", "312212", "322112", "322211",
		"212123", "212321", "232121", "111323", "131123", "131321", "112313", "132113", "132311", "211313",
		"231113", "231311", "112133", "112331", "132131", "113123", "113321", "133121", "313121", "211331",
		"231131", "213113", "213311", "213131", "311123", "311321", "331121", "312113", "312311", "332111",
		"314111", "221411", "431111", "111224", "111422", "121124", "121421", "141122", "141221", "112214",
		"112412", "122114", "122411", "142112", "142211", "241211", "221114", "413111", "241112", "134111",
		"111242", "121142", "121241", "114212", "124112", "124211", "411212", "421112", "421211", "212141",
		"214121", "412121", "111143", "111341", "131141", "114113", "114311", "411113", "411311", "113141",
		"114131", "311141", "411131", "211412", "211214", "211232", "2331112",
	];

	/**
	 * @return array{BarcodeMatrix, string} the bars and the text for the interpretation line
	 */
	public function encode(string $data, Code128Mode $mode, bool $uccCheckDigit = false): array {
		return match ($mode) {
			Code128Mode::Normal => $this->encodeWithInvocationCodes($data, $uccCheckDigit),
			Code128Mode::Automatic => $this->encodeAutomatic($data, $uccCheckDigit),
			Code128Mode::Ucc => $this->encodeUccCase($data),
			Code128Mode::Ean => $this->encodeEan($data),
		};
	}

	/**
	 * @return array{BarcodeMatrix, string}
	 */
	private function encodeWithInvocationCodes(string $data, bool $uccCheckDigit): array {
		$tokens = $this->tokenize($data);

		if ($uccCheckDigit) {
			$tokens[] = ["type" => "char", "value" => $this->mod10($this->plainText($tokens))];
		}

		$set = "B";
		$first = $tokens[0] ?? null;

		if ($first !== null && $first["type"] === "start") {
			$set = $first["value"];
			array_shift($tokens);
		}

		$values = [$this->startValue($set)];
		$text = "";
		$count = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if ($token["type"] === "start" || $token["type"] === "switch") {
				$this->switchTo($values, $set, $token["value"]);
				continue;
			}

			if ($token["type"] === "function") {
				$values[] = $this->functionValue($token["value"], $set);
				continue;
			}

			if ($token["type"] === "shift") {
				$next = $tokens[$i + 1] ?? null;

				if ($next !== null && $next["type"] === "char") {
					$values[] = self::SHIFT;
					$values[] = $this->charValue($next["value"], $set === "A" ? "B" : "A");
					$text .= $next["value"];
					$i++;
				}

				continue;
			}

			$char = $token["value"];
			$text .= $char;

			if ($set === "C") {
				$next = $tokens[$i + 1] ?? null;

				if (ctype_digit($char) && $next !== null && $next["type"] === "char" && ctype_digit($next["value"])) {
					$values[] = (int) ($char . $next["value"]);
					$text .= $next["value"];
					$i++;
					continue;
				}

				$this->switchTo($values, $set, "B");
			}

			if ($set === "A" && ord($char) > 95) {
				$this->switchTo($values, $set, "B");
			} elseif ($set === "B" && ord($char) < 32) {
				$this->switchTo($values, $set, "A");
			}

			$values[] = $this->charValue($char, $set);
		}

		return [$this->bars($values), $text];
	}

	/**
	 * @return array{BarcodeMatrix, string}
	 */
	private function encodeAutomatic(string $data, bool $uccCheckDigit): array {
		[$plain, $functions] = $this->plainTextWithFunctions($this->tokenize($data));

		if ($uccCheckDigit) {
			$plain .= $this->mod10($plain);
		}

		return [$this->bars($this->optimize($plain, $functions)), $plain];
	}

	/**
	 * @return array{BarcodeMatrix, string}
	 */
	private function encodeUccCase(string $data): array {
		$digits = preg_replace("/\D/", "", $data) ?? "";

		if (strlen($digits) !== 19) {
			throw new RenderException("UCC case mode (^BC mode U) needs exactly 19 digits, got " . strlen($digits) . ".");
		}

		$digits .= $this->mod10($digits);
		$values = [self::START_C, self::FNC1];

		foreach (str_split($digits, 2) as $pair) {
			$values[] = (int) $pair;
		}

		return [$this->bars($values), $digits];
	}

	/**
	 * @return array{BarcodeMatrix, string}
	 */
	private function encodeEan(string $data): array {
		$tokens = array_values(array_filter(
			$this->tokenize($data),
			fn (array $token): bool => $token["type"] !== "char" || ($token["value"] !== "(" && $token["value"] !== ")"),
		));
		[$plain, $functions] = $this->plainTextWithFunctions($tokens);
		$functions[0] = true;

		return [$this->bars($this->optimize($plain, $functions)), $data];
	}

	/**
	 * Split the field data into characters and ">" invocation codes.
	 *
	 * @return list<array{type: string, value: string}>
	 */
	private function tokenize(string $data): array {
		$tokens = [];
		$length = strlen($data);

		for ($i = 0; $i < $length; $i++) {
			$char = $data[$i];

			if ($char !== ">" || $i + 1 >= $length) {
				$tokens[] = ["type" => "char", "value" => $char];
				continue;
			}

			$code = $data[$i + 1];
			$i++;

			$tokens[] = match ($code) {
				"9" => ["type" => "start", "value" => "A"],
				":" => ["type" => "start", "value" => "B"],
				";" => ["type" => "start", "value" => "C"],
				"5" => ["type" => "switch", "value" => "C"],
				"6" => ["type" => "switch", "value" => "B"],
				"7" => ["type" => "switch", "value" => "A"],
				"4" => ["type" => "shift", "value" => ""],
				"8" => ["type" => "function", "value" => "1"],
				"3" => ["type" => "function", "value" => "2"],
				"2" => ["type" => "function", "value" => "3"],
				"1" => ["type" => "char", "value" => chr(127)],
				"=" => ["type" => "char", "value" => chr(126)],
				"<", "0" => ["type" => "char", "value" => ">"],
				default => ["type" => "char", "value" => $code],
			};
		}

		return $tokens;
	}

	/**
	 * @param list<array{type: string, value: string}> $tokens
	 */
	private function plainText(array $tokens): string {
		return $this->plainTextWithFunctions($tokens)[0];
	}

	/**
	 * @param list<array{type: string, value: string}> $tokens
	 * @return array{string, array<int, true>} the characters, and the positions before which FNC1 goes
	 */
	private function plainTextWithFunctions(array $tokens): array {
		$plain = "";
		$functions = [];

		foreach ($tokens as $token) {
			if ($token["type"] === "char") {
				$plain .= $token["value"];
			} elseif ($token["type"] === "function" && $token["value"] === "1") {
				$functions[strlen($plain)] = true;
			}
		}

		return [$plain, $functions];
	}

	private function startValue(string $set): int {
		return match ($set) {
			"A" => self::START_A,
			"C" => self::START_C,
			default => self::START_B,
		};
	}

	/**
	 * @param list<int> $values
	 */
	private function switchTo(array &$values, string &$set, string $target): void {
		if ($set === $target) {
			return;
		}

		$values[] = match ($target) {
			"A" => self::CODE_A,
			"C" => self::CODE_C,
			default => self::CODE_B,
		};
		$set = $target;
	}

	private function functionValue(string $function, string $set): int {
		return match ($function) {
			"1" => self::FNC1,
			"2" => self::FNC2,
			"3" => self::FNC3,
			default => $set === "A" ? self::CODE_A : self::CODE_B,
		};
	}

	private function charValue(string $char, string $set): int {
		$code = ord($char);

		if ($set === "A") {
			if ($code < 32) {
				return $code + 64;
			}

			if ($code > 95) {
				throw new RenderException("Character \"{$char}\" cannot be encoded in Code 128 subset A.");
			}

			return $code - 32;
		}

		if ($code < 32 || $code > 127) {
			throw new RenderException("Character with code {$code} cannot be encoded in Code 128 subset B.");
		}

		return $code - 32;
	}

	/**
	 * Standard subset optimization: subset C for runs of four or more digits,
	 * subset B otherwise, subset A for control characters.
	 *
	 * @param array<int, true> $functionsAt positions in the data before which FNC1 is inserted
	 * @return list<int>
	 */
	private function optimize(string $data, array $functionsAt): array {
		$length = strlen($data);
		$values = [];
		$set = null;
		$i = 0;

		if ($length === 0) {
			return [self::START_B];
		}

		while ($i < $length) {
			$digits = $this->digitRun($data, $i);
			$useC = $digits >= 4 || ($digits >= 2 && $i + $digits === $length && $digits % 2 === 0);

			if ($useC) {
				$this->select($values, $set, "C");
				$this->insertFunction($values, $functionsAt, $i);

				for ($pairs = intdiv($digits, 2); $pairs > 0; $pairs--) {
					$values[] = (int) substr($data, $i, 2);
					$i += 2;
				}

				continue;
			}

			$char = $data[$i];
			$target = ord($char) < 32 ? "A" : (ord($char) > 95 ? "B" : ($set === "A" ? "A" : "B"));
			$this->select($values, $set, $target);
			$this->insertFunction($values, $functionsAt, $i);
			$values[] = $this->charValue($char, $set);
			$i++;
		}

		return $values;
	}

	/**
	 * @param list<int> $values
	 * @param array<int, true> $functionsAt
	 */
	private function insertFunction(array &$values, array $functionsAt, int $position): void {
		if (isset($functionsAt[$position])) {
			$values[] = self::FNC1;
		}
	}

	private function digitRun(string $data, int $from): int {
		$count = 0;
		$length = strlen($data);

		while ($from + $count < $length && ctype_digit($data[$from + $count])) {
			$count++;
		}

		return $count;
	}

	/**
	 * @param list<int> $values
	 * @param-out string $set
	 */
	private function select(array &$values, ?string &$set, string $target): void {
		if ($set === null) {
			$values[] = $this->startValue($target);
			$set = $target;

			return;
		}

		$this->switchTo($values, $set, $target);
	}

	/**
	 * @param list<int> $values
	 */
	private function bars(array $values): BarcodeMatrix {
		$sum = $values[0];

		foreach ($values as $index => $value) {
			if ($index > 0) {
				$sum += $index * $value;
			}
		}

		$values[] = $sum % 103;
		$values[] = self::STOP;
		$pattern = "";

		foreach ($values as $value) {
			$widths = self::PATTERNS[$value];
			$length = strlen($widths);

			for ($i = 0; $i < $length; $i++) {
				$pattern .= str_repeat($i % 2 === 0 ? "1" : "0", (int) $widths[$i]);
			}
		}

		return BarcodeMatrix::fromPattern($pattern);
	}

	private function mod10(string $digits): string {
		$digits = preg_replace("/\D/", "", $digits) ?? "";
		$sum = 0;
		$weight = 3;

		for ($i = strlen($digits) - 1; $i >= 0; $i--) {
			$sum += (int) $digits[$i] * $weight;
			$weight = $weight === 3 ? 1 : 3;
		}

		return (string) ((10 - $sum % 10) % 10);
	}
}
