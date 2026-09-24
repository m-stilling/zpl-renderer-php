#!/usr/bin/env php
<?php

/*
 * Render every .zpl file in this folder to a .pdf next to it.
 *
 * Run it with:  composer examples
 */

require __DIR__ . "/../vendor/autoload.php";

use Stilling\Zpl\Zpl;

foreach (glob(__DIR__ . "/*.zpl") ?: [] as $file) {
	$output = substr($file, 0, -4) . ".pdf";
	file_put_contents($output, Zpl::toPdf((string) file_get_contents($file)));
	echo basename($output), "\n";
}
