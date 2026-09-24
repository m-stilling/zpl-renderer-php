<?php

namespace Stilling\ZplRenderer;

use Stilling\ZplRenderer\Graphics\Silencer;

/**
 * The command behind bin/zpl.
 */
class Cli {
	private const string USAGE = <<<TEXT
		Usage: zpl <input> <format> [output] [options]

		  input     Path to a ZPL file, or - for standard input.
		  format    Output format: pdf or png
		  output    Path to write. Defaults to the input name with the new
		            extension, or standard output when the input is -.
		            A PNG is one image per label; with several labels the
		            files are numbered: name-1.png, name-2.png, ...

		Options:
		  --dpmm=<6|8|12|24>   Print density in dots per millimeter (default 8)
		  --width=<mm>         Label width in millimeters (default 101.6)
		  --height=<mm>        Label height in millimeters (default 152.4)
		  --size=<w>x<h>       Label size in inches, for example 4x6
		  --scale=<1-8>        Pixels per dot in PNG output (default 1)
		  --no-antialias       Draw PNG text in black and white only, like the printer
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
		$format = strtolower($format);
		$output = $positional[2] ?? null;

		if ($format !== "pdf" && $format !== "png") {
			fwrite($stderr, "Unsupported format \"{$format}\". Supported: pdf, png\n");

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

		$kind = $this->binaryKind($zpl);

		if ($kind !== null) {
			fwrite($stderr, "{$input} is {$kind}, not ZPL.\n");

			return 65;
		}

		try {
			$outputs = $format === "pdf" ? [ZplRenderer::toPdf($zpl, $options)] : ZplRenderer::toPngs($zpl, $options);
		} catch (\Throwable $exception) {
			fwrite($stderr, "Error: " . $exception->getMessage() . "\n");

			return 65;
		}

		if ($outputs === []) {
			fwrite($stderr, "The input holds no label.\n");

			return 65;
		}

		if ($output === null && $input !== "-") {
			$output = preg_replace('/\.[^.\/\\\\]+$/', "", $input) . ".{$format}";
		}

		if ($output === null || $output === "-") {
			fwrite($stdout, $outputs[0]);

			if (count($outputs) > 1) {
				fwrite($stderr, "Only the first of " . count($outputs) . " labels was written to standard output.\n");
			}

			return 0;
		}

		$files = count($outputs) === 1 ? [$output] : array_map(
			fn (int $index): string => preg_replace('/(\.[^.\/\\\\]+)?$/', "-" . ($index + 1) . '$1', $output, 1) ?? $output,
			array_keys($outputs),
		);

		if (!isset($flags["overwrite"])) {
			foreach ($files as $file) {
				if (file_exists($file)) {
					fwrite($stderr, "{$file} exists. Pass --overwrite to replace it.\n");

					return 73;
				}
			}
		}

		foreach ($files as $index => $file) {
			if (Silencer::call(fn () => file_put_contents($file, $outputs[$index])) === null) {
				fwrite($stderr, "Cannot write {$file}\n");

				return 73;
			}

			fwrite($stderr, "Wrote {$file}\n");
		}

		return 0;
	}

	/**
	 * What a non-text input looks like, or null when it can be ZPL.
	 */
	private function binaryKind(string $data): ?string {
		if (str_starts_with($data, "%PDF")) {
			return "a PDF file";
		}

		if (str_starts_with($data, "\x89PNG")) {
			return "a PNG image";
		}

		return str_contains($data, "\0") ? "a binary file" : null;
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
			pixelsPerDot: isset($flags["scale"]) ? (int) $flags["scale"] : 1,
			antialias: !isset($flags["no-antialias"]),
		);
	}
}
