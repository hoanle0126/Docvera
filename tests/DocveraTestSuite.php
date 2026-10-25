<?php

declare(strict_types=1);

namespace Docvera\Tests;

use Docvera\Auth\Principal;
use Docvera\Billing\PayPalProvider;
use Docvera\Billing\StripeProvider;
use Docvera\Core\Application;
use Docvera\Core\Config;
use Docvera\Drivers\NativePhpPdfDriver;
use Docvera\Http\AdminController;
use Docvera\Http\ApiController;
use Docvera\Http\Controller;
use Docvera\Http\InstallController;
use Docvera\Http\Request;
use Docvera\Security\ValidationException;
use Docvera\Tests\Fixtures\FixtureFactory;
use Docvera\Tools\ToolRegistry;
use RuntimeException;
use Throwable;
use ZipArchive;

final class DocveraTestSuite
{
    private Application $app;
    private FixtureFactory $fixtures;
    private string $tmpDir;
    private int $passed = 0;
    private int $failed = 0;
    /** @var list<string> */
    private array $failures = [];

    public function __construct()
    {
        $basePath = dirname(__DIR__);
        $this->tmpDir = $basePath . '/tests/.tmp_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        mkdir($this->tmpDir . '/jobs', 0700, true);
        mkdir($this->tmpDir . '/staged', 0700, true);
        mkdir($this->tmpDir . '/database', 0700, true);
        mkdir($this->tmpDir . '/logs', 0700, true);
        mkdir($this->tmpDir . '/fixtures', 0700, true);

        $configData = require $basePath . '/config/app.php';
        $configData['paths']['jobs'] = $this->tmpDir . '/jobs';
        $configData['paths']['staged'] = $this->tmpDir . '/staged';
        $configData['paths']['database'] = $this->tmpDir . '/database';
        $configData['paths']['logs'] = $this->tmpDir . '/logs';
        $configData['database']['connection'] = 'file';
        $configData['database']['file_store_path'] = $this->tmpDir . '/database/test_store.json';
        $configData['limits']['max_file_size_mb'] = 2;
        $configData['limits']['max_file_size_bytes'] = 2 * 1024 * 1024;
        $configData['limits']['max_files_per_job'] = 5;

        $this->app = new Application(new Config($configData));
        $this->fixtures = new FixtureFactory(
            new NativePhpPdfDriver($this->app->capabilities),
            $this->app->runner,
            $this->app->capabilities
        );
    }

