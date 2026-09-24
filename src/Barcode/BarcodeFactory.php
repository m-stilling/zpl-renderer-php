<?php

namespace Stilling\ZplRenderer\Barcode;

use Com\Tecnick\Barcode\Barcode;
use Stilling\ZplRenderer\Exceptions\RenderException;

/**
 * Encodes every symbology except Code 128 through tc-lib-barcode.
 */
class BarcodeFactory {
	private Barcode $barcode;

	public function __construct() {
		$this->barcode = new Barcode();
	}

	/**
	 * @param string $type a tc-lib-barcode type with its comma-separated parameters, for example "QRCODE,H"
	 * @return array{BarcodeMatrix, string} the bars and the data including any check digit the symbology added
	 */
	public function create(string $type, string $data): array {
		try {
			$object = $this->barcode->getBarcodeObj($type, $data, -1, -1, "black", [0, 0, 0, 0]);
		} catch (\Throwable $exception) {
			throw new RenderException("Cannot encode {$type} barcode: " . $exception->getMessage(), 0, $exception);
		}

		$array = $object->getArray();

		return [
			new BarcodeMatrix($array["ncols"], $array["nrows"], array_values($array["bars"])),
			$object->getExtendedCode(),
		];
	}
}
