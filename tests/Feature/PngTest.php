<?php

use Stilling\ZplRenderer\Exceptions\RenderException;
use Stilling\ZplRenderer\Options;
use Stilling\ZplRenderer\Zpl;

function png(string $zpl, ?Options $options = null): GdImage {
	$image = imagecreatefromstring(Zpl::toPng($zpl, $options));

	if ($image === false) {
		throw new RuntimeException("The output is not a PNG.");
	}

	return $image;
}

function isBlack(GdImage $image, int $x, int $y): bool {
	$index = imagecolorat($image, $x, $y);

	if ($index === false) {
		throw new RuntimeException("Pixel {$x},{$y} is outside the image.");
	}

	return imagecolorsforindex($image, $index)["red"] < 128;
}

/**
 * Count the black pixels inside a rectangle.
 */
function blackPixels(GdImage $image, int $x0, int $y0, int $x1, int $y1): int {
	$count = 0;

	for ($y = $y0; $y < $y1; $y++) {
		for ($x = $x0; $x < $x1; $x++) {
			if (isBlack($image, $x, $y)) {
				$count++;
			}
		}
	}

	return $count;
}

test("the image is one pixel per dot, or more with pixelsPerDot", function () {
	$one = png("^XA^PW100^LL50^XZ");
	$three = png("^XA^PW100^LL50^XZ", new Options(pixelsPerDot: 3));

	expect(imagesx($one))->toBe(100)
		->and(imagesy($one))->toBe(50)
		->and(imagesx($three))->toBe(300)
		->and(imagesy($three))->toBe(150)
		->and(blackPixels($one, 0, 0, 100, 50))->toBe(0);
});

test("a solid box covers exactly its dots", function () {
	$image = png("^XA^PW100^LL100^FO10,20^GB30,40,30^FS^XZ");

	expect(blackPixels($image, 10, 20, 40, 60))->toBe(30 * 40)
		->and(blackPixels($image, 0, 0, 100, 100))->toBe(30 * 40);
});

test("a circle stays inside its box", function () {
	$image = png("^XA^PW100^LL100^FO10,10^GC40,20^FS^XZ");

	expect(blackPixels($image, 10, 10, 50, 50))->toBeGreaterThan(1000)
		->and(blackPixels($image, 0, 0, 100, 100))->toBe(blackPixels($image, 10, 10, 50, 50))
		->and(isBlack($image, 10, 10))->toBeFalse()
		->and(isBlack($image, 30, 10))->toBeTrue();
});

test("a hollow box leaves its middle untouched", function () {
	$image = png("^XA^PW100^LL100^FO10,10^GB50,50,5^FS^XZ");

	expect(isBlack($image, 12, 12))->toBeTrue()
		->and(isBlack($image, 30, 30))->toBeFalse()
		->and(isBlack($image, 57, 57))->toBeTrue();
});

test("a white box erases what is under it", function () {
	$image = png("^XA^PW100^LL100^FO0,0^GB100,100,100^FS^FO20,20^GB20,20,20,W^FS^XZ");

	expect(isBlack($image, 10, 10))->toBeTrue()
		->and(isBlack($image, 30, 30))->toBeFalse();
});

test("a reverse field flips the pixels under it", function () {
	$image = png("^XA^PW100^LL100^FO0,0^GB100,50,50^FS^FO20,20^FR^GB20,60,60^FS^XZ");

	expect(isBlack($image, 30, 30))->toBeFalse()
		->and(isBlack($image, 30, 70))->toBeTrue()
		->and(isBlack($image, 10, 30))->toBeTrue()
		->and(isBlack($image, 10, 70))->toBeFalse();
});

test("^LR reverses the whole label", function () {
	$image = png("^XA^PW100^LL100^LRY^FO0,0^GB50,50,50^FS^XZ");

	expect(isBlack($image, 10, 10))->toBeTrue()
		->and(blackPixels($image, 0, 0, 50, 50))->toBe(50 * 50);
});

test("text draws inside its field box and nowhere else", function () {
	$image = png("^XA^PW300^LL100^FO20,20^A0N,40,40^FDHello^FS^XZ");

	expect(blackPixels($image, 20, 20, 150, 62))->toBeGreaterThan(200)
		->and(blackPixels($image, 0, 0, 300, 18))->toBe(0)
		->and(blackPixels($image, 0, 0, 18, 100))->toBe(0)
		->and(blackPixels($image, 0, 64, 300, 100))->toBe(0);
});

test("bitmap fonts draw with their capitals inside the cell", function () {
	$image = png("^XA^PW300^LL100^FO20,20^AAN,18,10^FDHELLO^FS^XZ");

	expect(blackPixels($image, 20, 20, 90, 40))->toBeGreaterThan(50)
		->and(blackPixels($image, 0, 0, 300, 19))->toBe(0);
});

