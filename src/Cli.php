<?php

namespace Stilling\Zpl;

use Stilling\Zpl\Graphics\Silencer;

/**
 * The command behind bin/zpl.
 */
class Cli {
	private const string USAGE = <<<TEXT
		Usage: zpl <input> <format> [output] [options]

		  input     Path to a ZPL file, or - for standard input.
		  format    Output format. Supported: pdf
		  output    Path to write. Defaults to the input name with the new
		            extension, or standard output when the input is -.

		Options:
		  --dpmm=<6|8|12|24>   Print density in dots per millimeter (default 8)
		  --width=<mm>         Label width in millimeters (default 101.6)
		  --height=<mm>        Label height in millimeters (default 152.4)
		  --size=<w>x<h>       Label size in inches, for example 4x6
		  --ignore-label-size  Ignore ^PW and ^LL in the label
		  --single             Ignore ^PQ and render each label once
		  --overwrite          Replace the output file when it exists
		  -h, --help           Show this help
		TEXT;

	/**
	 * @param list<string> $argv
	 * @param resource $stdout
	 * @param resource $stderr
	 */
	public function run(array $argv, $stdout, $stderr): int {
		$positional = [];
		$flags = [];

		foreach (array_slice($argv, 1) as $arg) {
			if ($arg === "-h" || $arg === "--help") {
				fwrite($stdout, self::USAGE . "\n");

				return 0;
			}

			if (str_starts_with($arg, "--")) {
				[$name, $value] = array_pad(explode("=", substr($arg, 2), 2), 2, "");
				$flags[$name] = $value;
			} else {
				$positional[] = $arg;
			}
		}

		if (count($positional) < 2) {
			fwrite($stderr, self::USAGE . "\n");

			return 64;
		}

		[$input, $format] = $positional;
		$output = $positional[2] ?? null;

		if (strtolower($format) !== "pdf") {
			fwrite($stderr, "Unsupported format \"{$format}\". Supported: pdf\n");

			return 64;
		}

		try {
			$options = $this->options($flags);
		} catch (\InvalidArgumentException $exception) {
			fwrite($stderr, $exception->getMessage() . "\n");

			return 64;
		}

		$zpl = $input === "-" ? stream_get_contents(STDIN) : (is_file($input) ? file_get_contents($input) : false);

		if ($zpl === false) {
			fwrite($stderr, "Cannot read {$input}\n");

			return 66;
		}

		try {
			$pdf = Zpl::toPdf($zpl, $options);
		} catch (\Throwable $exception) {
			fwrite($stderr, "Error: " . $exception->getMessage() . "\n");

			return 65;
		}

		if ($output === null && $input !== "-") {
			$output = preg_replace('/\.[^.\/\\\\]+$/', "", $input) . ".pdf";
		}

		if ($output === null || $output === "-") {
			fwrite($stdout, $pdf);

			return 0;
		}

		if (file_exists($output) && !isset($flags["overwrite"])) {
			fwrite($stderr, "{$output} exists. Pass --overwrite to replace it.\n");

			return 73;
		}

		if (Silencer::call(fn () => file_put_contents($output, $pdf)) === null) {
			fwrite($stderr, "Cannot write {$output}\n");

			return 73;
		}

		fwrite($stderr, "Wrote {$output}\n");

		return 0;
	}

	/**
	 * @param array<string, string> $flags
	 */
	private function options(array $flags): Options {
		$dpmm = isset($flags["dpmm"]) ? (int) $flags["dpmm"] : 8;
		$width = isset($flags["width"]) ? (float) $flags["width"] : 101.6;
		$height = isset($flags["height"]) ? (float) $flags["height"] : 152.4;

		if (isset($flags["size"])) {
			if (preg_match('/^(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)$/i', $flags["size"], $match) !== 1) {
				throw new \InvalidArgumentException("--size expects <width>x<height> in inches, for example 4x6.");
			}

			$width = (float) $match[1] * 25.4;
			$height = (float) $match[2] * 25.4;
		}

		return new Options(
			dpmm: $dpmm,
			widthMm: $width,
			heightMm: $height,
			honorLabelSize: !isset($flags["ignore-label-size"]),
			honorQuantity: !isset($flags["single"]),
		);
	}
}
