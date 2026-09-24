<?php

namespace Stilling\Zpl\Font;

use Stilling\Zpl\Options;

/**
 * The font files the printer fonts are drawn with.
 */
enum Typeface {
	/** The scalable font 0. */
	case Scalable;

	/** The bitmap fonts A and C to H. */
	case Mono;

	/** The bold bitmap font B. */
	case MonoBold;

	public function file(Options $options): string {
		return match ($this) {
			self::Scalable => $options->getScalableFontFile(),
			self::Mono => $options->getMonoFontFile(),
			self::MonoBold => $options->getMonoBoldFontFile(),
		};
	}
}