    public function runAll(): int
    {
        echo "Docvera Phase 1 & Phase 2 Automated Verification & SaaS Platform Test Suite\n";
        echo str_repeat('=', 76) . "\n";

        try {
            $this->testEnvironmentCapabilitySystem();
            $this->testToolRegistryStructure();
            $this->testMergePdf();
            $this->testSplitPdfAllPagesAndEveryN();
            $this->testExtractPages();
            $this->testDeletePages();
            $this->testReorderPages();
            $this->testRotatePages();
            $this->testImagesToPdf();
            $this->testPdfToImagesPngAndJpg();
            $this->testCompressPdf();
            $this->testPasswordProtectAndUnlockPdf();
            $this->testWatermarkPdf();
            $this->testCropPdf();
            $this->testAddPageNumbers();
            $this->testPdfMetadataViewAndEdit();
            $this->testGrayscalePdf();
            $this->testFlattenPdf();
            $this->testTextToPdf();
            $this->testUnavailableCapabilityGating();
            $this->testLibreOfficeAndTesseractDriversWithShimBinaries();
            $this->testSecurityValidationAndCleanupLifecycle();

            // Phase 2 SaaS, Entitlements, Billing, REST API, Multi-Deployment & Isolation Tests
            $this->testGuestRegisteredPaidAndAdminPlanEntitlements();
            $this->testQuotaExhaustionAndReset();
            $this->testConcurrentJobSlots();
            $this->testSubscriptionLifecycleAndWebhooksWithSignatureAndIdempotency();
            $this->testRestApiAuthenticationRevocationScopesRateLimitsAndQuotas();
            $this->testRestApiSyncIdempotencyAndAsyncWorkerLifecycle();
            $this->testUnauthorizedFileAccessIsolationAndAdminPermissions();
            $this->testMultiDeploymentModesAndRetentionMetadataPreservation();
            $this->testViewRenderingAndSeoMetadata();
            $this->testAuthBruteForceAndRegistrationRateLimiting();
            $this->testGuidedWebInstallerPreflightExecutionAndLockout();
            $this->testAdminControllerFormActionsAndViewDataParity();
            $this->testUpgradePathFromPhase1SchemaFixture();
            $this->testOpenXmlArchiveSecurityAndRestApiHardening();
        } finally {
            $this->app->storage->removeDirectoryRecursive($this->tmpDir);
        }

        echo str_repeat('=', 76) . "\n";
        echo sprintf("Test Results: %d passed, %d failed\n", $this->passed, $this->failed);

        if ($this->failures !== []) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "  - " . $failure . "\n";
            }
            return 1;
        }

        return 0;
    }

    private function testEnvironmentCapabilitySystem(): void
    {
        $this->runCase('Capability system detects PHP, extensions, storage, Ghostscript, and Poppler', function (): void {
            $all = $this->app->capabilities->detectAll(true);
            $this->assertTrue($all['php_runtime']->available, 'php_runtime should be available');
            $this->assertTrue($all['php_extensions']->available, 'php_extensions should be available');
            $this->assertTrue($all['writable_storage']->available, 'writable_storage should be available');
            $this->assertTrue($all['database']->available, 'database should be available');
            $this->assertTrue($all['ghostscript']->available, 'ghostscript should be available');
            $this->assertTrue($all['poppler']->available, 'poppler should be available');
            $this->assertTrue(isset($all['libreoffice']), 'libreoffice capability entry must be present');
            $this->assertTrue(isset($all['tesseract']), 'tesseract capability entry must be present');

            $report = $this->app->capabilities->toMachineReadableReport();
            $this->assertTrue($report['healthy'] === true, 'Machine-readable report should be healthy');
        });
    }

    private function testToolRegistryStructure(): void
    {
        $this->runCase('Tool Registry exposes 22 structured tools (18 core + 4 capability-gated)', function (): void {
            $tools = $this->app->registry->all(true);
            $this->assertEquals(22, count($tools), 'Expected 22 Phase 1 tools');

            $active = array_filter($tools, static fn($t): bool => $t->availability->available);
            $this->assertTrue(count($active) >= 18, 'Expected at least 18 active tools on this host');

            // Verify deterministic capability gating when optional binaries are absent
            $gatedConfig = new Config($this->app->config->all());
            $gatedConfig->set('binaries.libreoffice', '__DISABLED__');
            $gatedConfig->set('binaries.tesseract', '__DISABLED__');
            $gatedApp = new Application($gatedConfig);

            $gatedActive = array_filter($gatedApp->registry->all(true), static fn($t): bool => $t->availability->available);
            $this->assertEquals(18, count($gatedActive), 'Expected 18 active tools when LibreOffice and Tesseract are absent');

            foreach (['ocr-pdf', 'word-to-pdf', 'excel-to-pdf', 'powerpoint-to-pdf'] as $gatedId) {
                $t = $gatedApp->registry->get($gatedId);
                $this->assertTrue($t !== null, "Tool $gatedId must exist");
                $this->assertTrue(!$t->availability->available, "Tool $gatedId must be marked unavailable when binary is absent");
                $this->assertTrue(
                    $t->availability->unavailableReason !== null && $t->availability->unavailableReason !== '',
                    "Tool $gatedId must provide an unavailable reason"
                );
            }
        });
    }

    private function testMergePdf(): void
    {
        $this->runCase('Merge PDF combines 1-page + 4-page PDFs into a valid 5-page PDF', function (): void {
            $pdf1 = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/merge1.pdf', 'MergeTokenFirstDoc');
            $pdf2 = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/merge2.pdf');

            $job = $this->app->executor->execute('merge-pdf', [
                ['path' => $pdf1, 'original_name' => 'first.pdf'],
                ['path' => $pdf2, 'original_name' => 'second.pdf'],
            ], [], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(5, $info['page_count'], 'Merged PDF must have 5 pages');

            $text = $this->app->inspector->extractText($outPath);
            $this->assertContains('MergeTokenFirstDoc', $text, 'Merged PDF must contain first document text');
            $this->assertContains('PageFourToken', $text, 'Merged PDF must contain last page text');
        });
    }

    private function testSplitPdfAllPagesAndEveryN(): void
    {
        $this->runCase('Split PDF splits 4-page PDF into 4 single-page PDFs and 2 two-page PDFs', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/split_src.pdf');

            // Mode 1: all_pages
            $jobAll = $this->app->executor->execute('split-pdf', [
                ['path' => $multi, 'original_name' => 'quarterly.pdf'],
            ], ['split_mode' => 'all_pages'], 'session_test_1');

            $zipPath = $this->app->storage->getJobOutputPath((string) $jobAll['id'], (string) $jobAll['output_filename']);
            $this->assertEquals('application/zip', $jobAll['output_mime'], 'Split output should be application/zip');

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true, 'Split ZIP must open cleanly');
            $this->assertEquals(4, $zip->numFiles, 'ZIP must contain 4 single-page PDF files');

            $extractDir = $this->tmpDir . '/split_verify';
            mkdir($extractDir, 0700, true);
            $zip->extractTo($extractDir);
            $zip->close();

            $extractedPdfs = glob($extractDir . '/*.pdf') ?: [];
            $this->assertEquals(4, count($extractedPdfs), '4 PDF files extracted');
            foreach ($extractedPdfs as $ep) {
                $this->assertValidPdfSignature($ep);
                $pInfo = $this->app->inspector->inspect($ep);
                $this->assertEquals(1, $pInfo['page_count'], 'Each split PDF must have 1 page');
            }

            // Mode 2: every_n (2 pages per file)
            $jobEvery2 = $this->app->executor->execute('split-pdf', [
                ['path' => $multi, 'original_name' => 'quarterly.pdf'],
            ], ['split_mode' => 'every_n', 'every_n_pages' => 2], 'session_test_1');

            $zip2Path = $this->app->storage->getJobOutputPath((string) $jobEvery2['id'], (string) $jobEvery2['output_filename']);
            $zip2 = new ZipArchive();
            $this->assertTrue($zip2->open($zip2Path) === true, 'Every-2 ZIP must open');
            $this->assertEquals(2, $zip2->numFiles, '4-page PDF split every 2 pages must yield 2 files');
            $zip2->close();
        });
    }

    private function testExtractPages(): void
    {
        $this->runCase('Extract Pages pulls pages 2 and 4 into a 2-page PDF with exact content', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/extract_src.pdf');

            $job = $this->app->executor->execute('extract-pages', [
                ['path' => $multi, 'original_name' => 'report.pdf'],
            ], ['pages' => '2, 4'], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(2, $info['page_count'], 'Extracted PDF must have 2 pages');

            $text = $this->app->inspector->extractText($outPath);
            $this->assertContains('PageTwoToken', $text, 'Must contain page 2 text');
            $this->assertContains('PageFourToken', $text, 'Must contain page 4 text');
            $this->assertTrue(!str_contains($text, 'PageOneToken'), 'Must not contain page 1 text');
            $this->assertTrue(!str_contains($text, 'PageThreeToken'), 'Must not contain page 3 text');
        });
    }

    private function testDeletePages(): void
    {
        $this->runCase('Delete Pages removes pages 1 and 3 and prevents deleting all pages', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/delete_src.pdf');

            $job = $this->app->executor->execute('delete-pages', [
                ['path' => $multi, 'original_name' => 'report.pdf'],
            ], ['pages_to_delete' => '1, 3'], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(2, $info['page_count'], 'Remaining PDF must have 2 pages');

            $text = $this->app->inspector->extractText($outPath);
            $this->assertContains('PageTwoToken', $text, 'Remaining PDF must keep page 2');
            $this->assertContains('PageFourToken', $text, 'Remaining PDF must keep page 4');
            $this->assertTrue(!str_contains($text, 'PageOneToken'), 'Page 1 must be deleted');

            // Verify deleting all pages throws ValidationException
            $threw = false;
            try {
                $this->app->executor->execute('delete-pages', [
                    ['path' => $multi, 'original_name' => 'report.pdf'],
                ], ['pages_to_delete' => '1-4'], 'session_test_1');
            } catch (ValidationException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'Deleting all pages must throw ValidationException');
        });
    }

    private function testReorderPages(): void
    {
        $this->runCase('Reorder Pages rearranges 4-page PDF into sequence 4, 2, 1, 3', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/reorder_src.pdf');

            $job = $this->app->executor->execute('reorder-pages', [
                ['path' => $multi, 'original_name' => 'deck.pdf'],
            ], ['page_order' => '4, 2, 1, 3'], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(4, $info['page_count'], 'Reordered PDF must have 4 pages');

            // Extract page 1 of the reordered PDF to confirm it is PageFourToken
            $checkJob = $this->app->executor->execute('extract-pages', [
                ['path' => $outPath, 'original_name' => 'reordered.pdf'],
            ], ['pages' => '1'], 'session_test_1');
            $page1Path = $this->app->storage->getJobOutputPath((string) $checkJob['id'], (string) $checkJob['output_filename']);
            $page1Text = $this->app->inspector->extractText($page1Path);
            $this->assertContains('PageFourToken', $page1Text, 'First page after reorder must be PageFourToken');
        });
    }

    private function testRotatePages(): void
    {
        $this->runCase('Rotate Pages updates PDF page orientation (0 -> 90 and 90 -> 270)', function (): void {
            $single = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/rot_0.pdf');
            $job90 = $this->app->executor->execute('rotate-pages', [
                ['path' => $single, 'original_name' => 'portrait.pdf'],
            ], ['angle' => '90', 'pages' => 'all'], 'session_test_1');

            $out90 = $this->app->storage->getJobOutputPath((string) $job90['id'], (string) $job90['output_filename']);
            $this->assertValidPdfSignature($out90);
            $info90 = $this->app->inspector->inspect($out90);
            $this->assertEquals(90, $info90['rotation'], 'Page rotation should be 90 degrees');

            // Rotate an already 90-degree rotated fixture by 180 degrees -> 270 degrees
            $preRotated = $this->fixtures->createRotatedPdf($this->tmpDir . '/fixtures/rot_pre90.pdf', 90);
            $job270 = $this->app->executor->execute('rotate-pages', [
                ['path' => $preRotated, 'original_name' => 'prerotated.pdf'],
            ], ['angle' => '180', 'pages' => 'all'], 'session_test_1');

            $out270 = $this->app->storage->getJobOutputPath((string) $job270['id'], (string) $job270['output_filename']);
            $info270 = $this->app->inspector->inspect($out270);
            $this->assertEquals(270, $info270['rotation'], '90 + 180 rotation should equal 270 degrees');
        });
    }

    private function testImagesToPdf(): void
    {
        $this->runCase('Images to PDF compiles PNG + JPEG into a 2-page PDF with embedded images', function (): void {
            $png = $this->fixtures->createValidPng($this->tmpDir . '/fixtures/photo1.png', 200, 150);
            $jpg = $this->fixtures->createValidJpeg($this->tmpDir . '/fixtures/photo2.jpg');

            $job = $this->app->executor->execute('images-to-pdf', [
                ['path' => $png, 'original_name' => 'photo1.png'],
                ['path' => $jpg, 'original_name' => 'photo2.jpg'],
            ], [
                'page_size' => 'a4',
                'orientation' => 'auto',
                'margin' => 'small',
            ], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(2, $info['page_count'], 'Generated PDF must have 2 pages for 2 images');
        });
    }

    private function testPdfToImagesPngAndJpg(): void
    {
        $this->runCase('PDF to Images renders multi-page PDF into PNG ZIP and single-page PDF into JPEG', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/render_multi.pdf');

            $jobZip = $this->app->executor->execute('pdf-to-images', [
                ['path' => $multi, 'original_name' => 'slides.pdf'],
            ], ['image_format' => 'png', 'dpi' => '150', 'pages' => 'all'], 'session_test_1');

            $zipPath = $this->app->storage->getJobOutputPath((string) $jobZip['id'], (string) $jobZip['output_filename']);
            $this->assertEquals('application/zip', $jobZip['output_mime'], 'Multi-page export should be a ZIP');

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true, 'Rendered images ZIP must open');
            $this->assertEquals(4, $zip->numFiles, 'ZIP must contain 4 rendered PNG images');
            $firstPngBytes = (string) $zip->getFromIndex(0);
            $zip->close();
            $this->assertTrue(str_starts_with($firstPngBytes, "\x89PNG\r\n\x1a\n"), 'Rendered file must have valid PNG header');

            // Single page export to JPG
            $single = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/render_single.pdf');
            $jobJpg = $this->app->executor->execute('pdf-to-images', [
                ['path' => $single, 'original_name' => 'flyer.pdf'],
            ], ['image_format' => 'jpg', 'dpi' => '150', 'pages' => '1'], 'session_test_1');

            $jpgPath = $this->app->storage->getJobOutputPath((string) $jobJpg['id'], (string) $jobJpg['output_filename']);
            $this->assertEquals('image/jpeg', $jobJpg['output_mime'], 'Single-page JPG export should return image/jpeg');
            $imgInfo = (array) getimagesize($jpgPath);
            $this->assertTrue(($imgInfo[0] ?? 0) > 100 && ($imgInfo[1] ?? 0) > 100, 'Rendered JPG must have positive dimensions');
        });
    }

    private function testCompressPdf(): void
    {
        $this->runCase('Compress PDF optimizes image-heavy PDF and preserves page count', function (): void {
            $heavy = $this->fixtures->createImageHeavyPdf($this->tmpDir . '/fixtures/heavy.pdf');

            $job = $this->app->executor->execute('compress-pdf', [
                ['path' => $heavy, 'original_name' => 'scan_bundle.pdf'],
            ], ['compression_level' => 'screen'], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(1, $info['page_count'], 'Compressed PDF must preserve page count');
            $this->assertTrue(isset($job['summary']['saved_percent']), 'Summary must report saved_percent');
        });
    }

    private function testPasswordProtectAndUnlockPdf(): void
    {
        $this->runCase('Password Protect PDF encrypts document and Unlock PDF decrypts with password verification', function (): void {
            $plain = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/confidential_plain.pdf', 'VaultSecretPayload2026');

            // 1. Encrypt
            $protectJob = $this->app->executor->execute('protect-pdf', [
                ['path' => $plain, 'original_name' => 'contract.pdf'],
            ], [
                'user_password' => 'CorrectHorseBattery99',
                'owner_password' => 'OwnerMasterKey99',
            ], 'session_test_1');

            $protectedPath = $this->app->storage->getJobOutputPath((string) $protectJob['id'], (string) $protectJob['output_filename']);
            $this->assertValidPdfSignature($protectedPath);

            $inspProtected = $this->app->inspector->inspect($protectedPath);
            $this->assertTrue($inspProtected['encrypted'] === true, 'Protected PDF must be flagged as encrypted');

            // 2. Attempt unlock with WRONG password -> must fail with ValidationException
            $wrongPassFailed = false;
            try {
                $this->app->executor->execute('unlock-pdf', [
                    ['path' => $protectedPath, 'original_name' => 'contract-protected.pdf'],
                ], ['password' => 'WrongPassword123'], 'session_test_1');
            } catch (ValidationException) {
                $wrongPassFailed = true;
            }
            $this->assertTrue($wrongPassFailed, 'Unlocking with wrong password must throw ValidationException');

            // 3. Unlock with CORRECT password -> must succeed and restore unencrypted PDF
            $unlockJob = $this->app->executor->execute('unlock-pdf', [
                ['path' => $protectedPath, 'original_name' => 'contract-protected.pdf'],
            ], ['password' => 'CorrectHorseBattery99'], 'session_test_1');

            $unlockedPath = $this->app->storage->getJobOutputPath((string) $unlockJob['id'], (string) $unlockJob['output_filename']);
            $this->assertValidPdfSignature($unlockedPath);

            $inspUnlocked = $this->app->inspector->inspect($unlockedPath);
            $this->assertTrue($inspUnlocked['encrypted'] === false, 'Unlocked PDF must have encrypted = false');

            $extracted = $this->app->inspector->extractText($unlockedPath);
            $this->assertContains('VaultSecretPayload2026', $extracted, 'Unlocked PDF must preserve original text');
        });
    }

    private function testWatermarkPdf(): void
    {
        $this->runCase('Watermark PDF stamps persistent vector watermark across pages', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/wm_src.pdf');

            $job = $this->app->executor->execute('watermark-pdf', [
                ['path' => $multi, 'original_name' => 'proposal.pdf'],
            ], [
                'watermark_text' => 'DOCVERA-VERIFIED-STAMP',
                'position' => 'center_diagonal',
                'font_size' => 36,
                'opacity' => 40,
            ], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(4, $info['page_count'], 'Watermarked PDF must preserve 4 pages');

            $text = $this->app->inspector->extractText($outPath);
            $this->assertContains('DOCVERA-VERIFIED-STAMP', $text, 'Watermark text must be embedded in PDF content stream');
        });
    }

    private function testCropPdf(): void
    {
        $this->runCase('Crop PDF trims 36pt from all margins of a 612x792 PDF to 540x720 pts', function (): void {
            $single = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/crop_src.pdf');

            $job = $this->app->executor->execute('crop-pdf', [
                ['path' => $single, 'original_name' => 'letter.pdf'],
            ], [
                'margin_top' => 36,
                'margin_right' => 36,
                'margin_bottom' => 36,
                'margin_left' => 36,
            ], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals(540, (int) round($info['width_pts']), 'Cropped width must be 540 pts');
            $this->assertEquals(720, (int) round($info['height_pts']), 'Cropped height must be 720 pts');
        });
    }

    private function testAddPageNumbers(): void
    {
        $this->runCase('Add Page Numbers stamps "Page 1 of 4" through "Page 4 of 4" onto pages', function (): void {
            $multi = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/pagenum_src.pdf');

            $job = $this->app->executor->execute('page-numbers', [
                ['path' => $multi, 'original_name' => 'manual.pdf'],
            ], [
                'position' => 'bottom_center',
                'format' => 'page_x_of_y',
                'start_number' => 1,
                'font_size' => 10,
            ], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $text = $this->app->inspector->extractText($outPath);
            $this->assertContains('Page 1 of 4', $text, 'Must contain Page 1 of 4');
            $this->assertContains('Page 4 of 4', $text, 'Must contain Page 4 of 4');
        });
    }

    private function testPdfMetadataViewAndEdit(): void
    {
        $this->runCase('PDF Metadata writes Title, Author, Subject, and Keywords into PDF Info dictionary', function (): void {
            $single = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/meta_src.pdf');

            $job = $this->app->executor->execute('pdf-metadata', [
                ['path' => $single, 'original_name' => 'whitepaper.pdf'],
            ], [
                'title' => 'Docvera Architecture Whitepaper',
                'author' => 'Principal Systems Team',
                'subject' => 'Self-Hosted Document Security',
                'keywords' => 'pdf, self-hosted, docvera',
                'creator' => 'Docvera QA Suite',
            ], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $info = $this->app->inspector->inspect($outPath);
            $this->assertEquals('Docvera Architecture Whitepaper', $info['title'], 'Title metadata must match');
            $this->assertEquals('Principal Systems Team', $info['author'], 'Author metadata must match');
            $this->assertEquals('Self-Hosted Document Security', $info['subject'], 'Subject metadata must match');
            $this->assertEquals('pdf, self-hosted, docvera', $info['keywords'], 'Keywords metadata must match');
        });
    }

    private function testGrayscalePdf(): void
    {
        $this->runCase('Grayscale PDF converts color document to DeviceGray color space', function (): void {
            $colorPdf = $this->fixtures->createImageHeavyPdf($this->tmpDir . '/fixtures/color_src.pdf');

            $job = $this->app->executor->execute('grayscale-pdf', [
                ['path' => $colorPdf, 'original_name' => 'brochure.pdf'],
            ], [], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $raw = (string) file_get_contents($outPath);
            $this->assertContains('DeviceGray', $raw, 'Grayscale PDF must use DeviceGray color space');
        });
    }

    private function testFlattenPdf(): void
    {
        $this->runCase('Flatten PDF processes both annotations mode and full-page rasterize mode', function (): void {
            $single = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/flatten_src.pdf', 'FlattenVerificationText');

            $jobAnnots = $this->app->executor->execute('flatten-pdf', [
                ['path' => $single, 'original_name' => 'form.pdf'],
            ], ['flatten_mode' => 'annotations'], 'session_test_1');

            $outAnnots = $this->app->storage->getJobOutputPath((string) $jobAnnots['id'], (string) $jobAnnots['output_filename']);
            $this->assertValidPdfSignature($outAnnots);

            $jobRaster = $this->app->executor->execute('flatten-pdf', [
                ['path' => $single, 'original_name' => 'form.pdf'],
            ], ['flatten_mode' => 'rasterize'], 'session_test_1');

            $outRaster = $this->app->storage->getJobOutputPath((string) $jobRaster['id'], (string) $jobRaster['output_filename']);
            $this->assertValidPdfSignature($outRaster);
            $info = $this->app->inspector->inspect($outRaster);
            $this->assertEquals(1, $info['page_count'], 'Rasterized flattened PDF must have 1 page');
        });
    }

    private function testTextToPdf(): void
    {
        $this->runCase('Text to PDF converts .txt file and direct text content into paginated PDF', function (): void {
            $txtFile = $this->fixtures->createSampleTextFile(
                $this->tmpDir . '/fixtures/notes.txt',
                "Docvera Text To PDF Heading\nSecond Line Token Alpha99"
            );

            $job = $this->app->executor->execute('text-to-pdf', [
                ['path' => $txtFile, 'original_name' => 'notes.txt'],
            ], [
                'page_size' => 'a4',
                'font_family' => 'Courier',
                'font_size' => 11,
            ], 'session_test_1');

            $outPath = $this->app->storage->getJobOutputPath((string) $job['id'], (string) $job['output_filename']);
            $this->assertValidPdfSignature($outPath);

            $extracted = $this->app->inspector->extractText($outPath);
            $this->assertContains('Docvera Text To PDF Heading', $extracted, 'Generated PDF must contain heading text');
            $this->assertContains('Second Line Token Alpha99', $extracted, 'Generated PDF must contain second line');
        });
    }

    private function testUnavailableCapabilityGating(): void
    {
        $this->runCase('Unavailable tools (Word/Excel/PPT/OCR) block execution cleanly with 422 before running', function (): void {
            $docx = $this->fixtures->createMinimalDocx($this->tmpDir . '/fixtures/sample.docx');

            $gatedConfig = new Config($this->app->config->all());
            $gatedConfig->set('binaries.libreoffice', '__DISABLED__');
            $gatedApp = new Application($gatedConfig);

            $caughtCode = 0;
            $caughtMessage = '';
            try {
                $gatedApp->executor->execute('word-to-pdf', [
                    ['path' => $docx, 'original_name' => 'sample.docx'],
                ], [], 'session_test_1');
            } catch (ValidationException $ve) {
                $caughtCode = $ve->getCode();
                $caughtMessage = $ve->getMessage();
            }

            $this->assertEquals(422, $caughtCode, 'Unavailable tool must throw 422 ValidationException');
            $this->assertContains('LibreOffice is required', $caughtMessage, 'Reason must mention LibreOffice');
        });
    }

    private function testLibreOfficeAndTesseractDriversWithShimBinaries(): void
    {
        $this->runCase('LibreOfficeDriver and TesseractOcrDriver execute end-to-end when binaries are detected', function (): void {
            $binDir = $this->tmpDir . '/mock_bin';
            mkdir($binDir, 0700, true);

            $samplePdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/mock_ref.pdf', 'ConvertedViaHeadlessDriverToken');

            // Create executable shim for libreoffice
            $loShim = $binDir . '/libreoffice';
            $loScript = "#!/usr/bin/env php\n<?php\n"
                . "if (in_array('--version', \$argv, true)) { echo \"LibreOffice 24.2.5.2\\n\"; exit(0); }\n"
                . "\$outIdx = array_search('--outdir', \$argv, true);\n"
                . "\$outDir = \$argv[\$outIdx + 1];\n"
                . "\$inFile = end(\$argv);\n"
                . "\$base = pathinfo(\$inFile, PATHINFO_FILENAME);\n"
                . "copy(" . var_export($samplePdf, true) . ", \$outDir . '/' . \$base . '.pdf');\n"
                . "exit(0);\n";
            file_put_contents($loShim, $loScript);
            chmod($loShim, 0755);

            // Create executable shim for tesseract
            $tessShim = $binDir . '/tesseract';
            $tessScript = "#!/usr/bin/env php\n<?php\n"
                . "if (in_array('--version', \$argv, true)) { echo \"tesseract 5.3.4\\n\"; exit(0); }\n"
                . "\$outBase = \$argv[2];\n"
                . "if (in_array('pdf', \$argv, true)) {\n"
                . "  copy(" . var_export($samplePdf, true) . ", \$outBase . '.pdf');\n"
                . "} else {\n"
                . "  file_put_contents(\$outBase . '.txt', \"OCR Extracted Text Token\\n\");\n"
                . "}\n"
                . "exit(0);\n";
            file_put_contents($tessShim, $tessScript);
            chmod($tessShim, 0755);

            $customConfig = new Config($this->app->config->all());
            $customConfig->set('binaries.libreoffice', $loShim);
            $customConfig->set('binaries.tesseract', $tessShim);

            $customApp = new Application($customConfig);
            $this->assertTrue($customApp->registry->get('word-to-pdf')?->availability->available === true, 'word-to-pdf becomes available when LibreOffice is detected');
            $this->assertTrue($customApp->registry->get('ocr-pdf')?->availability->available === true, 'ocr-pdf becomes available when Tesseract is detected');

            // Execute word-to-pdf
            $docx = $this->fixtures->createMinimalDocx($this->tmpDir . '/fixtures/contract.docx');
            $wordJob = $customApp->executor->execute('word-to-pdf', [
                ['path' => $docx, 'original_name' => 'contract.docx'],
            ], [], 'session_test_1');
            $wordOut = $customApp->storage->getJobOutputPath((string) $wordJob['id'], (string) $wordJob['output_filename']);
            $this->assertValidPdfSignature($wordOut);

            // Execute ocr-pdf
            $scanImg = $this->fixtures->createValidPng($this->tmpDir . '/fixtures/scan.png', 160, 120);
            $ocrJob = $customApp->executor->execute('ocr-pdf', [
                ['path' => $scanImg, 'original_name' => 'scan.png'],
            ], ['language' => 'eng', 'output_format' => 'pdf'], 'session_test_1');
            $ocrOut = $customApp->storage->getJobOutputPath((string) $ocrJob['id'], (string) $ocrJob['output_filename']);
            $this->assertValidPdfSignature($ocrOut);
        });
    }

    private function testSecurityValidationAndCleanupLifecycle(): void
    {
        $this->runCase('Security layer blocks malformed PDFs, oversized files, path traversal, and purges files on Delete Now & TTL expiry', function (): void {
            // 1. Malformed PDF rejected
            $malformed = $this->fixtures->createMalformedPdf($this->tmpDir . '/fixtures/fake.pdf');
            $malformedRejected = false;
            try {
                $this->app->executor->execute('compress-pdf', [
                    ['path' => $malformed, 'original_name' => 'fake.pdf'],
                ], [], 'session_test_1');
            } catch (ValidationException) {
                $malformedRejected = true;
            }
            $this->assertTrue($malformedRejected, 'Malformed PDF must be rejected by UploadValidator');

            // 2. Truncated PDF without %%EOF rejected
            $truncated = $this->fixtures->createTruncatedPdf($this->tmpDir . '/fixtures/truncated.pdf');
            $truncatedRejected = false;
            try {
                $this->app->executor->execute('compress-pdf', [
                    ['path' => $truncated, 'original_name' => 'truncated.pdf'],
                ], [], 'session_test_1');
            } catch (ValidationException) {
                $truncatedRejected = true;
            }
            $this->assertTrue($truncatedRejected, 'Truncated PDF missing %%EOF must be rejected');

            // 3. Oversized file (> 2 MB test limit) rejected
            $oversized = $this->fixtures->createOversizedFile($this->tmpDir . '/fixtures/huge.pdf', 3 * 1024 * 1024);
            $oversizedRejected = false;
            try {
                $this->app->executor->execute('compress-pdf', [
                    ['path' => $oversized, 'original_name' => 'huge.pdf'],
                ], [], 'session_test_1');
            } catch (ValidationException) {
                $oversizedRejected = true;
            }
            $this->assertTrue($oversizedRejected, 'Oversized file must be rejected');

            // 4. Path traversal identifier rejected
            $traversalBlocked = false;
            try {
                $this->app->storage->assertSafeIdentifier('../etc/passwd');
            } catch (RuntimeException) {
                $traversalBlocked = true;
            }
            $this->assertTrue($traversalBlocked, 'Path traversal job identifier must be rejected');

            // 5. Explicit "Delete Now" wipes job directory and marks record deleted_by_user
            $validPdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/clean_test.pdf');
            $job = $this->app->executor->execute('grayscale-pdf', [
                ['path' => $validPdf, 'original_name' => 'clean_test.pdf'],
            ], [], 'session_test_1');
            $jobId = (string) $job['id'];
            $this->assertTrue($this->app->storage->jobWorkspaceExists($jobId), 'Job workspace must exist before deletion');

            $this->app->cleanup->deleteJobNow($jobId);
            $this->assertTrue(!$this->app->storage->jobWorkspaceExists($jobId), 'Job workspace must be removed immediately after Delete Now');
            $updatedRecord = $this->app->jobs->find($jobId);
            $this->assertEquals('deleted_by_user', $updatedRecord['status'] ?? '', 'Job status must be deleted_by_user');

            // 6. Scheduled TTL cleanup purges expired jobs
            $job2 = $this->app->executor->execute('grayscale-pdf', [
                ['path' => $validPdf, 'original_name' => 'ttl_test.pdf'],
            ], [], 'session_test_1');
            $job2Id = (string) $job2['id'];

            // Advance simulated clock by 2 hours past retention TTL
            $purgeSummary = $this->app->cleanup->purgeExpired(time() + 7200);
            $this->assertTrue($purgeSummary['expired_jobs_purged'] >= 1, 'TTL cleanup must purge expired job');
            $this->assertTrue(!$this->app->storage->jobWorkspaceExists($job2Id), 'Expired job workspace must be removed from disk');
        });
    }

    private function testGuestRegisteredPaidAndAdminPlanEntitlements(): void
    {
        $this->runCase('Guest, Free Registered, Paid Pro, and Admin entitlements enforce configurable file, page, batch, OCR, and tool limits', function (): void {
            // Configure a custom restrictive guest plan for testing
            $guestPlan = $this->app->platform->findPlan('guest');
            $this->assertTrue($guestPlan !== null, 'Default guest plan must exist');

            $customGuestEnt = $guestPlan['entitlements'];
            $customGuestEnt['max_pages_per_op'] = 2;
            $customGuestEnt['batch_allowed'] = false;
            $customGuestEnt['allowed_tools'] = ['grayscale-pdf', 'rotate-pages'];
            $this->app->platform->savePlan([
                'id' => 'guest',
                'name' => 'Guest Restricted Test',
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'is_public' => false,
                'is_active' => true,
                'sort_order' => 0,
                'entitlements' => $customGuestEnt,
            ]);

            $guestPrincipal = $this->app->auth->resolveSessionPrincipal('guest_sess_ent_1');

            // 1. Disallowed tool for guest must be blocked by EntitlementService
            $mergeTool = $this->app->registry->get('merge-pdf');
            $this->assertTrue($mergeTool !== null, 'merge-pdf must exist');
            $decisionDisallowed = $this->app->entitlements->evaluateToolAccess($guestPrincipal, $mergeTool);
            $this->assertTrue(!$decisionDisallowed->allowed, 'Tool outside allowed_tools must be denied');
            $this->assertEquals('plan_tool_restricted', $decisionDisallowed->reasonCode, 'Reason code must be plan_tool_restricted');

            // 2. Page limit enforcement: 4-page PDF on rotate-pages when max_pages_per_op = 2
            $fourPagePdf = $this->fixtures->createMultiPagePdf($this->tmpDir . '/fixtures/ent_4p.pdf', ['P1', 'P2', 'P3', 'P4']);
            $pageLimitBlocked = false;
            try {
                $this->app->executor->execute(
                    'rotate-pages',
                    [['path' => $fourPagePdf, 'original_name' => 'ent_4p.pdf']],
                    ['angle' => 90, 'pages' => 'all'],
                    'guest_sess_ent_1',
                    $guestPrincipal
                );
            } catch (ValidationException $ve) {
                $pageLimitBlocked = str_contains($ve->getMessage(), 'exceeds your plan allowance of 2 pages');
            }
            $this->assertTrue($pageLimitBlocked, 'Guest exceeding max_pages_per_op (4 > 2) must be blocked before processing');

            // 3. Seed an admin first, then register a Free user who gets 100 pages allowance and succeeds on the 4-page PDF
            $adminUser = $this->app->auth->register('System Admin', 'admin@docvera.test', 'adminpass123', 'admin', 'free');
            $adminPrincipal = $this->app->auth->resolveUserPrincipalById((string) $adminUser['id'], 'admin_sess_1');
            $adminEnt = $this->app->entitlements->resolvePlanEntitlements($adminPrincipal);
            $this->assertTrue($adminEnt['ops_per_day'] >= 10000, 'Admin must receive unrestricted entitlement limits');

            $freeUser = $this->app->auth->register('Free Tester', 'free@docvera.test', 'password1234', 'user', 'free');
            $freePrincipal = $this->app->auth->resolveUserPrincipalById((string) $freeUser['id'], 'free_sess_1');
            $freeJob = $this->app->executor->execute(
                'rotate-pages',
                [['path' => $fourPagePdf, 'original_name' => 'ent_4p.pdf']],
                ['angle' => 90, 'pages' => 'all'],
                'free_sess_1',
                $freePrincipal
            );
            $this->assertEquals('completed', $freeJob['status'], 'Registered Free user must succeed on 4-page PDF');

            // 4. Paid Pro user has API access & OCR allowance
            $proUser = $this->app->auth->register('Pro Tester', 'pro@docvera.test', 'password1234', 'user', 'pro');
            $proPrincipal = $this->app->auth->resolveUserPrincipalById((string) $proUser['id'], 'pro_sess_1');
            $proEnt = $this->app->entitlements->resolvePlanEntitlements($proPrincipal);
            $this->assertTrue((bool) $proEnt['api_access'], 'Pro user must have API access enabled');
            $this->assertTrue((bool) $proEnt['ocr_allowed'], 'Pro user must have OCR enabled');

            // Restore default guest plan for subsequent tests
            $this->app->platform->savePlan($guestPlan);
        });
    }

    private function testQuotaExhaustionAndReset(): void
    {
        $this->runCase('Quota engine enforces daily operation exhaustion (429) and recovers cleanly upon bucket reset', function (): void {
            // Create a custom plan with ops_per_day = 2
            $this->app->platform->savePlan([
                'id' => 'micro_quota',
                'name' => 'Micro Quota Plan',
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'is_public' => false,
                'is_active' => true,
                'sort_order' => 9,
                'entitlements' => [
                    'ops_per_day' => 2,
                    'ops_per_month' => 10,
                    'max_file_size_mb' => 10,
                    'max_files_per_op' => 5,
                    'max_pages_per_op' => 50,
                    'concurrent_jobs' => 2,
                    'ocr_allowed' => false,
                    'batch_allowed' => true,
                    'allowed_tools' => ['*'],
                    'api_access' => false,
                    'api_requests_per_day' => 0,
                    'api_requests_per_month' => 0,
                    'api_rate_limit_per_min' => 10,
                    'retention_minutes' => 30,
                    'ads_visible' => false,
                ],
            ]);

            $user = $this->app->auth->register('Quota User', 'quota@docvera.test', 'password1234', 'user', 'micro_quota');
            $principal = $this->app->auth->resolveUserPrincipalById((string) $user['id'], 'quota_sess_1');
            $pdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/quota_single.pdf');

            // Operation 1 & 2 succeed
            $this->app->executor->execute('grayscale-pdf', [['path' => $pdf, 'original_name' => 'q1.pdf']], [], 'quota_sess_1', $principal);
            $this->app->executor->execute('grayscale-pdf', [['path' => $pdf, 'original_name' => 'q2.pdf']], [], 'quota_sess_1', $principal);

            // Operation 3 must fail with 429 Daily Quota Reached
            $quotaCode = 0;
            try {
                $this->app->executor->execute('grayscale-pdf', [['path' => $pdf, 'original_name' => 'q3.pdf']], [], 'quota_sess_1', $principal);
            } catch (ValidationException $ve) {
                $quotaCode = $ve->getCode();
            }
            $this->assertEquals(429, $quotaCode, 'Third operation exceeding ops_per_day=2 must fail with 429');

            // Reset daily usage bucket for subject (simulating UTC midnight reset) and verify operation succeeds again
            $dayKey = gmdate('Y-m-d');
            $this->app->platform->mutateUsageBucket($principal->subjectKey, 'user', 'day', $dayKey, static function (array $b): array {
                $b['ops_count'] = 0;
                return $b;
            });
            $jobAfterReset = $this->app->executor->execute('grayscale-pdf', [['path' => $pdf, 'original_name' => 'q4.pdf']], [], 'quota_sess_1', $principal);
            $this->assertEquals('completed', $jobAfterReset['status'], 'Operation must succeed immediately after quota reset');
        });
    }

    private function testConcurrentJobSlots(): void
    {
        $this->runCase('Concurrency limiter blocks simultaneous jobs exceeding plan concurrent_jobs limit and releases slots cleanly', function (): void {
            $guest = $this->app->auth->resolveSessionPrincipal('conc_sess_test');

            // Acquire 1 slot when maxConcurrent = 1
            $this->app->entitlements->acquireConcurrentSlot($guest, 1);

            // Attempting to acquire a 2nd concurrent slot when maxConcurrent = 1 must throw 429
            $concurrentBlocked = false;
            try {
                $this->app->entitlements->acquireConcurrentSlot($guest, 1);
            } catch (ValidationException $ve) {
                $concurrentBlocked = ($ve->getCode() === 429);
            }
            $this->assertTrue($concurrentBlocked, 'Second concurrent job must be blocked with 429 when 1/1 slot is active');

            // Release slot and verify slot can be acquired again cleanly
            $this->app->entitlements->releaseConcurrentSlot($guest);
            $this->app->entitlements->acquireConcurrentSlot($guest, 1);
            $this->app->entitlements->releaseConcurrentSlot($guest);
            $this->assertTrue(true, 'Concurrency slot re-acquired and released cleanly');
        });
    }

    private function testSubscriptionLifecycleAndWebhooksWithSignatureAndIdempotency(): void
    {
        $this->runCase('Billing engine verifies Stripe/PayPal webhook signatures, enforces idempotency, and transitions subscription & user plan states', function (): void {
            // 1. Default unconfigured state blocks fake checkout
            $this->assertTrue(!$this->app->billing->isAnyProviderConfigured(), 'Default test environment has no billing credentials configured');

            $billUser = $this->app->auth->register('Billing Customer', 'billing@docvera.test', 'password1234', 'user', 'free');
            $unconfBlocked = false;
            try {
                $this->app->billing->startCheckout((string) $billUser['id'], 'pro', 'stripe', 'monthly', 'http://localhost:8080/dashboard');
            } catch (ValidationException $ve) {
                $unconfBlocked = str_contains($ve->getMessage(), 'unconfigured');
            }
            $this->assertTrue($unconfBlocked, 'Unconfigured Stripe provider must refuse checkout rather than faking success');

            // 2. Configure Stripe & PayPal sandbox credentials in PlatformRepository settings
            $this->app->platform->saveSettings([
                'stripe_enabled' => true,
                'stripe_mode' => 'test',
                'stripe_secret_key' => 'sk_test_docvera_998877',
                'stripe_webhook_secret' => 'whsec_docvera_signing_secret_123',
                'paypal_enabled' => true,
                'paypal_mode' => 'sandbox',
                'paypal_client_id' => 'paypal_client_test_id',
                'paypal_client_secret' => 'paypal_secret_test_key',
                'paypal_webhook_secret' => 'wh_paypal_secret_999',
            ]);

            $this->assertTrue($this->app->billing->isAnyProviderConfigured(), 'Configured billing service must report providers ready');

            // 3. Start checkout session -> creates incomplete subscription record
            $checkoutRes = $this->app->billing->startCheckout((string) $billUser['id'], 'pro', 'stripe', 'monthly', 'http://localhost:8080/dashboard');
            $subRecord = $checkoutRes['subscription_record'];
            $this->assertEquals('incomplete', $subRecord['status'], 'Initial subscription status must be incomplete');

            // 4. Invalid Stripe signature must be rejected (400)
            $stripePayload = json_encode([
                'id' => 'evt_stripe_test_001',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'id' => 'cs_test_001',
                        'customer' => 'cus_test_001',
                        'subscription' => 'sub_ext_stripe_001',
                        'amount_total' => 1900,
                        'currency' => 'usd',
                        'metadata' => [
                            'user_id' => $billUser['id'],
                            'plan_id' => 'pro',
                            'interval' => 'monthly',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);

            $badSigRejected = false;
            try {
                $this->app->billing->handleWebhook('stripe', $stripePayload, [
                    'HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=deadbeef0000',
                ]);
            } catch (ValidationException $ve) {
                $badSigRejected = ($ve->getCode() === 400);
            }
            $this->assertTrue($badSigRejected, 'Invalid Stripe webhook HMAC signature must be rejected with 400');

            // 5. Valid signed Stripe webhook transitions subscription to active & upgrades user to pro
            $validSig = StripeProvider::signPayloadForTesting($stripePayload, 'whsec_docvera_signing_secret_123');
            $whResult1 = $this->app->billing->handleWebhook('stripe', $stripePayload, [
                'HTTP_STRIPE_SIGNATURE' => $validSig,
            ]);
            $this->assertTrue($whResult1['ok'] === true && $whResult1['duplicate'] === false, 'First valid webhook delivery must be processed');

            $upgradedUser = $this->app->platform->findUserById((string) $billUser['id']);
            $this->assertEquals('pro', $upgradedUser['plan_id'] ?? '', 'User plan must be upgraded to pro after checkout.session.completed');

            $paymentsBeforeReplay = count($this->app->platform->listPayments((string) $billUser['id']));
            $this->assertEquals(1, $paymentsBeforeReplay, 'Exactly 1 payment record must be created');

            // 6. Replaying identical event_id is idempotent (no duplicate payment or state mutation)
            $whResultReplay = $this->app->billing->handleWebhook('stripe', $stripePayload, [
                'HTTP_STRIPE_SIGNATURE' => $validSig,
            ]);
            $this->assertTrue($whResultReplay['duplicate'] === true, 'Duplicate webhook event_id must return duplicate=true');
            $this->assertEquals(1, count($this->app->platform->listPayments((string) $billUser['id'])), 'Idempotent replay must not create duplicate payment rows');

            // 7. Failed payment webhook transitions subscription to past_due
            $failedPayload = json_encode([
                'id' => 'evt_stripe_test_002',
                'type' => 'invoice.payment_failed',
                'data' => [
                    'object' => [
                        'id' => 'in_fail_001',
                        'subscription' => 'sub_ext_stripe_001',
                        'amount_due' => 1900,
                        'currency' => 'usd',
                        'metadata' => ['user_id' => $billUser['id'], 'plan_id' => 'pro'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            $this->app->billing->handleWebhook('stripe', $failedPayload, [
                'HTTP_STRIPE_SIGNATURE' => StripeProvider::signPayloadForTesting($failedPayload, 'whsec_docvera_signing_secret_123'),
            ]);
            $subAfterFail = $this->app->platform->findByProviderSubscriptionId('stripe', 'sub_ext_stripe_001');
            $this->assertEquals('past_due', $subAfterFail['status'] ?? '', 'Failed invoice must transition subscription to past_due');

            // 8. PayPal signed webhook cancellation downgrades user back to free
            $paypalCancelPayload = json_encode([
                'id' => 'WH-PAYPAL-EVT-001',
                'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
                'resource' => [
                    'id' => 'I-PAYPALSUB01',
                    'custom_id' => $billUser['id'] . ':pro',
                ],
            ], JSON_THROW_ON_ERROR);
            $ppHeaders = PayPalProvider::signHeadersForTesting($paypalCancelPayload, 'wh_paypal_secret_999');
            $this->app->billing->handleWebhook('paypal', $paypalCancelPayload, $ppHeaders);

            $downgradedUser = $this->app->platform->findUserById((string) $billUser['id']);
            $this->assertEquals('free', $downgradedUser['plan_id'] ?? '', 'Cancelled subscription webhook must downgrade user plan back to free');
        });
    }

    private function testRestApiAuthenticationRevocationScopesRateLimitsAndQuotas(): void
    {
        $this->runCase('REST API (/api/v1) enforces Bearer auth, key scopes, rotation/revocation, rate limits, and API quotas', function (): void {
            $apiController = new ApiController($this->app);
            $pdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/api_auth_test.pdf');
            $filePayload = [
                'files' => [
                    'name' => 'api_auth_test.pdf',
                    'type' => 'application/pdf',
                    'tmp_name' => $pdf,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($pdf),
                ],
            ];

            // 1. Unauthenticated protected request (/api/v1/usage) returns 401 authentication_error with X-Request-Id
            $unauthReq = new Request('GET', '/api/v1/usage', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
            $unauthRes = $apiController->dispatch($unauthReq);
            $this->assertEquals(401, $unauthRes['status'], 'Unauthenticated API call must return 401');
            $this->assertEquals('authentication_error', $unauthRes['body']['error']['code'] ?? '', 'Error code must be authentication_error');
            $this->assertTrue(!empty($unauthRes['headers']['X-Request-Id']), 'Response must include X-Request-Id header');

            // 2. Free user without api_access gets 403 when attempting tool execution
            $freeUser = $this->app->auth->register('No API User', 'noapi@docvera.test', 'password1234', 'user', 'free');
            $freeKey = $this->app->auth->createApiKey((string) $freeUser['id'], 'Free Key', ['tools:execute', 'jobs:read']);
            $freeReq = new Request('POST', '/api/v1/pdf/grayscale', [], [], $filePayload, [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $freeKey['plain_secret'],
            ]);
            $freeRes = $apiController->dispatch($freeReq);
            $this->assertEquals(403, $freeRes['status'], 'User on plan without api_access must get 403');

            // 3. Pro user with jobs:read scope only gets 403 when attempting POST /api/v1/pdf/grayscale (which requires tools:execute)
            $apiUser = $this->app->auth->register('API Pro User', 'apipro@docvera.test', 'password1234', 'user', 'pro');
            $readOnlyKey = $this->app->auth->createApiKey((string) $apiUser['id'], 'Jobs Read Only Key', ['jobs:read']);

            $usageRes = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $readOnlyKey['plain_secret'],
            ]));
            $this->assertEquals(200, $usageRes['status'], 'Valid key must succeed on GET /api/v1/usage');

            $writeAttempt = $apiController->dispatch(new Request('POST', '/api/v1/pdf/grayscale', [], [], $filePayload, [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $readOnlyKey['plain_secret'],
            ]));
            $this->assertEquals(403, $writeAttempt['status'], 'Key lacking tools:execute scope attempting POST tool execution must return 403');

            // 4. Key rotation invalidates old key and returns working new key; revocation blocks key immediately
            $rotated = $this->app->auth->rotateApiKey((string) $readOnlyKey['record']['id'], (string) $apiUser['id']);
            $oldKeyAttempt = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $readOnlyKey['plain_secret'],
            ]));
            $this->assertEquals(401, $oldKeyAttempt['status'], 'Old rotated API key must be rejected with 401');

            $newKeyAttempt = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $rotated['plain_secret'],
            ]));
            $this->assertEquals(200, $newKeyAttempt['status'], 'Newly rotated API key must succeed');

            $this->app->auth->revokeApiKey((string) $rotated['record']['id'], (string) $apiUser['id']);
            $revokedAttempt = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $rotated['plain_secret'],
            ]));
            $this->assertEquals(401, $revokedAttempt['status'], 'Revoked API key must be rejected with 401');

            // 5. API Rate Limiting (per-minute) enforcement
            $this->app->platform->savePlan([
                'id' => 'api_strict_plan',
                'name' => 'Strict API Plan',
                'price_monthly_cents' => 1000,
                'price_yearly_cents' => 10000,
                'currency' => 'USD',
                'is_public' => false,
                'is_active' => true,
                'sort_order' => 10,
                'entitlements' => [
                    'ops_per_day' => 50,
                    'ops_per_month' => 500,
                    'max_file_size_mb' => 10,
                    'max_files_per_op' => 5,
                    'max_pages_per_op' => 50,
                    'concurrent_jobs' => 2,
                    'ocr_allowed' => true,
                    'batch_allowed' => true,
                    'allowed_tools' => ['*'],
                    'api_access' => true,
                    'api_requests_per_day' => 10,
                    'api_requests_per_month' => 100,
                    'api_rate_limit_per_min' => 2,
                    'retention_minutes' => 30,
                    'ads_visible' => false,
                ],
            ]);
            $strictUser = $this->app->auth->register('Strict API User', 'strictapi@docvera.test', 'password1234', 'user', 'api_strict_plan');
            $strictKey = $this->app->auth->createApiKey((string) $strictUser['id'], 'Strict Key', ['tools:execute', 'jobs:read'], 2);

            // Call 1 & 2 succeed within 2 req/min rate limit
            $r1 = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], ['HTTP_X_API_KEY' => $strictKey['plain_secret']]));
            $r2 = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], ['HTTP_X_API_KEY' => $strictKey['plain_secret']]));
            $this->assertEquals(200, $r1['status'], 'First call within rate limit succeeds');
            $this->assertEquals(200, $r2['status'], 'Second call within rate limit succeeds');

            // Call 3 in same minute hits 429 rate_or_quota_limit_exceeded
            $r3 = $apiController->dispatch(new Request('GET', '/api/v1/usage', [], [], [], ['HTTP_X_API_KEY' => $strictKey['plain_secret']]));
            $this->assertEquals(429, $r3['status'], 'Third call in same minute exceeding 2/min must return 429');
            $this->assertEquals('rate_or_quota_limit_exceeded', $r3['body']['error']['code'] ?? '', 'Error code must be rate_or_quota_limit_exceeded');
        });
    }

    private function testRestApiSyncIdempotencyAndAsyncWorkerLifecycle(): void
    {
        $this->runCase('REST API executes synchronous jobs with Idempotency-Key replay and asynchronous queued jobs via worker pipeline', function (): void {
            $apiController = new ApiController($this->app);
            $proUser = $this->app->auth->register('Worker API User', 'workerapi@docvera.test', 'password1234', 'user', 'pro');
            $apiKey = $this->app->auth->createApiKey((string) $proUser['id'], 'Full Key', ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete']);

            $pdf1 = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/api_p1.pdf', 'ApiPageOne');
            $pdf2 = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/api_p2.pdf', 'ApiPageTwo');

            $filesPayload = [
                'files' => [
                    'name' => ['api_p1.pdf', 'api_p2.pdf'],
                    'type' => ['application/pdf', 'application/pdf'],
                    'tmp_name' => [$pdf1, $pdf2],
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    'size' => [filesize($pdf1), filesize($pdf2)],
                ],
            ];

            // 1. Synchronous POST /api/v1/pdf/merge with Idempotency-Key
            $syncReq = new Request('POST', '/api/v1/pdf/merge', [], [], $filesPayload, [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $apiKey['plain_secret'],
                'HTTP_IDEMPOTENCY_KEY' => 'idem-merge-key-001',
            ]);
            $syncRes = $apiController->dispatch($syncReq);
            $this->assertEquals(200, $syncRes['status'], 'Sync POST /api/v1/pdf/merge must return 200 OK');
            $this->assertEquals('completed', $syncRes['body']['status'] ?? '', 'Sync job status must be completed');
            $syncJobId = (string) $syncRes['body']['job_id'];

            // Replay with same Idempotency-Key returns cached response without re-running
            $replayRes = $apiController->dispatch($syncReq);
            $this->assertEquals(200, $replayRes['status'], 'Idempotent replay must return 200');
            $this->assertEquals('true', $replayRes['headers']['X-Idempotent-Replay'] ?? '', 'Replay must set X-Idempotent-Replay header');
            $this->assertEquals($syncJobId, $replayRes['body']['job_id'] ?? '', 'Idempotent replay must return original job_id');

            // 2. Asynchronous POST /api/v1/pdf/grayscale with async=1
            $singleFilePayload = [
                'files' => [
                    'name' => 'api_p1.pdf',
                    'type' => 'application/pdf',
                    'tmp_name' => $pdf1,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($pdf1),
                ],
            ];
            $asyncReq = new Request('POST', '/api/v1/pdf/grayscale', ['async' => '1'], [], $singleFilePayload, [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $apiKey['plain_secret'],
            ]);
            $asyncRes = $apiController->dispatch($asyncReq);
            $this->assertEquals(202, $asyncRes['status'], 'Async job submission must return 202 Accepted');
            $this->assertEquals('queued', $asyncRes['body']['status'] ?? '', 'Initial async job status must be queued');
            $asyncJobId = (string) $asyncRes['body']['job_id'];

            // Poll status while still queued
            $pollQueued = $apiController->dispatch(new Request('GET', '/api/v1/jobs/' . $asyncJobId, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $apiKey['plain_secret'],
            ]));
            $this->assertEquals('queued', $pollQueued['body']['status'] ?? '', 'Polling before worker runs must show queued');

            // Run background worker (processAllQueuedJobs)
            $processedCount = $this->app->executor->processAllQueuedJobs(10);
            $this->assertTrue($processedCount >= 1, 'Background worker must process queued job');

            // Poll status after worker finishes
            $pollDone = $apiController->dispatch(new Request('GET', '/api/v1/jobs/' . $asyncJobId, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $apiKey['plain_secret'],
            ]));
            $this->assertEquals('completed', $pollDone['body']['status'] ?? '', 'Polling after worker runs must show completed');
            $this->assertTrue(!empty($pollDone['body']['download_url']), 'Completed job response must include download_url');

            // Download result via GET /api/v1/jobs/{id}/download
            $dlRes = $apiController->dispatch(new Request('GET', '/api/v1/jobs/' . $asyncJobId . '/download', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $apiKey['plain_secret'],
            ]));
            $this->assertEquals(200, $dlRes['status'], 'Download endpoint must return 200');
            $this->assertValidPdfSignature((string) $dlRes['download_path']);

            // Delete result via DELETE /api/v1/jobs/{id}
            $delRes = $apiController->dispatch(new Request('DELETE', '/api/v1/jobs/' . $asyncJobId, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $apiKey['plain_secret'],
            ]));
            $this->assertEquals(200, $delRes['status'], 'DELETE /api/v1/jobs/{id} must return 200');
            $this->assertTrue(!$this->app->storage->jobWorkspaceExists($asyncJobId), 'Job workspace must be wiped after API DELETE');
        });
    }

    private function testUnauthorizedFileAccessIsolationAndAdminPermissions(): void
    {
        $this->runCase('Document access isolation prevents cross-user/cross-session file access in both UI and API while permitting Admins', function (): void {
            $userA = $this->app->auth->register('User Alpha', 'alpha@docvera.test', 'password1234', 'user', 'pro');
            $userB = $this->app->auth->register('User Beta', 'beta@docvera.test', 'password1234', 'user', 'pro');
            $adminUser = $this->app->auth->register('Sec Admin', 'secadmin@docvera.test', 'adminpass123', 'admin', 'business');

            $principalA = $this->app->auth->resolveUserPrincipalById((string) $userA['id'], 'sess_alpha');
            $principalB = $this->app->auth->resolveUserPrincipalById((string) $userB['id'], 'sess_beta');
            $principalGuest = $this->app->auth->resolveSessionPrincipal('sess_stranger');
            $principalAdmin = $this->app->auth->resolveUserPrincipalById((string) $adminUser['id'], 'sess_admin');

            $pdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/isolated.pdf');
            $jobA = $this->app->executor->execute(
                'grayscale-pdf',
                [['path' => $pdf, 'original_name' => 'isolated.pdf']],
                [],
                'sess_alpha',
                $principalA
            );
            $jobIdA = (string) $jobA['id'];

            $webController = new Controller($this->app);
            // Owner A is authorized without needing raw download token
            $this->assertTrue($webController->isAuthorizedForJob($jobA, $principalA, ''), 'Owner User A must be authorized for their job');
            // Stranger User B and Guest without token are strictly denied
            $this->assertTrue(!$webController->isAuthorizedForJob($jobA, $principalB, ''), 'User B must be denied access to User A job');
            $this->assertTrue(!$webController->isAuthorizedForJob($jobA, $principalGuest, 'wrong_token'), 'Guest with wrong token must be denied');
            // Admin is authorized
            $this->assertTrue($webController->isAuthorizedForJob($jobA, $principalAdmin, ''), 'Administrator must be authorized');

            // Verify API isolation: User B's API key attempting to read or delete User A's job gets 403
            $keyB = $this->app->auth->createApiKey((string) $userB['id'], 'Beta Key', ['jobs:read', 'jobs:delete']);
            $apiController = new ApiController($this->app);

            $crossRead = $apiController->dispatch(new Request('GET', '/api/v1/jobs/' . $jobIdA, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $keyB['plain_secret'],
            ]));
            $this->assertEquals(403, $crossRead['status'], 'User B API key must receive 403 when querying User A job');

            $crossDelete = $apiController->dispatch(new Request('DELETE', '/api/v1/jobs/' . $jobIdA, [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $keyB['plain_secret'],
            ]));
            $this->assertEquals(403, $crossDelete['status'], 'User B API key must receive 403 when deleting User A job');
        });
    }

    private function testMultiDeploymentModesAndRetentionMetadataPreservation(): void
    {
        $this->runCase('Multi-deployment modes (free_ads, saas, private), metadata history after file purge, and API Doc generator match ToolRegistry', function (): void {
            $guest = $this->app->auth->resolveSessionPrincipal('mode_guest_sess');
            $proUser = $this->app->auth->register('Mode Pro User', 'modepro@docvera.test', 'password1234', 'user', 'pro');
            $proPrincipal = $this->app->auth->resolveUserPrincipalById((string) $proUser['id'], 'mode_pro_sess');
            $tool = $this->app->registry->get('grayscale-pdf');
            $this->assertTrue($tool !== null, 'grayscale-pdf must exist');

            // 1. Mode: free_ads -> guest allowed, billing disabled, ads visible for guest
            $this->app->platform->saveSettings([
                'deployment_mode' => 'free_ads',
                'guest_tools_enabled' => true,
                'billing_enabled' => false,
                'ads_enabled' => true,
            ]);
            $this->assertTrue(!$this->app->entitlements->isBillingEnabled(), 'Billing must be disabled in free_ads mode');
            $this->assertTrue($this->app->entitlements->areAdsVisibleForPrincipal($guest), 'Ads must be visible for guest in free_ads mode');
            $this->assertTrue($this->app->entitlements->evaluateToolAccess($guest, $tool)->allowed, 'Guest tools must be allowed in free_ads mode');

            // 2. Mode: private -> guest blocked (authentication_required), registration disabled, billing disabled, authenticated user allowed
            $this->app->platform->saveSettings([
                'deployment_mode' => 'private',
                'guest_tools_enabled' => false,
                'registration_enabled' => false,
                'billing_enabled' => false,
                'ads_enabled' => false,
            ]);
            $privGuestDec = $this->app->entitlements->evaluateToolAccess($guest, $tool);
            $this->assertTrue(!$privGuestDec->allowed, 'Guest must be blocked in private deployment mode');
            $this->assertEquals('authentication_required', $privGuestDec->reasonCode, 'Reason code must be authentication_required');
            $this->assertTrue($this->app->entitlements->evaluateToolAccess($proPrincipal, $tool)->allowed, 'Authenticated user must be allowed in private mode');

            // Restore saas mode
            $this->app->platform->saveSettings([
                'deployment_mode' => 'saas',
                'guest_tools_enabled' => true,
                'registration_enabled' => true,
                'billing_enabled' => true,
                'ads_enabled' => false,
            ]);

            // 3. Verify operation metadata remains in user history after physical document files are purged
            $pdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/history_meta.pdf');
            $job = $this->app->executor->execute('grayscale-pdf', [['path' => $pdf, 'original_name' => 'secret_contract.pdf']], [], 'mode_pro_sess', $proPrincipal);
            $jobId = (string) $job['id'];
            $this->app->cleanup->deleteJobNow($jobId);

            $this->assertTrue(!$this->app->storage->jobWorkspaceExists($jobId), 'Physical files must be completely deleted from disk');
            $history = $this->app->jobs->findByUserOrSession((string) $proUser['id'], '', 10);
            $foundMeta = null;
            foreach ($history as $h) {
                if (($h['id'] ?? '') === $jobId) {
                    $foundMeta = $h;
                    break;
                }
            }
            $this->assertTrue($foundMeta !== null, 'Safe job metadata must remain in user history after file deletion');
            $this->assertEquals('deleted_by_user', $foundMeta['status'] ?? '', 'History record reflects deleted_by_user state');

            // 4. Verify ApiDocGenerator endpoints match ToolRegistry::apiCapableTools() 1-to-1
            $docEndpoints = $this->app->apiDocs->getEndpointsDocumentation();
            $apiTools = $this->app->registry->apiCapableTools();
            $this->assertEquals(count($apiTools), count($docEndpoints), 'Generated API documentation endpoint count must match ToolRegistry::apiCapableTools()');
            $openApi = $this->app->apiDocs->generateOpenApiSpec();
            $this->assertEquals('3.1.0', $openApi['openapi'] ?? '', 'OpenAPI spec version must be 3.1.0');
        });
    }

    private function testViewRenderingAndSeoMetadata(): void
    {
        $this->runCase('All Web UI, Dashboard, Pricing, API Docs, CMS Pages, SEO tags, and 16 Admin views render without warnings', function (): void {
            $controller = new Controller($this->app);
            $user = $this->app->auth->register('View Tester', 'views@docvera.test', 'password1234', 'admin', 'pro');
            $principal = $this->app->auth->resolveUserPrincipalById((string) $user['id'], 'view_sess_1');

            // 1. Render Auth views
            $loginHtml = $controller->renderView('auth_login', [
                'pageTitle' => 'Sign In — Docvera',
                'seoIndexable' => false,
                'errorMessage' => null,
                'notice' => '',
                'deploymentMode' => 'saas',
            ], $principal);
            $this->assertContains('Sign in to Docvera', $loginHtml, 'Login view must render heading');
            $this->assertContains('noindex, nofollow', $loginHtml, 'Non-indexable page must output noindex meta tag');

            // 2. Render Tool view with SEO canonical + JSON-LD structured data
            $mergeTool = $this->app->registry->get('merge-pdf');
            $this->assertTrue($mergeTool !== null, 'merge-pdf must exist');
            $decision = $this->app->entitlements->evaluateToolAccess($principal, $mergeTool);
            $toolHtml = $controller->renderView('tool', [
                'pageTitle' => 'Merge PDF — Docvera',
                'metaDescription' => 'Combine multiple PDF documents in local isolated workspace.',
                'canonicalUrl' => 'http://localhost:8080/tools/merge-pdf',
                'seoIndexable' => true,
                'jsonLd' => ['@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => 'Merge PDF'],
                'tool' => $mergeTool,
                'decision' => $decision,
                'stagedManifest' => null,
                'errorMessage' => null,
                'allTools' => $this->app->registry->all(),
                'categories' => ToolRegistry::getCategoryLabels(),
                'maxFileSizeMb' => $decision->maxFileSizeMb,
                'retentionMinutes' => $decision->retentionMinutes,
                'processingLocalityNotice' => 'Server-side local execution (isolated 0700 workspace)',
            ], $principal);
            $this->assertContains('rel="canonical" href="http://localhost:8080/tools/merge-pdf"', $toolHtml, 'Tool page must include canonical link');
            $this->assertContains('application/ld+json', $toolHtml, 'Tool page must include JSON-LD structured data');

            // 3. Render Pricing, Dashboard, API Docs, and CMS Privacy page
            $pricingHtml = $controller->renderView('pricing', [
                'pageTitle' => 'Plans & Quotas',
                'plans' => $this->app->platform->listPlans(true),
                'principal' => $principal,
                'billingEnabled' => true,
                'providers' => $this->app->billing->getProviderStatuses(),
                'anyProviderConfigured' => true,
                'errorMessage' => null,
                'checkoutSession' => null,
            ], $principal);
            $this->assertContains('Compare plans', $pricingHtml, 'Pricing view must render matrix');

            $dashHtml = $controller->renderView('dashboard', [
                'pageTitle' => 'Dashboard',
                'seoIndexable' => false,
                'principal' => $principal,
                'usage' => $this->app->entitlements->getUsageSummary($principal),
                'plan' => $this->app->entitlements->resolvePlanForPrincipal($principal),
                'subscription' => null,
                'payments' => [],
                'recentJobs' => [],
                'apiKeys' => $this->app->platform->listApiKeys((string) $user['id']),
                'apiLogs' => [],
                'newApiKeyPlain' => 'dcv_live_sample_one_time_secret',
                'flashMessage' => 'Key created',
                'errorMessage' => null,
                'billingEnabled' => true,
                'allowDeleteNow' => true,
            ], $principal);
            $this->assertContains('dcv_live_sample_one_time_secret', $dashHtml, 'Dashboard must display one-time API key');

            $docsHtml = $controller->renderView('api_docs', [
                'pageTitle' => 'API Docs',
                'endpoints' => $this->app->apiDocs->getEndpointsDocumentation(),
                'errorCodes' => $this->app->apiDocs->getErrorCodesReference(),
                'principal' => $principal,
            ], $principal);
            $this->assertContains('/api/v1/pdf/merge', $docsHtml, 'API docs view must render /api/v1/pdf/merge');

            $privacyPage = $this->app->platform->getCmsPage('privacy');
            $this->assertTrue($privacyPage !== null, 'Seeded privacy CMS page must exist');
            $pageHtml = $controller->renderView('page', [
                'pageTitle' => 'Privacy Policy',
                'page' => $privacyPage,
                'retentionMinutes' => 60,
                'localityNotice' => 'Server-side local execution',
            ], $principal);
            $this->assertContains('Privacy', $pageHtml, 'CMS page must render');
            $this->assertContains('isolated 0700 temporary working directories', $pageHtml, 'CMS page must render body paragraphs');
        });
    }

    private function testAuthBruteForceAndRegistrationRateLimiting(): void
    {
        $this->runCase('Auth layer enforces brute-force login lockout (429) and IP registration rate limits', function (): void {
            $user = $this->app->auth->register('Rate Limit Target', 'bruteforce@docvera.test', 'ValidPass123!', 'user', 'free', true);
            $attackerIp = '198.51.100.77';

            for ($i = 1; $i <= 10; $i++) {
                $failedCode = 0;
                try {
                    $this->app->auth->login('bruteforce@docvera.test', 'WrongPass' . $i, $attackerIp);
                } catch (ValidationException $ve) {
                    $failedCode = $ve->getCode();
                }
                $this->assertEquals(401, $failedCode, 'Failed login #' . $i . ' should return 401');
            }

            // 11th attempt from same IP + email must be blocked with 429
            $lockedCode = 0;
            try {
                $this->app->auth->login('bruteforce@docvera.test', 'ValidPass123!', $attackerIp);
            } catch (ValidationException $ve) {
                $lockedCode = $ve->getCode();
            }
            $this->assertEquals(429, $lockedCode, '11th login attempt from throttled IP/email must return 429');

            // Legitimate login from a different IP must still succeed
            $loggedIn = $this->app->auth->login('bruteforce@docvera.test', 'ValidPass123!', '198.51.100.88');
            $this->assertEquals($user['id'], $loggedIn['id'], 'Login from unthrottled IP must succeed');

            // Registration IP rate limiting (max 10 per 15m window per IP)
            $regIp = '203.0.113.99';
            for ($i = 1; $i <= 10; $i++) {
                $this->app->auth->register(
                    'Reg User ' . $i,
                    sprintf('reg_%d_%s@docvera.test', $i, bin2hex(random_bytes(2))),
                    'Password123!',
                    'user',
                    'free',
                    false,
                    $regIp
                );
            }
            $regLockedCode = 0;
            try {
                $this->app->auth->register('Reg User 11', 'reg11@docvera.test', 'Password123!', 'user', 'free', false, $regIp);
            } catch (ValidationException $ve) {
                $regLockedCode = $ve->getCode();
            }
            $this->assertEquals(429, $regLockedCode, '11th registration from same IP must return 429');
        });
    }

    private function testGuidedWebInstallerPreflightExecutionAndLockout(): void
    {
        $this->runCase('Guided Web Installer separates REQUIRED vs OPTIONAL checks, writes .env, and locks /install (403)', function (): void {
            $instDir = $this->tmpDir . '/fresh_install_' . bin2hex(random_bytes(3));
            mkdir($instDir . '/storage/app/jobs', 0700, true);
            mkdir($instDir . '/storage/app/staged', 0700, true);
            mkdir($instDir . '/storage/app/database', 0700, true);
            mkdir($instDir . '/storage/logs', 0700, true);

            $basePath = dirname(__DIR__);
            $cfg = require $basePath . '/config/app.php';
            $cfg['installed'] = false;
            $cfg['paths']['base'] = $instDir;
            $cfg['paths']['storage'] = $instDir . '/storage';
            $cfg['paths']['jobs'] = $instDir . '/storage/app/jobs';
            $cfg['paths']['staged'] = $instDir . '/storage/app/staged';
            $cfg['paths']['database'] = $instDir . '/storage/app/database';
            $cfg['paths']['logs'] = $instDir . '/storage/logs';
            $cfg['paths']['installed_lock'] = $instDir . '/storage/app/installed.lock';
            $cfg['database']['driver'] = 'file';
            $cfg['database']['file_store_path'] = $instDir . '/storage/app/database/docvera_store.json';

            $freshApp = new Application(new Config($cfg));
            $this->assertTrue(!$freshApp->isInstalled(), 'Fresh instance without users or lock file must report isInstalled() === false');

            $installer = new InstallController($freshApp);
            $preflight = $installer->buildPreflightReport();
            $this->assertTrue($preflight['all_required_passed'] === true, 'All REQUIRED checks must pass even when LibreOffice/Tesseract are absent');

            $optMap = [];
            foreach ($preflight['optional_capabilities'] as $opt) {
                $optMap[$opt['key']] = $opt;
            }
            $this->assertEquals('OPTIONAL CAPABILITY', $optMap['tesseract']['category'], 'Tesseract must be classified as OPTIONAL CAPABILITY');
            $this->assertEquals('OPTIONAL CAPABILITY', $optMap['libreoffice']['category'], 'LibreOffice must be classified as OPTIONAL CAPABILITY');

            // Render install view to confirm zero warnings
            $ctrl = new Controller($freshApp);
            $installHtml = $ctrl->renderView('install', [
                'pageTitle' => 'Install Docvera',
                'report' => $preflight,
                'submitted' => [
                    'site_name' => 'Docvera Enterprise',
                    'app_url' => 'https://docs.example.com',
                    'deployment_mode' => 'saas',
                    'db_driver' => 'file',
                    'db_sqlite_path' => 'storage/app/database/docvera.sqlite',
                    'db_dsn' => '',
                    'db_username' => '',
                    'default_retention_minutes' => 60,
                    'max_file_size_mb' => 50,
                    'admin_name' => 'Lead Admin',
                    'admin_email' => 'admin@docs.example.com',
                ],
                'errorMessage' => null,
            ]);
            $this->assertContains('Install Docvera', $installHtml, 'Installer view must render heading');
            $this->assertContains('OPTIONAL CAPABILITY', $installHtml, 'Installer view must distinguish optional capabilities');

            // Execute installation
            $res = $installer->executeInstall([
                'site_name' => 'Docvera Enterprise',
                'app_url' => 'https://docs.example.com',
                'deployment_mode' => 'saas',
                'db_driver' => 'file',
                'admin_name' => 'Lead Admin',
                'admin_email' => 'admin@docs.example.com',
                'admin_password' => 'StrongAdminPass99!',
                'default_retention_minutes' => 90,
                'max_file_size_mb' => 50,
            ]);

            $this->assertTrue(is_file($res['env_path']), '.env file must be written on install');
            $this->assertTrue(is_file($res['lock_path']), 'installed.lock must be written on install');
            $this->assertTrue($freshApp->isInstalled(), 'isInstalled() must return true after installation');
            $this->assertEquals('admin', $res['admin_user']['role'], 'Initial user must have admin role');

            // Verify second install attempt is blocked with 403
            $rerunCode = 0;
            try {
                $installer->executeInstall([
                    'site_name' => 'Hacked Site',
                    'app_url' => 'https://evil.example.com',
                    'deployment_mode' => 'saas',
                    'db_driver' => 'file',
                    'admin_name' => 'Attacker',
                    'admin_email' => 'attacker@evil.example.com',
                    'admin_password' => 'AttackerPass99!',
                ]);
            } catch (ValidationException $ve) {
                $rerunCode = $ve->getCode();
            }
            $this->assertEquals(403, $rerunCode, 'Re-running installer after lock must be rejected with 403');
        });
    }

    private function testAdminControllerFormActionsAndViewDataParity(): void
    {
        $this->runCase('AdminController buildAdminViewData and processPostAction support all 16 sections and form_action aliases', function (): void {
            $adminCtrl = new AdminController($this->app);
            $ctrl = new Controller($this->app);
            $adminUser = $this->app->auth->register('Admin Parity', 'admin.parity@docvera.test', 'AdminPass123!', 'admin', 'business', true);
            $adminPrincipal = $this->app->auth->resolveUserPrincipalById((string) $adminUser['id']);

            $sections = [
                'overview', 'tools', 'users', 'plans', 'subscriptions', 'payments',
                'usage', 'jobs', 'health', 'storage', 'api-usage', 'seo',
                'branding', 'pages', 'security', 'updates',
            ];
            foreach ($sections as $sec) {
                $viewData = $adminCtrl->buildAdminViewData($sec, $adminPrincipal, 'Test flash', null);
                $html = $ctrl->renderView('admin', $viewData, $adminPrincipal);
                $this->assertContains('>Administration<', $html, 'Admin section ' . $sec . ' must render cleanly from buildAdminViewData()');
            }

            // Verify form_action aliases matching views/admin.php
            $msgCms = $adminCtrl->processPostAction(
                new Request('POST', '/admin/pages', [], [
                    'form_action' => 'save_cms_page',
                    'slug' => 'privacy',
                    'title' => 'Updated Privacy & Retention Policy',
                    'meta_description' => 'Updated description',
                    'body' => 'Custom body text saved via form_action and body field.',
                    'is_published' => '1',
                    'in_sitemap' => '1',
                ], [], []),
                'pages'
            );
            $this->assertContains('/p/privacy', $msgCms, 'CMS save should confirm /p/privacy');
            $updatedPage = $this->app->platform->getCmsPage('privacy');
            $this->assertEquals('Custom body text saved via form_action and body field.', $updatedPage['body'], 'CMS page body must match saved content');

            $msgPlan = $adminCtrl->processPostAction(
                new Request('POST', '/admin/plans', [], [
                    'form_action' => 'save_plan',
                    'id' => 'pro',
                    'name' => 'Workbench Pro Updated',
                    'price_monthly_cents' => '2100',
                    'price_yearly_cents' => '19900',
                    'ops_per_day' => '600',
                    'ops_per_month' => '12000',
                    'max_file_size_mb' => '100',
                    'max_files_per_op' => '50',
                    'max_pages_per_op' => '1000',
                    'concurrent_jobs' => '5',
                    'retention_minutes' => '180',
                    'allowed_tools' => '*',
                    'ocr_allowed' => '1',
                    'batch_allowed' => '1',
                    'api_access' => '1',
                    'is_public' => '1',
                ], [], []),
                'plans'
            );
            $this->assertContains('Workbench Pro Updated', $msgPlan, 'save_plan with id and allowed_tools must succeed');
        });
    }

    private function testUpgradePathFromPhase1SchemaFixture(): void
    {
        $this->runCase('Upgrade path migrates a clean v1.0.0 database fixture to v2.0.0 without losing existing jobs', function (): void {
            if (!extension_loaded('pdo_sqlite')) {
                return;
            }
            $upgDir = $this->tmpDir . '/upgrade_v1_' . bin2hex(random_bytes(3));
            mkdir($upgDir, 0700, true);
            $sqlitePath = $upgDir . '/v1_legacy.sqlite';

            $pdo = new \PDO('sqlite:' . $sqlitePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $v1Sql = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/001_initial_schema.sql');
            $pdo->exec($v1Sql);
            $pdo->prepare('INSERT INTO migrations (id, executed_at) VALUES (:id, :t)')->execute([
                'id' => '001_initial_schema.sql',
                't' => '2026-01-15T00:00:00Z',
            ]);

            // Insert a legacy v1.0.0 job row before Phase 2 columns existed
            $pdo->exec("INSERT INTO processing_jobs (
                id, session_hash, tool_id, status, driver_used, input_count, input_bytes,
                output_filename, output_mime, output_bytes, download_token_hash,
                summary_json, error_message, created_at, completed_at, expires_at, deleted_at
            ) VALUES (
                'job_legacy_v1_000000000000000001', 'sess_v1', 'merge-pdf', 'completed', 'ghostscript',
                2, 4096, 'merged-v1.pdf', 'application/pdf', 3800, 'tok_v1',
                '{\"page_count\":5}', NULL, '2026-01-15T01:00:00Z', '2026-01-15T01:00:02Z', '2026-01-15T02:00:00Z', NULL
            )");
            unset($pdo);

            // Boot Application against this v1.0.0 SQLite database to trigger Migrator upgrade to v2.0.0
            $basePath = dirname(__DIR__);
            $cfg = require $basePath . '/config/app.php';
            $cfg['paths']['jobs'] = $upgDir . '/jobs';
            $cfg['paths']['staged'] = $upgDir . '/staged';
            $cfg['paths']['database'] = $upgDir;
            $cfg['paths']['logs'] = $upgDir . '/logs';
            $cfg['database']['connection'] = 'sqlite';
            $cfg['database']['driver'] = 'sqlite';
            $cfg['database']['sqlite_path'] = $sqlitePath;

            $upgradedApp = new Application(new Config($cfg));
            $applied = $upgradedApp->migrator->getAppliedMigrations();
            $this->assertTrue(in_array('001_initial_schema.sql', $applied, true), '001_initial_schema.sql must remain recorded');
            $this->assertTrue(in_array('002_phase2_saas_platform.sql', $applied, true), '002_phase2_saas_platform.sql must be applied during upgrade');
            $this->assertEquals([], $upgradedApp->migrator->getPendingMigrations(), 'Zero pending migrations after upgrade');

            // Confirm legacy v1.0.0 job record was preserved intact
            $legacyJob = $upgradedApp->jobs->find('job_legacy_v1_000000000000000001');
            $this->assertTrue($legacyJob !== null, 'Legacy v1.0.0 job must survive schema upgrade');
            $this->assertEquals('merged-v1.pdf', $legacyJob['output_filename'], 'Legacy job filename must be intact');
            $this->assertTrue( count($upgradedApp->platform->listPlans(false)) >= 4, 'Default plans must be seeded during upgrade');
        });
    }

    private function testOpenXmlArchiveSecurityAndRestApiHardening(): void
    {
        $this->runCase('OpenXML archive validator blocks traversal & spoofed archives while REST API supports /api/v1/health, /api/v1/jobs, /api/v1/account/usage, and scrubs async job parameters', function (): void {
            $wordTool = $this->app->registry->get('word-to-pdf');
            $this->assertTrue($wordTool !== null, 'word-to-pdf tool must exist');

            // 1. Valid minimal DOCX passes UploadValidator
            $validDocx = $this->fixtures->createMinimalDocx($this->tmpDir . '/fixtures/valid_check.docx');
            $validated = $this->app->validator->validateFiles(
                [['path' => $validDocx, 'original_name' => 'valid_check.docx']],
                $wordTool->supportedMimes
            );
            $this->assertEquals(1, count($validated), 'Valid minimal DOCX must pass UploadValidator');

            // 2. Spoofed non-ZIP file with .docx extension is rejected
            $fakeDocx = $this->tmpDir . '/fixtures/spoofed.docx';
            file_put_contents($fakeDocx, "PK\x03\x04NOT_A_REAL_OPENXML_ARCHIVE_PAYLOAD");
            $spoofRejected = false;
            try {
                $this->app->validator->validateFiles(
                    [['path' => $fakeDocx, 'original_name' => 'spoofed.docx']],
                    $wordTool->supportedMimes
                );
            } catch (ValidationException) {
                $spoofRejected = true;
            }
            $this->assertTrue($spoofRejected, 'Corrupted/spoofed .docx ZIP must be rejected by UploadValidator');

            // 3. ZIP archive missing word/ structure is rejected for .docx
            $missingWordDirZip = $this->tmpDir . '/fixtures/not_word.docx';
            $z = new ZipArchive();
            $z->open($missingWordDirZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $z->addFromString('[Content_Types].xml', '<Types/>');
            $z->addFromString('other/file.xml', '<doc/>');
            $z->close();
            $missingStructureRejected = false;
            try {
                $this->app->validator->validateFiles(
                    [['path' => $missingWordDirZip, 'original_name' => 'not_word.docx']],
                    $wordTool->supportedMimes
                );
            } catch (ValidationException) {
                $missingStructureRejected = true;
            }
            $this->assertTrue($missingStructureRejected, 'ZIP missing word/ structure must be rejected as invalid .docx');

            // 4. ZIP archive containing path traversal entry (../evil.xml) is rejected
            $traversalZip = $this->tmpDir . '/fixtures/traversal.docx';
            $z2 = new ZipArchive();
            $z2->open($traversalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $z2->addFromString('[Content_Types].xml', '<Types/>');
            $z2->addFromString('word/document.xml', '<w:document/>');
            $z2->addFromString('../evil.xml', '<exploit/>');
            $z2->close();
            $traversalZipRejected = false;
            try {
                $this->app->validator->validateFiles(
                    [['path' => $traversalZip, 'original_name' => 'traversal.docx']],
                    $wordTool->supportedMimes
                );
            } catch (ValidationException) {
                $traversalZipRejected = true;
            }
            $this->assertTrue($traversalZipRejected, 'OpenXML archive with ../ path traversal entry must be rejected');

            // 5. Verify GET /api/v1/health public probe
            $api = new ApiController($this->app);
            $healthRes = $api->dispatch(new Request('GET', '/api/v1/health', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
            $this->assertEquals(200, $healthRes['status'], 'GET /api/v1/health must return 200 on healthy host');
            $this->assertTrue(($healthRes['body']['ok'] ?? false) === true, 'Health response must report ok: true');
            $this->assertEquals(22, $healthRes['body']['tools_total'] ?? 0, 'Health response must report 22 total tools');

            // 6. Verify GET /api/v1/account/usage, GET /api/v1/jobs, Prefer: respond-async on POST /api/v1/tools/protect-pdf, and parameter scrubbing
            $proUser = $this->app->auth->register('Hardening Pro', 'hardening-pro@docvera.test', 'ProPass1234!', 'user', 'pro');
            $issuedKey = $this->app->auth->createApiKey(
                (string) $proUser['id'],
                'Hardening Suite Key',
                ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete']
            );
            $bearer = [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $issuedKey['plain_secret'],
            ];

            $usageAliasRes = $api->dispatch(new Request('GET', '/api/v1/account/usage', [], [], [], $bearer));
            $this->assertEquals(200, $usageAliasRes['status'], 'GET /api/v1/account/usage must return 200');
            $this->assertTrue(isset($usageAliasRes['body']['usage']['plan_id']), 'Usage summary must contain plan_id');

            $srcPdf = $this->fixtures->createSinglePagePdf($this->tmpDir . '/fixtures/async_secret.pdf', 'Secret Scrub Check');
            $filePayload = [
                'files' => [
                    'name' => 'async_secret.pdf',
                    'type' => 'application/pdf',
                    'tmp_name' => $srcPdf,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($srcPdf),
                ],
            ];
            $asyncProtectRes = $api->dispatch(new Request(
                'POST',
                '/api/v1/tools/protect-pdf',
                [],
                ['user_password' => 'SuperSecretPassword99!'],
                $filePayload,
                array_merge($bearer, ['HTTP_PREFER' => 'respond-async'])
            ));
            $this->assertEquals(202, $asyncProtectRes['status'], 'Prefer: respond-async must enqueue job with HTTP 202');
            $queuedJobId = (string) ($asyncProtectRes['body']['job_id'] ?? '');
            $this->assertTrue(str_starts_with($queuedJobId, 'job_'), 'Queued job ID must be returned');

            // Poll job status while still queued: ensure queued_options (containing password) is NOT leaked in summary
            $pollWhileQueued = $api->dispatch(new Request('GET', '/api/v1/jobs/' . $queuedJobId, [], [], [], $bearer));
            $this->assertEquals(200, $pollWhileQueued['status'], 'GET /api/v1/jobs/{id} must return 200');
            $this->assertTrue(!isset($pollWhileQueued['body']['summary']['queued_options']), 'API response must never leak queued_options in summary');
            $this->assertTrue(!isset($pollWhileQueued['body']['summary']['staged_inputs']), 'API response must never leak staged_inputs in summary');

            // Process queued job via worker and verify request_params are scrubbed from stored job record
            $processed = $this->app->executor->processAllQueuedJobs(10);
            $this->assertTrue($processed >= 1, 'Worker must process the queued protect-pdf job');

            $storedJobAfter = $this->app->jobs->find($queuedJobId);
            $this->assertEquals('completed', $storedJobAfter['status'] ?? '', 'Job must transition to completed');
            $this->assertEquals([], $storedJobAfter['request_params'] ?? ['leaked'], 'Completed async job must scrub request_params so passwords do not linger in DB');

            // Verify GET /api/v1/jobs list endpoint
            $jobsListRes = $api->dispatch(new Request('GET', '/api/v1/jobs', ['limit' => '10'], [], [], $bearer));
            $this->assertEquals(200, $jobsListRes['status'], 'GET /api/v1/jobs must return 200');
            $this->assertTrue(($jobsListRes['body']['count'] ?? 0) >= 1, 'GET /api/v1/jobs must list the user jobs');
        });
    }

    private function runCase(string $description, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            echo sprintf(" [PASS] %s\n", $description);
        } catch (Throwable $e) {
            $this->failed++;
            $msg = sprintf("%s -> %s (%s:%d)", $description, $e->getMessage(), basename($e->getFile()), $e->getLine());
            $this->failures[] = $msg;
            echo sprintf(" [FAIL] %s\n        Reason: %s\n", $description, $e->getMessage());
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true)));
        }
    }

    private function assertContains(string $needle, string $haystack, string $message): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(sprintf('%s (missing "%s")', $message, $needle));
        }
    }

    private function assertValidPdfSignature(string $path): void
    {
        if (!is_file($path) || (int) filesize($path) <= 0) {
            throw new RuntimeException(sprintf('Expected non-empty PDF file at %s', basename($path)));
        }
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if (!str_starts_with($head, '%PDF-')) {
            throw new RuntimeException(sprintf('File %s does not start with %%PDF- header', basename($path)));
        }
    }
}

