<?php

use Stilling\ZplRenderer\Exceptions\RenderException;
use Stilling\ZplRenderer\Options;
use Stilling\ZplRenderer\ZplRenderer;

/**
 * The PNG inside the first <image> of an SVG, decoded with GD.
 */
function embeddedImage(string $svg): GdImage {
	preg_match('/href="data:image\/png;base64,([^"]+)"/', $svg, $match);
	$image = imagecreatefromstring(base64_decode($match[1] ?? ""));

	if ($image === false) {
		throw new RuntimeException("The SVG holds no PNG image.");
	}

	return $image;
}

/**
 * @return array{int, bool} gray level of a pixel of a palette image, and whether it is transparent
 */
function grayAlpha(GdImage $image, int $x, int $y): array {
	$index = (int) imagecolorat($image, $x, $y);
	$color = imagecolorsforindex($image, $index);

	return [$color["red"], $index === imagecolortransparent($image) || $color["alpha"] === 127];
}

test("the output is one SVG per label, sized in dots and in millimeters", function () {
	$zpl = "^XA^PW100^LL50^XZ^XA^PW200^LL100^XZ";
	$svgs = ZplRenderer::toSvgs($zpl, new Options(dpmm: 8));

	expect($svgs)->toHaveCount(2)
		->and($svgs[0])->toStartWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"")
		->and($svgs[0])->toContain('width="12.5mm" height="6.25mm" viewBox="0 0 100 50"')
		->and($svgs[0])->toContain('<rect width="100" height="50" fill="#fff"/>')
		->and($svgs[0])->toEndWith("</svg>\n")
		->and($svgs[1])->toContain('viewBox="0 0 200 100"')
		->and(ZplRenderer::toSvg($zpl, new Options(dpmm: 8), 1))->toBe($svgs[1]);
});

test("a label index that does not exist throws", function () {
	ZplRenderer::toSvg("^XA^XZ", null, 1);
})->throws(RenderException::class, "no label 1");

test("a solid box is a filled path placed at its origin", function () {
	$svg = ZplRenderer::toSvg("^XA^PW100^LL100^FO10,20^GB30,40,30^FS^XZ");

	expect($svg)->toContain("<g transform=\"matrix(1 0 0 1 10 20)\">\n<path fill-rule=\"evenodd\" d=\"M0 0L30 0L30 40L0 40Z\"/>\n</g>");
});

test("a hollow box cuts its middle out with a second subpath", function () {
	$svg = ZplRenderer::toSvg("^XA^PW100^LL100^FO10,10^GB50,50,5^FS^XZ");

	expect($svg)->toContain('d="M0 0L50 0L50 50L0 50ZM5 5L45 5L45 45L5 45Z"');
});

test("a circle is drawn with curves", function () {
	$svg = ZplRenderer::toSvg("^XA^PW100^LL100^FO10,10^GC40,20^FS^XZ");

	expect($svg)->toContain('d="M40 20C40 31.046')
		->and($svg)->not->toContain("<use");
});

test("a white box is filled white and a reverse field blends by difference", function () {
	$white = ZplRenderer::toSvg("^XA^PW100^LL100^FO20,20^GB20,20,20,W^FS^XZ");
	$reverse = ZplRenderer::toSvg("^XA^PW100^LL100^FO20,20^FR^GB20,20,20^FS^XZ");
	$reverseWhite = ZplRenderer::toSvg("^XA^PW100^LL100^FO20,20^FR^GB20,20,20,W^FS^XZ");

	expect($white)->toContain('<path fill="#fff" fill-rule="evenodd"')
		->and($reverse)->toContain("<g transform=\"matrix(1 0 0 1 20 20)\" fill=\"#fff\" style=\"mix-blend-mode:difference\">\n<path fill-rule=\"evenodd\"")
		->and($reverseWhite)->toContain("style=\"mix-blend-mode:difference\">\n<path fill-rule=\"evenodd\"");
});

