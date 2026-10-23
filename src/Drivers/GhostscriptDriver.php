<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\SafeProcessRunner;
use Docvera\Security\ValidationException;
use Docvera\Storage\StagedFile;
use RuntimeException;

/**
 * Ghostscript (gs) processing driver supporting PDF manipulation, compression,
 * encryption, unlocking, watermarking, cropping, page numbering, metadata, grayscale, and flattening.
 */
final class GhostscriptDriver implements ProcessingDriverInterface
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
        return 'ghostscript';
    }

    public function isAvailable(): bool
    {
        return $this->capabilities->isAvailable('ghostscript');
    }

    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult {
        return match ($toolId) {
            'merge-pdf' => $this->mergePdf($inputs, $workspace),
            'split-pdf' => $this->splitPdf($inputs[0], $options, $workspace),
            'extract-pages' => $this->extractPages($inputs[0], $options, $workspace),
            'delete-pages' => $this->deletePages($inputs[0], $options, $workspace),
            'reorder-pages' => $this->reorderPages($inputs[0], $options, $workspace),
            'rotate-pages' => $this->rotatePages($inputs[0], $options, $workspace),
            'compress-pdf' => $this->compressPdf($inputs[0], $options, $workspace),
            'protect-pdf' => $this->protectPdf($inputs[0], $options, $workspace),
            'unlock-pdf' => $this->unlockPdf($inputs[0], $options, $workspace),
            'watermark-pdf' => $this->watermarkPdf($inputs[0], $options, $workspace),
            'crop-pdf' => $this->cropPdf($inputs[0], $options, $workspace),
            'page-numbers' => $this->addPageNumbers($inputs[0], $options, $workspace),
            'pdf-metadata' => $this->editMetadata($inputs[0], $options, $workspace),
            'grayscale-pdf' => $this->grayscalePdf($inputs[0], $workspace),
            'flatten-pdf' => $this->flattenPdf($inputs[0], $options, $workspace),
            default => throw new RuntimeException(sprintf('Tool "%s" is not supported by GhostscriptDriver.', $toolId)),
        };
    }

    /**
     * @param list<StagedFile> $inputs
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function mergePdf(array $inputs, array $workspace): DriverResult
    {
        if (count($inputs) < 2) {
            throw new ValidationException('Merge PDF requires at least 2 PDF files.');
        }

        $gs = $this->requireGsBinary();
        $outputFilename = 'merged-document.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sOutputFile=' . $outputPath,
        ];

        $totalPages = 0;
        foreach ($inputs as $file) {
            $info = $this->inspector->inspect($file->internalPath);
            if ($info['encrypted']) {
                throw new ValidationException(sprintf('File "%s" is password-protected. Unlock it before merging.', $file->originalSafeName));
            }
            $totalPages += max(1, (int) $info['page_count']);
            $cmd[] = $file->internalPath;
        }

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Ghostscript failed to merge PDF files.');
        }

        $outInfo = $this->inspector->inspect($outputPath);

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'files_merged' => count($inputs),
                'page_count' => $outInfo['page_count'] ?: $totalPages,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function splitPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);
        $totalPages = max(1, (int) $info['page_count']);

        $splitMode = strtolower((string) ($options['split_mode'] ?? 'all_pages'));
        $everyN = max(1, min(100, (int) ($options['every_n_pages'] ?? 1)));
        if ($splitMode !== 'every_n') {
            $everyN = 1;
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-split-pages.zip';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $zip = new \ZipArchive();
        if ($zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create split PDF ZIP archive.');
        }

        $chunkCount = 0;
        for ($startPage = 1; $startPage <= $totalPages; $startPage += $everyN) {
            $endPage = min($totalPages, $startPage + $everyN - 1);
            $chunkCount++;
            $pageListSpec = $startPage === $endPage ? (string) $startPage : sprintf('%d-%d', $startPage, $endPage);
            $partName = $everyN === 1
                ? sprintf('%s-page-%03d.pdf', $baseTitle, $startPage)
                : sprintf('%s-pages-%03d-%03d.pdf', $baseTitle, $startPage, $endPage);
            $partPath = $workspace['work'] . '/' . $partName;
            $cmd = [
                $gs,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-sDEVICE=pdfwrite',
                '-sPageList=' . $pageListSpec,
                '-sOutputFile=' . $partPath,
                $input->internalPath,
            ];
            $res = $this->runner->run($cmd, $workspace['work']);
            if (!$res->isSuccessful() || !is_file($partPath)) {
                throw new RuntimeException(sprintf('Failed to split pages %s.', $pageListSpec));
            }
            $zip->addFile($partPath, $partName);
        }

        $zip->close();

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/zip',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'source_pages' => $totalPages,
                'split_mode' => $splitMode,
                'every_n_pages' => $everyN,
                'split_files_count' => $chunkCount,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function extractPages(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);
        $totalPages = max(1, (int) $info['page_count']);

        $pagesSpec = trim((string) ($options['pages'] ?? '1'));
        $selectedPages = PdfInspector::parsePageRangeSpec($pagesSpec, $totalPages);
        if ($selectedPages === []) {
            throw new ValidationException(sprintf('No valid pages matched "%s" (document has %d pages).', $pagesSpec, $totalPages));
        }

        $pageListArg = implode(',', $selectedPages);
        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-extracted.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sPageList=' . $pageListArg,
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to extract selected pages from the PDF.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'source_pages' => $totalPages,
                'page_count' => count($selectedPages),
                'extracted_pages' => implode(', ', $selectedPages),
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function deletePages(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);
        $totalPages = max(1, (int) $info['page_count']);

        $deleteSpec = trim((string) ($options['pages_to_delete'] ?? ''));
        if ($deleteSpec === '') {
            throw new ValidationException('Please specify which page numbers or ranges to delete.');
        }

        $pagesToDelete = array_unique(PdfInspector::parsePageRangeSpec($deleteSpec, $totalPages));
        if ($pagesToDelete === []) {
            throw new ValidationException(sprintf('None of the specified pages ("%s") exist in this %d-page document.', $deleteSpec, $totalPages));
        }

        $remainingPages = array_values(array_diff(range(1, $totalPages), $pagesToDelete));
        if ($remainingPages === []) {
            throw new ValidationException('Cannot delete all pages from a PDF document. At least 1 page must remain.');
        }

        $pageListArg = implode(',', $remainingPages);
        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-pages-removed.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sPageList=' . $pageListArg,
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to delete the selected pages.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'source_pages' => $totalPages,
                'deleted_count' => count($pagesToDelete),
                'deleted_pages' => implode(', ', $pagesToDelete),
                'page_count' => count($remainingPages),
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function reorderPages(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);
        $totalPages = max(1, (int) $info['page_count']);

        $orderSpec = trim((string) ($options['page_order'] ?? ''));
        $orderedPages = PdfInspector::parsePageRangeSpec($orderSpec, $totalPages);
        if ($orderedPages === []) {
            throw new ValidationException('Please provide a valid comma-separated page order.');
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-reordered.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sPageList=' . implode(',', $orderedPages),
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to reorder PDF pages.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'source_pages' => $totalPages,
                'page_count' => count($orderedPages),
                'applied_order' => implode(', ', $orderedPages),
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function rotatePages(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);
        $totalPages = max(1, (int) $info['page_count']);

        $angle = (int) ($options['angle'] ?? 90);
        $angle = (($angle % 360) + 360) % 360;
        if (!in_array($angle, [90, 180, 270], true)) {
            throw new ValidationException('Rotation angle must be 90, 180, or 270 degrees.');
        }

        $pagesSpec = trim((string) ($options['pages'] ?? 'all'));
        $targetPages = array_unique(PdfInspector::parsePageRangeSpec($pagesSpec, $totalPages));
        if ($targetPages === []) {
            throw new ValidationException('No valid pages matched the rotation selection.');
        }

        $initialRotation = (int) ($info['rotation'] ?? 0);
        $newRotation = ($initialRotation + $angle) % 360;

        // First normalize the PDF via Ghostscript so every Page dictionary is uncompressed and cleanly structured
        $normalizedPath = $workspace['work'] . '/normalized.pdf';
        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sOutputFile=' . $normalizedPath,
            $input->internalPath,
        ];
        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($normalizedPath)) {
            throw new RuntimeException('Failed to prepare PDF for page rotation.');
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-rotated.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $this->applyPdfPageRotationDictionaries($normalizedPath, $outputPath, $targetPages, $newRotation);

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => $totalPages,
                'rotated_pages_count' => count($targetPages),
                'angle_applied' => $angle,
                'new_rotation' => $newRotation,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function compressPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $level = strtolower((string) ($options['compression_level'] ?? 'ebook'));
        $pdfSetting = match ($level) {
            'extreme', 'screen' => '/screen',
            'high_quality', 'printer' => '/printer',
            'prepress' => '/prepress',
            default => '/ebook',
        };

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-compressed.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5',
            '-dPDFSETTINGS=' . $pdfSetting,
            '-dDetectDuplicateImages=true',
            '-dCompressFonts=true',
            '-dSubsetFonts=true',
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to compress PDF document.');
        }

        $inputBytes = $input->sizeBytes;
        $outputBytes = (int) filesize($outputPath);
        $savedBytes = max(0, $inputBytes - $outputBytes);
        $savedPercent = $inputBytes > 0 ? round(($savedBytes / $inputBytes) * 100, 1) : 0.0;

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: $outputBytes,
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'compression_profile' => ltrim($pdfSetting, '/'),
                'original_bytes' => $inputBytes,
                'output_bytes' => $outputBytes,
                'saved_bytes' => $savedBytes,
                'saved_percent' => $savedPercent,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function protectPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $userPass = (string) ($options['user_password'] ?? '');
        $ownerPass = (string) ($options['owner_password'] ?? '');

        if ($userPass === '' && $ownerPass === '') {
            throw new ValidationException('Please enter a password to protect this PDF.');
        }
        if (strlen($userPass) > 128 || strlen($ownerPass) > 128) {
            throw new ValidationException('Password must not exceed 128 characters.');
        }
        if ($ownerPass === '') {
            $ownerPass = $userPass . '_owner';
        }
        if ($userPass === '') {
            $userPass = $ownerPass;
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-protected.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-sUserPassword=' . $userPass,
            '-sOwnerPassword=' . $ownerPass,
            '-dEncryptionR=3',
            '-dKeyLength=128',
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to encrypt the PDF document.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'encrypted' => true,
                'encryption_strength' => '128-bit PDF Standard',
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function unlockPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $password = (string) ($options['password'] ?? '');

        $infoWithPass = $this->inspector->inspect($input->internalPath, $password);
        if (!$infoWithPass['valid'] || ($infoWithPass['encrypted'] && $infoWithPass['page_count'] === 0)) {
            throw new ValidationException('The password provided is incorrect for this encrypted PDF document.');
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-unlocked.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
        ];
        if ($password !== '') {
            $cmd[] = '-sPDFPassword=' . $password;
        }
        $cmd[] = '-sOutputFile=' . $outputPath;
        $cmd[] = $input->internalPath;

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath) || (int) filesize($outputPath) === 0) {
            throw new ValidationException('Unable to unlock PDF. Please verify that the password is correct.');
        }

        $outInfo = $this->inspector->inspect($outputPath);

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $outInfo['page_count'],
                'encrypted' => (bool) $outInfo['encrypted'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function watermarkPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $watermarkText = trim((string) ($options['watermark_text'] ?? 'CONFIDENTIAL'));
        if ($watermarkText === '') {
            throw new ValidationException('Watermark text cannot be empty.');
        }
        if (strlen($watermarkText) > 80) {
            throw new ValidationException('Watermark text must be 80 characters or fewer.');
        }

        $fontSize = max(12, min(96, (int) ($options['font_size'] ?? 36)));
        $position = strtolower((string) ($options['position'] ?? 'center_diagonal'));
        $opacityOpt = max(10, min(90, (int) ($options['opacity'] ?? 35)));
        // Map opacity 10..90% to PostScript gray shade 0.85 (light) .. 0.20 (dark)
        $grayShade = round(1.0 - ($opacityOpt / 100.0) * 0.75, 2);

        $w = (float) ($info['width_pts'] ?: 612.0);
        $h = (float) ($info['height_pts'] ?: 792.0);
        $escapedText = $this->escapePostScriptString($watermarkText);

        $drawBlock = match ($position) {
            'top' => sprintf(
                "gsave /Helvetica-Bold findfont %d scalefont setfont %.2f setgray %.2f %.2f moveto (%s) dup stringwidth pop 2 div neg 0 rmoveto show grestore",
                $fontSize,
                $grayShade,
                $w / 2.0,
                $h - 42.0,
                $escapedText
            ),
            'bottom' => sprintf(
                "gsave /Helvetica-Bold findfont %d scalefont setfont %.2f setgray %.2f 28.0 moveto (%s) dup stringwidth pop 2 div neg 0 rmoveto show grestore",
                $fontSize,
                $grayShade,
                $w / 2.0,
                $escapedText
            ),
            default => sprintf(
                "gsave /Helvetica-Bold findfont %d scalefont setfont %.2f setgray %.2f %.2f translate 35 rotate 0 0 moveto (%s) dup stringwidth pop 2 div neg 0 rmoveto show grestore",
                $fontSize,
                $grayShade,
                $w / 2.0,
                $h / 2.0,
                $escapedText
            ),
        };

        $psScript = sprintf(
            "<< /EndPage { exch pop 2 lt { %s true } { false } ifelse } bind >> setpagedevice\n",
            $drawBlock
        );

        $psPath = $workspace['work'] . '/watermark.ps';
        file_put_contents($psPath, $psScript);

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-watermarked.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '--permit-file-read=' . $workspace['work'],
            '--permit-file-read=' . $workspace['input'],
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sOutputFile=' . $outputPath,
            $psPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to apply watermark to PDF.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'watermark_text' => $watermarkText,
                'position' => $position,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function cropPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $origW = (float) ($info['width_pts'] ?: 612.0);
        $origH = (float) ($info['height_pts'] ?: 792.0);

        $top = max(0.0, (float) ($options['margin_top'] ?? 36.0));
        $right = max(0.0, (float) ($options['margin_right'] ?? 36.0));
        $bottom = max(0.0, (float) ($options['margin_bottom'] ?? 36.0));
        $left = max(0.0, (float) ($options['margin_left'] ?? 36.0));

        $newW = round($origW - $left - $right, 2);
        $newH = round($origH - $top - $bottom, 2);

        if ($newW < 72.0 || $newH < 72.0) {
            throw new ValidationException(
                sprintf('Crop margins are too large for page size %.0f x %.0f pts (remaining page must be at least 72 x 72 pts).', $origW, $origH)
            );
        }

        $psScript = sprintf(
            "<< /PageSize [%.2f %.2f] /BeginPage { pop %.2f %.2f translate } bind >> setpagedevice\n",
            $newW,
            $newH,
            -$left,
            -$bottom
        );
        $psPath = $workspace['work'] . '/crop.ps';
        file_put_contents($psPath, $psScript);

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-cropped.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '--permit-file-read=' . $workspace['work'],
            '--permit-file-read=' . $workspace['input'],
            '-sDEVICE=pdfwrite',
            '-dFIXEDMEDIA',
            '-dDEVICEWIDTHPOINTS=' . (int) round($newW),
            '-dDEVICEHEIGHTPOINTS=' . (int) round($newH),
            '-sOutputFile=' . $outputPath,
            $psPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to crop PDF document.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'original_dimensions_pts' => sprintf('%.0f x %.0f', $origW, $origH),
                'cropped_dimensions_pts' => sprintf('%.0f x %.0f', $newW, $newH),
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function addPageNumbers(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);
        $totalPages = max(1, (int) $info['page_count']);

        $position = strtolower((string) ($options['position'] ?? 'bottom_center'));
        $formatStyle = strtolower((string) ($options['format'] ?? 'page_x_of_y'));
        $startNumber = max(1, (int) ($options['start_number'] ?? 1));
        $fontSize = max(8, min(20, (int) ($options['font_size'] ?? 10)));

        $w = (float) ($info['width_pts'] ?: 612.0);
        $h = (float) ($info['height_pts'] ?: 792.0);

        $yPos = str_starts_with($position, 'top') ? ($h - 28.0) : 24.0;
        $xPos = match (true) {
            str_ends_with($position, 'left') => 36.0,
            str_ends_with($position, 'right') => $w - 36.0,
            default => $w / 2.0,
        };
        $alignMode = match (true) {
            str_ends_with($position, 'left') => 'left',
            str_ends_with($position, 'right') => 'right',
            default => 'center',
        };

        // Generate per-page label lookups in PostScript dictionary so formatting is exact
        $dictEntries = [];
        for ($i = 0; $i < $totalPages; $i++) {
            $num = $startNumber + $i;
            $totalDisplay = $startNumber + $totalPages - 1;
            $label = match ($formatStyle) {
                'number_only' => (string) $num,
                'page_x' => sprintf('Page %d', $num),
                default => sprintf('Page %d of %d', $num, $totalDisplay),
            };
            $dictEntries[] = sprintf('%d (%s)', $i, $this->escapePostScriptString($label));
        }

        $alignPs = match ($alignMode) {
            'right' => 'dup stringwidth pop neg 0 rmoveto show',
            'center' => 'dup stringwidth pop 2 div neg 0 rmoveto show',
            default => 'show',
        };

        $psScript = sprintf(
            "/DocveraPageLabels << %s >> def\n" .
            "<< /EndPage {\n" .
            "  exch dup DocveraPageLabels exch known {\n" .
            "    DocveraPageLabels exch get\n" .
            "    exch 2 lt {\n" .
            "      gsave /Helvetica findfont %d scalefont setfont 0.15 setgray %.2f %.2f moveto %s grestore true\n" .
            "    } { pop false } ifelse\n" .
            "  } { pop 2 lt } ifelse\n" .
            "} bind >> setpagedevice\n",
            implode(' ', $dictEntries),
            $fontSize,
            $xPos,
            $yPos,
            $alignPs
        );

        $psPath = $workspace['work'] . '/pagenumbers.ps';
        file_put_contents($psPath, $psScript);

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-numbered.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '--permit-file-read=' . $workspace['work'],
            '--permit-file-read=' . $workspace['input'],
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sOutputFile=' . $outputPath,
            $psPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to stamp page numbers onto PDF.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => $totalPages,
                'position' => $position,
                'format' => $formatStyle,
                'start_number' => $startNumber,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function editMetadata(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $title = trim((string) ($options['title'] ?? $info['title']));
        $author = trim((string) ($options['author'] ?? $info['author']));
        $subject = trim((string) ($options['subject'] ?? $info['subject']));
        $keywords = trim((string) ($options['keywords'] ?? $info['keywords']));
        $creator = trim((string) ($options['creator'] ?? ($info['creator'] ?: 'Docvera Document Workbench')));

        $psScript = sprintf(
            "[ /Title (%s)\n  /Author (%s)\n  /Subject (%s)\n  /Keywords (%s)\n  /Creator (%s)\n  /DOCINFO pdfmark\n",
            $this->escapePostScriptString($title),
            $this->escapePostScriptString($author),
            $this->escapePostScriptString($subject),
            $this->escapePostScriptString($keywords),
            $this->escapePostScriptString($creator)
        );

        $psPath = $workspace['work'] . '/docinfo.ps';
        file_put_contents($psPath, $psScript);

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-metadata.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '--permit-file-read=' . $workspace['work'],
            '--permit-file-read=' . $workspace['input'],
            '-sDEVICE=pdfwrite',
            '-dAutoRotatePages=/None',
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
            $psPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to update PDF metadata.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'title' => $title,
                'author' => $author,
                'subject' => $subject,
                'keywords' => $keywords,
                'creator' => $creator,
            ]
        );
    }

    /**
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function grayscalePdf(StagedFile $input, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-grayscale.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $gs,
            '-q',
            '-dNOPAUSE',
            '-dBATCH',
            '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-sColorConversionStrategy=Gray',
            '-dProcessColorModel=/DeviceGray',
            '-dCompatibilityLevel=1.4',
            '-sOutputFile=' . $outputPath,
            $input->internalPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to convert PDF to grayscale.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'color_model' => 'DeviceGray',
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function flattenPdf(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $gs = $this->requireGsBinary();
        $info = $this->assertUnlockedPdf($input);

        $mode = strtolower((string) ($options['flatten_mode'] ?? 'annotations'));
        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-flattened.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        if ($mode === 'rasterize') {
            $cmd = [
                $gs,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-sDEVICE=pdfimage24',
                '-r150',
                '-sOutputFile=' . $outputPath,
                $input->internalPath,
            ];
        } else {
            $cmd = [
                $gs,
                '-q',
                '-dNOPAUSE',
                '-dBATCH',
                '-dSAFER',
                '-sDEVICE=pdfwrite',
                '-dPreserveAnnots=false',
                '-dAutoRotatePages=/None',
                '-sOutputFile=' . $outputPath,
                $input->internalPath,
            ];
        }

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to flatten PDF document.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'page_count' => (int) $info['page_count'],
                'flatten_mode' => $mode,
            ]
        );
    }

    /**
     * Updates /Rotate entries on target /Type /Page objects and recalculates the xref table.
     *
     * @param list<int> $targetPages
     */
    private function applyPdfPageRotationDictionaries(
        string $sourcePdfPath,
        string $destPdfPath,
        array $targetPages,
        int $newRotation
    ): void {
        $raw = (string) file_get_contents($sourcePdfPath);

        // Parse objects from normalized PDF
        if (!preg_match_all('/(\d+)\s+0\s+obj\r?\n(.*?)\r?\nendobj/s', $raw, $matches, PREG_SET_ORDER)) {
            copy($sourcePdfPath, $destPdfPath);
            return;
        }

        $objects = [];
        $pageCounter = 0;

        foreach ($matches as $m) {
            $id = (int) $m[1];
            $body = $m[2];

            if (preg_match('/\/Type\s*\/Page\b(?!s)/', $body)) {
                $pageCounter++;
                if (in_array($pageCounter, $targetPages, true)) {
                    if (preg_match('/\/Rotate\s+\d+/', $body)) {
                        $body = (string) preg_replace('/\/Rotate\s+\d+/', '/Rotate ' . $newRotation, $body);
                    } else {
                        $body = (string) preg_replace('/\/Type\s*\/Page\b/', '/Type /Page /Rotate ' . $newRotation, $body, 1);
                    }
                }
            }
            $objects[$id] = $body;
        }

        // Extract Root and Info references from original trailer if present
        $rootRef = '1 0 R';
        if (preg_match('/\/Root\s+(\d+\s+\d+\s+R)/', $raw, $rm)) {
            $rootRef = $rm[1];
        }
        $infoEntry = '';
        if (preg_match('/\/Info\s+(\d+\s+\d+\s+R)/', $raw, $im)) {
            $infoEntry = ' /Info ' . $im[1];
        }

        ksort($objects);
        $maxId = max(array_keys($objects));
        $buf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($buf);
            $buf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefOffset = strlen($buf);
        $size = $maxId + 1;
        $buf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $size);

        for ($i = 1; $i <= $maxId; $i++) {
            if (isset($offsets[$i])) {
                $buf .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $buf .= "0000000000 65535 f \n";
            }
        }

        $buf .= sprintf(
            "trailer\n<< /Size %d /Root %s%s >>\nstartxref\n%d\n%%%%EOF\n",
            $size,
            $rootRef,
            $infoEntry,
            $xrefOffset
        );

        file_put_contents($destPdfPath, $buf);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertUnlockedPdf(StagedFile $input): array
    {
        $info = $this->inspector->inspect($input->internalPath);
        if ($info['encrypted']) {
            throw new ValidationException(
                sprintf('File "%s" is password-protected. Please unlock it first using the Unlock PDF tool.', $input->originalSafeName)
            );
        }
        return $info;
    }

    private function requireGsBinary(): string
    {
        $bin = $this->capabilities->getBinaryPath('gs', ['gs', 'gswin64c']);
        if ($bin === null) {
            throw new RuntimeException('Ghostscript (gs) binary was not detected on this server.');
        }
        return $bin;
    }

    private function escapePostScriptString(string $input): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '', $input) ?? '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }
}

