<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\SafeProcessRunner;
use Docvera\Storage\StagedFile;
use RuntimeException;

/**
 * Headless LibreOffice conversion driver for Word -> PDF, Excel -> PDF, and PowerPoint -> PDF.
 * Uses an isolated per-job UserInstallation profile directory so concurrent conversions never collide.
 */
final class LibreOfficeDriver implements ProcessingDriverInterface
{
    private CapabilityDetector $capabilities;
    private SafeProcessRunner $runner;
    private PdfInspector $inspector;

    public function __construct(
        CapabilityDetector $capabilities,
        SafeProcessRunner $runner,
        PdfInspector $inspector
    ) {
        $this->capabilities = $capabilities;
        $this->runner = $runner;
        $this->inspector = $inspector;
    }

    public function getName(): string
    {
        return 'libreoffice';
    }

    public function isAvailable(): bool
    {
        return $this->capabilities->isAvailable('libreoffice');
    }

    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult {
        if (!in_array($toolId, ['word-to-pdf', 'excel-to-pdf', 'powerpoint-to-pdf'], true)) {
            throw new RuntimeException(sprintf('Tool "%s" is not supported by LibreOfficeDriver.', $toolId));
        }

        $loBin = $this->capabilities->getBinaryPath('libreoffice', ['libreoffice', 'soffice']);
        if ($loBin === null) {
            throw new RuntimeException('LibreOffice is required for this conversion but was not detected on this server.');
        }

        $input = $inputs[0];
        $profileDir = $workspace['work'] . '/lo_profile';
        if (!is_dir($profileDir)) {
            @mkdir($profileDir, 0700, true);
        }

        $cmd = [
            $loBin,
            '-env:UserInstallation=file://' . $profileDir,
            '--headless',
            '--nologo',
            '--nofirststartwizard',
            '--convert-to',
            'pdf',
            '--outdir',
            $workspace['work'],
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful()) {
            throw new RuntimeException('LibreOffice failed to convert the document to PDF.');
        }

        $expectedTmpPdf = $workspace['work'] . '/' . pathinfo($input->internalFilename, PATHINFO_FILENAME) . '.pdf';
        if (!is_file($expectedTmpPdf)) {
            $found = glob($workspace['work'] . '/*.pdf') ?: [];
            if ($found === []) {
                throw new RuntimeException('LibreOffice did not produce a valid PDF output file.');
            }
            $expectedTmpPdf = $found[0];
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        if (!@rename($expectedTmpPdf, $outputPath)) {
            @copy($expectedTmpPdf, $outputPath);
        }

        $info = $this->inspector->inspect($outputPath);

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'source_format' => strtoupper($input->extension),
                'page_count' => (int) ($info['page_count'] ?: 1),
            ]
        );
    }
}

