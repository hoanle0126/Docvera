<?php

declare(strict_types=1);

namespace Docvera\Tools;

use Docvera\Capabilities\CapabilityDetector;
use Docvera\Core\Config;
use Docvera\Database\PlatformRepository;

/**
 * Central Tool Registry for Docvera.
 * Resolves tool availability dynamically against the live Environment Capability System
 * and applies administrative Tool Overrides from PlatformRepository.
 */
final class ToolRegistry
{
    private CapabilityDetector $capabilities;
    private Config $config;
    private ?PlatformRepository $platform;

    /** @var array<string, ToolDefinition>|null */
    private ?array $tools = null;

    public function __construct(CapabilityDetector $capabilities, Config $config, ?PlatformRepository $platform = null)
    {
        $this->capabilities = $capabilities;
        $this->config = $config;
        $this->platform = $platform;
    }

    /**
     * @return array<string, ToolDefinition>
     */
    public function all(bool $forceRefresh = false): array
    {
        if ($this->tools !== null && !$forceRefresh) {
            return $this->tools;
        }

        if ($forceRefresh) {
            $this->capabilities->detectAll(true);
        }

        $maxFiles = (int) $this->config->get('limits.max_files_per_job', 20);
        $timeout = (int) $this->config->get('limits.process_timeout_seconds', 60);
        $retention = (int) $this->config->get('limits.retention_ttl_minutes', 60);

        $definitions = [
            // =========================================================================
            // PRIORITY GROUP A (12 Core Tools)
            // =========================================================================
            new ToolDefinition(
                id: 'merge-pdf',
                name: 'Merge PDF',
                description: 'Combine two or more PDF documents into a single file in your exact sequence.',
                category: 'organize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 2,
                maxFiles: $maxFiles,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['qpdf', 'poppler', 'ghostscript'],
                options: [],
                ctaLabel: 'Merge PDF files',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'split-pdf',
                name: 'Split PDF',
                description: 'Separate a multi-page PDF into individual single-page PDFs or fixed-size page bundles.',
                category: 'organize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/zip',
                outputExtensionLabel: 'ZIP (PDFs)',
                candidateDrivers: ['poppler', 'ghostscript'],
                options: [
                    new OptionField(
                        name: 'split_mode',
                        label: 'Split mode',
                        type: 'select',
                        default: 'all_pages',
                        choices: [
                            'all_pages' => 'Every page into a separate PDF file',
                            'every_n' => 'Split every N pages into separate PDFs',
                        ]
                    ),
                    new OptionField(
                        name: 'every_n_pages',
                        label: 'Pages per split file (when using every N pages)',
                        type: 'number',
                        default: 2,
                        min: 1,
                        max: 500
                    ),
                ],
                ctaLabel: 'Split PDF into files',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention,
                supportsPagePreview: true
            ),

            new ToolDefinition(
                id: 'extract-pages',
                name: 'Extract Pages',
                description: 'Pull specific pages or ranges from a PDF into a clean new document.',
                category: 'organize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(
                        name: 'pages',
                        label: 'Pages or ranges to extract',
                        type: 'text',
                        default: '1',
                        required: true,
                        placeholder: 'e.g. 1, 3-5, 8',
                        helpText: 'Click pages in the inspector above or enter page numbers/ranges separated by commas.'
                    ),
                ],
                ctaLabel: 'Extract selected pages',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention,
                supportsPagePreview: true
            ),

