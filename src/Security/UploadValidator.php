<?php

declare(strict_types=1);

namespace Docvera\Security;

use finfo;

/**
 * Inspects uploaded files using finfo MIME detection and magic header verification.
 * Never trusts client-provided Content-Type headers or file extensions alone.
 */
final class UploadValidator
{
    private int $globalMaxFileBytes;
    private int $globalMaxFiles;

    public function __construct(int $globalMaxFileBytes, int $globalMaxFiles)
    {
        $this->globalMaxFileBytes = max(1024, $globalMaxFileBytes);
        $this->globalMaxFiles = max(1, $globalMaxFiles);
    }

    /**
     * @param list<array{path: string, original_name: string, size?: int, error?: int}> $files
     * @param list<string> $allowedMimes
     * @return list<array{path: string, safe_name: string, mime: string, extension: string, size: int}>
     */
    public function validateFiles(
        array $files,
        array $allowedMimes,
        int $minFiles = 1,
        ?int $maxFiles = null,
        ?int $maxFileBytes = null
    ): array {
        $effectiveMaxFiles = min($this->globalMaxFiles, $maxFiles ?? $this->globalMaxFiles);
        $effectiveMaxBytes = min($this->globalMaxFileBytes, $maxFileBytes ?? $this->globalMaxFileBytes);

        $count = count($files);
        if ($count < $minFiles) {
            throw new ValidationException(
                $minFiles === 1
                    ? 'Please select at least 1 file to process.'
                    : sprintf('This tool requires at least %d files (you provided %d).', $minFiles, $count)
            );
        }

        if ($count > $effectiveMaxFiles) {
            throw new ValidationException(
                sprintf('This tool accepts at most %d files per operation (you provided %d).', $effectiveMaxFiles, $count)
            );
        }

        $validated = [];
        foreach ($files as $index => $file) {
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new ValidationException($this->translateUploadError($uploadError));
            }

            $path = (string) ($file['path'] ?? '');
            if ($path === '' || str_contains($path, "\0") || !is_file($path) || !is_readable($path)) {
                throw new ValidationException(sprintf('File #%d could not be read by the server.', $index + 1));
            }

            $size = (int) filesize($path);
            if ($size <= 0) {
                throw new ValidationException(sprintf('File "%s" is empty (0 bytes).', $this->sanitizeFilename((string) ($file['original_name'] ?? 'document'))));
            }

            if ($size > $effectiveMaxBytes) {
                $maxMb = round($effectiveMaxBytes / (1024 * 1024), 1);
                throw new ValidationException(
                    sprintf('File "%s" exceeds the maximum allowed size of %s MB.', $this->sanitizeFilename((string) ($file['original_name'] ?? 'document')), $maxMb)
                );
            }

            $detectedMime = $this->detectMimeType($path, (string) ($file['original_name'] ?? ''));
            if (!in_array($detectedMime, $allowedMimes, true)) {
                throw new ValidationException(
                    sprintf(
                        'File "%s" has unsupported type (%s). Allowed formats: %s.',
                        $this->sanitizeFilename((string) ($file['original_name'] ?? 'document')),
                        $detectedMime,
                        implode(', ', $allowedMimes)
                    )
                );
            }

            $this->verifyStructuralSignature($path, $detectedMime, (string) ($file['original_name'] ?? 'document'));

            $safeName = $this->sanitizeFilename((string) ($file['original_name'] ?? 'document'));
            $ext = $this->extensionForMime($detectedMime, $safeName);

            $validated[] = [
                'path' => $path,
                'safe_name' => $safeName,
                'mime' => $detectedMime,
                'extension' => $ext,
                'size' => $size,
            ];
        }

