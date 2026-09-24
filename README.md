# ZPL

[![tests](https://github.com/m-stilling/zpl-php/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/m-stilling/zpl-php/actions/workflows/tests.yml) [![Packagist Version](https://img.shields.io/packagist/v/stilling/zpl)](https://packagist.org/packages/stilling/zpl)

Parse ZPL label code and render it to vector PDF, without a printer and without an online service. One `^XA ... ^XZ` format becomes one PDF page. Text stays text, and bars, boxes and barcodes stay vector shapes, so the output is sharp at every zoom level.

```
composer require stilling/zpl
```

Requires PHP 8.4 with the `mbstring` and `zlib` extensions. The `gd` extension is needed only for PNG images sent with `~DY`.

## Usage

```php
use Stilling\Zpl\Options;
use Stilling\Zpl\Zpl;

$zpl = '^XA^FO50,50^A0N,40,40^FDHello^FS^FO50,120^BCN,80,Y,N,N^FD12345678^FS^XZ';

$pdf = Zpl::toPdf($zpl, new Options(dpmm: 8, widthMm: 101.6, heightMm: 152.4));

file_put_contents('label.pdf', $pdf);
```

`Zpl::toPdf()` returns the PDF as a string. `Zpl::parse()` returns the interpreted labels as a list of `Label` objects with their elements, for example to feed a different renderer.

### Options

| Option | Default | Effect |
| --- | --- | --- |
| `dpmm` | `8` | Print density in dots per millimeter: 6, 8, 12 or 24. |
| `widthMm` | `101.6` | Label width. `^PW` in the label overrides it. |
| `heightMm` | `152.4` | Label height. `^LL` in the label overrides it. |
| `honorLabelSize` | `true` | Let `^PW` and `^LL` change the page size. |
| `honorQuantity` | `true` | Repeat the page as many times as `^PQ` asks for. |
| `maxPages` | `1000` | Upper bound on pages per document. |
| `scalableFontCondense` | `0.85` | Horizontal scale of font 0 relative to Helvetica-Bold. |

`Options::inches(8, 4, 6)` builds the options from a label size in inches.

### Command line

The package installs a `zpl` command in `vendor/bin`.

```
vendor/bin/zpl label.zpl pdf
vendor/bin/zpl label.zpl pdf out/label.pdf --size=4x6 --dpmm=8
cat label.zpl | vendor/bin/zpl - pdf > label.pdf
```

Without an output path the PDF is written next to the input with a `.pdf` extension. An existing file is only replaced with `--overwrite`. Run `vendor/bin/zpl --help` for all options.

## Supported commands

Format and fields: `^XA`, `^XZ`, `^FO`, `^FT`, `^FS`, `^FD`, `^FV`, `^SN`, `^FN`, `^FR`, `^FH`, `^FB`, `^FW`, `^FX`, `^CC`, `^CT`, `^CD`, `^CI`.

Fonts: `^A`, `^A@`, `^CF`. Font 0 is drawn with Helvetica-Bold, condensed to the width of CG Triumvirate Bold Condensed. Fonts A to H are drawn with Courier at the cell size and pitch of the printer bitmap fonts; they magnify in whole steps like the printer does. Fonts B and H print capital letters only. Any other font id is treated like font 0.

Label: `^PW`, `^LL`, `^LH`, `^LT`, `^LS`, `^LR`, `^PO`, `^PM`, `^PQ`.

Graphics: `^GB`, `^GC`, `^GD`, `^GE`, `^GF` (ASCII hex, run-length compressed, `:B64:` and `:Z64:`), `~DG`, `~DY` (GRF, and PNG through GD), `^XG`, `^IM`.

Stored formats: `^DF` stores a format, `^XF` recalls it and fills the `^FN` fields from the recalling format.

Barcodes: `^BC` Code 128 with the `>` invocation codes and modes N, A, U and D, `^B3` Code 39, `^BA` Code 93, `^BE` EAN-13, `^B8` EAN-8, `^BU` UPC-A, `^B9` UPC-E, `^BS` UPC/EAN extensions, `^B2` Interleaved 2 of 5, `^BI` and `^BJ` Standard 2 of 5, `^BK` Codabar, `^BM` MSI, `^BP` Plessey, `^B1` Code 11, `^BL` LOGMARS, `^BZ` POSTNET, `^B5` PLANET, `^BR` GS1 DataBar, `^B4` Code 49, `^BQ` QR Code, `^BX` Data Matrix, `^B7` PDF417, `^BO` Aztec. The `^BY` module width and default height apply to the linear symbologies. The interpretation line is printed in font A at the module magnification, below or above the bars.

Unknown commands are ignored, like the printer ignores them. `^BD` MaxiCode, `^BT` TLC39 and `^BF` MicroPDF417 throw an `UnsupportedException`.

## Differences from a printer

- The wide-to-narrow ratio in `^BY` is ignored; Code 39, Interleaved 2 of 5, Codabar and MSI use a ratio of 3.
- EAN and UPC interpretation lines are printed as one centered line, not split around the guard bars.
- Reverse fields (`^FR`, `^LR`) use the PDF `Difference` blend mode. Every current viewer supports it.
- Text outside Windows-1252 is printed as `?`.
- `^MU` unit conversion, `^GS` symbols and binary `^GF` data are not supported.

## Examples

The [examples](examples) folder holds sample labels: a shipping label, every barcode type, shapes and fonts, graphics, and a stored format with `^FN` fields. Render one with:

```
vendor/bin/zpl examples/shipping-label.zpl pdf
```

## Development

```
composer test
composer analyse
```
