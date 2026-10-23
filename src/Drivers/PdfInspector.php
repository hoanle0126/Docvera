<?php

declare(strict_types=1);

namespace Docvera\Drivers;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Security\SafeProcessRunner;

/**
 * Inspects PDF documents to determine page count, dimensions, rotation, encryption state,
 * metadata fields, and text content using Poppler (pdfinfo/pdftotext) with pure-PHP fallbacks.
 */
final class PdfInspector
{
    private CapabilityDetector $capabilities;
    private SafeProcessRunner $runner;

    public function __construct(CapabilityDetector $capabilities, SafeProcessRunner $runner)
    {
        $this->capabilities = $capabilities;
        $this->runner = $runner;
    }

    /**
     * @return array{
     *   valid: bool,
     *   encrypted: bool,
     *   page_count: int,
     *   width_pts: float,
     *   height_pts: float,
     *   rotation: int,
     *   pdf_version: string,
     *   title: string,
     *   author: string,
     *   subject: string,
     *   keywords: string,
     *   creator: string,
     *   producer: string
     * }
     */
    public function inspect(string $pdfPath, ?string $password = null): array
    {
        $default = [
            'valid' => false,
            'encrypted' => false,
            'page_count' => 0,
            'width_pts' => 612.0,
            'height_pts' => 792.0,
            'rotation' => 0,
            'pdf_version' => '1.4',
            'title' => '',
            'author' => '',
            'subject' => '',
            'keywords' => '',
            'creator' => '',
            'producer' => '',
        ];

        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            return $default;
        }

        $pdfinfoBin = $this->capabilities->getBinaryPath('pdfinfo', ['pdfinfo']);
        if ($pdfinfoBin !== null) {
            $cmd = [$pdfinfoBin];
            if ($password !== null && $password !== '') {
                $cmd[] = '-upw';
                $cmd[] = $password;
            }
            $cmd[] = $pdfPath;

            $res = $this->runner->run($cmd, null, 10);
            if ($res->isSuccessful()) {
                return $this->parsePdfinfoOutput($res->stdout, $default);
            }

            $errLower = strtolower($res->stderr . ' ' . $res->stdout);
            if (str_contains($errLower, 'incorrect password') || str_contains($errLower, 'command line error')) {
                $default['valid'] = true;
                $default['encrypted'] = true;
                return $default;
            }
        }

        return $this->fallbackPhpInspect($pdfPath, $default);
    }

    public function extractText(string $pdfPath, ?string $password = null): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            return '';
        }

        $pdftotextBin = $this->capabilities->getBinaryPath('pdftotext', ['pdftotext']);
        if ($pdftotextBin !== null) {
            $cmd = [$pdftotextBin, '-raw'];
            if ($password !== null && $password !== '') {
                $cmd[] = '-upw';
                $cmd[] = $password;
            }
            $cmd[] = $pdfPath;
            $cmd[] = '-';

            $res = $this->runner->run($cmd, null, 15);
            if ($res->isSuccessful()) {
                return $res->stdout;
            }
        }

        // Pure PHP fallback for uncompressed PDF text literals
        $raw = (string) @file_get_contents($pdfPath);
        if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)\s*Tj/', $raw, $matches)) {
            return implode("\n", $matches[1]);
        }

        return '';
    }

    /**
     * Parses page specification strings like "1, 3-5, 7" against a document's total page count.
     *
     * @return list<int>
     */
    public static function parsePageRangeSpec(string $spec, int $totalPages): array
    {
        $clean = trim($spec);
        if ($clean === '' || strtolower($clean) === 'all') {
            return range(1, max(1, $totalPages));
        }

        $pages = [];
        $parts = array_filter(array_map('trim', explode(',', $clean)), static fn(string $p): bool => $p !== '');

        foreach ($parts as $part) {
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $start = (int) $m[1];
                $end = (int) $m[2];
                if ($start <= $end) {
                    for ($p = $start; $p <= $end; $p++) {
                        if ($p >= 1 && $p <= $totalPages) {
                            $pages[] = $p;
                        }
                    }
                } else {
                    for ($p = $start; $p >= $end; $p--) {
                        if ($p >= 1 && $p <= $totalPages) {
                            $pages[] = $p;
                        }
                    }
                }
            } elseif (preg_match('/^(\d+)-\s*$/', $part, $m)) {
                $start = (int) $m[1];
                for ($p = $start; $p <= $totalPages; $p++) {
                    if ($p >= 1 && $p <= $totalPages) {
                        $pages[] = $p;
                    }
                }
            } elseif (ctype_digit($part)) {
                $p = (int) $part;
                if ($p >= 1 && $p <= $totalPages) {
                    $pages[] = $p;
                }
            }
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    private function parsePdfinfoOutput(string $stdout, array $default): array
    {
        $info = $default;
        $info['valid'] = true;

        $lines = preg_split('/\r?\n/', $stdout) ?: [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));

            switch ($key) {
                case 'title':
                    $info['title'] = $val;
                    break;
                case 'author':
                    $info['author'] = $val;
                    break;
                case 'subject':
                    $info['subject'] = $val;
                    break;
                case 'keywords':
                    $info['keywords'] = $val;
                    break;
                case 'creator':
                    $info['creator'] = $val;
                    break;
                case 'producer':
                    $info['producer'] = $val;
                    break;
                case 'pages':
                    $info['page_count'] = max(0, (int) $val);
                    break;
                case 'encrypted':
                    $info['encrypted'] = str_starts_with(strtolower($val), 'yes');
                    break;
                case 'page size':
                    if (preg_match('/([0-9.]+)\s*x\s*([0-9.]+)\s*pts/i', $val, $m)) {
                        $info['width_pts'] = (float) $m[1];
                        $info['height_pts'] = (float) $m[2];
                    }
                    break;
                case 'page rot':
                    $info['rotation'] = ((int) $val) % 360;
                    break;
                case 'pdf version':
                    $info['pdf_version'] = $val;
                    break;
            }
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $default
     * @return array<string, mixed>
     */
    private function fallbackPhpInspect(string $pdfPath, array $default): array
    {
        $raw = (string) @file_get_contents($pdfPath);
        if (!str_starts_with($raw, '%PDF-')) {
            return $default;
        }

        $info = $default;
        $info['valid'] = true;
        if (preg_match('/^%PDF-([0-9.]+)/', $raw, $m)) {
            $info['pdf_version'] = $m[1];
        }
        if (str_contains($raw, '/Encrypt')) {
            $info['encrypted'] = true;
        }
        $pageMatches = preg_match_all('/\/Type\s*\/Page\b/', $raw);
        if (is_int($pageMatches) && $pageMatches > 0) {
            $info['page_count'] = $pageMatches;
        } elseif (preg_match('/\/Count\s+(\d+)/', $raw, $m)) {
            $info['page_count'] = (int) $m[1];
        }
        if (preg_match('/\/Rotate\s+(\d+)/', $raw, $m)) {
            $info['rotation'] = ((int) $m[1]) % 360;
        }
        if (preg_match('/\/MediaBox\s*\[\s*([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s+([0-9.\-]+)\s*\]/', $raw, $m)) {
            $info['width_pts'] = abs((float) $m[3] - (float) $m[1]);
            $info['height_pts'] = abs((float) $m[4] - (float) $m[2]);
        }

        return $info;
    }
}
