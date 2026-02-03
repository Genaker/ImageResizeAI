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

use Gemini\Data\Part;
use Gemini\Data\Content;
use Gemini\Data\Blob;
use Gemini\Enums\MimeType;
use Psr\Log\LoggerInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Gemini Video Service
 * Handles AI-powered video generation using Google Veo 3.1 API
 */
class GeminiVideoService
{
    private GeminiClientFactory $clientFactory;
    private ?\Gemini\Client $client;
    private LoggerInterface $logger;
    private File $filesystem;
    private ScopeConfigInterface $scopeConfig;
    private string $model;
    private bool $available;

    public function __construct(
        GeminiClientFactory $clientFactory,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger = null,
        File $filesystem = null,
        string $model = 'veo-3.1-generate-preview'
    ) {
        $this->clientFactory = $clientFactory;
        $this->scopeConfig = $scopeConfig;
        $this->client = $clientFactory->createClient();
        $this->logger = $logger ?? \Magento\Framework\App\ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->filesystem = $filesystem ?? \Magento\Framework\App\ObjectManager::getInstance()->get(File::class);
        $this->model = $model;
        $this->available = $this->client !== null && class_exists('\Gemini\Client');
    }

    /**
     * Check if Gemini video service is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if (!$this->available) {
            return false;
        }
        
        // Check if video generation methods are available
        return $this->isVideoGenerationSupported();
    }

    /**
     * Check if video generation is supported by the SDK
     *
     * @return bool
     */
    private function isVideoGenerationSupported(): bool
    {
        if (!$this->client) {
            return false;
        }

        try {
            // Try to get generative model to check if generateVideos method exists
            $generativeModel = $this->client->generativeModel($this->model);
            
            // Check if generateVideos method exists
            if (method_exists($generativeModel, 'generateVideos')) {
                return true;
            }
            
            // Check if operations() method exists (needed for polling)
            if (!method_exists($this->client, 'operations')) {
                return false;
            }
            
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate video from image and prompt
     * This is an asynchronous operation that returns an operation ID
     *
     * @param string $imagePath Path to source image
     * @param string $prompt Video generation prompt
     * @param string|null $aspectRatio Aspect ratio (e.g., "16:9", "9:16", "1:1")
     * @return array Operation details with operation name/id
     * @throws \RuntimeException
     */
    public function generateVideoFromImage(string $imagePath, string $prompt, ?string $aspectRatio = '16:9'): array
    {
        if (!$this->client) {
            throw new \RuntimeException(
                'Gemini video service is not available. Please configure the Gemini API key in ' .
                'Stores > Configuration > Genaker > Image AI Resize > Gemini API Key, or set the GEMINI_API_KEY environment variable.'
            );
        }

        if (!$this->isVideoGenerationSupported()) {
            throw new \RuntimeException(
                'Video generation is not supported by your current Gemini SDK version. ' .
                'Video generation requires Gemini SDK with Veo 3.1 API support. ' .
                'Please update your Gemini SDK package to a version that supports the `generateVideos()` method ' .
                'and Veo 3.1 models (veo-3.1-generate-preview or veo-3.1-fast-generate-preview). ' .
                'For more information, visit: https://ai.google.dev/gemini-api/docs/models/veo'
            );
        }

        try {
            // Read image file
            if (!file_exists($imagePath)) {
                throw new \RuntimeException("Source image not found: {$imagePath}");
            }

            $imageContent = file_get_contents($imagePath);
            if ($imageContent === false) {
                throw new \RuntimeException("Failed to read image file: {$imagePath}");
            }

            // Detect MIME type
            $mimeType = $this->detectMimeType($imagePath, $imageContent);
            $geminiMimeType = $this->convertToGeminiMimeType($mimeType);

            // Create blob from image
            $blob = new Blob(
                mimeType: $geminiMimeType,
                data: base64_encode($imageContent)
            );

            // Create content with image and prompt
            $content = new Content(parts: [
                new Part(inlineData: $blob),
                new Part(text: $prompt)
            ]);

            // Get generative model instance
            $generativeModel = $this->client->generativeModel($this->model);

            // Start video generation (returns an Operation)
            // Note: Veo 3.1 uses generateVideos method which may not be available in all SDK versions
            // Check if method exists, otherwise throw helpful error
            if (!method_exists($generativeModel, 'generateVideos')) {
                throw new \RuntimeException(
                    'Video generation (generateVideos) method not available in current Gemini SDK. ' .
                    'Please ensure you are using a Gemini SDK version that supports Veo 3.1 API. ' .
                    'Video generation requires: veo-3.1-generate-preview or veo-3.1-fast-generate-preview model support.'
                );
            }

            // Call generateVideos with aspect ratio configuration
            // The exact API may vary - adjust based on actual SDK implementation
            try {
                $operation = $generativeModel->generateVideos($content, [
                    'aspectRatio' => $aspectRatio
                ]);
            } catch (\Exception $e) {
                // Try without aspectRatio parameter if it fails
                try {
                    $operation = $generativeModel->generateVideos($content);
                } catch (\Exception $e2) {
                    throw new \RuntimeException(
                        'Failed to start video generation: ' . $e->getMessage() . 
                        ' (Also tried without aspectRatio: ' . $e2->getMessage() . ')'
                    );
                }
            }

            // Return operation details for polling
            return [
                'operationName' => $operation->name(),
                'done' => $operation->done(),
                'status' => $operation->done() ? 'completed' : 'running'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Gemini video generation failed', [
                'error' => $e->getMessage(),
                'image' => $imagePath,
                'prompt' => $prompt,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Gemini video generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Poll operation status and get video when ready
     *
     * @param string $operationName Operation name/ID
     * @param int $maxWaitSeconds Maximum time to wait in seconds (default 300 = 5 minutes)
     * @param int $pollIntervalSeconds Interval between polls in seconds (default 10)
     * @return array Video details with URL and embed code
     * @throws \RuntimeException
     */
    public function pollVideoOperation(string $operationName, int $maxWaitSeconds = 300, int $pollIntervalSeconds = 10): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Gemini video service is not available.');
        }

        $startTime = time();
        $operation = null;

            try {
                // Poll until operation is done or timeout
            while (true) {
                // Get operation status
                // Check if operations() method exists on client
                if (!method_exists($this->client, 'operations')) {
                    throw new \RuntimeException(
                        'Operations API not available in current Gemini SDK. ' .
                        'Video generation polling requires operations() method support.'
                    );
                }
                
                $operation = $this->client->operations()->get($operationName);

                if ($operation->done()) {
                    break;
                }

                // Check timeout
                if ((time() - $startTime) > $maxWaitSeconds) {
                    throw new \RuntimeException("Video generation timeout after {$maxWaitSeconds} seconds");
                }

                // Wait before next poll
                sleep($pollIntervalSeconds);
            }

            // Extract video from operation response
            $video = $this->extractVideoFromOperation($operation);

            // Check if video caching is enabled
            $videoCacheEnabled = $this->isVideoCacheEnabled();

            if ($videoCacheEnabled) {
                // Save video file to cache
                $videoPath = $this->saveVideo($video, $operationName);
                
                // Generate URLs from cached file
                $videoUrl = $this->getVideoUrl($videoPath);
                $embedUrl = $this->getEmbedUrl($videoPath);
                
                return [
                    'videoUrl' => $videoUrl,
                    'embedUrl' => $embedUrl,
                    'videoPath' => $videoPath,
                    'status' => 'completed',
                    'cached' => true
                ];
            } else {
                // Caching disabled - return video URL directly from API
                $videoUrl = $this->getVideoUrlFromApi($video);
                $embedUrl = $this->getEmbedUrlFromApi($videoUrl);
                
                return [
                    'videoUrl' => $videoUrl,
                    'embedUrl' => $embedUrl,
                    'videoPath' => null,
                    'status' => 'completed',
                    'cached' => false
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error('Video operation polling failed', [
                'error' => $e->getMessage(),
                'operationName' => $operationName,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Video operation polling failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract video data from operation response
     *
     * @param mixed $operation
     * @return mixed Video data
     * @throws \RuntimeException
     */
    private function extractVideoFromOperation($operation)
    {
        // The exact structure depends on the SDK implementation
        // This is a placeholder that needs to be adjusted based on actual SDK response
        if (method_exists($operation, 'response')) {
            $response = $operation->response();
            if (isset($response->generatedVideos) && !empty($response->generatedVideos)) {
                return $response->generatedVideos[0];
            }
        }

        throw new \RuntimeException('No video found in operation response');
    }

    /**
     * Save video file to media directory
     *
     * @param mixed $video Video data (URL or binary)
     * @param string $operationName Operation name for filename
     * @return string Saved video file path
     * @throws \RuntimeException
     */
    private function saveVideo($video, string $operationName): string
    {
        try {
            // Create video directory in media
            $videoDir = BP . '/pub/media/cache/video/';
            if (!is_dir($videoDir)) {
                $this->filesystem->createDirectory($videoDir, 0755);
            }

            // Generate filename from operation name
            $filename = 'veo_' . md5($operationName) . '.mp4';
            $videoPath = $videoDir . $filename;

            // Handle video data (could be URL or binary)
            if (is_string($video) && filter_var($video, FILTER_VALIDATE_URL)) {
                // Download from URL
                $videoContent = file_get_contents($video);
                if ($videoContent === false) {
                    throw new \RuntimeException("Failed to download video from URL: {$video}");
                }
                $this->filesystem->filePutContents($videoPath, $videoContent);
            } elseif (is_string($video)) {
                // Binary data
                $this->filesystem->filePutContents($videoPath, base64_decode($video));
            } elseif (isset($video->uri)) {
                // Video object with URI
                $videoContent = file_get_contents($video->uri);
                if ($videoContent === false) {
                    throw new \RuntimeException("Failed to download video from URI: {$video->uri}");
                }
                $this->filesystem->filePutContents($videoPath, $videoContent);
            } else {
                throw new \RuntimeException('Unsupported video data format');
            }

            return $videoPath;

        } catch (\Exception $e) {
            $this->logger->error('Failed to save video', [
                'error' => $e->getMessage(),
                'operationName' => $operationName,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Failed to save video: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get public URL for video
     *
     * @param string $videoPath Full path to video file
     * @return string Public URL
     */
    private function getVideoUrl(string $videoPath): string
    {
        // Extract relative path from pub/media
        $relativePath = str_replace(BP . '/pub/media/', '', $videoPath);
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        return $baseUrl . '/media/' . $relativePath;
    }

    /**
     * Get HTML embed URL/code for video
     *
     * @param string $videoPath Full path to video file
     * @return string HTML embed code
     */
    private function getEmbedUrl(string $videoPath): string
    {
        $videoUrl = $this->getVideoUrl($videoPath);
        return '<video controls width="100%" height="auto"><source src="' . htmlspecialchars($videoUrl) . '" type="video/mp4">Your browser does not support the video tag.</video>';
    }

    /**
     * Get base URL
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            return $storeManager->getStore()->getBaseUrl();
        } catch (\Exception $e) {
            // Fallback to REQUEST_URI
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $protocol . $host;
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
            if ($mimeType && $mimeType !== 'application/octet-stream') {
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
     * Convert MIME type to Gemini MimeType enum
     *
     * @param string $mimeType
     * @return MimeType
     */
    private function convertToGeminiMimeType(string $mimeType): MimeType
    {
        return match ($mimeType) {
            'image/png' => MimeType::IMAGE_PNG,
            'image/jpeg', 'image/jpg' => MimeType::IMAGE_JPEG,
            'image/gif' => MimeType::IMAGE_GIF,
            'image/webp' => MimeType::IMAGE_WEBP,
            default => MimeType::IMAGE_JPEG,
        };
    }

    /**
     * Check if video caching is enabled
     *
     * @return bool
     */
    private function isVideoCacheEnabled(): bool
    {
        try {
            $value = $this->scopeConfig->getValue(
                'genaker_imageaibundle/general/video_cache_enabled',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            // Handle string '0' and '1' as well as boolean values
            if ($value === '0' || $value === false || $value === 0) {
                return false;
            }
            return (bool)$value;
        } catch (\Exception $e) {
            // Default to disabled if config error
            return false;
        }
    }

    /**
     * Get video URL directly from API response (when caching disabled)
     *
     * @param mixed $video Video data from API
     * @return string Video URL
     */
    private function getVideoUrlFromApi($video): string
    {
        // Handle different video data formats
        if (is_string($video) && filter_var($video, FILTER_VALIDATE_URL)) {
            // Video is already a URL
            return $video;
        } elseif (isset($video->uri)) {
            // Video object with URI property
            return $video->uri;
        } elseif (isset($video->url)) {
            // Video object with URL property
            return $video->url;
        } else {
            // Fallback: if we can't get URL, throw error
            throw new \RuntimeException('Video URL not available in API response. Enable video caching to save video locally.');
        }
    }

    /**
     * Get HTML embed code from API video URL (when caching disabled)
     *
     * @param string $videoUrl Video URL from API
     * @return string HTML embed code
     */
    private function getEmbedUrlFromApi(string $videoUrl): string
    {
        return '<video controls width="100%" height="auto"><source src="' . htmlspecialchars($videoUrl) . '" type="video/mp4">Your browser does not support the video tag.</video>';
    }
}