test("the same character draws the same pixels wherever it appears", function () {
	$image = png("^XA^PW400^LL60^FO3,10^A0N,33,31^FDHHHHHHHH^FS^XZ");
	$glyphs = [];
	$current = "";

	for ($x = 0; $x < 400; $x++) {
		$column = "";

		for ($y = 0; $y < 60; $y++) {
			$column .= isBlack($image, $x, $y) ? "1" : "0";
		}

		if (str_contains($column, "1")) {
			$current .= $column;
		} elseif ($current !== "") {
			$glyphs[] = $current;
			$current = "";
		}
	}

	expect($glyphs)->toHaveCount(8)->and(array_unique($glyphs))->toHaveCount(1);
});

test("the capital letters of font 0 are a whole number of pixels tall and sit on the baseline", function () {
	$image = png("^XA^PW200^LL60^FT10,50^A0N,27,27^FDH^FS^XZ");
	$rows = array_values(array_filter(range(0, 59), fn (int $y): bool => blackPixels($image, 0, $y, 200, $y + 1) > 0));

	expect($rows[count($rows) - 1])->toBe(49)
		->and(count($rows))->toBe((int) round(27 * 0.8));
});

test("rotated text runs down from the origin", function () {
	$image = png("^XA^PW200^LL200^FO50,50^A0R,30,30^FDHello^FS^XZ");

	expect(blackPixels($image, 50, 50, 82, 150))->toBeGreaterThan(200)
		->and(blackPixels($image, 0, 0, 200, 48))->toBe(0)
		->and(blackPixels($image, 0, 0, 48, 200))->toBe(0)
		->and(blackPixels($image, 84, 0, 200, 200))->toBe(0);
});

test("a barcode draws its bars and the interpretation line", function () {
	$image = png("^XA^PW300^LL120^BY2^FO10,10^BCN,50,Y,N,N^FDAB^FS^XZ");

	expect(isBlack($image, 10, 30))->toBeTrue()
		->and(isBlack($image, 11, 30))->toBeTrue()
		->and(isBlack($image, 14, 30))->toBeFalse()
		->and(blackPixels($image, 10, 64, 200, 84))->toBeGreaterThan(20);
});

test("images are drawn dot for dot and magnified", function () {
	$image = png("^XA^PW100^LL100^FO10,10^GFA,2,2,1,FF81^FS^FO50,50^GFA,1,1,1,F0^FS^XZ");
	$magnified = png("~DGR:A.GRF,1,1,F0^XA^PW100^LL100^FO10,10^XGR:A.GRF,3,2^FS^XZ");

	expect(blackPixels($image, 10, 10, 18, 11))->toBe(8)
		->and(blackPixels($image, 10, 11, 18, 12))->toBe(2)
		->and(blackPixels($image, 50, 50, 58, 51))->toBe(4)
		->and(blackPixels($magnified, 10, 10, 34, 12))->toBe(24);
});

test("^POI and ^PMY flip the image", function () {
	$inverted = png("^XA^PW100^LL50^POI^FO0,0^GB10,10,10^FS^XZ");
	$mirrored = png("^XA^PW100^LL50^PMY^FO0,0^GB10,10,10^FS^XZ");

	expect(isBlack($inverted, 95, 45))->toBeTrue()
		->and(isBlack($inverted, 5, 5))->toBeFalse()
		->and(isBlack($mirrored, 95, 5))->toBeTrue()
		->and(isBlack($mirrored, 5, 5))->toBeFalse();
});

test("toPngs gives one image per label and toPng picks one", function () {
	$zpl = "^XA^PW10^LL10^XZ^XA^PW20^LL20^XZ";
	$all = Zpl::toPngs($zpl);

	expect($all)->toHaveCount(2)
		->and(imagesx((imagecreatefromstring(Zpl::toPng($zpl, index: 1)) ?: throw new RuntimeException())))->toBe(20);
});

test("toPng rejects a label index that does not exist", function () {
	Zpl::toPng("^XA^XZ", index: 3);
})->throws(RenderException::class);

test("a missing font file is reported", function () {
	Zpl::toPng("^XA^FO0,0^A0N,20,20^FDx^FS^XZ", new Options(scalableFontFile: "/nowhere/font.ttf"));
})->throws(RenderException::class, "font.ttf");

test("the example labels render to PNG", function (string $file) {
	foreach (Zpl::toPngs((string) file_get_contents($file)) as $png) {
		expect(imagecreatefromstring($png))->not->toBeFalse();
	}
})->with(array_map(fn ($f) => [$f], glob(__DIR__ . "/../../examples/*.zpl") ?: []));
