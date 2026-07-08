<?php
// Combines PNG carousel slides into a single PDF for LinkedIn's
// /rest/documents upload. Two engines:
//   - Imagick, if the extension is loaded on the host (fast, simple).
//   - A self-contained pure-PHP fallback that needs only PHP's core zlib
//     functions (gzcompress/gzuncompress) — no compiled image extension
//     required — for hosts where Imagick isn't available. It manually
//     decodes PNG scanlines and writes a minimal valid PDF.
// pdf_engine() can be overridden by defining PDF_ENGINE_OVERRIDE (used in
// local testing to exercise both code paths regardless of what's
// installed on the machine running the tests).

function pdf_engine(): string
{
    if (defined('PDF_ENGINE_OVERRIDE')) {
        return PDF_ENGINE_OVERRIDE;
    }
    return extension_loaded('imagick') ? 'imagick' : 'fpdf';
}

function build_carousel_pdf(array $pngPaths, string $outPdfPath): void
{
    if (empty($pngPaths)) {
        throw new InvalidArgumentException('No slides provided for carousel PDF.');
    }
    if (pdf_engine() === 'imagick') {
        build_carousel_pdf_imagick($pngPaths, $outPdfPath);
    } else {
        build_carousel_pdf_pure_php($pngPaths, $outPdfPath);
    }
}

function build_carousel_pdf_imagick(array $pngPaths, string $outPdfPath): void
{
    $pdf = new Imagick();
    foreach ($pngPaths as $path) {
        $page = new Imagick($path);
        $page->setImageFormat('pdf');
        $pdf->addImage($page);
    }
    $pdf->setImageFormat('pdf');
    $pdf->writeImages($outPdfPath, true);
}

// ---- Pure-PHP fallback -----------------------------------------------

function build_carousel_pdf_pure_php(array $pngPaths, string $outPdfPath): void
{
    $objects = [];
    $nextId  = 1;
    $alloc   = function () use (&$nextId) {
        return $nextId++;
    };

    $catalogId = $alloc();
    $pagesId   = $alloc();
    $pageIds   = [];

    foreach ($pngPaths as $path) {
        $png = png_decode_for_pdf($path);

        $imgId   = $alloc();
        $smaskId = null;
        $rgbCompressed = gzcompress($png['color'], 6);

        $imgDict = "<< /Type /XObject /Subtype /Image /Width {$png['width']} /Height {$png['height']}"
                 . " /ColorSpace /{$png['colorspace']} /BitsPerComponent 8 /Filter /FlateDecode";

        if ($png['alpha'] !== null) {
            $smaskId = $alloc();
            $alphaCompressed = gzcompress($png['alpha'], 6);
            $objects[$smaskId] = "<< /Type /XObject /Subtype /Image /Width {$png['width']} /Height {$png['height']}"
                . " /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($alphaCompressed)
                . " >>\nstream\n" . $alphaCompressed . "\nendstream";
            $imgDict .= " /SMask {$smaskId} 0 R";
        }
        $imgDict .= " /Length " . strlen($rgbCompressed) . " >>\nstream\n" . $rgbCompressed . "\nendstream";
        $objects[$imgId] = $imgDict;

        // Render at 150 DPI (matches the original Python renderer's PDF export).
        $scale = 72 / 150;
        $pw = round($png['width'] * $scale, 2);
        $ph = round($png['height'] * $scale, 2);

        $contentId = $alloc();
        $contentStream = "q {$pw} 0 0 {$ph} 0 0 cm /Im1 Do Q";
        $objects[$contentId] = "<< /Length " . strlen($contentStream) . " >>\nstream\n{$contentStream}\nendstream";

        $pageId = $alloc();
        $pageIds[] = $pageId;
        $objects[$pageId] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 {$pw} {$ph}]"
            . " /Resources << /XObject << /Im1 {$imgId} 0 R >> /ProcSet [/PDF /ImageC] >>"
            . " /Contents {$contentId} 0 R >>";
    }

    $objects[$pagesId]   = "<< /Type /Pages /Kids [" . implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds)) . "] /Count " . count($pageIds) . " >>";
    $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";

    write_pdf_objects($objects, $catalogId, $outPdfPath);
}

function write_pdf_objects(array $objects, int $catalogId, string $outPath): void
{
    $out = "%PDF-1.4\n";
    $offsets = [];
    $maxId = max(array_keys($objects));

    for ($id = 1; $id <= $maxId; $id++) {
        if (!isset($objects[$id])) {
            continue;
        }
        $offsets[$id] = strlen($out);
        $out .= "{$id} 0 obj\n{$objects[$id]}\nendobj\n";
    }

    $xrefOffset = strlen($out);
    $out .= "xref\n0 " . ($maxId + 1) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        if (isset($offsets[$id])) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        } else {
            $out .= "0000000000 00000 f \n";
        }
    }
    $out .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\n";
    $out .= "startxref\n{$xrefOffset}\n%%EOF";

    file_put_contents($outPath, $out);
}

