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

			if ($this->changesPrefix($name)) {
				$i = min($length, $i + 1);
			} else {
				while ($i < $length && $zpl[$i] !== $this->formatPrefix && $zpl[$i] !== $this->controlPrefix) {
					$i++;
				}
			}

			$params = str_replace(["\r", "\n"], "", substr($zpl, $start, $i - $start));
			$commands[] = new Command($name, $params, $this->delimiter);

			$this->applyPrefixChange($name, $params);
		}

		return $commands;
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
