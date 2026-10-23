<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\SafeProcessRunner;
use Docvera\Security\ValidationException;
use Docvera\Storage\StagedFile;
use RuntimeException;

/**
 * QPDF processing driver (used automatically when the qpdf binary is installed on the host).
 */
final class QpdfDriver implements ProcessingDriverInterface
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
        return 'qpdf';
    }

    public function isAvailable(): bool
    {
        return $this->capabilities->isAvailable('qpdf');
    }

    public function process(
        string $toolId,
        array $inputs,
        array $options,
        array $workspace
    ): DriverResult {
        $qpdf = $this->capabilities->getBinaryPath('qpdf', ['qpdf']);
        if ($qpdf === null) {
            throw new RuntimeException('QPDF binary was not detected on this server.');
        }

        return match ($toolId) {
            'merge-pdf' => $this->mergePdf($qpdf, $inputs, $workspace),
            'rotate-pages' => $this->rotatePages($qpdf, $inputs[0], $options, $workspace),
            'protect-pdf' => $this->protectPdf($qpdf, $inputs[0], $options, $workspace),
            'unlock-pdf' => $this->unlockPdf($qpdf, $inputs[0], $options, $workspace),
            default => throw new RuntimeException(sprintf('Tool "%s" is not supported by QpdfDriver.', $toolId)),
        };
    }

    /**
     * @param list<StagedFile> $inputs
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function mergePdf(string $qpdf, array $inputs, array $workspace): DriverResult
    {
        if (count($inputs) < 2) {
            throw new ValidationException('Merge PDF requires at least 2 PDF files.');
        }

        foreach ($inputs as $file) {
            $info = $this->inspector->inspect($file->internalPath);
            if ($info['encrypted'] && $info['page_count'] === 0) {
                throw new ValidationException(
                    sprintf('File "%s" is password-protected. Please unlock it first using the Unlock PDF tool.', $file->originalSafeName)
                );
            }
        }

        $outputFilename = 'merged-document.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [$qpdf, '--empty', '--pages'];
        foreach ($inputs as $file) {
            $cmd[] = $file->internalPath;
            $cmd[] = '1-z';
        }
        $cmd[] = '--';
        $cmd[] = $outputPath;

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('QPDF failed to merge PDF files.');
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
                'page_count' => (int) $outInfo['page_count'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function rotatePages(string $qpdf, StagedFile $input, array $options, array $workspace): DriverResult
    {
        $info = $this->inspector->inspect($input->internalPath);
        if ($info['encrypted'] && $info['page_count'] === 0) {
            throw new ValidationException(
                sprintf('File "%s" is password-protected. Please unlock it first using the Unlock PDF tool.', $input->originalSafeName)
            );
        }

        $angle = (int) ($options['angle'] ?? 90);
        if (!in_array($angle, [90, 180, 270], true)) {
            throw new ValidationException('Rotation angle must be 90, 180, or 270 degrees.');
        }

        $pagesSpec = trim((string) ($options['pages'] ?? 'all'));
        $rangeArg = ($pagesSpec === '' || strtolower($pagesSpec) === 'all') ? '1-z' : $pagesSpec;

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-rotated.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $qpdf,
            $input->internalPath,
            $outputPath,
            sprintf('--rotate=+%d:%s', $angle, $rangeArg),
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('QPDF failed to rotate PDF pages.');
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
                'angle_applied' => $angle,
                'new_rotation' => (int) $outInfo['rotation'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function protectPdf(string $qpdf, StagedFile $input, array $options, array $workspace): DriverResult
    {
        $userPass = (string) ($options['user_password'] ?? '');
        $ownerPass = (string) ($options['owner_password'] ?? '');
        if ($userPass === '' && $ownerPass === '') {
            throw new ValidationException('Please provide a password to protect the PDF.');
        }
        if ($ownerPass === '') {
            $ownerPass = $userPass . '_owner';
        }

        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-protected.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $qpdf,
            '--encrypt',
            $userPass,
            $ownerPass,
            '256',
            '--',
            $input->internalPath,
            $outputPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new RuntimeException('QPDF failed to encrypt the PDF.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'encrypted' => true,
                'encryption_strength' => '256-bit AES',
            ]
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{root: string, input: string, output: string, work: string} $workspace
     */
    private function unlockPdf(string $qpdf, StagedFile $input, array $options, array $workspace): DriverResult
    {
        $password = (string) ($options['password'] ?? '');
        $baseTitle = pathinfo($input->originalSafeName, PATHINFO_FILENAME) ?: 'document';
        $outputFilename = $baseTitle . '-unlocked.pdf';
        $outputPath = $workspace['output'] . '/' . $outputFilename;

        $cmd = [
            $qpdf,
            '--password=' . $password,
            '--decrypt',
            $input->internalPath,
            $outputPath,
        ];

        $res = $this->runner->run($cmd, $workspace['work']);
        if (!$res->isSuccessful() || !is_file($outputPath)) {
            throw new ValidationException('Unable to unlock PDF. Please verify that the password is correct.');
        }

        return new DriverResult(
            outputPath: $outputPath,
            outputFilename: $outputFilename,
            outputMimeType: 'application/pdf',
            outputBytes: (int) filesize($outputPath),
            driverName: $this->getName(),
            summary: [
                'encrypted' => false,
            ]
        );
    }
}

