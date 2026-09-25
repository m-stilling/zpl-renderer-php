<?php

namespace Stilling\ZplRenderer\Parser;

/**
 * One ZPL command: the two-letter name and the raw parameter string that follows it.
 */
class Command {
	/** @var list<string>|null */
	private ?array $args = null;

	public function __construct(
		/** Command name without the prefix, for example "FO", "A" or "DG". */
		public readonly string $name,
		/** Everything between the name and the next command, with CR and LF removed. */
		public readonly string $params,
		/** The parameter delimiter in force when the command was read. */
		public readonly string $delimiter = ",",
		/** Raw bytes of a binary ~DY object, taken by the byte count in its header. Not in params. */
		public readonly ?string $data = null,
	) {
	}

	/**
	 * Parameters split on the delimiter. Each is trimmed; missing ones are absent.
	 *
	 * @return list<string>
	 */
	public function args(): array {
		if ($this->args === null) {
			$this->args = $this->params === "" ? [] : array_map(trim(...), explode($this->delimiter(), $this->params));
		}

		return $this->args;
	}

	/**
	 * Parameters split on at most $limit - 1 delimiters, so the last one keeps
	 * every delimiter it contains. Used for commands that end with data.
	 *
	 * @return list<string>
	 */
	public function argsLimited(int $limit): array {
		return $this->params === "" ? [] : array_map(trim(...), explode($this->delimiter(), $this->params, $limit));
	}

	/**
	 * @return non-empty-string
	 */
	private function delimiter(): string {
		return $this->delimiter === "" ? "," : $this->delimiter;
	}

	public function arg(int $index, string $default = ""): string {
		$value = $this->args()[$index] ?? "";

		return $value === "" ? $default : $value;
	}

	public function int(int $index, int $default): int {
		$value = $this->arg($index);

		if ($value === "" || !is_numeric($value)) {
			return $default;
		}

		return (int) round((float) $value);
	}

	public function float(int $index, float $default): float {
		$value = $this->arg($index);

		if ($value === "" || !is_numeric($value)) {
			return $default;
		}

		return (float) $value;
	}

	public function bool(int $index, bool $default): bool {
		return match (strtoupper($this->arg($index))) {
			"Y" => true,
			"N" => false,
			default => $default,
		};
	}

	/**
	 * The first character of a parameter, upper-cased, or the default when the parameter is empty.
	 */
	public function char(int $index, string $default): string {
		$value = $this->arg($index);

		return $value === "" ? $default : strtoupper($value[0]);
	}

	public function is(string ...$names): bool {
		return in_array($this->name, $names, true);
	}
}
