#!/usr/bin/env php
<?php

/*
 * Render every .zpl file in this folder to a .pdf and a .png next to it.
 * A file with several labels gets one PNG per label: name-1.png, name-2.png, ...
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

	$pngs = ZplRenderer::toPngs($zpl);

	foreach ($pngs as $index => $png) {
		$output = count($pngs) === 1 ? "{$base}.png" : "{$base}-" . ($index + 1) . ".png";
		file_put_contents($output, $png);
		echo basename($output), "\n";
	}
}
