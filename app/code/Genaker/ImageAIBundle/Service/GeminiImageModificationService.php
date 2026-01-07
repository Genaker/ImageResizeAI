<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Service;

use Psr\Log\LoggerInterface;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Gemini Image Modification Service
 * Handles AI-powered image modification using Google Gemini API
 */
class GeminiImageModificationService
{
    private ?\Gemini\Client $client;
    private LoggerInterface $logger;
    private File $filesystem;
    private string $model;
    private bool $available;

    public function __construct(
        ?\Gemini\Client $client = null,
        LoggerInterface $logger = null,
        File $filesystem = null,
        string $model = 'gemini-2.0-flash-exp'
    ) {
        $this->client = $client;
        $this->logger = $logger ?? \Magento\Framework\App\ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->filesystem = $filesystem ?? \Magento\Framework\App\ObjectManager::getInstance()->get(File::class);
        $this->model = $model;
        $this->available = $this->client !== null && class_exists('\Gemini\Client');
    }

    /**
     * Check if Gemini service is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Modify image using Gemini AI
     *
     * @param string $imagePath Image path
     * @param string $prompt Modification prompt
     * @param int|null $width Target width
     * @param int|null $height Target height
     * @return string Path to modified image (temporary file)
     * @throws \RuntimeException
     */
    public function modifyImage(string $imagePath, string $prompt, ?int $width = null, ?int $height = null): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Gemini service is not available. Please configure the Gemini API key.');
        }

        try {
            // Read image file
            $imageContent = $this->filesystem->fileGetContents($imagePath);
            if ($imageContent === false) {
                throw new \RuntimeException("Failed to read image file: {$imagePath}");
            }

            // Detect MIME type
            $mimeType = $this->detectMimeType($imagePath, $imageContent);
            
            // Create blob for Gemini API
            $blob = new \Gemini\Data\Blob(
                mimeType: $mimeType,
                data: $imageContent
            );

            // Build prompt with size requirements if specified
            $fullPrompt = $prompt;
            if ($width !== null || $height !== null) {
                $sizeInfo = [];
                if ($width !== null) {
                    $sizeInfo[] = "width: {$width}px";
                }
                if ($height !== null) {
                    $sizeInfo[] = "height: {$height}px";
                }
                $fullPrompt .= " (Output dimensions: " . implode(', ', $sizeInfo) . ")";
            }

            // Call Gemini API
            $response = $this->client->generativeModels->generateContent(
                model: $this->model,
                contents: [
                    new \Gemini\Data\Content(
                        parts: [
                            new \Gemini\Data\Part\TextPart(text: $fullPrompt),
                            new \Gemini\Data\Part\BlobPart(blob: $blob)
                        ]
                    )
                ]
            );

            // Extract image from response
            $modifiedImageContent = $this->extractImageFromResponse($response);
            
            if ($modifiedImageContent === null) {
                throw new \RuntimeException('Gemini API did not return a valid image');
            }

            // Save to temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'gemini_modified_');
            $extension = $this->getExtensionFromMimeType($mimeType);
            $tempFile = $tempFile . '.' . $extension;
            
            $this->filesystem->filePutContents($tempFile, $modifiedImageContent);

            if ($this->logger) {
                $this->logger->info('Gemini image modification successful', [
                    'original' => $imagePath,
                    'prompt' => $prompt,
                    'temp_file' => $tempFile
                ]);
            }

            return $tempFile;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Gemini image modification failed', [
                    'error' => $e->getMessage(),
                    'image' => $imagePath,
                    'prompt' => $prompt,
                    'trace' => $e->getTraceAsString()
                ]);
            }
            throw new \RuntimeException('Gemini AI image modification failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Detect MIME type from file path and content
     *
     * @param string $filePath
     * @param string $content
     * @return string
     */
    private function detectMimeType(string $filePath, string $content): string
    {
        // Try finfo first
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $content);
            finfo_close($finfo);
            if ($mimeType !== false) {
                return $mimeType;
            }
        }

        // Fallback to extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $mimeTypes[$extension] ?? 'image/jpeg';
    }

    /**
     * Extract image content from Gemini API response
     *
     * @param mixed $response
     * @return string|null
     */
    private function extractImageFromResponse($response): ?string
    {
        if (!isset($response->candidates) || empty($response->candidates)) {
            return null;
        }

        $candidate = $response->candidates[0];
        if (!isset($candidate->content->parts)) {
            return null;
        }

        foreach ($candidate->content->parts as $part) {
            if (isset($part->inlineData) && isset($part->inlineData->data)) {
                return base64_decode($part->inlineData->data);
            }
            if (isset($part->blob) && isset($part->blob->data)) {
                return $part->blob->data;
            }
        }

        return null;
    }

    /**
     * Get file extension from MIME type
     *
     * @param string $mimeType
     * @return string
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $mimeToExt[$mimeType] ?? 'jpg';
    }
}

