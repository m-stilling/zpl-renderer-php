<?php

namespace Stilling\Zpl\Graphics;

/**
 * Runs a decoder that reports bad input through PHP warnings, and turns the
 * warning into a null result instead.
 */
class Silencer {
	/**
	 * @template T
	 * @param callable(): (T|false) $callable
	 * @return T|null
	 */
	public static function call(callable $callable): mixed {
		set_error_handler(fn (): bool => true);

		try {
			$result = $callable();
		} finally {
			restore_error_handler();
		}

		return $result === false ? null : $result;
	}
}
