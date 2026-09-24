#!/usr/bin/env php
<?php

/*
 * Render every .zpl file in this folder to a .pdf, a .svg and a .png next to it.
 * A file with several labels gets one SVG and one PNG per label: name-1.png, name-2.png, ...
 *
 * Run it with:  composer examples
 */

require __DIR__ . "/../vendor/autoload.php";

use Stilling\ZplRenderer\ZplRenderer;

foreach (glob(__DIR__ . "/*.zpl") ?: [] as $file) {
	$base = substr($file, 0, -4);
	$zpl = (string) file_get_contents($file);

	file_put_contents("{$base}.pdf", ZplRenderer::toPdf($zpl));
	echo basename($base), ".pdf\n";

	foreach (["svg" => ZplRenderer::toSvgs($zpl), "png" => ZplRenderer::toPngs($zpl)] as $extension => $images) {
		foreach ($images as $index => $image) {
			$output = count($images) === 1 ? "{$base}.{$extension}" : "{$base}-" . ($index + 1) . ".{$extension}";
			file_put_contents($output, $image);
			echo basename($output), "\n";
		}
	}
}
