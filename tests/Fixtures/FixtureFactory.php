<?php

declare(strict_types=1);

namespace Docvera\Tests\Fixtures;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Drivers\NativePhpPdfDriver;
use Docvera\Security\SafeProcessRunner;
use RuntimeException;
use ZipArchive;

/**
 * Generates deterministic, standards-compliant PDF, PNG, JPEG, TXT, and malformed test fixtures.
 */
final class FixtureFactory
{
    private NativePhpPdfDriver $nativeDriver;
    private SafeProcessRunner $runner;
    private CapabilityDetector $capabilities;

    public function __construct(
        NativePhpPdfDriver $nativeDriver,
        SafeProcessRunner $runner,
        CapabilityDetector $capabilities
    ) {
        $this->nativeDriver = $nativeDriver;
        $this->runner = $runner;
        $this->capabilities = $capabilities;
    }

    public function createSinglePagePdf(string $path, string $text = 'Docvera Single Page Alpha'): string
    {
        $res = $this->nativeDriver->buildTextPdfDocument(
            text: $text,
            pageW: 612.0,
            pageH: 792.0,
            baseFont: 'Helvetica',
            fontSize: 12,
            margin: 54.0
        );
        file_put_contents($path, $res['bytes']);
        return $path;
    }

    /**
     * @param list<string> $pageTexts
     */
    public function createMultiPagePdf(string $path, array $pageTexts = ['PageOneToken', 'PageTwoToken', 'PageThreeToken', 'PageFourToken']): string
    {
        $gs = $this->capabilities->getBinaryPath('gs', ['gs']);
        if ($gs === null) {
            throw new RuntimeException('Ghostscript required to build multi-page test fixture.');
        }

        $tmpDir = dirname($path);
        $partFiles = [];
        foreach ($pageTexts as $idx => $text) {
            $partPath = $tmpDir . '/_part_' . ($idx + 1) . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $this->createSinglePagePdf($partPath, $text);
            $partFiles[] = $partPath;
        }

        $cmd = array_merge([
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-sOutputFile=' . $path,
        ], $partFiles);

        $this->runner->run($cmd, $tmpDir);

        foreach ($partFiles as $pf) {
            @unlink($pf);
        }

        return $path;
    }

    public function createRotatedPdf(string $path, int $rotation = 90, string $text = 'Rotated Fixture Content'): string
    {
        $res = $this->nativeDriver->buildTextPdfDocument(
            text: $text,
            pageW: 612.0,
            pageH: 792.0,
            baseFont: 'Helvetica',
            fontSize: 12,
            margin: 54.0,
            rotation: $rotation
        );
        file_put_contents($path, $res['bytes']);
        return $path;
    }

    public function createPasswordProtectedPdf(string $path, string $password, string $text = 'Top Secret Protected Document'): string
    {
        $gs = $this->capabilities->getBinaryPath('gs', ['gs']);
        if ($gs === null) {
            throw new RuntimeException('Ghostscript required to build password-protected fixture.');
        }

        $plainPath = $path . '.plain.pdf';
        $this->createSinglePagePdf($plainPath, $text);

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-sUserPassword=' . $password,
            '-sOwnerPassword=' . $password . '_owner',
            '-dEncryptionR=3',
            '-dKeyLength=128',
            '-sOutputFile=' . $path,
            $plainPath,
        ];

        $this->runner->run($cmd, dirname($path));
        @unlink($plainPath);

        return $path;
    }

    public function createValidPng(string $path, int $width = 160, int $height = 120): string
    {
        $rawScanlines = '';
        for ($y = 0; $y < $height; $y++) {
            $rawScanlines .= "\x00"; // Filter type 0 (None)
            for ($x = 0; $x < $width; $x++) {
                $r = ($x * 2) & 0xFF;
                $g = ($y * 2) & 0xFF;
                $b = (($x + $y) * 3) & 0xFF;
                $rawScanlines .= chr($r) . chr($g) . chr($b);
            }
        }

        $ihdrData = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $idatData = (string) gzcompress($rawScanlines, 6);

        $png = "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', $ihdrData)
            . $this->pngChunk('IDAT', $idatData)
            . $this->pngChunk('IEND', '');

        file_put_contents($path, $png);
        return $path;
    }

    public function createValidJpeg(string $path): string
    {
        $gs = $this->capabilities->getBinaryPath('gs', ['gs']);
        if ($gs !== null) {
            $tmpPdf = $path . '.src.pdf';
            $this->createSinglePagePdf($tmpPdf, 'JPEG Raster Source');
            $cmd = [
                $gs,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-sDEVICE=jpeg',
                '-r72',
                '-sOutputFile=' . $path,
                $tmpPdf,
            ];
            $this->runner->run($cmd, dirname($path));
            @unlink($tmpPdf);
            if (is_file($path) && (int) filesize($path) > 0) {
                return $path;
            }
        }

        throw new RuntimeException('Failed to generate valid JPEG fixture.');
    }

    public function createImageHeavyPdf(string $path): string
    {
        $png1 = $path . '.img1.png';
        $png2 = $path . '.img2.png';
        $this->createValidPng($png1, 320, 240);
        $this->createValidPng($png2, 320, 240);

        $raw1 = (string) file_get_contents($png1);
        // Use NativePhpPdfDriver via reflection or direct build
        $jpegPath = $path . '.img.jpg';
        $this->createValidJpeg($jpegPath);
        $jpegBytes = (string) file_get_contents($jpegPath);
        $info = (array) getimagesize($jpegPath);

        $pdfBytes = $this->nativeDriver->buildImagesPdfDocument([
            [
                'width' => (int) ($info[0] ?? 200),
                'height' => (int) ($info[1] ?? 200),
                'color_space' => 'DeviceRGB',
                'bits' => 8,
                'filter' => 'DCTDecode',
                'decode_parms' => '',
                'stream' => $jpegBytes,
            ],
        ], 'a4', 'portrait', 24.0);

        file_put_contents($path, $pdfBytes);
        @unlink($png1);
        @unlink($png2);
        @unlink($jpegPath);

        return $path;
    }

    public function createSampleTextFile(string $path, string $content = "Docvera Plain Text Conversion Fixture\nLine Two Verification Token\nLine Three Archive Check"): string
    {
        file_put_contents($path, $content);
        return $path;
    }

    public function createMalformedPdf(string $path): string
    {
        file_put_contents($path, 'NOT_A_VALID_PDF_HEADER_AT_ALL_CORRUPTED_BYTES_1234567890');
        return $path;
    }

    public function createTruncatedPdf(string $path): string
    {
        file_put_contents($path, "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n");
        return $path;
    }

    public function createOversizedFile(string $path, int $sizeBytes): string
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Unable to create oversized fixture.');
        }
        fwrite($fp, "%PDF-1.7\n");
        fseek($fp, max(16, $sizeBytes - 8));
        fwrite($fp, "\n%%EOF\n");
        fclose($fp);
        return $path;
    }

    public function createMinimalDocx(string $path): string
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Docvera Word Fixture</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        return $path;
    }

    private function pngChunk(string $type, string $data): string
    {
        $len = pack('N', strlen($data));
        $crc = pack('N', crc32($type . $data));
        return $len . $type . $data . $crc;
    }
}

