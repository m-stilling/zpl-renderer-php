<?php

namespace Stilling\ZplRenderer\Exceptions;

/**
 * Thrown when the input uses a feature this package does not render.
 */
class UnsupportedException extends \RuntimeException implements ZplException {
}
