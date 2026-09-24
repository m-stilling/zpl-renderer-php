<?php

namespace Stilling\Zpl\Renderer;

use Stilling\Zpl\Font\Encoding;
use Stilling\Zpl\Font\ResolvedFont;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;

/**
 * Breaks the text of a field into positioned lines, in dots, with the top-left
 * of the text at (0, 0).
 */
class TextLayout {
	/**
	 * @param list<Line> $lines
	 */
	public function __construct(
		public readonly array $lines,
		public readonly float $width,
		public readonly float $height,
		/** Distance from the top to the first baseline. */
		public readonly float $ascent,
	) {
	}

	public static function layout(TextElement $element, ResolvedFont $font): self {
		$block = $element->block;
		$winAnsi = Encoding::toWinAnsi($font->uppercase ? mb_strtoupper($element->text) : $element->text);
		$paragraphs = explode("\n", $winAnsi);

		if ($block === null || $block->width <= 0) {
			return self::plain($paragraphs, $font);
		}

		$lines = [];
		$step = $font->lineHeight + $block->lineSpacing;
		$width = $block->width;

		foreach ($paragraphs as $paragraph) {
			foreach (self::wrap($paragraph, $font, $width, $block->hangingIndent) as $index => $text) {
				$indent = count($lines) === 0 ? 0 : $block->hangingIndent;
				$available = $width - $indent;
				$lineWidth = $font->width($text);
				$lastOfParagraph = $index === array_key_last(self::wrap($paragraph, $font, $width, $block->hangingIndent));
				$x = $indent;
				$wordSpacing = 0.0;

				switch ($block->justification) {
					case TextJustification::Center:
						$x = $indent + ($available - $lineWidth) / 2;
						break;
					case TextJustification::Right:
						$x = $indent + $available - $lineWidth;
						break;
					case TextJustification::Justified:
						$spaces = substr_count($text, " ");

						if (!$lastOfParagraph && $spaces > 0) {
							$wordSpacing = ($available - $lineWidth) / $spaces;
						}

						break;
					case TextJustification::Left:
						break;
				}

				$lines[] = new Line($text, $x, $font->ascent + count($lines) * $step, $wordSpacing);
			}
		}

		$lines = array_slice($lines, 0, $block->maxLines);
		$count = count($lines);

		return new self($lines, $width, $count === 0 ? 0 : ($count - 1) * $step + $font->lineHeight, $font->ascent);
	}

	/**
	 * @param list<string> $paragraphs
	 */
	private static function plain(array $paragraphs, ResolvedFont $font): self {
		$lines = [];
		$width = 0.0;

		foreach ($paragraphs as $index => $text) {
			$lines[] = new Line($text, 0, $font->ascent + $index * $font->lineHeight);
			$width = max($width, $font->width($text));
		}

		return new self($lines, $width, count($lines) * $font->lineHeight, $font->ascent);
	}

	/**
	 * Greedy word wrap. A word longer than a line is broken between characters.
	 *
	 * @return list<string>
	 */
	private static function wrap(string $paragraph, ResolvedFont $font, int $width, int $hangingIndent): array {
		$lines = [];
		$current = "";

		foreach (explode(" ", $paragraph) as $word) {
			$available = $width - ($lines === [] ? 0 : $hangingIndent);
			$candidate = $current === "" ? $word : "{$current} {$word}";

			if ($font->width($candidate) <= $available || $current === "" && $font->width($word) <= $available) {
				$current = $candidate;
				continue;
			}

			if ($current !== "") {
				$lines[] = $current;
				$current = "";
				$available = $width - $hangingIndent;
			}

			while ($font->width($word) > $available && strlen($word) > 1) {
				$fit = 1;

				while ($fit < strlen($word) && $font->width(substr($word, 0, $fit + 1)) <= $available) {
					$fit++;
				}

				$lines[] = substr($word, 0, $fit);
				$word = substr($word, $fit);
			}

			$current = $word;
		}

		$lines[] = $current;

		return $lines;
	}
}