        return $validated;
    }

    public function detectMimeType(string $path, string $originalName = ''): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);

        // Normalize common finfo variations
        if ($mime === 'application/x-pdf') {
            return 'application/pdf';
        }
        if ($mime === 'image/jpg') {
            return 'image/jpeg';
        }

        // Check if header starts with %PDF- even when finfo reports octet-stream
        $header = $this->readHeaderBytes($path, 16);
        if (str_starts_with($header, '%PDF-')) {
            return 'application/pdf';
        }

        // Handle Office OpenXML files (.docx, .xlsx, .pptx) which finfo sometimes classifies as application/zip
        if ($mime === 'application/zip' && $originalName !== '') {
            $lowerExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($lowerExt === 'docx') {
                return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            }
            if ($lowerExt === 'xlsx') {
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
            if ($lowerExt === 'pptx') {
                return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
            }
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    public function verifyStructuralSignature(string $path, string $mime, string $originalName): void
    {
        $safeDisplay = $this->sanitizeFilename($originalName);
        $header = $this->readHeaderBytes($path, 1024);

        if ($mime === 'application/pdf') {
            $pos = strpos($header, '%PDF-');
            if ($pos === false || $pos > 32) {
                throw new ValidationException(sprintf('File "%s" is not a valid PDF document (missing %%PDF header signature).', $safeDisplay));
            }
            // Also verify PDF trailer / %%EOF or /Root /Pages markers exist in the file
            $size = (int) filesize($path);
            $tailOffset = max(0, $size - 4096);
            $fp = @fopen($path, 'rb');
            $tail = '';
            if (is_resource($fp)) {
                fseek($fp, $tailOffset);
                $tail = (string) fread($fp, 4096);
                fclose($fp);
            }
            if (!str_contains($tail, '%%EOF') && !str_contains($header, '%%EOF')) {
                throw new ValidationException(sprintf('File "%s" appears to be a truncated or corrupted PDF document (missing %%EOF marker).', $safeDisplay));
            }
            return;
        }

        if ($mime === 'image/jpeg') {
            if (!str_starts_with($header, "\xFF\xD8\xFF")) {
                throw new ValidationException(sprintf('File "%s" is not a valid JPEG image.', $safeDisplay));
            }
            return;
        }

        if ($mime === 'image/png') {
            if (!str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
                throw new ValidationException(sprintf('File "%s" is not a valid PNG image.', $safeDisplay));
            }
            return;
        }

        if ($mime === 'image/webp') {
            if (!str_starts_with($header, 'RIFF') || substr($header, 8, 4) !== 'WEBP') {
                throw new ValidationException(sprintf('File "%s" is not a valid WebP image.', $safeDisplay));
            }
            return;
        }

        if ($mime === 'text/plain') {
            if (str_contains($header, "\0")) {
                throw new ValidationException(sprintf('File "%s" contains binary data and is not a valid plain-text file.', $safeDisplay));
            }
        }
    }

    public function sanitizeFilename(string $filename): string
    {
        $clean = str_replace("\0", '', $filename);
        $clean = basename(str_replace('\\', '/', $clean));
        $clean = preg_replace('/[^a-zA-Z0-9._\-\s()]/', '_', $clean) ?? 'document';
        $clean = trim($clean, " ._");
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return 'document';
        }
        if (strlen($clean) > 120) {
            $ext = pathinfo($clean, PATHINFO_EXTENSION);
            $base = substr(pathinfo($clean, PATHINFO_FILENAME), 0, 100);
            $clean = $ext !== '' ? $base . '.' . $ext : $base;
        }
        return $clean;
    }

    private function readHeaderBytes(string $path, int $length): string
    {
        $fp = @fopen($path, 'rb');
        if (!is_resource($fp)) {
            return '';
        }
        $bytes = (string) fread($fp, $length);
        fclose($fp);
        return $bytes;
    }

    private function extensionForMime(string $mime, string $safeName): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-powerpoint' => 'ppt',
            default => strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) ?: 'bin',
        };
    }

    private function translateUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the server size limit.',
            UPLOAD_ERR_PARTIAL => 'The file upload was interrupted before completion.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server temporary storage error during upload.',
            default => 'File upload failed with validation error.',
        };
    }
}

