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
    private GeminiClientFactory $clientFactory;
    private ?\Gemini\Client $client;
    private LoggerInterface $logger;
    private File $filesystem;
    private string $model;
    private bool $available;

    public function __construct(
        GeminiClientFactory $clientFactory,
        LoggerInterface $logger = null,
        File $filesystem = null,
        string $model = 'gemini-2.5-flash-image'
    ) {
        $this->clientFactory = $clientFactory;
        $this->client = $clientFactory->createClient();
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
     * Generate image from two source images using Gemini AI
     *
     * @param string $modelImagePath Path to model/base image
     * @param string $lookImagePath Path to look/apparel image
     * @param string $prompt Generation prompt
     * @return array Result with imagePath
     * @throws \RuntimeException
     */
    public function generateImageFromImages(string $modelImagePath, string $lookImagePath, string $prompt): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Gemini service is not available. Please configure the Gemini API key.');
        }

        try {
            // Read model image file
            $modelImageContent = $this->filesystem->fileGetContents($modelImagePath);
            if ($modelImageContent === false) {
                throw new \RuntimeException("Failed to read model image file: {$modelImagePath}");
            }

            // Read look image file
            $lookImageContent = $this->filesystem->fileGetContents($lookImagePath);
            if ($lookImageContent === false) {
                throw new \RuntimeException("Failed to read look image file: {$lookImagePath}");
            }

            // Detect MIME types
            $modelMimeTypeString = $this->detectMimeType($modelImagePath, $modelImageContent);
            $lookMimeTypeString = $this->detectMimeType($lookImagePath, $lookImageContent);

            // Convert string MIME types to MimeType enum
            $modelMimeType = \Gemini\Enums\MimeType::from($modelMimeTypeString);
            $lookMimeType = \Gemini\Enums\MimeType::from($lookMimeTypeString);

            // Create blobs for Gemini API - data must be base64 encoded
            $modelBlob = new \Gemini\Data\Blob(
                mimeType: $modelMimeType,
                data: base64_encode($modelImageContent)
            );

            $lookBlob = new \Gemini\Data\Blob(
                mimeType: $lookMimeType,
                data: base64_encode($lookImageContent)
            );

            // Get generative model instance
            $generativeModel = $this->client->generativeModel($this->model);

            // Create content with both images and prompt
            $content = new \Gemini\Data\Content(
                parts: [
                    new \Gemini\Data\Part(inlineData: $modelBlob), // Model Image
                    new \Gemini\Data\Part(inlineData: $lookBlob),  // Look/Apparel Image
                    new \Gemini\Data\Part(text: $prompt)           // Generation Prompt
                ]
            );

            // Generate content
            $response = $generativeModel->generateContent($content);

            // Extract image from response
            $generatedImageContent = $this->extractImageFromResponse($response);

            if ($generatedImageContent === null) {
                throw new \RuntimeException('Failed to generate image: No image data in response');
            }

            // Save the generated image
            $savedPath = $this->saveGeneratedImage($generatedImageContent, $modelImagePath, $lookImagePath, $prompt);

            return [
                'imagePath' => $savedPath
            ];

        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Image generation failed', [
                    'modelImage' => $modelImagePath,
                    'lookImage' => $lookImagePath,
                    'prompt' => $prompt,
                    'error' => $e->getMessage()
                ]);
            }
            throw $e;
        }
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
            $mimeTypeString = $this->detectMimeType($imagePath, $imageContent);
            
            // Convert string MIME type to MimeType enum
            $mimeType = \Gemini\Enums\MimeType::from($mimeTypeString);
            
            // Create blob for Gemini API - data must be base64 encoded
            $blob = new \Gemini\Data\Blob(
                mimeType: $mimeType,
                data: base64_encode($imageContent)
            );

            // Build prompt with size requirements if specified
            // Gemini 2.5 Flash Image (Nano Banana) supports image generation/editing
            // Best practice: Use narrative instructions rather than keyword-heavy prompts
            // Example: Instead of "add product", use "Take the object from this image and place it on a modern kitchen counter with soft morning light"
            $fullPrompt = $prompt;
            if ($width !== null || $height !== null) {
                $sizeInfo = [];
                if ($width !== null) {
                    $sizeInfo[] = "width: {$width}px";
                }
                if ($height !== null) {
                    $sizeInfo[] = "height: {$height}px";
                }
                $fullPrompt .= " Output image dimensions: " . implode(' x ', $sizeInfo) . ".";
            }

            // Call Gemini API
            // Get generative model instance - using gemini-2.5-flash-image (Nano Banana) for image generation
            $generativeModel = $this->client->generativeModel($this->model);
            
            // Best practice: Reference image(s) first, then the instruction
            // This establishes visual context before the model parses the transformation logic
            // Nano Banana can return interleaved content (both text and image), so we scan all parts
            $content = new \Gemini\Data\Content(
                parts: [
                    new \Gemini\Data\Part(inlineData: $blob), // Reference Product Image
                    new \Gemini\Data\Part(text: $fullPrompt)  // Narrative Instruction
                ]
            );
            
            // Generate content - Gemini 2.5 Flash Image will return modified image
            // Note: All images generated/edited by Gemini 2.5 Flash Image include SynthID digital watermark
            // This watermark is embedded in pixel data and cannot be removed, ensuring AI safety compliance
            $response = $generativeModel->generateContent($content);

            // Extract image from response
            $modifiedImageContent = $this->extractImageFromResponse($response);
            
            if ($modifiedImageContent === null) {
                // Log response details for debugging
                $responseInfo = [
                    'hasCandidates' => !empty($response->candidates),
                    'candidatesCount' => count($response->candidates ?? []),
                ];
                if (!empty($response->candidates)) {
                    $candidate = $response->candidates[0];
                    $responseInfo['finishReason'] = $candidate->finishReason?->value;
                    $responseInfo['hasContent'] = !empty($candidate->content);
                    $responseInfo['hasParts'] = !empty($candidate->content->parts);
                    $responseInfo['partsCount'] = count($candidate->content->parts ?? []);
                    if (!empty($candidate->content->parts)) {
                        $partsInfo = [];
                        foreach ($candidate->content->parts as $idx => $part) {
                            $partsInfo[$idx] = [
                                'hasText' => !empty($part->text),
                                'hasInlineData' => !empty($part->inlineData),
                                'textLength' => strlen($part->text ?? ''),
                            ];
                        }
                        $responseInfo['parts'] = $partsInfo;
                    }
                }
                if ($this->logger) {
                    $this->logger->error('Gemini API response parsing failed', [
                        'responseInfo' => $responseInfo,
                        'prompt' => $prompt
                    ]);
                }
                throw new \RuntimeException('Gemini API did not return a valid image');
            }

            // Save to temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'gemini_modified_');
            $extension = $this->getExtensionFromMimeType($mimeTypeString);
            $tempFile = $tempFile . '.' . $extension;
            
            $this->filesystem->filePutContents($tempFile, $modifiedImageContent);

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
     * Nano Banana specifically supports:
     * - image/png
     * - image/jpeg
     * - image/webp (great for e-commerce performance)
     * - image/heic
     * - image/heif
     *
     * @param string $filePath
     * @param string $content
     * @return string
     */
    private function detectMimeType(string $filePath, string $content): string
    {
        // Try finfo first (most accurate)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $content);
            finfo_close($finfo);
            if ($mimeType !== false && $this->isSupportedMimeType($mimeType)) {
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
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ];

        return $mimeTypes[$extension] ?? 'image/jpeg';
    }

    /**
     * Check if MIME type is supported by Nano Banana
     *
     * @param string $mimeType
     * @return bool
     */
    private function isSupportedMimeType(string $mimeType): bool
    {
        $supportedTypes = [
            'image/png',
            'image/jpeg',
            'image/webp',
            'image/heic',
            'image/heif',
        ];
        return in_array($mimeType, $supportedTypes, true);
    }

    /**
     * Extract image content from Gemini API response
     *
     * Nano Banana can return interleaved content (both text and image).
     * This method scans all parts to find inlineData (image blob).
     * 
     * Note: All images generated/edited by Gemini 2.5 Flash Image include SynthID digital watermark.
     * This watermark is embedded in pixel data and cannot be removed, ensuring AI safety compliance.
     *
     * @param \Gemini\Responses\GenerativeModel\GenerateContentResponse $response
     * @return string|null
     */
    private function extractImageFromResponse($response): ?string
    {
        // Check if response has candidates
        if (empty($response->candidates)) {
            if ($this->logger) {
                $this->logger->warning('Gemini API response has no candidates', [
                    'promptFeedback' => $response->promptFeedback ? $response->promptFeedback->toArray() : null
                ]);
            }
            return null;
        }

        // Get first candidate
        $candidate = $response->candidates[0];
        
        // Check if candidate has content with parts
        if (empty($candidate->content->parts)) {
            if ($this->logger) {
                $this->logger->warning('Gemini API candidate has no parts', [
                    'finishReason' => $candidate->finishReason?->value,
                    'safetyRatings' => array_map(fn($r) => $r->toArray(), $candidate->safetyRatings)
                ]);
            }
            return null;
        }

        // Iterate through all parts to find image data
        // Nano Banana can return interleaved content (text + image), so we scan all parts
        foreach ($candidate->content->parts as $part) {
            // Check for inlineData (image blob) - preferred method
            if ($part->inlineData !== null && !empty($part->inlineData->data)) {
                // Data is base64 encoded, decode it
                $decoded = base64_decode($part->inlineData->data, true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }
            
            // Check for base64 image data in text field (Gemini sometimes returns images as base64 text)
            if ($part->text !== null && !empty($part->text)) {
                $text = trim($part->text);
                
                // Remove markdown code blocks if present (```base64 or ```)
                $text = preg_replace('/^```(?:base64)?\s*/m', '', $text);
                $text = preg_replace('/\s*```$/m', '', $text);
                $text = trim($text);
                
                // Remove data URI prefix if present (data:image/jpeg;base64, or data:image/png;base64, etc.)
                $text = preg_replace('/^data:image\/[^;]+;base64,/', '', $text);
                
                // Remove any leading/trailing whitespace and newlines
                $text = preg_replace('/^\s+/', '', $text);
                $text = preg_replace('/\s+$/', '', $text);
                
                // Try to decode as base64
                $decoded = base64_decode($text, true);
                if ($decoded === false) {
                    // Not base64 - Gemini returned text instead of image
                    // This might mean the model doesn't support image generation
                    if ($this->logger) {
                        $this->logger->warning('Gemini returned text instead of image', [
                            'text' => $text,
                            'textLength' => strlen($text),
                            'hint' => 'Model may not support image generation, or prompt needs adjustment'
                        ]);
                    }
                    return null;
                } elseif (strlen($decoded) <= 100) {
                    // Decoded data too short, skip
                } else {
                    // Verify it's actually image data by checking magic bytes
                    $magicBytes = substr($decoded, 0, 4);
                    $isImage = (
                        $magicBytes === "\xFF\xD8\xFF\xE0" || // JPEG
                        $magicBytes === "\xFF\xD8\xFF\xE1" || // JPEG
                        substr($decoded, 0, 8) === "\x89PNG\r\n\x1a\n" || // PNG
                        substr($decoded, 0, 6) === "GIF87a" || // GIF
                        substr($decoded, 0, 6) === "GIF89a" || // GIF
                        (substr($decoded, 0, 4) === "RIFF" && substr($decoded, 8, 4) === "WEBP") // WebP
                    );
                    
                    if ($isImage) {
                        return $decoded;
                    }
                }
            }
        }

        // Log if no image found
        if ($this->logger) {
            $partsInfo = [];
            foreach ($candidate->content->parts as $i => $part) {
                $partsInfo[$i] = [
                    'hasText' => $part->text !== null,
                    'hasInlineData' => $part->inlineData !== null,
                    'hasFileData' => $part->fileData !== null
                ];
            }
            $this->logger->warning('No image data found in Gemini response parts', [
                'parts' => $partsInfo
            ]);
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

    /**
     * Save generated image to file system
     *
     * @param string $imageContent Binary image content
     * @param string $modelImagePath Original model image path
     * @param string $lookImagePath Original look image path
     * @param string $prompt Generation prompt
     * @return string Path where image was saved
     * @throws \RuntimeException
     */
    private function saveGeneratedImage(string $imageContent, string $modelImagePath, string $lookImagePath, string $prompt): string
    {
        try {
            // Detect MIME type from image content
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $imageContent);
            finfo_close($finfo);

            // Get file extension
            $extension = $this->getExtensionFromMimeType($mimeType);

            // Generate filename based on input images and prompt
            $modelFilename = pathinfo($modelImagePath, PATHINFO_FILENAME);
            $lookFilename = pathinfo($lookImagePath, PATHINFO_FILENAME);
            $promptHash = substr(md5($prompt), 0, 8);
            $timestamp = time();

            $filename = sprintf(
                'generated_%s_%s_%s_%d.%s',
                $modelFilename,
                $lookFilename,
                $promptHash,
                $timestamp,
                $extension
            );

            // Create directory if it doesn't exist
            $outputDir = BP . '/pub/media/genai';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Save the image
            $filePath = $outputDir . '/' . $filename;
            $result = file_put_contents($filePath, $imageContent);

            if ($result === false) {
                throw new \RuntimeException("Failed to save generated image to: {$filePath}");
            }

            if ($this->logger) {
                $this->logger->info('Generated image saved', [
                    'path' => $filePath,
                    'size' => strlen($imageContent),
                    'mimeType' => $mimeType
                ]);
            }

            return $filePath;

        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Failed to save generated image', [
                    'error' => $e->getMessage()
                ]);
            }
            throw new \RuntimeException('Failed to save generated image: ' . $e->getMessage());
        }
    }
}

