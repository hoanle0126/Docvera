<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\SafeProcessRunner;
use Docvera\Storage\StagedFile;
use RuntimeException;

/**
 * Tesseract OCR driver for converting scanned PDFs or images into searchable PDFs or extracted text.
 */
final class TesseractOcrDriver implements ProcessingDriverInterface
{
    private CapabilityDetector $capabilities;
    private SafeProcessRunner $runner;

    public function __construct(
        CapabilityDetector $capabilities,
        SafeProcessRunner $runner
    ) {
        $this->capabilities = $capabilities;
        $this->runner = $runner;
    }

    public function getName(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        return $this->capabilities->isAvailable('tesseract');
    }

    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult {
        if ($toolId !== 'ocr-pdf') {
            throw new RuntimeException(sprintf('Tool "%s" is not supported by TesseractOcrDriver.', $toolId));
        }

        $tessBin = $this->capabilities->getBinaryPath('tesseract', ['tesseract']);
        if ($tessBin === null) {
            throw new RuntimeException('Tesseract OCR is required for optical character recognition on scanned documents, but was not detected on this server.');
        }

        $input = $inputs[0];
        $lang = preg_replace('/[^a-zA-Z_]/', '', (string) ($options['language'] ?? 'eng')) ?: 'eng';
        $outputFormat = strtolower((string) ($options['output_format'] ?? 'pdf'));

        $imageInputs = [];
        if ($input->mimeType === 'application/pdf') {
            $pdftoppm = $this->capabilities->getBinaryPath('pdftoppm', ['pdftoppm']);
            if ($pdftoppm === null) {
                throw new RuntimeException('Poppler pdftoppm is required to rasterize scanned PDF pages for OCR.');
            }
            $prefix = $workspace['work'] . '/scan_page';
            $rRes = $this->runner->run([$pdftoppm, '-png', '-r', '300', $input->internalPath, $prefix], $workspace['work']);
            if (!$rRes->isSuccessful()) {
                throw new RuntimeException('Failed to rasterize scanned PDF pages for OCR.');
            }
            $imageInputs = glob($workspace['work'] . '/scan_page*.png') ?: [];
            sort($imageInputs, SORT_NATURAL);
        } else {
            $imageInputs = [$input->internalPath];
        }

        if ($imageInputs === []) {
            throw new RuntimeException('No readable pages found for OCR processing.');
        }

        $listFile = $workspace['work'] . '/ocr_inputs.txt';
        file_put_contents($listFile, implode("\n", $imageInputs) . "\n");

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'scanned-document';
        $outBase = $workspace['work'] . '/ocr_result';

        $cmd = [$tessBin, $listFile, $outBase, '-l', $lang];
        if ($outputFormat === 'pdf') {
            $cmd[] = 'pdf';
        }

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful()) {
            throw new RuntimeException('Tesseract OCR failed to process the document.');
        }

        $ext = $outputFormat === 'pdf' ? 'pdf' : 'txt';
        $generatedFile = $outBase . '.' . $ext;
        if (!is_file($generatedFile)) {
            throw new RuntimeException('Tesseract OCR did not produce an output file.');
        }

        $outputFilename = sprintf('%s-ocr.%s', $baseTitle, $ext);
        $outputPath = $workspace['output'] . '/' . $outputFilename;
        @rename($generatedFile, $outputPath);

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: $ext === 'pdf' ? 'application/pdf' : 'text/plain',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'pages_processed' => count($imageInputs),
                'language' => $lang,
                'output_format' => strtoupper($ext),
            ]
        );
    }
}

