<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\SafeProcessRunner;
use Docvera\Security\ValidationException;
use Docvera\Storage\StagedFile;
use RuntimeException;
use ZipArchive;

/**
 * Poppler-backed processing driver (pdftoppm, pdfinfo, pdfseparate, pdfunite).
 */
final class PopplerDriver implements ProcessingDriverInterface
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
        return 'poppler';
    }

    public function isAvailable(): bool
    {
        return $this->capabilities->isAvailable('poppler');
    }

    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult {
        return match ($toolId) {
            'pdf-to-images' => $this->pdfToImages($inputs[0], $options, $workspace),
            'merge-pdf' => $this->mergePdf($inputs, $workspace),
            'split-pdf' => $this->splitPdf($inputs[0], $options, $workspace),
            'reorder-pages' => $this->reorderPages($inputs[0], $options, $workspace),
            default => throw new RuntimeException(sprintf('Tool "%s" is not supported by PopplerDriver.', $toolId)),
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function pdfToImages(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $pdftoppm = $this->capabilities->getBinaryPath('pdftoppm', ['pdftoppm']);
        if ($pdftoppm === null) {
            throw new RuntimeException('Poppler pdftoppm binary is not available.');
        }

        $info = $this->inspector->inspect($input->internalPath);
        if ($info['encrypted']) {
            throw new ValidationException('This PDF is password-protected. Please unlock it first using Unlock PDF.');
        }
        $totalPages = max(1, (int) $info['page_count']);

        $format = strtolower((string) ($options['image_format'] ?? 'png'));
        if (!in_array($format, ['png', 'jpg', 'jpeg'], true)) {
            $format = 'png';
        }
        $isJpeg = ($format === 'jpg' || $format === 'jpeg');
        $ext = $isJpeg ? 'jpg' : 'png';

        $dpi = (int) ($options['dpi'] ?? 150);
        if (!in_array($dpi, [72, 150, 300], true)) {
            $dpi = 150;
        }

        $pagesSpec = trim((string) ($options['pages'] ?? 'all'));
        $selectedPages = PdfInspector::parsePageRangeSpec($pagesSpec, $totalPages);
        if ($selectedPages === []) {
            throw new ValidationException(sprintf('No valid pages matched "%s" (document has %d pages).', $pagesSpec, $totalPages));
        }

        $prefix = $workspace['work'] . '/page';
        $cmd = [
            $pdftoppm,
            $isJpeg ? '-jpeg' : '-png',
            '-r',
            (string) $dpi,
            $input->internalPath,
            $prefix,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful()) {
            throw new RuntimeException('Failed to render PDF pages into images.');
        }

        $allRendered = glob($workspace['work'] . '/page*.' . $ext) ?: [];
        sort($allRendered, SORT_NATURAL);

        if ($allRendered === []) {
            throw new RuntimeException('No page images were generated from the PDF document.');
        }

        // Filter to selected page numbers (1-based)
        $filteredFiles = [];
        foreach ($selectedPages as $pNum) {
            $idx = $pNum - 1;
            if (isset($allRendered[$idx])) {
                $filteredFiles[$pNum] = $allRendered[$idx];
            }
        }
        if ($filteredFiles === []) {
            $filteredFiles = [1 => $allRendered[0]];
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';

        if (count($filteredFiles) === 1 && !((bool) ($options['always_zip'] ?? false))) {
            $onlyPageNum = array_key_first($filteredFiles);
            $onlyFile = $filteredFiles[$onlyPageNum];
            $outputFilename = sprintf('%s-page-%d.%s', $baseTitle, $onlyPageNum, $ext);
            $outputPath = $workspace['output'] . '/' . $outputFilename;
            if (!@copy($onlyFile, $outputPath)) {
                throw new RuntimeException('Failed to write rendered image output.');
            }

            return new DriverResult(
                outputPath: $outputPath,
                outputFilename: $outputFilename,
                outputMimeType: $isJpeg ? 'image/jpeg' : 'image/png',
                outputBytes: (int) filesize($outputPath),
                driverName: $this->getName(),
                summary: [
                    'images_rendered' => 1,
                    'format' => strtoupper($ext),
                    'dpi' => $dpi,
                    'source_pages' => $totalPages,
                ]
            );
        }

        $outputFilename = sprintf('%s-%s-images.zip', $baseTitle, $ext);
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create image archive.');
        }
        foreach ($filteredFiles as $pageNum => $filePath) {
            $entryName = sprintf('%s-page-%03d.%s', $baseTitle, $pageNum, $ext);
            $zip->addFile($filePath, $entryName);
        }
        $zip->close();

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/zip',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'images_rendered' => count($filteredFiles),
                'format' => strtoupper($ext),
                'dpi' => $dpi,
                'source_pages' => $totalPages,
            ]
        );
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

        $pdfunite = $this->capabilities->getBinaryPath('pdfunite', ['pdfunite']);
        if ($pdfunite === null) {
            throw new RuntimeException('pdfunite binary is not available.');
        }

        $totalPages = 0;
        $cmd = [$pdfunite];
        foreach ($inputs as $file) {
            $info = $this->inspector->inspect($file->internalPath);
            if ($info['encrypted']) {
                throw new ValidationException(sprintf('File "%s" is password-protected. Unlock it before merging.', $file->originalSafeName));
            }
            $totalPages += max(1, (int) $info['page_count']);
            $cmd[] = $file->internalPath;
        }

        $outputFilename = 'merged-document.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;
        $cmd[] = $outputPath;

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('Failed to merge PDF documents.');
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
        $pdfseparate = $this->capabilities->getBinaryPath('pdfseparate', ['pdfseparate']);
        $pdfunite = $this->capabilities->getBinaryPath('pdfunite', ['pdfunite']);
        if ($pdfseparate === null) {
            throw new RuntimeException('pdfseparate binary is not available.');
        }

        $info = $this->inspector->inspect($input->internalPath);
        if ($info['encrypted']) {
            throw new ValidationException('This PDF is password-protected. Please unlock it first.');
        }
        $totalPages = max(1, (int) $info['page_count']);

        $pattern = $workspace['work'] . '/page-%04d.pdf';
        $res = $this->runner->run([$pdfseparate, $input->internalPath, $pattern], $workspace['work']);
        if (!$res->isSuccessful()) {
            throw new RuntimeException('Failed to separate PDF pages.');
        }

        $singlePages = glob($workspace['work'] . '/page-*.pdf') ?: [];
        sort($singlePages, SORT_NATURAL);
        if ($singlePages === []) {
            throw new RuntimeException('No pages were produced while splitting the PDF.');
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $mode = strtolower((string) ($options['split_mode'] ?? 'all_pages'));
        if ($mode === 'every_n' && $pdfunite === null) {
            throw new RuntimeException('Poppler pdfunite binary is required for every_n split mode.');
        }

        $outputFilename = $baseTitle . '-split-pages.zip';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create split PDF ZIP archive.');
        }

        $filesCreated = 0;

        if ($mode === 'every_n' && $pdfunite !== null) {
            $chunkSize = max(1, min($totalPages, (int) ($options['every_n_pages'] ?? 2)));
            $chunks = array_chunk($singlePages, $chunkSize, true);
            foreach ($chunks as $chunkIdx => $chunkFiles) {
                $firstPage = array_key_first($chunkFiles) + 1;
                $lastPage = array_key_last($chunkFiles) + 1;
                $partName = sprintf('%s-pages-%d-to-%d.pdf', $baseTitle, $firstPage, $lastPage);
                $partPath = $workspace['work'] . '/' . $partName;

                if (count($chunkFiles) === 1) {
                    copy(reset($chunkFiles), $partPath);
                } else {
                    $uCmd = array_merge([$pdfunite], array_values($chunkFiles), [$partPath]);
                    $uRes = $this->runner->run($uCmd, $workspace['work']);
                    if (!$uRes->isSuccessful()) {
                        throw new RuntimeException('Failed to assemble split page group.');
                    }
                }
                $zip->addFile($partPath, $partName);
                $filesCreated++;
            }
        } else {
            foreach ($singlePages as $idx => $pageFile) {
                $entryName = sprintf('%s-page-%03d.pdf', $baseTitle, $idx + 1);
                $zip->addFile($pageFile, $entryName);
                $filesCreated++;
            }
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
                'split_files_count' => $filesCreated,
                'split_mode' => $mode,
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function reorderPages(StagedFile $input, array $options, array $workspace): DriverResult
    {
        $pdfseparate = $this->capabilities->getBinaryPath('pdfseparate', ['pdfseparate']);
        $pdfunite = $this->capabilities->getBinaryPath('pdfunite', ['pdfunite']);
        if ($pdfseparate === null || $pdfunite === null) {
            throw new RuntimeException('Poppler pdfseparate/pdfunite binaries are required.');
        }

        $info = $this->inspector->inspect($input->internalPath);
        if ($info['encrypted']) {
            throw new ValidationException('This PDF is password-protected. Please unlock it first.');
        }
        $totalPages = max(1, (int) $info['page_count']);

        $orderSpec = trim((string) ($options['page_order'] ?? ''));
        if ($orderSpec === '') {
            throw new ValidationException('Please specify the desired page order (for example: 2, 1, 3).');
        }

        $orderedPages = PdfInspector::parsePageRangeSpec($orderSpec, $totalPages);
        if ($orderedPages === []) {
            throw new ValidationException(sprintf('The page order "%s" does not match any valid pages (1 to %d).', $orderSpec, $totalPages));
        }

        $pattern = $workspace['work'] . '/page-%04d.pdf';
        $sepRes = $this->runner->run([$pdfseparate, $input->internalPath, $pattern], $workspace['work']);
        if (!$sepRes->isSuccessful()) {
            throw new RuntimeException('Failed to separate pages for reordering.');
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-reordered.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        if (count($orderedPages) === 1) {
            $singleSrc = sprintf($workspace['work'] . '/page-%04d.pdf', $orderedPages[0]);
            if (!@copy($singleSrc, $outputPath)) {
                throw new RuntimeException('Failed to write reordered single-page PDF.');
            }
        } else {
            $cmd = [$pdfunite];
            foreach ($orderedPages as $pageNum) {
                $pagePath = sprintf($workspace['work'] . '/page-%04d.pdf', $pageNum);
                if (!is_file($pagePath)) {
                    throw new ValidationException(sprintf('Page %d does not exist in the source document.', $pageNum));
                }
                $cmd[] = $pagePath;
            }
            $cmd[] = $outputPath;
            $uniteRes = $this->runner->run($cmd, $workspace['work']);
            if (!$uniteRes->isSuccessful() || !is_file($outputPath)) {
                throw new RuntimeException('Failed to combine reordered PDF pages.');
            }
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
}

