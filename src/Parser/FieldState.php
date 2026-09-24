<?php

namespace Stilling\Zpl\Parser;

use Stilling\Zpl\Model\Element;
use Stilling\Zpl\Model\FieldBlock;
use Stilling\Zpl\Model\Justification;

/**
 * Everything collected for the field being built, from ^FO or ^FT up to ^FS.
 */
class FieldState {
	public int $x = 0;

	public int $y = 0;

	public bool $typeset = false;

	public ?Justification $justification = null;

	public bool $reverse = false;

	/** Hex escape indicator from ^FH, or null when hex escapes are off. */
	public ?string $hexIndicator = null;

	public ?FieldBlock $block = null;

	/** Raw field data from ^FD, ^FV or ^SN, or null when the field has none yet. */
	public ?string $data = null;

	/** Field number from ^FN, or null. */
	public ?int $number = null;

	/** Builds the element for a barcode command once the data is known. */
	public ?\Closure $barcode = null;

	/** A graphic element (^GB, ^GC, ^GD, ^GE, ^GF, ^XG, ^IM) waiting for ^FS. */
	public ?\Closure $shape = null;
}