// Decodes an 8-bit, non-interlaced PNG (grayscale/RGB/RGBA) into raw
// pixel bytes suitable for direct embedding in a PDF FlateDecode image
// stream. Throws on unsupported formats (16-bit, palette, interlaced) —
// those should be re-exported as standard 8-bit PNGs, or Imagick used.
function png_decode_for_pdf(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException("Not a valid PNG file: {$path}");
    }

    $pos = 8;
    $len = strlen($data);
    $idat = '';
    $width = $height = $bitDepth = $colorType = $interlace = null;

    while ($pos < $len) {
        $chunkLen  = unpack('N', substr($data, $pos, 4))[1];
        $chunkType = substr($data, $pos + 4, 4);
        $chunkData = substr($data, $pos + 8, $chunkLen);
        $pos += 8 + $chunkLen + 4; // + CRC

        if ($chunkType === 'IHDR') {
            $width     = unpack('N', substr($chunkData, 0, 4))[1];
            $height    = unpack('N', substr($chunkData, 4, 4))[1];
            $bitDepth  = ord($chunkData[8]);
            $colorType = ord($chunkData[9]);
            $interlace = ord($chunkData[12]);
        } elseif ($chunkType === 'IDAT') {
            $idat .= $chunkData;
        } elseif ($chunkType === 'IEND') {
            break;
        }
    }

    if ($bitDepth !== 8 || $interlace !== 0 || !in_array($colorType, [0, 2, 6], true)) {
        throw new RuntimeException(
            "Unsupported PNG for pure-PHP PDF conversion (need 8-bit, non-interlaced grayscale/RGB/RGBA): {$path}. " .
            "Install the PHP Imagick extension, or re-export the slide as a standard 8-bit PNG."
        );
    }

    $channels = $colorType === 0 ? 1 : ($colorType === 2 ? 3 : 4);
    $rowBytes = $width * $channels;
    $raw = gzuncompress($idat);
    if ($raw === false) {
        throw new RuntimeException("Could not decompress PNG data: {$path}");
    }

    $prevLine = str_repeat("\0", $rowBytes);
    $colorOut = '';
    $alphaOut = $channels === 4 ? '' : null;
    $offset = 0;

    for ($y = 0; $y < $height; $y++) {
        $filterType = ord($raw[$offset]);
        $line = substr($raw, $offset + 1, $rowBytes);
        $offset += 1 + $rowBytes;

        $unfiltered = png_unfilter_scanline($filterType, $line, $prevLine, $channels, $rowBytes);
        $prevLine = $unfiltered;

        if ($channels === 4) {
            for ($x = 0; $x < $rowBytes; $x += 4) {
                $colorOut .= substr($unfiltered, $x, 3);
                $alphaOut .= $unfiltered[$x + 3];
            }
        } else {
            $colorOut .= $unfiltered;
        }
    }

    return [
        'width'      => $width,
        'height'     => $height,
        'colorspace' => $colorType === 0 ? 'DeviceGray' : 'DeviceRGB',
        'color'      => $colorOut,
        'alpha'      => $alphaOut,
    ];
}

function png_unfilter_scanline(int $filterType, string $line, string $prevLine, int $bpp, int $rowBytes): string
{
    $out = str_repeat("\0", $rowBytes);
    for ($x = 0; $x < $rowBytes; $x++) {
        $raw = ord($line[$x]);
        $a = $x >= $bpp ? ord($out[$x - $bpp]) : 0;
        $b = ord($prevLine[$x]);
        $c = $x >= $bpp ? ord($prevLine[$x - $bpp]) : 0;

        switch ($filterType) {
            case 0: $recon = $raw; break;
            case 1: $recon = $raw + $a; break;
            case 2: $recon = $raw + $b; break;
            case 3: $recon = $raw + intdiv($a + $b, 2); break;
            case 4: $recon = $raw + png_paeth($a, $b, $c); break;
            default: throw new RuntimeException("Unsupported PNG filter type {$filterType}");
        }
        $out[$x] = chr($recon & 0xFF);
    }
    return $out;
}

function png_paeth(int $a, int $b, int $c): int
{
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);
    if ($pa <= $pb && $pa <= $pc) {
        return $a;
    }
    return $pb <= $pc ? $b : $c;
}
