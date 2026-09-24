# ZPL

[![tests](https://github.com/m-stilling/zpl-php/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/m-stilling/zpl-php/actions/workflows/tests.yml) [![Packagist Version](https://img.shields.io/packagist/v/stilling/zpl)](https://packagist.org/packages/stilling/zpl)

Parse ZPL label code and render it to vector PDF or to PNG, without a printer and without an online service. One `^XA ... ^XZ` format becomes one PDF page or one PNG image. In the PDF, text stays text and bars, boxes and barcodes stay vector shapes, so the output is sharp at every zoom level.

```
composer require stilling/zpl
```

Requires PHP 8.3 with the `mbstring` and `zlib` extensions. PNG output needs the `gd` extension; PDF output does not.

<table>
<tr>
<th><a href="examples/shipping-label.zpl">Shipping label</a></th>
<th><a href="examples/barcodes.zpl">Barcodes</a></th>
<th><a href="examples/shapes-and-fonts.zpl">Shapes and fonts</a></th>
</tr>
<tr>
<td valign="top"><img src="examples/shipping-label.png" width="270" alt="A shipping label rendered to PNG"></td>
<td valign="top"><img src="examples/barcodes.png" width="270" alt="Every barcode type rendered to PNG"></td>
<td valign="top"><img src="examples/shapes-and-fonts.png" width="270" alt="Shapes and fonts rendered to PNG"></td>
</tr>
</table>

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
| `widthMm` | `101.6` | Label width. `^PW` overrides it for its label and the labels after it. |
| `heightMm` | `152.4` | Label height. `^LL` overrides it for its label and the labels after it. |
| `honorLabelSize` | `true` | Let `^PW` and `^LL` change the page size. |
| `honorQuantity` | `true` | Repeat a PDF page as many times as `^PQ` asks for. |
| `maxPages` | `1000` | Upper bound on pages per PDF. |
| `scalableFontCondense` | `0.887` | Horizontal scale of font 0 relative to the natural width of `scalableFontFile`. |
| `pixelsPerDot` | `1` | Pixels per printer dot in PNG output, 1 to 8. |
| `antialias` | `true` | Smooth text edges in PNG output with gray pixels. `false` gives black and white only, like the printer. |
| `scalableFontFile` | Roboto Condensed Bold | TrueType file drawn for font 0. |
| `monoFontFile` | Roboto Mono | TrueType file drawn for fonts A and C to H. |
| `monoBoldFontFile` | Roboto Mono Bold | TrueType file drawn for font B. |

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

| Format | Fields | Fonts and graphics | Label |
| --- | --- | --- | --- |
| `^XA` Start format | `^FO` Field origin | `^A` Font | `^PW` Print width |
| `^XZ` End format | `^FT` Field typeset | `^A@` Font by name | `^LL` Label length |
| `^DF` Download format | `^FS` Field separator | `^CF` Change default font | `^LH` Label home |
| `^XF` Recall format | `^FD` Field data | `^GB` Graphic box | `^LT` Label top |
| `^FX` Comment | `^FV` Field variable | `^GC` Graphic circle | `^LS` Label shift |
| `^CC` Change caret | `^SN` Serialization data | `^GD` Graphic diagonal line | `^LR` Label reverse print |
| `^CT` Change tilde | `^FN` Field number | `^GE` Graphic ellipse | `^PO` Print orientation |
| `^CD` Change delimiter | `^FR` Field reverse print | `^GF` Graphic field | `^PM` Mirror image |
| `^CI` Change encoding | `^FH` Field hex indicator | `~DG` Download graphic | `^PQ` Print quantity |
| | `^FB` Field block | `~DY` Download objects | |
| | `^FW` Field orientation | `^XG` Recall graphic | |
| | | `^IM` Image move | |

`^DF` stores a format. `^XF` recalls it and fills the `^FN` fields from the recalling format.

`^GF` takes ASCII hex, run-length compressed, `:B64:` and `:Z64:` data. `~DY` takes GRF, and PNG through GD.

Font 0 is the scalable CG Triumvirate Bold Condensed. Fonts A to H are the fixed-pitch bitmap fonts; they magnify in whole steps like the printer does, and fonts B and H print capital letters only. Any other font id is treated like font 0.

### Barcodes

| Linear | Retail and postal | 2D and stacked |
| --- | --- | --- |
| `^BC` Code 128 | `^BE` EAN-13 | `^BQ` QR Code |
| `^B3` Code 39 | `^B8` EAN-8 | `^BX` Data Matrix |
| `^BA` Code 93 | `^BU` UPC-A | `^B7` PDF417 |
| `^B2` Interleaved 2 of 5 | `^B9` UPC-E | `^B0`, `^BO` Aztec |
| `^BI`, `^BJ` Standard 2 of 5 | `^BS` UPC/EAN extensions | `^B4` Code 49 |
| `^BK` Codabar | `^BR` GS1 DataBar | |
| `^BM` MSI | `^BZ` POSTNET | |
| `^BP` Plessey | `^B5` PLANET | |
| `^B1` Code 11 | | |
| `^BL` LOGMARS | | |

`^BC` supports the `>` invocation codes and the modes N, A, U and D. The `^BY` module width and default height apply to the linear symbologies. The interpretation line is printed in font A at the module magnification, below or above the bars.

### Unsupported commands

Unknown commands are ignored, like the printer ignores them. `^BB` Codablock, `^BD` MaxiCode, `^BT` TLC39 and `^BF` MicroPDF417 throw an `UnsupportedException`.

## Fonts

Font 0 is drawn with [Roboto Condensed](https://github.com/googlefonts/roboto-2) Bold, at the width of CG Triumvirate Bold Condensed. Fonts A to H are drawn with [Roboto Mono](https://github.com/googlefonts/robotomono), at the cell size and pitch of the printer bitmap fonts. The font files sit in `resources/fonts` with their licenses.

The PDF and the PNG draw the same glyphs from the same font files, at the same positions. The PDF embeds only the characters that the document draws, so a font adds a few kilobytes. The PNG puts every character on a whole pixel, so the same character has the same pixels wherever it appears. Text edges in the PNG are smoothed with gray pixels; shapes, barcodes and images stay black and white, dot for dot. Set `antialias: false` to get black and white text, like the printer prints it.

Point the `scalableFontFile`, `monoFontFile` and `monoBoldFontFile` options at other TrueType files to draw with different glyphs. The file must have TrueType outlines (a `glyf` table). Set `scalableFontCondense` to the horizontal scale that gives the new font 0 file the width of the printer font.

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
