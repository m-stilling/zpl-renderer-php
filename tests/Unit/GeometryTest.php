<?php

use Stilling\ZplRenderer\Model\BoxElement;
use Stilling\ZplRenderer\Model\DiagonalElement;
use Stilling\ZplRenderer\Model\Justification;
use Stilling\ZplRenderer\Model\Orientation;
use Stilling\ZplRenderer\Renderer\Matrix;
use Stilling\ZplRenderer\Renderer\Path;
use Stilling\ZplRenderer\Renderer\Placement;
use Stilling\ZplRenderer\Renderer\Shapes;

test("a matrix applies, scales and bounds", function () {
	$matrix = (new Matrix(0, 1, -1, 0, 10, 20))->scaled(2);

	expect($matrix->apply(3, 0))->toBe([20.0, 46.0])
		->and($matrix->bounds(0, 0, 4, 2))->toBe([16.0, 40.0, 20.0, 48.0]);
});

test("^FO places the rotated box with its top-left at the origin", function () {
	$box = fn (Orientation $o) => new BoxElement(100, 200, 40, 10, 1, orientation: $o);

	expect(Placement::matrix($box(Orientation::Normal), 40, 10, 0)->bounds(0, 0, 40, 10))->toBe([100.0, 200.0, 140.0, 210.0])
		->and(Placement::matrix($box(Orientation::Rotated), 40, 10, 0)->bounds(0, 0, 40, 10))->toBe([100.0, 200.0, 110.0, 240.0])
		->and(Placement::matrix($box(Orientation::Inverted), 40, 10, 0)->bounds(0, 0, 40, 10))->toBe([100.0, 200.0, 140.0, 210.0])
		->and(Placement::matrix($box(Orientation::BottomUp), 40, 10, 0)->bounds(0, 0, 40, 10))->toBe([100.0, 200.0, 110.0, 240.0]);
});

test("^FT anchors the bottom edge or the anchor line on the origin", function () {
	$box = new BoxElement(100, 200, 40, 10, 1, typeset: true);
	$anchored = new BoxElement(100, 200, 40, 10, 1, typeset: true, orientation: Orientation::Rotated);

	expect(Placement::matrix($box, 40, 10, 0)->bounds(0, 0, 40, 10))->toBe([100.0, 190.0, 140.0, 200.0])
		->and(Placement::matrix($anchored, 40, 10, 6)->apply(0, 6))->toBe([100.0, 200.0]);
});

test("right justification ends the field at the origin", function () {
	$box = new BoxElement(100, 200, 40, 10, 1, justification: Justification::Right);

	expect(Placement::matrix($box, 40, 10, 0)->bounds(0, 0, 40, 10))->toBe([60.0, 200.0, 100.0, 210.0]);
});

test("a rectangle path prints as a re operator and flattens to four points", function () {
	$path = (new Path())->rect(1, 2, 3, 4);

	expect($path->toPdf())->toBe("1 2 3 4 re\n")
		->and($path->flatten())->toBe([[[1.0, 2.0], [4.0, 2.0], [4.0, 6.0], [1.0, 6.0]]]);
});

test("curves flatten into many points and print as c operators", function () {
	$path = (new Path())->ellipse(0, 0, 10, 10);

	expect(count($path->flatten(8)[0]))->toBe(33)
		->and(substr_count($path->toPdf(), " c "))->toBe(4);
});

test("a box shape is an outer and an inner outline, a solid box only one", function () {
	expect(Shapes::box(new BoxElement(0, 0, 100, 50, 5))->flatten())->toHaveCount(2)
		->and(Shapes::box(new BoxElement(0, 0, 100, 50, 25))->flatten())->toHaveCount(1);
});

test("a diagonal is a four-point strip of its thickness", function () {
	$polygon = Shapes::diagonal(new DiagonalElement(0, 0, 30, 40, 10))->flatten()[0];

	expect($polygon)->toHaveCount(4)
		->and($polygon[0][0])->toEqualWithDelta(4, 0.001)
		->and($polygon[0][1])->toEqualWithDelta(43, 0.001);
});