test("text defines every glyph once and reuses it", function () {
	$svg = ZplRenderer::toSvg("^XA^PW300^LL100^FO20,20^A0N,40,40^FDHello^FS^XZ");
	preg_match('/<defs>\n(.*)\n<\/defs>/s', $svg, $defs);

	expect(substr_count($defs[1] ?? "", "<path id=\"g"))->toBe(4)
		->and($defs[1] ?? "")->toContain('<path id="g0" d="M')
		->and(substr_count($svg, "<use href=\"#g"))->toBe(5)
		->and(substr_count($svg, "<use href=\"#g2\""))->toBe(2)
		->and($svg)->toMatch('/<use href="#g0" transform="matrix\([\d.]+ 0 0 -[\d.]+ 0 [\d.]+\)"\/>/');
});

test("a space advances the pen without a glyph", function () {
	$svg = ZplRenderer::toSvg("^XA^PW300^LL100^FO20,20^A0N,40,40^FDa b^FS^XZ");
	preg_match_all('/<use href="#g\d+" transform="matrix\([\d.]+ 0 0 -[\d.]+ ([\d.]+) /', $svg, $uses);

	expect($uses[1])->toHaveCount(2)
		->and((float) $uses[1][1] - (float) $uses[1][0])->toBeGreaterThan(20);
});

test("a rotated field carries its rotation in the group transform", function () {
	$svg = ZplRenderer::toSvg("^XA^PW100^LL100^FO10,10^A0R,20,20^FDx^FS^XZ");

	expect($svg)->toMatch('/<g transform="matrix\(0 1 -1 0 [\d.]+ 10\)">/');
});

test("a mirrored or an inverted label flips the whole drawing", function () {
	$mirrored = ZplRenderer::toSvg("^XA^PW100^LL50^PMY^XZ");
	$inverted = ZplRenderer::toSvg("^XA^PW100^LL50^POI^XZ");
	$both = ZplRenderer::toSvg("^XA^PW100^LL50^PMY^POI^XZ");
	$plain = ZplRenderer::toSvg("^XA^PW100^LL50^XZ");

	expect($mirrored)->toContain('<g transform="matrix(-1 0 0 1 100 0)" fill="#000">')
		->and($inverted)->toContain('<g transform="matrix(-1 0 0 -1 100 50)" fill="#000">')
		->and($both)->toContain('<g transform="matrix(1 0 0 -1 0 50)" fill="#000">')
		->and($plain)->toContain('<g fill="#000">');
});

test("a barcode is one crisp path of bars with its interpretation line", function () {
	$bars = ZplRenderer::toSvg("^XA^PW300^LL100^FO10,10^BY2^BCN,50,N,N,N^FD123^FS^XZ");
	$withText = ZplRenderer::toSvg("^XA^PW300^LL100^FO10,10^BY2^BCN,50,Y,N,N^FD123^FS^XZ");

	expect($bars)->toContain('<path shape-rendering="crispEdges" d="M0 0h4v50h-4ZM6 0h2v50h-2Z')
		->and($bars)->not->toContain("<use")
		->and($withText)->toContain('<path shape-rendering="crispEdges" d="M0 0h4v50h-4Z')
		->and(substr_count($withText, "<use href=\"#g"))->toBe(3);
});

test("an image is an embedded PNG with transparent paper, black or white ink", function () {
	$svg = ZplRenderer::toSvg("^XA^PW100^LL100^FO10,10^GFA,4,4,1,80402010^FS^XZ");
	$reverse = ZplRenderer::toSvg("^XA^PW100^LL100^FO10,10^FR^GFA,4,4,1,80402010^FS^XZ");
	$image = embeddedImage($svg);
	$reverseImage = embeddedImage($reverse);

	expect($svg)->toContain("<g transform=\"matrix(1 0 0 1 10 10)\">\n<image width=\"8\" height=\"4\" preserveAspectRatio=\"none\" style=\"image-rendering:pixelated\" href=\"data:image/png;base64,")
		->and(imagesx($image))->toBe(8)
		->and(imagesy($image))->toBe(4)
		->and(grayAlpha($image, 0, 0))->toBe([0, false])
		->and(grayAlpha($image, 1, 1))->toBe([0, false])
		->and(grayAlpha($image, 1, 0)[1])->toBeTrue()
		->and(grayAlpha($image, 7, 3)[1])->toBeTrue()
		->and(grayAlpha($reverseImage, 0, 0))->toBe([255, false])
		->and(grayAlpha($reverseImage, 1, 0)[1])->toBeTrue();
});
