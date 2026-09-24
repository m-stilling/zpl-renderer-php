<?php

namespace Stilling\ZplRenderer\Renderer;

use Stilling\ZplRenderer\Model\Element;
use Stilling\ZplRenderer\Model\Justification;

/**
 * Where a field goes on the label. Every element is drawn in a local box
 * (0,0)-(width,height) with y down; this computes the transform from that box
 * to label dots.
 */
class Placement {
	/**
	 * ^FO puts the top-left of the rotated box at the origin. ^FT rotates the
	 * field around its anchor line: the first baseline of text, the bottom of
	 * the bars of a barcode, the bottom edge of anything else.
	 *
	 * @param float $anchor distance from the top of the local box to the anchor line, or 0 for the bottom edge
	 */
	public static function matrix(Element $element, float $width, float $height, float $anchor): Matrix {
		$orientation = $element->orientation;
		[$a, $b, $c, $d] = $orientation->matrix();
		$shiftX = $element->justification === Justification::Right ? -$width : 0.0;
		$shiftY = 0.0;

		if ($element->typeset) {
			$shiftY = -($anchor > 0 ? $anchor : $height);
			$offsetX = $element->x;
			$offsetY = $element->y;
		} else {
			$minX = INF;
			$minY = INF;

			foreach ([[0, 0], [$width, 0], [0, $height], [$width, $height]] as [$px, $py]) {
				[$rx, $ry] = $orientation->rotate($px, $py);
				$minX = min($minX, $rx);
				$minY = min($minY, $ry);
			}

			$offsetX = $element->x - $minX;
			$offsetY = $element->y - $minY;
		}

		[$sx, $sy] = $orientation->rotate($shiftX, $shiftY);

		return new Matrix($a, $b, $c, $d, $offsetX + $sx, $offsetY + $sy);
	}
}
