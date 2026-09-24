<?php

namespace Stilling\ZplRenderer\Exceptions;

/**
 * Thrown when a label cannot be rendered, for example when barcode data is invalid.
 */
class RenderException extends \RuntimeException implements ZplException {
}
