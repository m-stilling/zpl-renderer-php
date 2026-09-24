<?php

namespace Stilling\ZplRenderer;

/**
 * Options whose setters return a changed copy and leave the instance as it is.
 */
class OptionsImmutable extends Options {
	protected bool $immutable = true;
}
