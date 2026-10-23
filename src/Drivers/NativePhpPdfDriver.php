<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\ValidationException;
use Docvera\Storage\StagedFile;
use RuntimeException;

/**
 * Pure PHP PDF 1.7 engine for Images -> PDF, Text -> PDF, and PDF object manipulation.
 * Does not require ext-gd or external binaries for standard JPEG, PNG, and text operations.
 */
final class NativePhpPdfDriver implements ProcessingDriverInterface
{
    private CapabilityDetector $capabilities;

    public function __construct(CapabilityDetector $capabilities)
    {
        $this->capabilities = $capabilities;
    }

    public function getName(): string
    {
        return 'native_php_pdf';
    }

    public function isAvailable(): bool
    {
        return $this->capabilities->isAvailable('native_php_pdf');
    }

    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult {
        return match ($toolId) {
            'images-to-pdf' => $this->imagesToPdf($inputs, $options, $workspace),
            'text-to-pdf' => $this->textToPdf($inputs, $options, $workspace),
            default => throw new RuntimeException(sprintf('Tool "%s" is not supported by NativePhpPdfDriver.', $toolId)),
        };
    }

    /**
     * @param list<StagedFile> $inputs
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function imagesToPdf(array $inputs, array $options, array $workspace): DriverResult
    {
        $pageSizeOpt = strtolower((string) ($options['page_size'] ?? 'a4'));
        $orientationOpt = strtolower((string) ($options['orientation'] ?? 'auto'));
        $marginOpt = strtolower((string) ($options['margin'] ?? 'small'));

        $marginPts = match ($marginOpt) {
            'none' => 0.0,
            'large' => 54.0,
            default => 24.0,
        };

        $imagesData = [];
        foreach ($inputs as $file) {
            $parsed = $this->parseImageForPdf($file->internalPath, $file->mimeType, $file->originalSafeName);
            $imagesData[] = $parsed;
        }

        $outputFilename = 'images-converted.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $pdfBytes = $this->buildImagesPdfDocument($imagesData, $pageSizeOpt, $orientationOpt, $marginPts);
        if (@file_put_contents($outputPath, $pdfBytes) === false) {
            throw new RuntimeException('Failed to write generated PDF output.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'images_embedded' => count($imagesData),
                'page_count' => count($imagesData),
                'page_size' => strtoupper($pageSizeOpt),
                'margin_pts' => $marginPts,
            ]
        );
    }

    /**
     * @param list<StagedFile> $inputs
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function textToPdf(array $inputs, array $options, array $workspace): DriverResult
    {
        $text = '';
        if ($inputs !== []) {
            $chunks = [];
            foreach ($inputs as $input) {
                $chunks[] = (string) @file_get_contents($input->internalPath);
            }
            $text = implode("\n\n", $chunks);
        }

        $extraText = trim((string) ($options['text_content'] ?? ''));
        if ($extraText !== '') {
            $text = $text !== '' ? ($text . "\n\n" . $extraText) : $extraText;
        }

        if (trim($text) === '') {
            throw new ValidationException('Please provide a non-empty text file or enter text content to convert.');
        }

        $pageSizeOpt = strtolower((string) ($options['page_size'] ?? 'a4'));
        $fontFamilyOpt = (string) ($options['font_family'] ?? 'Helvetica');
        $fontSize = max(8, min(24, (int) ($options['font_size'] ?? 11)));
        $marginPts = max(24.0, min(90.0, (float) ($options['margin_pts'] ?? 54.0)));

        $baseFont = match (strtolower($fontFamilyOpt)) {
            'times', 'times-roman', 'serif' => 'Times-Roman',
            'courier', 'mono', 'monospace' => 'Courier',
            default => 'Helvetica',
        };

        [$pageWidth, $pageHeight] = $pageSizeOpt === 'letter'
            ? [612.0, 792.0]
            : [595.28, 841.89];

        $pdfResult = $this->buildTextPdfDocument($text, $pageWidth, $pageHeight, $baseFont, $fontSize, $marginPts);

        $baseName = $inputs !== []
            ? pathinfo($inputs[0]->originalSafeName, PATHINFO_FILENAME)
            : 'document';
        $outputFilename = $baseName . '.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        if (@file_put_contents($outputPath, $pdfResult['bytes']) === false) {
            throw new RuntimeException('Failed to write generated text PDF.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => $pdfResult['page_count'],
                'line_count' => $pdfResult['line_count'],
                'font' => $baseFont,
                'font_size' => $fontSize,
            ]
        );
    }

    /**
     * Builds a multi-page PDF 1.7 document from parsed image streams.
     *
     * @param list<array{width: int, height: int, color_space: string, bits: int, filter: string, decode_parms: string, stream: string}> $images
     */
    public function buildImagesPdfDocument(
        array $images,
        string $pageSizeOpt = 'a4',
        string $orientationOpt = 'auto',
        float $marginPts = 24.0
    ): string {
        $objects = [];
        // Object 1: Catalog, Object 2: Pages
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = ''; // Filled after pages are allocated

        $pageObjectIds = [];
        $nextId = 3;

        foreach ($images as $img) {
            $imgW = max(1, $img['width']);
            $imgH = max(1, $img['height']);

            if ($pageSizeOpt === 'fit') {
                $pageW = (float) $imgW + ($marginPts * 2);
                $pageH = (float) $imgH + ($marginPts * 2);
            } else {
                [$baseW, $baseH] = $pageSizeOpt === 'letter'
                    ? [612.0, 792.0]
                    : [595.28, 841.89];

                $isLandscape = match ($orientationOpt) {
                    'landscape' => true,
                    'portrait' => false,
                    default => $imgW > $imgH,
                };

                $pageW = $isLandscape ? $baseH : $baseW;
                $pageH = $isLandscape ? $baseW : $baseH;
            }

            $availW = max(10.0, $pageW - ($marginPts * 2));
            $availH = max(10.0, $pageH - ($marginPts * 2));
            $scale = min($availW / $imgW, $availH / $imgH);
            $drawW = round($imgW * $scale, 2);
            $drawH = round($imgH * $scale, 2);
            $posX = round(($pageW - $drawW) / 2, 2);
            $posY = round(($pageH - $drawH) / 2, 2);

            $imageObjId = $nextId++;
            $contentObjId = $nextId++;
            $pageObjId = $nextId++;
            $pageObjectIds[] = $pageObjId;

            $decodeParmsEntry = $img['decode_parms'] !== '' ? ' /DecodeParms ' . $img['decode_parms'] : '';
            $imgStream = $img['stream'];
            $imgLen = strlen($imgStream);

            $objects[$imageObjId] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent %d /Filter /%s%s /Length %d >>\nstream\n%s\nendstream",
                $imgW,
                $imgH,
                $img['color_space'],
                $img['bits'],
                $img['filter'],
                $decodeParmsEntry,
                $imgLen,
                $imgStream
            );

            $contentStream = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q", $drawW, $drawH, $posX, $posY);
            $objects[$contentObjId] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($contentStream),
                $contentStream
            );

