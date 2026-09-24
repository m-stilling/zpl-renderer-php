<?php

namespace Stilling\Zpl\Renderer;

use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\DiagonalElement;
use Stilling\Zpl\Model\EllipseElement;

/**
 * The outlines of the ^GB, ^GC, ^GE and ^GD graphics, in their local box.
 */
class Shapes {
	public static function box(BoxElement $element): Path {
		$w = $element->width;
		$h = $element->height;
		$t = min($element->thickness, intdiv(min($w, $h) + 1, 2));
		$radius = $element->rounding > 0 ? $element->rounding / 8 * min($w, $h) / 2 : 0.0;
		$path = (new Path())->roundedRect(0, 0, $w, $h, $radius);

		if ($t < min($w, $h) / 2) {
			$path->roundedRect($t, $t, $w - 2 * $t, $h - 2 * $t, max(0, $radius - $t));
		}

		return $path;
	}

	public static function circle(CircleElement $element): Path {
		$d = $element->diameter;
		$t = min($element->thickness, intdiv($d + 1, 2));
		$path = (new Path())->ellipse(0, 0, $d, $d);

		if ($t < $d / 2) {
			$path->ellipse($t, $t, $d - 2 * $t, $d - 2 * $t);
		}

		return $path;
	}

	public static function ellipse(EllipseElement $element): Path {
		$w = $element->width;
		$h = $element->height;
		$t = min($element->thickness, intdiv(min($w, $h) + 1, 2));
		$path = (new Path())->ellipse(0, 0, $w, $h);

		if ($t < min($w, $h) / 2) {
			$path->ellipse($t, $t, $w - 2 * $t, $h - 2 * $t);
		}

		return $path;
	}

	/**
	 * A line of the given thickness from one corner of the box to the opposite one.
	 */
	public static function diagonal(DiagonalElement $element): Path {
		$w = $element->width;
		$h = $element->height;
		[$x0, $y0, $x1, $y1] = $element->rightLeaning ? [0, $h, $w, 0] : [0, 0, $w, $h];
		$length = sqrt($w * $w + $h * $h);
		$nx = -($y1 - $y0) / $length * $element->thickness / 2;
		$ny = ($x1 - $x0) / $length * $element->thickness / 2;

		return (new Path())->polygon([
			[$x0 + $nx, $y0 + $ny],
			[$x1 + $nx, $y1 + $ny],
			[$x1 - $nx, $y1 - $ny],
			[$x0 - $nx, $y0 - $ny],
		]);
	}
}