            new ToolDefinition(
                id: 'delete-pages',
                name: 'Delete Pages',
                description: 'Remove unwanted pages from a PDF and save the remaining pages as a new file.',
                category: 'organize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(
                        name: 'pages_to_delete',
                        label: 'Pages or ranges to remove',
                        type: 'text',
                        default: '',
                        required: true,
                        placeholder: 'e.g. 2, 4-5',
                        helpText: 'Select pages to remove. At least one page must remain in the document.'
                    ),
                ],
                ctaLabel: 'Remove selected pages',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention,
                supportsPagePreview: true
            ),

            new ToolDefinition(
                id: 'reorder-pages',
                name: 'Reorder Pages',
                description: 'Rearrange the sequence of pages in a PDF document into any custom order.',
                category: 'organize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['poppler', 'ghostscript'],
                options: [
                    new OptionField(
                        name: 'page_order',
                        label: 'Target page sequence',
                        type: 'text',
                        default: '',
                        required: true,
                        placeholder: 'e.g. 3, 1, 2, 4',
                        helpText: 'Drag page cards in the inspector or list the exact 1-based page numbers in your desired sequence.'
                    ),
                ],
                ctaLabel: 'Save new page order',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention,
                supportsPagePreview: true
            ),

            new ToolDefinition(
                id: 'rotate-pages',
                name: 'Rotate Pages',
                description: 'Rotate all or selected PDF pages by 90°, 180°, or 270° and persist the orientation.',
                category: 'organize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['qpdf', 'ghostscript'],
                options: [
                    new OptionField(
                        name: 'angle',
                        label: 'Rotation direction',
                        type: 'select',
                        default: '90',
                        choices: [
                            '90' => '90° Clockwise',
                            '180' => '180° Upside down',
                            '270' => '270° (90° Counter-clockwise)',
                        ]
                    ),
                    new OptionField(
                        name: 'pages',
                        label: 'Pages to rotate',
                        type: 'text',
                        default: 'all',
                        required: true,
                        placeholder: 'all or 1, 3-4',
                        helpText: 'Use "all" to rotate every page, or specify page numbers.'
                    ),
                ],
                ctaLabel: 'Rotate PDF pages',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention,
                supportsPagePreview: true
            ),

            new ToolDefinition(
                id: 'images-to-pdf',
                name: 'Images to PDF',
                description: 'Compile JPG and PNG images into a clean, print-ready single or multi-page PDF.',
                category: 'convert_to_pdf',
                priorityGroup: 'A',
                supportedMimes: ['image/jpeg', 'image/png'],
                supportedExtensions: ['.jpg', '.jpeg', '.png'],
                minFiles: 1,
                maxFiles: $maxFiles,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['native_php_pdf'],
                options: [
                    new OptionField(
                        name: 'page_size',
                        label: 'Page size',
                        type: 'select',
                        default: 'a4',
                        choices: [
                            'a4' => 'A4 (210 × 297 mm)',
                            'letter' => 'US Letter (8.5 × 11 in)',
                            'fit' => 'Fit page to image dimensions',
                        ]
                    ),
                    new OptionField(
                        name: 'orientation',
                        label: 'Page orientation',
                        type: 'select',
                        default: 'auto',
                        choices: [
                            'auto' => 'Automatic (match image aspect ratio)',
                            'portrait' => 'Portrait',
                            'landscape' => 'Landscape',
                        ]
                    ),
                    new OptionField(
                        name: 'margin',
                        label: 'Page margin',
                        type: 'select',
                        default: 'small',
                        choices: [
                            'none' => 'No margin (full bleed)',
                            'small' => 'Small margin (24 pt)',
                            'large' => 'Large margin (54 pt)',
                        ]
                    ),
                ],
                ctaLabel: 'Build PDF from images',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'pdf-to-images',
                name: 'PDF to JPG / PNG',
                description: 'Render PDF pages into high-resolution PNG or JPG raster images.',
                category: 'convert_from_pdf',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/zip',
                outputExtensionLabel: 'PNG / JPG / ZIP',
                candidateDrivers: ['poppler'],
                options: [
                    new OptionField(
                        name: 'image_format',
                        label: 'Output image format',
                        type: 'select',
                        default: 'png',
                        choices: [
                            'png' => 'PNG (Lossless sharp text & graphics)',
                            'jpg' => 'JPG (Compact photographic size)',
                        ]
                    ),
                    new OptionField(
                        name: 'dpi',
                        label: 'Render resolution (DPI)',
                        type: 'select',
                        default: '150',
                        choices: [
                            '72' => '72 DPI (Screen preview)',
                            '150' => '150 DPI (Standard document quality)',
                            '300' => '300 DPI (High-resolution print)',
                        ]
                    ),
                    new OptionField(
                        name: 'pages',
                        label: 'Pages to export',
                        type: 'text',
                        default: 'all',
                        placeholder: 'all or 1-3'
                    ),
                ],
                ctaLabel: 'Export pages as images',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention,
                supportsPagePreview: true
            ),

            new ToolDefinition(
                id: 'compress-pdf',
                name: 'Compress PDF',
                description: 'Reduce PDF file size by optimizing embedded images, streams, and font subsets.',
                category: 'optimize',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(
                        name: 'compression_level',
                        label: 'Optimization profile',
                        type: 'select',
                        default: 'ebook',
                        choices: [
                            'ebook' => 'Balanced (150 DPI images — recommended for email & web)',
                            'screen' => 'Maximum compression (72 DPI images — smallest file size)',
                            'printer' => 'High quality (300 DPI images — preserves print fidelity)',
                        ]
                    ),
                ],
                ctaLabel: 'Compress PDF file',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'protect-pdf',
                name: 'Password Protect PDF',
                description: 'Encrypt a PDF with a required open password and owner permission controls.',
                category: 'security',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['qpdf', 'ghostscript'],
                options: [
                    new OptionField(
                        name: 'user_password',
                        label: 'Document open password',
                        type: 'password',
                        default: '',
                        required: true,
                        placeholder: 'Enter password required to open PDF',
                        helpText: 'Recipients will be prompted for this password before viewing the file.'
                    ),
                    new OptionField(
                        name: 'owner_password',
                        label: 'Owner permissions password (optional)',
                        type: 'password',
                        default: '',
                        required: false,
                        placeholder: 'Optional separate owner password'
                    ),
                ],
                ctaLabel: 'Encrypt & protect PDF',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'unlock-pdf',
                name: 'Unlock PDF',
                description: 'Remove password protection from an encrypted PDF using the known password.',
                category: 'security',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['qpdf', 'ghostscript'],
                options: [
                    new OptionField(
                        name: 'password',
                        label: 'Current PDF password',
                        type: 'password',
                        default: '',
                        required: true,
                        placeholder: 'Enter the document password to unlock'
                    ),
                ],
                ctaLabel: 'Unlock PDF document',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'watermark-pdf',
                name: 'Watermark PDF',
                description: 'Stamp a persistent vector text watermark across every page of a PDF document.',
                category: 'security',
                priorityGroup: 'A',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(
                        name: 'watermark_text',
                        label: 'Watermark text',
                        type: 'text',
                        default: 'CONFIDENTIAL',
                        required: true,
                        placeholder: 'e.g. DRAFT, CONFIDENTIAL, INTERNAL USE ONLY'
                    ),
                    new OptionField(
                        name: 'position',
                        label: 'Placement',
                        type: 'select',
                        default: 'center_diagonal',
                        choices: [
                            'center_diagonal' => 'Center diagonal (35° angle)',
                            'top' => 'Top header centered',
                            'bottom' => 'Bottom footer centered',
                        ]
                    ),
                    new OptionField(
                        name: 'font_size',
                        label: 'Font size (pt)',
                        type: 'number',
                        default: 36,
                        min: 12,
                        max: 96
                    ),
                    new OptionField(
                        name: 'opacity',
                        label: 'Ink darkness (%)',
                        type: 'number',
                        default: 35,
                        min: 10,
                        max: 90
                    ),
                ],
                ctaLabel: 'Apply watermark to PDF',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            // =========================================================================
            // PRIORITY GROUP B (10 Extended Tools)
            // =========================================================================
            new ToolDefinition(
                id: 'crop-pdf',
                name: 'Crop PDF',
                description: 'Trim page margins (top, right, bottom, left) in points across all pages of a PDF.',
                category: 'optimize',
                priorityGroup: 'B',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(name: 'margin_top', label: 'Crop Top (pt)', type: 'number', default: 36, min: 0, max: 300),
                    new OptionField(name: 'margin_right', label: 'Crop Right (pt)', type: 'number', default: 36, min: 0, max: 300),
                    new OptionField(name: 'margin_bottom', label: 'Crop Bottom (pt)', type: 'number', default: 36, min: 0, max: 300),
                    new OptionField(name: 'margin_left', label: 'Crop Left (pt)', type: 'number', default: 36, min: 0, max: 300),
                ],
                ctaLabel: 'Crop PDF margins',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'page-numbers',
                name: 'Add Page Numbers',
                description: 'Stamp formatted page numbers into the header or footer of every PDF page.',
                category: 'organize',
                priorityGroup: 'B',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(
                        name: 'position',
                        label: 'Position on page',
                        type: 'select',
                        default: 'bottom_center',
                        choices: [
                            'bottom_center' => 'Bottom Center',
                            'bottom_right' => 'Bottom Right',
                            'bottom_left' => 'Bottom Left',
                            'top_center' => 'Top Center',
                            'top_right' => 'Top Right',
                            'top_left' => 'Top Left',
                        ]
                    ),
                    new OptionField(
                        name: 'format',
                        label: 'Numbering format',
                        type: 'select',
                        default: 'page_x_of_y',
                        choices: [
                            'page_x_of_y' => 'Page 1 of N',
                            'page_x' => 'Page 1',
                            'number_only' => '1',
                        ]
                    ),
                    new OptionField(
                        name: 'start_number',
                        label: 'Starting page number',
                        type: 'number',
                        default: 1,
                        min: 1,
                        max: 9999
                    ),
                    new OptionField(
                        name: 'font_size',
                        label: 'Font size (pt)',
                        type: 'number',
                        default: 10,
                        min: 8,
                        max: 20
                    ),
                ],
                ctaLabel: 'Stamp page numbers',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'pdf-metadata',
                name: 'PDF Metadata View & Edit',
                description: 'Inspect existing document properties and write updated Title, Author, Subject, and Keywords.',
                category: 'security',
                priorityGroup: 'B',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(name: 'title', label: 'Document Title', type: 'text', default: '', placeholder: 'e.g. Annual Financial Report 2026'),
                    new OptionField(name: 'author', label: 'Author / Organization', type: 'text', default: '', placeholder: 'e.g. Legal & Compliance Team'),
                    new OptionField(name: 'subject', label: 'Subject', type: 'text', default: '', placeholder: 'e.g. Audited Statements'),
                    new OptionField(name: 'keywords', label: 'Keywords (comma-separated)', type: 'text', default: '', placeholder: 'e.g. finance, audit, 2026'),
                    new OptionField(name: 'creator', label: 'Creator Application', type: 'text', default: 'Docvera Document Workbench'),
                ],
                ctaLabel: 'Write updated metadata',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'grayscale-pdf',
                name: 'Grayscale PDF',
                description: 'Convert all color graphics, photos, and text in a PDF into monochrome grayscale.',
                category: 'optimize',
                priorityGroup: 'B',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [],
                ctaLabel: 'Convert PDF to grayscale',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'flatten-pdf',
                name: 'Flatten PDF',
                description: 'Flatten interactive form fields and annotations or rasterize pages into static content.',
                category: 'optimize',
                priorityGroup: 'B',
                supportedMimes: ['application/pdf'],
                supportedExtensions: ['.pdf'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['ghostscript'],
                options: [
                    new OptionField(
                        name: 'flatten_mode',
                        label: 'Flattening method',
                        type: 'select',
                        default: 'annotations',
                        choices: [
                            'annotations' => 'Flatten form fields & annotations (preserve selectable text)',
                            'rasterize' => 'Full page rasterization (150 DPI non-editable image pages)',
                        ]
                    ),
                ],
                ctaLabel: 'Flatten PDF document',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'text-to-pdf',
                name: 'Text to PDF',
                description: 'Format plain-text (.txt) files or pasted notes into a paginated PDF document.',
                category: 'convert_to_pdf',
                priorityGroup: 'B',
                supportedMimes: ['text/plain'],
                supportedExtensions: ['.txt'],
                minFiles: 1,
                maxFiles: 5,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['native_php_pdf'],
                options: [
                    new OptionField(
                        name: 'page_size',
                        label: 'Page size',
                        type: 'select',
                        default: 'a4',
                        choices: [
                            'a4' => 'A4 (210 × 297 mm)',
                            'letter' => 'US Letter (8.5 × 11 in)',
                        ]
                    ),
                    new OptionField(
                        name: 'font_family',
                        label: 'Typeface',
                        type: 'select',
                        default: 'Helvetica',
                        choices: [
                            'Helvetica' => 'Helvetica (Sans-serif)',
                            'Times-Roman' => 'Times Roman (Serif)',
                            'Courier' => 'Courier (Monospace)',
                        ]
                    ),
                    new OptionField(
                        name: 'font_size',
                        label: 'Font size (pt)',
                        type: 'number',
                        default: 11,
                        min: 8,
                        max: 24
                    ),
                ],
                ctaLabel: 'Generate PDF from text',
                timeoutSeconds: $timeout,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'ocr-pdf',
                name: 'OCR Scanned PDF / Image',
                description: 'Extract selectable text or build a searchable PDF from scanned pages using Tesseract OCR.',
                category: 'convert_from_pdf',
                priorityGroup: 'B',
                supportedMimes: ['application/pdf', 'image/jpeg', 'image/png'],
                supportedExtensions: ['.pdf', '.jpg', '.png'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'Searchable PDF / TXT',
                candidateDrivers: ['tesseract'],
                options: [
                    new OptionField(
                        name: 'language',
                        label: 'Document language',
                        type: 'select',
                        default: 'eng',
                        choices: [
                            'eng' => 'English (eng)',
                            'deu' => 'German (deu)',
                            'fra' => 'French (fra)',
                            'spa' => 'Spanish (spa)',
                        ]
                    ),
                    new OptionField(
                        name: 'output_format',
                        label: 'Output format',
                        type: 'select',
                        default: 'pdf',
                        choices: [
                            'pdf' => 'Searchable PDF document',
                            'txt' => 'Extracted plain text (.txt)',
                        ]
                    ),
                ],
                ctaLabel: 'Run OCR recognition',
                queueRequired: true,
                timeoutSeconds: 120,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'word-to-pdf',
                name: 'Word to PDF',
                description: 'Convert Microsoft Word (.docx, .doc) documents to PDF via headless LibreOffice.',
                category: 'convert_to_pdf',
                priorityGroup: 'B',
                supportedMimes: [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/msword',
                ],
                supportedExtensions: ['.docx', '.doc'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['libreoffice'],
                options: [],
                ctaLabel: 'Convert Word to PDF',
                queueRequired: true,
                timeoutSeconds: 90,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'excel-to-pdf',
                name: 'Excel to PDF',
                description: 'Convert Microsoft Excel (.xlsx, .xls) spreadsheets to paginated PDF tables.',
                category: 'convert_to_pdf',
                priorityGroup: 'B',
                supportedMimes: [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel',
                ],
                supportedExtensions: ['.xlsx', '.xls'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['libreoffice'],
                options: [],
                ctaLabel: 'Convert Excel to PDF',
                queueRequired: true,
                timeoutSeconds: 90,
                retentionMinutes: $retention
            ),

            new ToolDefinition(
                id: 'powerpoint-to-pdf',
                name: 'PowerPoint to PDF',
                description: 'Convert Microsoft PowerPoint (.pptx, .ppt) slide decks into PDF handouts.',
                category: 'convert_to_pdf',
                priorityGroup: 'B',
                supportedMimes: [
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.ms-powerpoint',
                ],
                supportedExtensions: ['.pptx', '.ppt'],
                minFiles: 1,
                maxFiles: 1,
                outputMime: 'application/pdf',
                outputExtensionLabel: 'PDF',
                candidateDrivers: ['libreoffice'],
                options: [],
                ctaLabel: 'Convert PowerPoint to PDF',
                queueRequired: true,
                timeoutSeconds: 90,
                retentionMinutes: $retention
            ),
        ];

        $overrides = $this->platform !== null ? $this->platform->getAllToolOverrides() : [];
        $apiRoutes = self::getApiRouteMap();

        $resolved = [];
        foreach ($definitions as $def) {
            $availability = $this->resolveAvailability($def);
            $override = $overrides[$def->id] ?? null;
            $apiRoute = $apiRoutes[$def->id] ?? ('/api/v1/tools/' . $def->id);
            $resolved[$def->id] = $def->withState($availability, $override, $apiRoute);
        }

        $this->tools = $resolved;
        return $this->tools;
    }

    public function invalidateCache(): void
    {
        $this->tools = null;
    }

    public function get(string $toolId): ?ToolDefinition
    {
        $all = $this->all();
        return $all[$toolId] ?? null;
    }

    /**
     * Canonical mapping of tool IDs to versioned /api/v1 endpoints.
     *
     * @return array<string, string>
     */
    public static function getApiRouteMap(): array
    {
        return [
            'merge-pdf' => '/api/v1/pdf/merge',
            'split-pdf' => '/api/v1/pdf/split',
            'extract-pages' => '/api/v1/pdf/extract-pages',
            'delete-pages' => '/api/v1/pdf/delete-pages',
            'reorder-pages' => '/api/v1/pdf/reorder-pages',
            'rotate-pages' => '/api/v1/pdf/rotate',
            'images-to-pdf' => '/api/v1/convert/images-to-pdf',
            'pdf-to-images' => '/api/v1/convert/pdf-to-images',
            'compress-pdf' => '/api/v1/pdf/compress',
            'protect-pdf' => '/api/v1/pdf/protect',
            'unlock-pdf' => '/api/v1/pdf/unlock',
            'watermark-pdf' => '/api/v1/pdf/watermark',
            'crop-pdf' => '/api/v1/pdf/crop',
            'page-numbers' => '/api/v1/pdf/page-numbers',
            'pdf-metadata' => '/api/v1/pdf/metadata',
            'grayscale-pdf' => '/api/v1/pdf/grayscale',
            'flatten-pdf' => '/api/v1/pdf/flatten',
            'text-to-pdf' => '/api/v1/convert/text-to-pdf',
            'ocr-pdf' => '/api/v1/pdf/ocr',
            'word-to-pdf' => '/api/v1/convert/word-to-pdf',
            'excel-to-pdf' => '/api/v1/convert/excel-to-pdf',
            'powerpoint-to-pdf' => '/api/v1/convert/powerpoint-to-pdf',
        ];
    }

    public function resolveByApiRoute(string $path): ?ToolDefinition
    {
        $map = array_flip(self::getApiRouteMap());
        if (isset($map[$path])) {
            return $this->get($map[$path]);
        }
        if (preg_match('#^/api/v1/tools/([a-z0-9\-]+)$#', $path, $m)) {
            return $this->get($m[1]);
        }
        if (preg_match('#^/api/v1/(?:pdf|convert)/([a-z0-9\-]+)$#', $path, $m)) {
            $slug = $m[1];
            return $this->get($slug) ?? $this->get($slug . '-pdf');
        }
        return null;
    }

    /**
     * Returns only tools that are enabled and marked API-eligible.
     *
     * @return array<string, ToolDefinition>
     */
    public function apiCapableTools(): array
    {
        return array_filter(
            $this->all(),
            static fn(ToolDefinition $t): bool => $t->enabled && $t->apiEligible
        );
    }

    /**
     * Returns tools compatible with a specific MIME type (for the Homepage Smart File Router).
     *
     * @return list<ToolDefinition>
     */
    public function findCompatibleForMime(string $mime): array
    {
        $matches = [];
        foreach ($this->all() as $tool) {
            if ($tool->acceptsMime($mime)) {
                $matches[] = $tool;
            }
        }
        return $matches;
    }

    /**
     * @return array<string, string>
     */
    public static function getCategoryLabels(): array
    {
        return [
            'organize' => 'Organize pages',
            'convert_to_pdf' => 'Convert to PDF',
            'convert_from_pdf' => 'Convert from PDF',
            'optimize' => 'Optimize and adjust',
            'security' => 'Security and metadata',
        ];
    }

    private function resolveAvailability(ToolDefinition $def): AvailabilityResult
    {
        // Ensure core storage and PHP runtime are available first
        if (!$this->capabilities->isAvailable('php_runtime') || !$this->capabilities->isAvailable('writable_storage')) {
            return new AvailabilityResult(
                available: false,
                activeDriver: null,
                missingCapabilities: ['writable_storage'],
                unavailableReason: 'Server temporary storage is not writable.'
            );
        }

        $missing = [];
        foreach ($def->candidateDrivers as $driverKey) {
            if ($this->capabilities->isAvailable($driverKey)) {
                return new AvailabilityResult(
                    available: true,
                    activeDriver: $driverKey,
                    missingCapabilities: [],
                    unavailableReason: null
                );
            }
            $missing[] = $driverKey;
        }

        // Build human-readable reason from the primary driver capability
        $primaryDriver = $def->candidateDrivers[0] ?? 'unknown';
        $capStatus = $this->capabilities->get($primaryDriver);
        $reason = $capStatus?->missingReason
            ?? sprintf('Required processing driver (%s) was not detected on this server.', $primaryDriver);

        return new AvailabilityResult(
            available: false,
            activeDriver: null,
            missingCapabilities: $missing,
            unavailableReason: $reason
        );
    }
}

