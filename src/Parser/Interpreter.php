<?php

namespace Stilling\Zpl\Parser;

use Stilling\Zpl\Barcode\BarcodeFactory;
use Stilling\Zpl\Barcode\BarcodeMatrix;
use Stilling\Zpl\Barcode\Code128;
use Stilling\Zpl\Barcode\Code128Mode;
use Stilling\Zpl\Exceptions\UnsupportedException;
use Stilling\Zpl\Font\Encoding;
use Stilling\Zpl\Font\ZebraFont;
use Stilling\Zpl\Graphics\Bitmap;
use Stilling\Zpl\Graphics\GraphicDecoder;
use Stilling\Zpl\Graphics\GraphicStore;
use Stilling\Zpl\Graphics\PngDecoder;
use Stilling\Zpl\Graphics\Silencer;
use Stilling\Zpl\Model\BarcodeElement;
use Stilling\Zpl\Model\BoxElement;
use Stilling\Zpl\Model\CircleElement;
use Stilling\Zpl\Model\DiagonalElement;
use Stilling\Zpl\Model\Element;
use Stilling\Zpl\Model\EllipseElement;
use Stilling\Zpl\Model\FieldBlock;
use Stilling\Zpl\Model\FontSpec;
use Stilling\Zpl\Model\ImageElement;
use Stilling\Zpl\Model\Justification;
use Stilling\Zpl\Model\Label;
use Stilling\Zpl\Model\Orientation;
use Stilling\Zpl\Model\TextElement;
use Stilling\Zpl\Model\TextJustification;
use Stilling\Zpl\Options;

/**
 * Walks the command stream and builds one Label per ^XA ... ^XZ format.
 *
 * Printer state that ZPL keeps between formats (downloaded graphics, stored
 * formats, the current font, the character set) is kept for the whole stream.
 */
class Interpreter {
	/** tc-lib-barcode emits every PDF417 row as three module rows. */
	private const int PDF417_UNITS_PER_ROW = 3;

	private GraphicStore $graphics;

	private GraphicDecoder $graphicDecoder;

	private BarcodeFactory $barcodes;

	private Code128 $code128;

	/** How deep ^XF recalls nest before a further ^XF is ignored. */
	private const int MAX_RECALL_DEPTH = 8;

	/** @var array<string, list<Command>> formats stored with ^DF */
	private array $storedFormats = [];

	/** @var array<string, true> names of the stored formats being recalled, outermost first */
	private array $openRecalls = [];

	private int $characterSet = 0;

	private FontSpec $font;

	private ?Orientation $fontOrientation = null;

	private Orientation $defaultOrientation = Orientation::Normal;

	private Justification $defaultJustification = Justification::Left;

	private int $barModuleWidth = 2;

	private int $barHeight = 10;

	private int $labelWidth = 0;

	private int $labelHeight = 0;

	/** The ^LL height for the labels that follow; a ^LL after the first ^FS sets only this one. */
	private int $nextLabelHeight = 0;

	private bool $fieldSeparated = false;

	private int $homeX = 0;

	private int $homeY = 0;

	private int $labelTop = 0;

	private int $labelShift = 0;

	private bool $reversed = false;

	private bool $inverted = false;

	private bool $mirrored = false;

	private int $quantity = 1;

	/** @var list<Element> */
	private array $elements = [];

	private FieldState $field;

	/** @var array<int, string> ^FN values given in a format that recalls a stored one */
	private array $fieldValues = [];

	private bool $recalling = false;

	public function __construct(
		private readonly Options $options,
	) {
		$this->graphics = new GraphicStore();
		$this->graphicDecoder = new GraphicDecoder($this->options->getMaxGraphicBytes());
		$this->barcodes = new BarcodeFactory();
		$this->code128 = new Code128();
		$this->font = new FontSpec(ZebraFont::DEFAULT_FONT, 9, 5);
		$this->field = new FieldState();
		$this->labelWidth = $this->options->widthDots();
		$this->nextLabelHeight = $this->options->heightDots();
	}