            $objects[$pageObjId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /XObject << /Im1 %d 0 R >> >> /Contents %d 0 R >>",
                $pageW,
                $pageH,
                $imageObjId,
                $contentObjId
            );
        }

        $kidsRefs = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kidsRefs, count($pageObjectIds));

        return $this->serializePdfObjects($objects);
    }

    /**
     * @return array{bytes: string, page_count: int, line_count: int}
     */
    public function buildTextPdfDocument(
        string $text,
        float $pageW = 595.28,
        float $pageH = 841.89,
        string $baseFont = 'Helvetica',
        int $fontSize = 11,
        float $margin = 54.0,
        int $rotation = 0
    ): array {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $rawLines = explode("\n", $normalized);

        $usableWidth = max(100.0, $pageW - ($margin * 2));
        $avgCharWidth = $fontSize * ($baseFont === 'Courier' ? 0.6 : 0.52);
        $maxCharsPerLine = max(20, (int) floor($usableWidth / $avgCharWidth));

        $wrappedLines = [];
        foreach ($rawLines as $rawLine) {
            $cleanLine = preg_replace('/[^\x20-\x7E]/', ' ', $rawLine) ?? '';
            if (strlen($cleanLine) <= $maxCharsPerLine) {
                $wrappedLines[] = $cleanLine;
            } else {
                $wrapped = wordwrap($cleanLine, $maxCharsPerLine, "\n", true);
                foreach (explode("\n", $wrapped) as $subLine) {
                    $wrappedLines[] = $subLine;
                }
            }
        }

        $lineHeight = round($fontSize * 1.45, 2);
        $usableHeight = max(100.0, $pageH - ($margin * 2));
        $linesPerPage = max(1, (int) floor($usableHeight / $lineHeight));

        $pagesLines = array_chunk($wrappedLines, $linesPerPage);
        if ($pagesLines === []) {
            $pagesLines = [['']];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '';
        $objects[3] = sprintf('<< /Type /Font /Subtype /Type1 /BaseFont /%s >>', $baseFont);

        $pageObjectIds = [];
        $nextId = 4;

        foreach ($pagesLines as $pageLines) {
            $contentObjId = $nextId++;
            $pageObjId = $nextId++;
            $pageObjectIds[] = $pageObjId;

            $ops = ['BT', sprintf('/F1 %d Tf', $fontSize)];
            $y = $pageH - $margin - $fontSize;

            foreach ($pageLines as $line) {
                $escaped = $this->escapePdfString($line);
                $ops[] = sprintf('1 0 0 1 %.2f %.2f Tm (%s) Tj', $margin, $y, $escaped);
                $y -= $lineHeight;
            }
            $ops[] = 'ET';

            $stream = implode("\n", $ops);
            $objects[$contentObjId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream);

            $rotateEntry = $rotation !== 0 ? sprintf(' /Rotate %d', $rotation) : '';
            $objects[$pageObjId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f]%s /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>",
                $pageW,
                $pageH,
                $rotateEntry,
                $contentObjId
            );
        }

        $kidsRefs = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', $kidsRefs, count($pageObjectIds));

        return [
            'bytes' => $this->serializePdfObjects($objects),
            'page_count' => count($pageObjectIds),
            'line_count' => count($wrappedLines),
        ];
    }

    /**
     * Parses a JPEG or PNG image into a PDF-ready XObject stream without requiring ext-gd.
     *
     * @return array{width: int, height: int, color_space: string, bits: int, filter: string, decode_parms: string, stream: string}
     */
    private function parseImageForPdf(string $path, string $mime, string $safeName): array
    {
        $info = @getimagesize($path);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            throw new ValidationException(sprintf('Image "%s" has invalid dimensions or could not be decoded.', $safeName));
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($mime === 'image/jpeg') {
            $raw = (string) @file_get_contents($path);
            $channels = (int) ($info['channels'] ?? 3);
            $colorSpace = $channels === 1 ? 'DeviceGray' : ($channels === 4 ? 'DeviceCMYK' : 'DeviceRGB');
            return [
                'width' => $width,
                'height' => $height,
                'color_space' => $colorSpace,
                'bits' => (int) ($info['bits'] ?? 8),
                'filter' => 'DCTDecode',
                'decode_parms' => '',
                'stream' => $raw,
            ];
        }

        if ($mime === 'image/png') {
            return $this->parsePngStream($path, $safeName);
        }

        throw new ValidationException(sprintf('Image "%s" must be a standard JPEG or PNG file.', $safeName));
    }

    /**
     * Parses PNG chunks and converts 8-bit RGB, RGBA, Grayscale, or Palette PNGs into a PDF FlateDecode image stream.
     *
     * @return array{width: int, height: int, color_space: string, bits: int, filter: string, decode_parms: string, stream: string}
     */
    private function parsePngStream(string $path, string $safeName): array
    {
        $data = (string) @file_get_contents($path);
        if (strlen($data) < 33 || !str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            throw new ValidationException(sprintf('File "%s" is not a valid PNG image.', $safeName));
        }

        $offset = 8;
        $len = strlen($data);
        $width = 0;
        $height = 0;
        $bitDepth = 8;
        $colorType = 2;
        $interlace = 0;
        $idat = '';
        $palette = '';

        while ($offset + 8 <= $len) {
            $chunkLen = unpack('N', substr($data, $offset, 4))[1];
            $chunkType = substr($data, $offset + 4, 4);
            $chunkData = substr($data, $offset + 8, $chunkLen);
            $offset += 12 + $chunkLen;

            if ($chunkType === 'IHDR') {
                $width = unpack('N', substr($chunkData, 0, 4))[1];
                $height = unpack('N', substr($chunkData, 4, 4))[1];
                $bitDepth = ord($chunkData[8]);
                $colorType = ord($chunkData[9]);
                $interlace = ord($chunkData[12]);
            } elseif ($chunkType === 'PLTE') {
                $palette = $chunkData;
            } elseif ($chunkType === 'IDAT') {
                $idat .= $chunkData;
            } elseif ($chunkType === 'IEND') {
                break;
            }
        }

        if ($width <= 0 || $height <= 0 || $idat === '') {
            throw new ValidationException(sprintf('PNG "%s" is missing required image data.', $safeName));
        }

        if ($interlace !== 0 || $bitDepth !== 8) {
            throw new ValidationException(sprintf('PNG "%s" uses interlacing or non-8-bit depth not supported by direct stream embedding.', $safeName));
        }

        // Fast path: 8-bit RGB (colorType 2) or 8-bit Grayscale (colorType 0) can use PDF Predictor 15 directly!
        if ($colorType === 2 || $colorType === 0) {
            $colors = $colorType === 2 ? 3 : 1;
            $colorSpace = $colorType === 2 ? 'DeviceRGB' : 'DeviceGray';
            return [
                'width' => $width,
                'height' => $height,
                'color_space' => $colorSpace,
                'bits' => 8,
                'filter' => 'FlateDecode',
                'decode_parms' => sprintf('<< /Predictor 15 /Colors %d /BitsPerComponent 8 /Columns %d >>', $colors, $width),
                'stream' => $idat,
            ];
        }

        // Decode RGBA (colorType 6), Gray+Alpha (colorType 4), or Palette (colorType 3) into flat RGB flattened onto white background
        $inflated = @zlib_decode($idat);
        if ($inflated === false) {
            throw new ValidationException(sprintf('Unable to decompress PNG image data in "%s".', $safeName));
        }

        $bytesPerPixel = match ($colorType) {
            6 => 4,
            4 => 2,
            3 => 1,
            default => 3,
        };

        $stride = $width * $bytesPerPixel;
        $rgbStream = '';
        $prevRow = str_repeat("\0", $stride);
        $pos = 0;

        for ($y = 0; $y < $height; $y++) {
            if ($pos >= strlen($inflated)) {
                break;
            }
            $filterType = ord($inflated[$pos]);
            $rowRaw = substr($inflated, $pos + 1, $stride);
            $pos += 1 + $stride;

            $recon = $this->unfilterPngRow($filterType, $rowRaw, $prevRow, $bytesPerPixel);
            $prevRow = $recon;

            if ($colorType === 6) {
                for ($x = 0; $x < $width; $x++) {
                    $idx = $x * 4;
                    $r = ord($recon[$idx]);
                    $g = ord($recon[$idx + 1]);
                    $b = ord($recon[$idx + 2]);
                    $a = ord($recon[$idx + 3]) / 255.0;
                    $outR = (int) round(($r * $a) + (255 * (1.0 - $a)));
                    $outG = (int) round(($g * $a) + (255 * (1.0 - $a)));
                    $outB = (int) round(($b * $a) + (255 * (1.0 - $a)));
                    $rgbStream .= chr($outR) . chr($outG) . chr($outB);
                }
            } elseif ($colorType === 4) {
                for ($x = 0; $x < $width; $x++) {
                    $idx = $x * 2;
                    $v = ord($recon[$idx]);
                    $a = ord($recon[$idx + 1]) / 255.0;
                    $out = (int) round(($v * $a) + (255 * (1.0 - $a)));
                    $rgbStream .= chr($out) . chr($out) . chr($out);
                }
            } elseif ($colorType === 3) {
                for ($x = 0; $x < $width; $x++) {
                    $pIdx = ord($recon[$x]) * 3;
                    if ($pIdx + 2 < strlen($palette)) {
                        $rgbStream .= substr($palette, $pIdx, 3);
                    } else {
                        $rgbStream .= "\xFF\xFF\xFF";
                    }
                }
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'color_space' => 'DeviceRGB',
            'bits' => 8,
            'filter' => 'FlateDecode',
            'decode_parms' => '',
            'stream' => (string) gzcompress($rgbStream, 6),
        ];
    }

    private function unfilterPngRow(int $filterType, string $raw, string $prev, int $bpp): string
    {
        $len = strlen($raw);
        if ($filterType === 0) {
            return $raw;
        }
        $out = str_repeat("\0", $len);
        for ($i = 0; $i < $len; $i++) {
            $x = ord($raw[$i]);
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;

            $val = match ($filterType) {
                1 => ($x + $a) & 0xFF,
                2 => ($x + $b) & 0xFF,
                3 => ($x + intdiv($a + $b, 2)) & 0xFF,
                4 => ($x + $this->paethPredictor($a, $b, $c)) & 0xFF,
                default => $x,
            };
            $out[$i] = chr($val);
        }
        return $out;
    }

    private function paethPredictor(int $a, int $b, int $c): int
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

    private function escapePdfString(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * @param array<int, string> $objects
     */
    private function serializePdfObjects(array $objects): string
    {
        ksort($objects);
        $buf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($buf);
            $buf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefOffset = strlen($buf);
        $count = count($objects) + 1;
        $buf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $count);

        for ($i = 1; $i < $count; $i++) {
            $off = $offsets[$i] ?? 0;
            $buf .= sprintf("%010d 00000 n \n", $off);
        }

        $buf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            $xrefOffset
        );

        return $buf;
    }
}

