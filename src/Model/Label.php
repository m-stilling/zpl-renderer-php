<?php

namespace Stilling\ZplRenderer\Model;

/**
 * One label format (^XA ... ^XZ) after interpretation: a page size and the elements on it.
 */
class Label {
	/**
	 * @param list<Element> $elements
	 */
	public function __construct(
		/** Label width in dots. */
		public readonly int $width,
		/** Label height in dots. */
		public readonly int $height,
		/** Print density in dots per millimeter. */
		public readonly int $dpmm,
		public readonly array $elements = [],
		/** Number of copies from ^PQ. */
		public readonly int $quantity = 1,
		/** ^POI: print the label upside down. */
		public readonly bool $inverted = false,
		/** ^PMY: mirror the label horizontally. */
		public readonly bool $mirrored = false,
	) {
	}
}