	/**
	 * @param list<Command> $commands
	 * @return list<Label>
	 */
	public function interpret(array $commands): array {
		$labels = [];
		$current = null;

		foreach ($commands as $command) {
			if ($command->is("XA")) {
				$current = [];
				continue;
			}

			if ($command->is("XZ")) {
				if ($current !== null) {
					$label = $this->interpretLabel($current);

					if ($label !== null) {
						$labels[] = $label;
					}
				}

				$current = null;
				continue;
			}

			if ($current === null) {
				$this->applyGlobal($command);
				continue;
			}

			$current[] = $command;
		}

		if ($current !== null && $current !== []) {
			$label = $this->interpretLabel($current);

			if ($label !== null) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Commands that work outside a format too, such as ~DG.
	 */
	private function applyGlobal(Command $command): void {
		match ($command->name) {
			"DG" => $this->downloadGraphic($command),
			"DY" => $this->downloadObject($command),
			"CI" => $this->characterSet = $command->int(0, 0),
			default => null,
		};
	}

	/**
	 * @param list<Command> $commands
	 */
	private function interpretLabel(array $commands): ?Label {
		$store = $this->storedFormatName($commands);

		if ($store !== null) {
			$this->storedFormats[$store] = $commands;

			return null;
		}

		$this->resetLabel();
		$this->fieldValues = $this->collectFieldValues($commands);
		$this->recalling = $this->hasRecall($commands);

		foreach ($commands as $command) {
			$this->apply($command);
		}

		$this->flushField();

		return new Label(
			width: $this->labelWidth,
			height: $this->labelHeight,
			dpmm: $this->options->getDpmm(),
			elements: $this->elements,
			quantity: $this->quantity,
			inverted: $this->inverted,
			mirrored: $this->mirrored,
		);
	}

	/**
	 * Start a new format. The printer settings (^PW, ^LL, ^LH, ^LS, ^LT, ^LR, ^PO, ^PM, ^CF, ^FW, ^BY, ^CI)
	 * stay as the earlier formats left them.
	 */
	private function resetLabel(): void {
		$this->labelHeight = $this->nextLabelHeight;
		$this->fieldSeparated = false;
		$this->quantity = 1;
		$this->elements = [];
		$this->field = new FieldState();
		$this->fieldValues = [];
		$this->recalling = false;
	}

	/**
	 * @param list<Command> $commands
	 */
	private function storedFormatName(array $commands): ?string {
		foreach ($commands as $command) {
			if ($command->is("DF")) {
				return GraphicStore::normalize($command->arg(0));
			}
		}

		return null;
	}

	/**
	 * @param list<Command> $commands
	 */
	private function hasRecall(array $commands): bool {
		foreach ($commands as $command) {
			if ($command->is("XF")) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The ^FN n ... ^FD value pairs of a format, used to fill a recalled stored format.
	 *
	 * @param list<Command> $commands
	 * @return array<int, string>
	 */
	private function collectFieldValues(array $commands): array {
		$values = [];
		$number = null;

		foreach ($commands as $command) {
			if ($command->is("FN")) {
				$number = $command->int(0, 0);
			} elseif ($command->is("FD", "FV") && $number !== null) {
				$values[$number] = $command->params;
			} elseif ($command->is("FS")) {
				$number = null;
			}
		}

		return $values;
	}

	private function apply(Command $command): void {
		match ($command->name) {
			"FO", "FT" => $this->fieldOrigin($command),
			"FS" => $this->fieldSeparator(),
			"FD", "FV" => $this->field->data = $command->params,
			"SN" => $this->field->data = $command->arg(0, "0"),
			"FN" => $this->field->number = $command->int(0, 0),
			"FR" => $this->field->reverse = true,
			"FH" => $this->field->hexIndicator = $command->arg(0, "_")[0],
			"FB" => $this->fieldBlock($command),
			"FX", "FP", "FM", "FL", "TB" => null,
			"A" => $this->fontA($command),
			"A@" => $this->fontAt($command),
			"CF" => $this->changeFont($command),
			"CI" => $this->characterSet = $command->int(0, 0),
			"FW" => $this->fieldOrientation($command),
			"PW" => $this->printWidth($command),
			"LL" => $this->labelLength($command),
			"LH" => $this->labelHome($command),
			"LT" => $this->labelTop = $command->int(0, 0),
			"LS" => $this->labelShift = $command->int(0, 0),
			"LR" => $this->reversed = $command->bool(0, false),
			"PO" => $this->inverted = $command->char(0, "N") === "I",
			"PM" => $this->mirrored = $command->bool(0, false),
			"PQ" => $this->quantity = max(1, $command->int(0, 1)),
			"BY" => $this->barcodeDefaults($command),
			"GB" => $this->graphicBox($command),
			"GC" => $this->graphicCircle($command),
			"GD" => $this->graphicDiagonal($command),
			"GE" => $this->graphicEllipse($command),
			"GF" => $this->graphicField($command),
			"XG", "IM" => $this->recallGraphic($command),
			"DG" => $this->downloadGraphic($command),
			"DY" => $this->downloadObject($command),
			"XF" => $this->recallFormat($command),
			"BC" => $this->code128($command),
			"B3" => $this->linear($command, "C39", modulo43: 1, heightIndex: 2, textIndex: 3, aboveIndex: 4),
			"BA" => $this->linear($command, "C93", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"BE" => $this->linear($command, "EAN13", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"B8" => $this->linear($command, "EAN8", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"BU" => $this->linear($command, "UPCA", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"B9" => $this->linear($command, "UPCE", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"B2" => $this->linear($command, "I25", heightIndex: 1, textIndex: 2, aboveIndex: 3, checkIndex: 4),
			"BI" => $this->linear($command, "S25", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"BJ" => $this->linear($command, "S25", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"BK" => $this->linear($command, "CODABAR", heightIndex: 2, textIndex: 3, aboveIndex: 4),
			"BM" => $this->linear($command, "MSI", heightIndex: 2, textIndex: 3, aboveIndex: 4, checkIndex: 1),
			"BL" => $this->linear($command, "LOGMARS", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"BP" => $this->linear($command, "PLESSEY", heightIndex: 2, textIndex: 3, aboveIndex: 4),
			"B1" => $this->linear($command, "CODE11", heightIndex: 2, textIndex: 3, aboveIndex: 4),
			"BZ" => $this->linear($command, "POSTNET", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"B5" => $this->linear($command, "PLANET", heightIndex: 1, textIndex: 2, aboveIndex: 3),
			"BS" => $this->upcExtension($command),
			"BR" => $this->databar($command),
			"B4" => $this->linear($command, "C49", heightIndex: 1, textIndex: 2, aboveIndex: -1),
			"BQ" => $this->qrCode($command),
			"BX" => $this->dataMatrix($command),
			"B7" => $this->pdf417($command),
			"BO", "B0" => $this->aztec($command),
			"BB", "BD", "BT", "BF", "BW" => throw new UnsupportedException("Barcode command ^{$command->name} is not supported."),
			default => null,
		};
	}

	private function fieldOrigin(Command $command): void {
		$this->flushField();
		$this->field->positioned = true;
		$this->field->x = $command->int(0, 0);
		$this->field->y = $command->int(1, 0);
		$this->field->typeset = $command->is("FT");
		$this->field->justification = $command->arg(2) === "" ? null : Justification::fromZpl($command->arg(2));
	}

	private function fieldBlock(Command $command): void {
		$this->field->block = new FieldBlock(
			width: $command->int(0, 0),
			maxLines: max(1, $command->int(1, 1)),
			lineSpacing: $command->int(2, 0),
			justification: TextJustification::fromZpl($command->arg(3, "L")),
			hangingIndent: $command->int(4, 0),
		);
	}

	private function fontA(Command $command): void {
		$first = $command->arg(0);
		$id = $first === "" ? $this->font->id : strtoupper($first[0]);
		$this->fontOrientation = strlen($first) > 1 ? Orientation::fromZpl($first[1], $this->defaultOrientation) : null;
		$height = $command->int(1, $this->font->height);
		$width = $command->int(2, 0);

		if ($id !== strtoupper($this->font->id) && $command->arg(1) === "") {
			$height = ZebraFont::defaultHeight($id);
		}

		$this->font = new FontSpec($id, $height, $width);
	}

	private function fontAt(Command $command): void {
		$this->fontOrientation = $command->arg(0) === "" ? null : Orientation::fromZpl($command->arg(0), $this->defaultOrientation);
		$this->font = new FontSpec("0", $command->int(1, 15), $command->int(2, 0));
	}

	private function changeFont(Command $command): void {
		$first = $command->arg(0);
		$id = $first === "" ? "A" : strtoupper($first[0]);
		$this->font = new FontSpec($id, $command->int(1, ZebraFont::defaultHeight($id)), $command->int(2, 0));
		$this->fontOrientation = null;
	}

	private function fieldOrientation(Command $command): void {
		$this->defaultOrientation = Orientation::fromZpl($command->arg(0, "N"));
		$this->defaultJustification = Justification::fromZpl($command->arg(1, "0"));
	}

	private function printWidth(Command $command): void {
		if ($this->options->getHonorLabelSize() && $command->int(0, 0) > 0) {
			$this->labelWidth = $command->int(0, 0);
		}
	}

	private function labelLength(Command $command): void {
		if (!$this->options->getHonorLabelSize() || $command->int(0, 0) <= 0) {
			return;
		}

		$this->nextLabelHeight = $command->int(0, 0);

		if (!$this->fieldSeparated) {
			$this->labelHeight = $this->nextLabelHeight;
		}
	}

	private function labelHome(Command $command): void {
		$this->homeX = $command->int(0, 0);
		$this->homeY = $command->int(1, 0);
	}

	private function barcodeDefaults(Command $command): void {
		$this->barModuleWidth = max(1, min(10, $command->int(0, 2)));
		$this->barHeight = max(1, $command->int(2, 10));
	}

	private function graphicBox(Command $command): void {
		$thickness = max(1, $command->int(2, 1));
		$width = max($thickness, $command->int(0, $thickness));
		$height = max($thickness, $command->int(1, $thickness));
		$black = $command->char(3, "B") !== "W";
		$rounding = max(0, min(8, $command->int(4, 0)));

		$this->field->shape = fn (): Element => new BoxElement(
			...$this->origin(),
			width: $width,
			height: $height,
			thickness: $thickness,
			rounding: $rounding,
			black: $black,
		);
	}

	private function graphicCircle(Command $command): void {
		$diameter = max(3, $command->int(0, 3));
		$thickness = max(1, $command->int(1, 1));
		$black = $command->char(2, "B") !== "W";

		$this->field->shape = fn (): Element => new CircleElement(
			...$this->origin(),
			diameter: $diameter,
			thickness: $thickness,
			black: $black,
		);
	}

	private function graphicDiagonal(Command $command): void {
		$width = max(3, $command->int(0, 3));
		$height = max(3, $command->int(1, 3));
		$thickness = max(1, $command->int(2, 1));
		$black = $command->char(3, "B") !== "W";
		$rightLeaning = $command->char(4, "R") !== "L";

		$this->field->shape = fn (): Element => new DiagonalElement(
			...$this->origin(),
			width: $width,
			height: $height,
			thickness: $thickness,
			black: $black,
			rightLeaning: $rightLeaning,
		);
	}

	private function graphicEllipse(Command $command): void {
		$width = max(3, $command->int(0, 3));
		$height = max(3, $command->int(1, 3));
		$thickness = max(1, $command->int(2, 1));
		$black = $command->char(3, "B") !== "W";

		$this->field->shape = fn (): Element => new EllipseElement(
			...$this->origin(),
			width: $width,
			height: $height,
			thickness: $thickness,
			black: $black,
		);
	}

	private function graphicField(Command $command): void {
		$args = $command->argsLimited(5);
		$format = strtoupper($args[0] ?? "A");
		$total = (int) ($args[2] ?? 0);
		$bytesPerRow = (int) ($args[3] ?? 0);
		$data = $args[4] ?? "";

		if ($format === "B" || $format === "C") {
			throw new UnsupportedException("^GF with binary data (format {$format}) is not supported; use ASCII hex, :B64: or :Z64:.");
		}

		if ($bytesPerRow < 1) {
			return;
		}

		$bitmap = $this->graphicDecoder->decode($data, $bytesPerRow, $total);
		$this->field->shape = fn (): Element => new ImageElement(...$this->origin(), bitmap: $bitmap);
	}

	private function downloadGraphic(Command $command): void {
		$args = $command->argsLimited(4);
		$name = $args[0] ?? "";
		$total = (int) ($args[1] ?? 0);
		$bytesPerRow = (int) ($args[2] ?? 0);

		if ($name === "" || $bytesPerRow < 1) {
			return;
		}

		$this->graphics->put($name, $this->graphicDecoder->decode($args[3] ?? "", $bytesPerRow, $total));
	}

	private function downloadObject(Command $command): void {
		$args = $command->argsLimited(6);
		$name = $args[0] ?? "";
		$extension = strtoupper($args[2] ?? "G");
		$total = (int) ($args[3] ?? 0);
		$bytesPerRow = (int) ($args[4] ?? 0);
		$data = $args[5] ?? "";

		if ($name === "") {
			return;
		}

		if ($extension === "G" || $extension === "GRF") {
			if ($bytesPerRow < 1) {
				return;
			}

			$this->graphics->put($name, $this->graphicDecoder->decode($data, $bytesPerRow, $total));

			return;
		}

		$this->graphics->put($name, (new PngDecoder())->decode($this->objectBytes($data)));
	}

	/**
	 * ~DY image data is base64 (:B64:), zlib+base64 (:Z64:) or ASCII hex.
	 */
	private function objectBytes(string $data): string {
		$data = trim($data);

		if (preg_match('/^:([BZ])64:([A-Za-z0-9+\/=\s]*):[0-9A-Fa-f]{4}$/s', $data, $match) === 1) {
			$binary = base64_decode(preg_replace('/\s+/', "", $match[2]) ?? "", true);

			if ($binary === false) {
				throw new UnsupportedException("Invalid base64 data in ~DY.");
			}

			if ($match[1] === "Z") {
				return Silencer::call(fn () => gzuncompress($binary)) ?? throw new UnsupportedException("Invalid zlib data in ~DY.");
			}

			return $binary;
		}

		if (ctype_xdigit($data) && strlen($data) % 2 === 0) {
			return (string) hex2bin($data);
		}

		return $data;
	}

	private function recallGraphic(Command $command): void {
		$bitmap = $this->graphics->get($command->arg(0));

		if ($bitmap === null) {
			return;
		}

		$magX = $command->is("XG") ? max(1, min(10, $command->int(1, 1))) : 1;
		$magY = $command->is("XG") ? max(1, min(10, $command->int(2, $magX))) : 1;

		$this->field->shape = fn (): Element => new ImageElement(
			...$this->origin(),
			bitmap: $bitmap,
			magnificationX: $magX,
			magnificationY: $magY,
		);
	}

	/**
	 * A format that is already being recalled, directly or through other
	 * formats, is not recalled again, and recalls do not nest deeper than
	 * MAX_RECALL_DEPTH. Both would otherwise loop forever or grow exponentially.
	 */
	private function recallFormat(Command $command): void {
		$name = GraphicStore::normalize($command->arg(0));
		$stored = $this->storedFormats[$name] ?? null;

		if ($stored === null || isset($this->openRecalls[$name]) || count($this->openRecalls) >= self::MAX_RECALL_DEPTH) {
			return;
		}

		$this->openRecalls[$name] = true;
		$this->flushField();
		$recalling = $this->recalling;
		$this->recalling = false;

		try {
			foreach ($stored as $storedCommand) {
				if (!$storedCommand->is("DF")) {
					$this->apply($storedCommand);
				}
			}
		} finally {
			unset($this->openRecalls[$name]);
		}

		$this->flushField();
		$this->recalling = $recalling;
	}

	private function code128(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$height = max(1, $command->int(1, $this->barHeight));
		$printText = $command->bool(2, true);
		$above = $command->bool(3, false);
		$checkDigit = $command->bool(4, false);
		$mode = Code128Mode::fromZpl($command->arg(5, "N"));

		$this->field->barcode = function (string $data) use ($orientation, $height, $printText, $above, $checkDigit, $mode): Element {
			[$matrix, $text] = $this->code128->encode($data, $mode, $checkDigit);

			return $this->linearElement($matrix, $height, $printText ? $text : null, $above, $orientation);
		};
	}

	/**
	 * A linear symbology encoded by tc-lib-barcode. Indexes point at the ^B
	 * parameters that hold the bar height, the interpretation-line flags and
	 * the check-digit flag; -1 means the command has no such parameter.
	 */
	private function linear(
		Command $command,
		string $type,
		int $heightIndex,
		int $textIndex,
		int $aboveIndex,
		int $checkIndex = -1,
		int $modulo43 = -1,
	): void {
		$orientation = $this->orientation($command->arg(0));
		$height = max(1, $command->int($heightIndex, $this->barHeight));
		$printText = $textIndex >= 0 && $command->bool($textIndex, true);
		$above = $aboveIndex >= 0 && $command->bool($aboveIndex, false);
		$check = ($checkIndex >= 0 && $command->bool($checkIndex, false)) || ($modulo43 >= 0 && $command->bool($modulo43, false));

		if ($check && in_array($type, ["C39", "I25", "MSI", "S25"], true)) {
			$type .= "+";
		}

		$this->field->barcode = function (string $data) use ($type, $height, $printText, $above, $orientation): Element {
			if ($type === "C39" || $type === "C39+" || $type === "LOGMARS") {
				$data = strtoupper($data);
			}

			if ($type === "CODABAR") {
				$data = preg_replace("/^[A-Da-d]|[A-Da-d]$/", "", $data) ?? $data;
			}

			[$matrix, $extended] = $this->barcodes->create($type, $data);

			return $this->linearElement($matrix, $height, $printText ? $extended : null, $above, $orientation);
		};
	}

	private function upcExtension(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$height = max(1, $command->int(1, $this->barHeight));
		$printText = $command->bool(2, true);
		$above = $command->bool(3, true);

		$this->field->barcode = function (string $data) use ($height, $printText, $above, $orientation): Element {
			$digits = preg_replace("/\D/", "", $data) ?? "";
			[$matrix, $extended] = $this->barcodes->create(strlen($digits) > 2 ? "EAN5" : "EAN2", $digits);

			return $this->linearElement($matrix, $height, $printText ? $extended : null, $above, $orientation);
		};
	}

	private function databar(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$type = match ($command->int(1, 1)) {
			1 => "DATABAR",
			2 => "DATABARTRUNC",
			3 => "DATABARSTACK",
			4 => "DATABARSTACKOMNI",
			5 => "DATABARLIMITED",
			6 => "DATABAREXP",
			7 => "UPCA",
			8 => "UPCE",
			9 => "EAN13",
			10 => "EAN8",
			11 => "DATABAREXPSTACK",
			default => throw new UnsupportedException("^BR type {$command->arg(1)} is not supported."),
		};
		$magnification = max(1, min(10, $command->int(2, 24)));
		$height = max(1, $command->int(4, 25));

		$this->field->barcode = function (string $data) use ($type, $magnification, $height, $orientation): Element {
			[$matrix, $extended] = $this->barcodes->create($type, $data);
			$rows = $matrix->rows;

			return new BarcodeElement(
				...$this->origin(),
				matrix: $matrix,
				moduleWidth: $magnification,
				moduleHeight: $rows > 1 ? $magnification : $height,
				text: $extended,
				orientation: $orientation,
			);
		};
	}

	private function qrCode(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$magnification = max(1, min(10, $command->int(2, 2)));
		$defaultLevel = $command->char(3, "Q");
		$mask = max(0, min(7, $command->int(4, 7)));

		$this->field->barcode = function (string $data) use ($magnification, $defaultLevel, $mask, $orientation): Element {
			$level = $defaultLevel;

			if (preg_match("/^([HQML])([AM]),(.*)$/s", $data, $match) === 1) {
				$level = $match[1];
				$data = $match[3];

				if ($match[2] === "M" && preg_match("/^(?:[NAK]|B\d{4})/", $data, $mode) === 1) {
					$data = substr($data, strlen($mode[0]));
				}
			} elseif (preg_match("/^([HQML])(.*)$/s", $data, $match) === 1 && strlen($data) > 1 && $data[1] === ",") {
				$level = $match[1];
				$data = substr($data, 2);
			}

			[$matrix] = $this->barcodes->create("QRCODE,{$level},NL,0,1,0,0,{$mask}", $data);

			return $this->matrixElement($matrix, $magnification, $magnification, $orientation);
		};
	}

	private function dataMatrix(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$module = max(1, $command->int(1, $this->barModuleWidth));
		$rectangular = $command->int(7, 1) === 2;

		$this->field->barcode = function (string $data) use ($module, $rectangular, $orientation): Element {
			[$matrix] = $this->barcodes->create($rectangular ? "DATAMATRIX,R" : "DATAMATRIX,S", $data);

			return $this->matrixElement($matrix, $module, $module, $orientation);
		};
	}

	private function pdf417(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$rowHeight = max(1, $command->int(1, $this->barHeight));
		$security = max(0, min(8, $command->int(2, -1)));
		$truncated = $command->bool(5, false);
		$moduleWidth = $this->barModuleWidth;

		$this->field->barcode = function (string $data) use ($rowHeight, $security, $truncated, $moduleWidth, $orientation): Element {
			$type = ($truncated ? "PDF417C" : "PDF417") . ",," . $security;
			[$matrix] = $this->barcodes->create($type, $data);

			return $this->matrixElement($matrix, $moduleWidth, $rowHeight / self::PDF417_UNITS_PER_ROW, $orientation);
		};
	}

	private function aztec(Command $command): void {
		$orientation = $this->orientation($command->arg(0));
		$magnification = max(1, min(10, $command->int(1, 2)));
		$correction = $command->int(3, 1);
		$percent = $correction >= 1 && $correction <= 99 ? $correction : 33;

		$this->field->barcode = function (string $data) use ($magnification, $percent, $orientation): Element {
			[$matrix] = $this->barcodes->create("AZTEC,{$percent},A,A", $data);

			return $this->matrixElement($matrix, $magnification, $magnification, $orientation);
		};
	}

	private function linearElement(BarcodeMatrix $matrix, int $height, ?string $text, bool $above, Orientation $orientation): BarcodeElement {
		return new BarcodeElement(
			...$this->origin(),
			matrix: $matrix,
			moduleWidth: $this->barModuleWidth,
			moduleHeight: $height,
			text: $text,
			textAbove: $above,
			orientation: $orientation,
		);
	}

	private function matrixElement(BarcodeMatrix $matrix, float $moduleWidth, float $moduleHeight, Orientation $orientation): BarcodeElement {
		return new BarcodeElement(
			...$this->origin(),
			matrix: $matrix,
			moduleWidth: $moduleWidth,
			moduleHeight: $moduleHeight,
			orientation: $orientation,
		);
	}

	private function orientation(string $value): Orientation {
		return $value === "" ? $this->defaultOrientation : Orientation::fromZpl($value, $this->defaultOrientation);
	}

	/**
	 * The named constructor arguments every element shares.
	 *
	 * @return array{x: int, y: int, reverse: bool, typeset: bool, justification: Justification}
	 */
	private function origin(): array {
		return [
			"x" => $this->field->x + $this->homeX + $this->labelShift,
			"y" => $this->field->y + $this->homeY + $this->labelTop,
			"reverse" => $this->field->reverse || $this->reversed,
			"typeset" => $this->field->typeset,
			"justification" => $this->field->justification ?? $this->defaultJustification,
		];
	}

	private function fieldSeparator(): void {
		$this->fieldSeparated = $this->fieldSeparated || !$this->field->isEmpty();
		$this->flushField();
	}

	private function flushField(): void {
		$element = $this->buildField($this->field);
		$this->field = new FieldState();

		if ($element !== null) {
			$this->elements[] = $element;
		}
	}

	private function buildField(FieldState $field): ?Element {
		if ($this->recalling && $field->number !== null) {
			return null;
		}

		$data = $field->data;

		if ($data === null && $field->number !== null) {
			$data = $this->fieldValues[$field->number] ?? null;
		}

		if ($field->barcode !== null) {
			if ($data === null || $data === "") {
				return null;
			}

			return ($field->barcode)($this->decodeData($data, $field, forBarcode: true));
		}

		if ($field->shape !== null) {
			return ($field->shape)();
		}

		if ($data === null) {
			return null;
		}

		return new TextElement(
			...$this->origin(),
			text: $this->decodeData($data, $field, forBarcode: false),
			font: $this->font,
			orientation: $this->fontOrientation ?? $this->defaultOrientation,
			block: $field->block,
		);
	}

	/**
	 * Apply ^FH hex escapes, the character set, and for text the backslash escapes.
	 */
	private function decodeData(string $data, FieldState $field, bool $forBarcode): string {
		if ($field->hexIndicator !== null) {
			$indicator = preg_quote($field->hexIndicator, "/");
			$data = preg_replace_callback(
				"/{$indicator}([0-9A-Fa-f]{2})/",
				fn (array $match): string => chr((int) hexdec($match[1])),
				$data,
			) ?? $data;
		}

		if ($forBarcode) {
			return $data;
		}

		$text = Encoding::toUtf8($data, $this->characterSet);

		return strtr($text, ["\\&" => "\n", "\\\\" => "\\"]);
	}
}
