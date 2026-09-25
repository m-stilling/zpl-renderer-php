<?php

namespace Stilling\ZplRenderer\Parser;

use Stilling\ZplRenderer\Exceptions\ParseException;

/**
 * Splits raw ZPL into commands. Honors ^CC, ^CT and ^CD, which change the
 * prefix and delimiter characters for the rest of the stream.
 */
class Tokenizer {
	private string $formatPrefix = "^";

	private string $controlPrefix = "~";

	/** @var non-empty-string */
	private string $delimiter = ",";

	/**
	 * @return list<Command>
	 */
	public function tokenize(string $zpl): array {
		$commands = [];
		$length = strlen($zpl);
		$i = 0;

		while ($i < $length) {
			$char = $zpl[$i];

			if ($char !== $this->formatPrefix && $char !== $this->controlPrefix) {
				$i++;
				continue;
			}

			$i++;

			if ($i >= $length) {
				break;
			}

			$name = strtoupper($zpl[$i]);
			$i++;

			if ($name === "A") {
				if ($i < $length && $zpl[$i] === "@") {
					$name = "A@";
					$i++;
				}
			} elseif ($i < $length) {
				$name .= strtoupper($zpl[$i]);
				$i++;
			}

			if (!ctype_alnum(str_replace("@", "", $name))) {
				$shown = preg_replace_callback('/[^\x20-\x7E]/', fn (array $m): string => sprintf("\\x%02X", ord($m[0])), $name) ?? $name;

				throw new ParseException("Invalid command name \"{$shown}\" at offset " . ($i - strlen($name)) . ".");
			}

			$start = $i;
			$data = null;

			if ($this->changesPrefix($name)) {
				$i = min($length, $i + 1);
			} elseif ($name === "DY" && ($object = $this->binaryObject($zpl, $start)) !== null) {
				[$i, $data] = $object;
			} else {
				while ($i < $length && $zpl[$i] !== $this->formatPrefix && $zpl[$i] !== $this->controlPrefix) {
					$i++;
				}
			}

			$params = str_replace(["\r", "\n"], "", substr($zpl, $start, $i - $start));
			$commands[] = new Command($name, $params, $this->delimiter, $data);

			if ($data !== null) {
				$i += 1 + strlen($data);
			}

			$this->applyPrefixChange($name, $params);
		}

		return $commands;
	}

	/**
	 * A ~DY object in a format other than A (ASCII) carries raw binary data,
	 * which can hold prefix bytes. The header gives the byte count, so the
	 * data is taken by count instead of scanning for the next command.
	 *
	 * Returns the offset of the delimiter before the data and the data itself,
	 * or null when the object is ASCII, :B64:, :Z64: or without a byte count.
	 *
	 * @return array{int, string}|null
	 */
	private function binaryObject(string $zpl, int $start): ?array {
		$end = $start - 1;

		for ($field = 0; $field < 5; $field++) {
			$end = strpos($zpl, $this->delimiter, $end + 1);

			if ($end === false) {
				return null;
			}
		}

		$header = substr($zpl, $start, $end - $start);

		if (strpbrk($header, $this->formatPrefix . $this->controlPrefix) !== false) {
			return null;
		}

		$fields = array_map(trim(...), explode($this->delimiter, $header));
		$total = (int) $fields[3];

		if (strtoupper($fields[1]) === "A" || $total < 1 || preg_match('/^:[BZ]64:/i', substr($zpl, $end + 1, 5)) === 1) {
			return null;
		}

		return [$end, substr($zpl, $end + 1, $total)];
	}

	/**
	 * ^CC, ^CT and ^CD take exactly one character, which may itself be a prefix.
	 */
	private function changesPrefix(string $name): bool {
		return $name === "CC" || $name === "CT" || $name === "CD";
	}

	private function applyPrefixChange(string $name, string $params): void {
		if ($params === "") {
			return;
		}

		match ($name) {
			"CC" => $this->formatPrefix = $params[0],
			"CT" => $this->controlPrefix = $params[0],
			"CD" => $this->delimiter = $params[0],
			default => null,
		};
	}
}
