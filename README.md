# ZPL

[![tests](https://github.com/m-stilling/zpl-php/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/m-stilling/zpl-php/actions/workflows/tests.yml) [![Packagist Version](https://img.shields.io/packagist/v/stilling/zpl)](https://packagist.org/packages/stilling/zpl)

Parse ZPL label code and render it to vector PDF or to PNG, without a printer and without an online service. One `^XA ... ^XZ` format becomes one PDF page or one PNG image. In the PDF, text stays text and bars, boxes and barcodes stay vector shapes, so the output is sharp at every zoom level.

```
composer require stilling/zpl
```

Requires PHP 8.3 with the `mbstring` and `zlib` extensions. PNG output needs the `gd` extension with FreeType support; PDF output does not.

<p align="center"><img src="examples/shipping-label.png" width="406" alt="A shipping label rendered to PNG"></p>

## Usage

```php
use Stilling\Zpl\Options;
use Stilling\Zpl\Zpl;

$zpl = '^XA^FO50,50^A0N,40,40^FDHello^FS^FO50,120^BCN,80,Y,N,N^FD12345678^FS^XZ';
$options = new Options(dpmm: 8, widthMm: 101.6, heightMm: 152.4);

file_put_contents('label.pdf', Zpl::toPdf($zpl, $options));
file_put_contents('label.png', Zpl::toPng($zpl, $options));
```

- `Zpl::toPdf()` returns one PDF with a page per label, and a page per copy when `^PQ` asks for more.
- `Zpl::toPng()` returns one PNG. The third argument picks the label when the input holds several, counted from 0.
- `Zpl::toPngs()` returns a list with one PNG per label.
- `Zpl::parse()` returns the interpreted labels as a list of `Label` objects with their elements, for example to feed a different renderer.

### Options

| Option | Default | Effect |
| --- | --- | --- |
| `dpmm` | `8` | Print density in dots per millimeter: 6, 8, 12 or 24. |
| `widthMm` | `101.6` | Label width. `^PW` in the label overrides it. |
| `heightMm` | `152.4` | Label height. `^LL` in the label overrides it. |
| `honorLabelSize` | `true` | Let `^PW` and `^LL` change the page size. |
| `honorQuantity` | `true` | Repeat a PDF page as many times as `^PQ` asks for. |
| `maxPages` | `1000` | Upper bound on pages per PDF. |
| `scalableFontCondense` | `0.85` | Horizontal scale of font 0 relative to Helvetica-Bold. |
| `pixelsPerDot` | `1` | Pixels per printer dot in PNG output, 1 to 8. |
| `scalableFontFile` | Roboto Condensed Bold | TrueType file drawn for font 0 in PNG output. |
| `monoFontFile` | Roboto Mono | TrueType file drawn for fonts A and C to H in PNG output. |
| `monoBoldFontFile` | Roboto Mono Bold | TrueType file drawn for font B in PNG output. |

`Options::inches(8, 4, 6)` builds the options from a label size in inches.

### Command line

The package installs a `zpl` command in `vendor/bin`.

```
vendor/bin/zpl label.zpl pdf
vendor/bin/zpl label.zpl png --scale=2
vendor/bin/zpl label.zpl pdf out/label.pdf --size=4x6 --dpmm=8
cat label.zpl | vendor/bin/zpl - png > label.png
```

Without an output path the file is written next to the input with the new extension. A PNG is one image per label; when the input holds several labels the files are numbered `name-1.png`, `name-2.png` and so on. An existing file is only replaced with `--overwrite`. Run `vendor/bin/zpl --help` for all options.

## Supported commands

Format and fields: `^XA`, `^XZ`, `^FO`, `^FT`, `^FS`, `^FD`, `^FV`, `^SN`, `^FN`, `^FR`, `^FH`, `^FB`, `^FW`, `^FX`, `^CC`, `^CT`, `^CD`, `^CI`.

Fonts: `^A`, `^A@`, `^CF`. Font 0 is the scalable CG Triumvirate Bold Condensed. Fonts A to H are the fixed-pitch bitmap fonts; they magnify in whole steps like the printer does, and fonts B and H print capital letters only. Any other font id is treated like font 0.

Label: `^PW`, `^LL`, `^LH`, `^LT`, `^LS`, `^LR`, `^PO`, `^PM`, `^PQ`.

Graphics: `^GB`, `^GC`, `^GD`, `^GE`, `^GF` (ASCII hex, run-length compressed, `:B64:` and `:Z64:`), `~DG`, `~DY` (GRF, and PNG through GD), `^XG`, `^IM`.

Stored formats: `^DF` stores a format, `^XF` recalls it and fills the `^FN` fields from the recalling format.

Barcodes: `^BC` Code 128 with the `>` invocation codes and modes N, A, U and D, `^B3` Code 39, `^BA` Code 93, `^BE` EAN-13, `^B8` EAN-8, `^BU` UPC-A, `^B9` UPC-E, `^BS` UPC/EAN extensions, `^B2` Interleaved 2 of 5, `^BI` and `^BJ` Standard 2 of 5, `^BK` Codabar, `^BM` MSI, `^BP` Plessey, `^B1` Code 11, `^BL` LOGMARS, `^BZ` POSTNET, `^B5` PLANET, `^BR` GS1 DataBar, `^B4` Code 49, `^BQ` QR Code, `^BX` Data Matrix, `^B7` PDF417, `^BO` Aztec. The `^BY` module width and default height apply to the linear symbologies. The interpretation line is printed in font A at the module magnification, below or above the bars.

Unknown commands are ignored, like the printer ignores them. `^BD` MaxiCode, `^BT` TLC39 and `^BF` MicroPDF417 throw an `UnsupportedException`.

## Fonts

Font 0 is drawn with Helvetica-Bold in the PDF, condensed to the width of CG Triumvirate Bold Condensed, and with [Roboto Condensed](https://github.com/googlefonts/roboto-2) Bold in the PNG. Nothing is embedded for it, so a PDF with only font 0 text is a few kilobytes.

Fonts A to H are drawn with [Roboto Mono](https://github.com/googlefonts/robotomono) in both outputs, at the cell size and pitch of the printer bitmap fonts. The PDF embeds the font file when a page uses it, which adds about 70 KB per weight. The font files sit in `resources/fonts` with their licenses. Both renderers lay text out with the same metrics, so words start and end at the same dots in the PDF and in the PNG. Point the `monoFontFile` and `monoBoldFontFile` options at other TrueType files to draw with different glyphs in both outputs; `scalableFontFile` changes the PNG only.

## Differences from a printer

- The wide-to-narrow ratio in `^BY` is ignored; Code 39, Interleaved 2 of 5, Codabar and MSI use a ratio of 3.
- EAN and UPC interpretation lines are printed as one centered line, not split around the guard bars.
- Reverse fields (`^FR`, `^LR`) in the PDF use the `Difference` blend mode. Every current viewer supports it.
- Text outside Windows-1252 is printed as `?`.
- `^MU` unit conversion, `^GS` symbols and binary `^GF` data are not supported.

## Examples

The [examples](examples) folder holds sample labels: a shipping label, every barcode type, shapes and fonts, graphics, and a stored format with `^FN` fields. The rendered PDF and PNG of each one sit next to it. Render them again with:

```
composer examples
```

## Development

```
composer test
composer analyse
```
