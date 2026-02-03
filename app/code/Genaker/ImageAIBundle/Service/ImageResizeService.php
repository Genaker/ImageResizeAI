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

use Genaker\ImageAIBundle\Api\ImageResizeServiceInterface;
use Genaker\ImageAIBundle\Model\ResizeResult;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Image\AdapterFactory;
use Psr\Log\LoggerInterface;

/**
 * Image Resize Service
 */
class ImageResizeService implements ImageResizeServiceInterface
{
    private ScopeConfigInterface $scopeConfig;
    private File $filesystem;
    private AdapterFactory $imageFactory;
    private LoggerInterface $logger;
    private ?GeminiImageModificationService $geminiService;
    private ResizeUrlGenerationService $urlGenerationService;
    private string $mediaPath;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        File $filesystem,
        AdapterFactory $imageFactory,
        LoggerInterface $logger,
        ResizeUrlGenerationService $urlGenerationService,
        GeminiImageModificationService $geminiService = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->filesystem = $filesystem;
        $this->imageFactory = $imageFactory;
        $this->logger = $logger;
        $this->urlGenerationService = $urlGenerationService;
        $this->geminiService = $geminiService;
        $this->mediaPath = BP . '/pub/media';
    }

    /**
     * {@inheritdoc}
     */
    public function resizeImage(string $imagePath, array $params, bool $allowPrompt = false, ?string $base64String = null): ResizeResult
    {
        // Normalize parameters
        $normalizedParams = $this->normalizeParameters($params);
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($imagePath, $normalizedParams);
        $extension = $normalizedParams['f'] ?? 'jpg';
        
        // Generate cache file path (use base64 if provided, otherwise generate it)
        $cacheFilePath = $this->generateCacheFilePath($imagePath, $normalizedParams, $extension, $base64String);
        
        // Check cache FIRST before doing any expensive operations (like Gemini API calls)
        // Use file_exists() for absolute paths
        if (file_exists($cacheFilePath)) {
            $mimeType = $this->getImageMimeType($cacheFilePath);
            $fileSize = filesize($cacheFilePath);
            return new ResizeResult($cacheFilePath, $mimeType, $fileSize, true, $cacheKey);
        }
        
        // Resolve source image path
        $sourcePath = $this->resolveSourceImagePath($imagePath);
        // Use file_exists() for absolute paths, filesystem driver for relative paths
        if (!file_exists($sourcePath)) {
            // Provide detailed error message
            $errorMsg = sprintf(
                "Source image not found: %s (resolved to: %s, mediaPath: %s, BP: %s, file_exists: %s)",
                $imagePath,
                $sourcePath,
                $this->mediaPath,
                defined('BP') ? BP : 'not defined',
                file_exists($sourcePath) ? 'true' : 'false'
            );
            $this->logger->error($errorMsg);
            throw new \InvalidArgumentException($errorMsg);
        }
        
        // Apply Gemini AI modification if prompt is provided and allowed
        // NOTE: This only runs if cache doesn't exist (cache check happened above)
        $workingImagePath = $sourcePath;
        $isGeminiModified = false;
        
        if (!empty($normalizedParams['prompt'])) {
            if (!$allowPrompt) {
                // Prompt provided but not allowed - log warning and continue without AI modification
                $this->logger->warning('AI prompt provided but not allowed (requires admin login or signature validation)', [
                    'image' => $imagePath,
                    'prompt' => $normalizedParams['prompt']
                ]);
                // Remove prompt from params to continue with normal resize
                unset($normalizedParams['prompt']);
            } elseif ($this->geminiService && $this->geminiService->isAvailable()) {
                // Check if Gemini caching is enabled
                $geminiCacheEnabled = $this->isGeminiCacheEnabled();
                
                // Check for cached Gemini-modified image (same image + prompt, regardless of dimensions)
                $geminiCachePath = null;
                if ($geminiCacheEnabled) {
                    $geminiCachePath = $this->getGeminiCachePath($imagePath, $normalizedParams['prompt']);
                    
                    if (file_exists($geminiCachePath)) {
                        // Use cached Gemini-modified image
                        $workingImagePath = $geminiCachePath;
                        $isGeminiModified = true;
                    }
                }
                
                // If cache doesn't exist or caching is disabled, call Gemini API
                if (!$isGeminiModified) {
                    // Call Gemini API to modify image (without dimensions - dimensions handled by resize)
                    try {
                        // Don't pass dimensions to Gemini - cache the modified image without dimensions
                        // Dimensions will be handled by the resize operation after
                        $modifiedImagePath = $this->geminiService->modifyImage(
                            $sourcePath,
                            $normalizedParams['prompt'],
                            null, // No width - cache base modified image
                            null  // No height - cache base modified image
                        );
                        
                        // Cache the Gemini-modified image for reuse if caching is enabled
                        if ($geminiCacheEnabled && $geminiCachePath) {
                            $this->cacheGeminiImage($modifiedImagePath, $geminiCachePath);
                            $workingImagePath = $geminiCachePath;
                        } else {
                            // Use temporary file directly if caching is disabled
                            $workingImagePath = $modifiedImagePath;
                        }
                        
                        $isGeminiModified = true;
                    } catch (\Exception $e) {
                        $this->logger->error('Gemini image modification failed', [
                            'error' => $e->getMessage(),
                            'image' => $imagePath,
                            'prompt' => $normalizedParams['prompt'],
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw new \RuntimeException('Gemini AI image modification failed: ' . $e->getMessage(), 0, $e);
                    }
                }
            } else {
                $this->logger->warning('Gemini AI service is not available', [
                    'image' => $imagePath,
                    'prompt' => $normalizedParams['prompt'],
                    'geminiServiceExists' => $this->geminiService ? 'yes' : 'no',
                    'isAvailable' => $this->geminiService && $this->geminiService->isAvailable() ? 'yes' : 'no'
                ]);
                throw new \RuntimeException('Gemini AI service is not available. Please configure the Gemini API key.');
            }
        }
        
        // Double-check cache (another process might have created it while we were processing)
        // Use file_exists() for absolute paths
        if (file_exists($cacheFilePath)) {
            // Clean up temporary Gemini-modified file if it was created
            if ($isGeminiModified && file_exists($workingImagePath)) {
                @unlink($workingImagePath);
            }
            $mimeType = $this->getImageMimeType($cacheFilePath);
            $fileSize = filesize($cacheFilePath);
            return new ResizeResult($cacheFilePath, $mimeType, $fileSize, true, $cacheKey);
        }
        
        // Process image (use Gemini-modified image if available)
        try {
            $this->processImage($workingImagePath, $cacheFilePath, $normalizedParams);
        } catch (\Exception $e) {
            $this->logger->error('Image processing failed', [
                'source' => $workingImagePath,
                'target' => $cacheFilePath,
                'params' => $normalizedParams,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
        
        // Don't clean up cached Gemini-modified files (they're reusable)
        // Only clean up temporary files if they weren't cached
        if ($isGeminiModified && file_exists($workingImagePath) && strpos($workingImagePath, '/tmp/') === 0) {
            // Only delete if it's a temp file (not cached)
            @unlink($workingImagePath);
        }
        
        // Get result metadata
        // Verify cache file exists after processing
        if (!file_exists($cacheFilePath)) {
            throw new \RuntimeException("Cache file was not created at: {$cacheFilePath}");
        }
        
        $mimeType = $this->getImageMimeType($cacheFilePath);
        $fileSize = filesize($cacheFilePath);
        
        return new ResizeResult($cacheFilePath, $mimeType, $fileSize, false, $cacheKey);
    }

    /**
     * {@inheritdoc}
     */
    public function getPublicDir(): string
    {
        return BP . '/pub';
    }

    /**
     * {@inheritdoc}
     */
    public function imageExists(string $imagePath): bool
    {
        $resolvedPath = $this->resolveSourceImagePath($imagePath);
        // Use file_exists() for absolute paths
        return file_exists($resolvedPath);
    }

    /**
     * {@inheritdoc}
     */
    public function getOriginalImagePath(string $imagePath): string
    {
        $resolvedPath = $this->resolveSourceImagePath($imagePath);
        // Use file_exists() for absolute paths
        if (!file_exists($resolvedPath)) {
            throw new \Magento\Framework\Exception\FileSystemException(__('Image not found: %1', $imagePath));
        }
        return $resolvedPath;
    }

    /**
     * Normalize and validate parameters
     *
     * @param array $params
     * @return array
     * @throws \InvalidArgumentException
     */
    private function normalizeParameters(array $params): array
    {
        $config = $this->getConfig();
        $normalized = [];
        
        // Width
        if (isset($params['w']) && $params['w'] !== null) {
            $width = (int)$params['w'];
            if ($width < $config['width_min'] || $width > $config['width_max']) {
                throw new \InvalidArgumentException("Width must be between {$config['width_min']} and {$config['width_max']}");
            }
            $normalized['w'] = $width;
        }
        
        // Height
        if (isset($params['h']) && $params['h'] !== null) {
            $height = (int)$params['h'];
            if ($height < $config['height_min'] || $height > $config['height_max']) {
                throw new \InvalidArgumentException("Height must be between {$config['height_min']} and {$config['height_max']}");
            }
            $normalized['h'] = $height;
        }
        
        // Quality
        if (isset($params['q']) && $params['q'] !== null) {
            $quality = (int)$params['q'];
            if ($quality < $config['quality_min'] || $quality > $config['quality_max']) {
                throw new \InvalidArgumentException("Quality must be between {$config['quality_min']} and {$config['quality_max']}");
            }
            $normalized['q'] = $quality;
        }
        
        // Format (required)
        if (!isset($params['f']) || empty($params['f'])) {
            throw new \InvalidArgumentException('Format parameter (f) is required');
        }
        $format = strtolower($params['f']);
        
        // Check WebP support if WebP format is requested
        if ($format === 'webp' && !function_exists('imagewebp')) {
            throw new \InvalidArgumentException('WebP format is requested but imagewebp() function is not available. Please install GD with WebP support.');
        }
        
        $allowedFormats = explode(',', $config['allowed_format_values']);
        if (!in_array($format, $allowedFormats)) {
            throw new \InvalidArgumentException("Format must be one of: " . implode(', ', $allowedFormats));
        }
        $normalized['f'] = $format;
        
        // Prompt - Gemini AI modification prompt (no validation, just trim)
        if (isset($params['prompt']) && $params['prompt'] !== null && $params['prompt'] !== '') {
            $prompt = trim((string) $params['prompt']);
            if (!empty($prompt)) {
                $normalized['prompt'] = $prompt;
            }
        }
        
        return $normalized;
    }

    /**
     * Get configuration limits
     *
     * @return array
     */
    private function getConfig(): array
    {
        return [
            'width_min' => (int)$this->getScopeConfigValue('genaker_imageaibundle/limits/width/min', 10),
            'width_max' => (int)$this->getScopeConfigValue('genaker_imageaibundle/limits/width/max', 5000),
            'height_min' => (int)$this->getScopeConfigValue('genaker_imageaibundle/limits/height/min', 10),
            'height_max' => (int)$this->getScopeConfigValue('genaker_imageaibundle/limits/height/max', 5000),
            'quality_min' => (int)$this->getScopeConfigValue('genaker_imageaibundle/limits/quality/min', 1),
            'quality_max' => (int)$this->getScopeConfigValue('genaker_imageaibundle/limits/quality/max', 100),
            'allowed_format_values' => $this->getScopeConfigValue('genaker_imageaibundle/allowed_format_values', 'webp,jpg,jpeg,png,gif'),
        ];
    }

    /**
     * Generate cache key
     *
     * @param string $imagePath
     * @param array $params
     * @return string
     */
    private function generateCacheKey(string $imagePath, array $params): string
    {
        $filteredParams = array_filter($params, fn($value) => $value !== null);
        ksort($filteredParams);
        $paramString = http_build_query($filteredParams);
        return md5($imagePath . '|' . $paramString);
    }

    /**
     * Generate cache file path
     * Uses ResizeUrlGenerationService to ensure consistent cache path generation
     * Format: /pub/media/resize/{base64}.{ext}
     *
     * @param string $imagePath
     * @param array $params
     * @param string $extension
     * @param string|null $base64String Pre-generated base64 string from URL (if available)
     * @return string Absolute cache file path
     */
    private function generateCacheFilePath(string $imagePath, array $params, string $extension, ?string $base64String = null): string
    {
        // If base64 string provided (from URL), use it directly
        if ($base64String !== null) {
            return BP . '/pub/media/resize/' . $base64String . '.' . $extension;
        }
        
        // Ensure params include the extension format
        $paramsWithFormat = $params;
        if (!isset($paramsWithFormat['f'])) {
            // Normalize jpg to jpeg
            $paramsWithFormat['f'] = ($extension === 'jpg') ? 'jpeg' : $extension;
        }
        
        // Use ResizeUrlGenerationService to generate base64 URL (without domain)
        // This ensures consistency with URL generation
        $base64Url = $this->urlGenerationService->generateBase64Url($imagePath, $paramsWithFormat, false);
        
        // Extract the base64 filename from the path
        // $base64Url format: /media/resize/{base64}.{extension}
        // We need: /pub/media/resize/{base64}.{extension}
        $cachePath = '/pub' . $base64Url;
        
        return BP . $cachePath;
    }

    /**
     * Get cache path for Gemini-modified image (based on image path + prompt, without dimensions)
     *
     * @param string $imagePath
     * @param string $prompt
     * @return string
     */
    private function getGeminiCachePath(string $imagePath, string $prompt): string
    {
        $sanitizedPath = ltrim($imagePath, '/');
        $sanitizedPath = str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $sanitizedPath);
        
        // Create cache key based on image path + prompt (no dimensions)
        $promptHash = md5($prompt);
        $cachePath = '/media/cache/gemini/' . $sanitizedPath . '/' . $promptHash . '.jpg';
        
        return BP . '/pub' . $cachePath;
    }

    /**
     * Cache Gemini-modified image to permanent location
     *
     * @param string $tempImagePath Temporary file path from Gemini service
     * @param string $cachePath Destination cache path
     * @return void
     */
    private function cacheGeminiImage(string $tempImagePath, string $cachePath): void
    {
        try {
            // Create directory if it doesn't exist
            $cacheDir = dirname($cachePath);
            if (!is_dir($cacheDir)) {
                $this->filesystem->createDirectory($cacheDir, 0755);
            }
            
            // Copy temporary file to cache location
            $this->filesystem->copy($tempImagePath, $cachePath);
            
            // Clean up temporary file
            if (file_exists($tempImagePath)) {
                @unlink($tempImagePath);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to cache Gemini-modified image', [
                'tempPath' => $tempImagePath,
                'cachePath' => $cachePath,
                'error' => $e->getMessage()
            ]);
            // Don't throw - continue with temp file if caching fails
        }
    }

    /**
     * Check if Gemini caching is enabled
     *
     * @return bool
     */
    private function isGeminiCacheEnabled(): bool
    {
        $value = $this->getScopeConfigValue(
            'genaker_imageaibundle/general/gemini_cache_enabled',
            true // Default to enabled
        );
        // Handle string '0' and '1' as well as boolean values
        if ($value === '0' || $value === false || $value === 0) {
            return false;
        }
        return (bool)$value;
    }

    /**
     * Get scope config value with fallback to default scope
     *
     * @param string $path
     * @param mixed $default
     * @return mixed
     */
    private function getScopeConfigValue(string $path, $default = null)
    {
        try {
            $value = $this->scopeConfig->getValue(
                $path,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            // Return value if it's not null or empty string (but allow false and 0)
            if ($value !== null && $value !== '') {
                return $value;
            }
            return $default;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // Store not found, use default scope
            try {
                $value = $this->scopeConfig->getValue(
                    $path,
                    \Magento\Store\Model\ScopeInterface::SCOPE_DEFAULT
                );
                // Return value if it's not null or empty string (but allow false and 0)
                if ($value !== null && $value !== '') {
                    return $value;
                }
                return $default;
            } catch (\Exception $e2) {
                return $default;
            }
        }
    }

    /**
     * Resolve source image path
     *
     * @param string $imagePath
     * @return string
     */
    private function resolveSourceImagePath(string $imagePath): string
    {
        $path = ltrim($imagePath, '/');
        return $this->mediaPath . '/' . $path;
    }

    /**
     * Process image (resize, crop, etc.)
     *
     * @param string $sourcePath
     * @param string $targetPath
     * @param array $params
     * @return void
     */
    private function processImage(string $sourcePath, string $targetPath, array $params): void
    {
        // Ensure cache directory exists
        $cacheDir = dirname($targetPath);
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                throw new \RuntimeException("Failed to create cache directory: {$cacheDir}");
            }
        }
        
        // Verify source file exists
        if (!file_exists($sourcePath)) {
            throw new \InvalidArgumentException("Source image not found: {$sourcePath}");
        }
        
        // Create image adapter
        $imageAdapter = $this->imageFactory->create();
        
        try {
            $imageAdapter->open($sourcePath);
        } catch (\Exception $e) {
            $this->logger->error('Image adapter open failed', [
                'source' => $sourcePath,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException("Failed to open source image: " . $e->getMessage(), 0, $e);
        }
        
        // Apply resize
        if (isset($params['w']) || isset($params['h'])) {
            $width = $params['w'] ?? null;
            $height = $params['h'] ?? null;
            try {
                $imageAdapter->resize($width, $height);
            } catch (\Exception $e) {
                $this->logger->error('Image adapter resize failed', [
                    'width' => $width,
                    'height' => $height,
                    'error' => $e->getMessage()
                ]);
                throw new \RuntimeException("Failed to resize image: " . $e->getMessage(), 0, $e);
            }
        }
        
        // Set quality
        if (isset($params['q'])) {
            try {
                $imageAdapter->quality($params['q']);
            } catch (\Exception $e) {
                $this->logger->warning('Image adapter quality setting failed', [
                    'quality' => $params['q'],
                    'error' => $e->getMessage()
                ]);
                // Quality setting failure is not critical, continue
            }
        }
        
        // Save with format
        $format = $params['f'] ?? 'jpg';
        
        // Magento's image adapter save() method signature is: save($destination = null, $newName = null)
        // The format is determined by the file extension in the target path
        // For WebP, we need to use PHP's imagewebp() function directly as Magento's adapter doesn't support it
        
        // Ensure directory is writable before saving
        $cacheDir = dirname($targetPath);
        if (!is_writable($cacheDir)) {
            throw new \RuntimeException("Cache directory is not writable: {$cacheDir}");
        }
        
        try {
            // Check if WebP format is requested
            if (strtolower($format) === 'webp') {
                // Magento's adapter doesn't support WebP, use PHP's imagewebp() directly
                if (!function_exists('imagewebp')) {
                    throw new \RuntimeException("WebP support is not available in PHP. Please install GD with WebP support.");
                }
                
                // Get the image resource from adapter
                $reflection = new \ReflectionClass($imageAdapter);
                $imageHandlerProperty = $reflection->getProperty('_imageHandler');
                $imageHandlerProperty->setAccessible(true);
                $imageResource = $imageHandlerProperty->getValue($imageAdapter);
                
                if (!$imageResource) {
                    throw new \RuntimeException("Image resource is not available");
                }
                
                // Save as WebP
                $quality = isset($params['q']) ? (int)$params['q'] : 85;
                if (!imagewebp($imageResource, $targetPath, $quality)) {
                    throw new \RuntimeException("Failed to save WebP image using imagewebp()");
                }
            } else {
                // For other formats, use Magento's adapter
                // Pass full path - adapter will extract directory and filename
                // The format is determined by the file extension
                $imageAdapter->save($targetPath);
            }
        } catch (\Exception $e) {
            $this->logger->error('Image adapter save failed', [
                'source' => $sourcePath,
                'target' => $targetPath,
                'format' => $format,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException("Failed to save resized image: " . $e->getMessage(), 0, $e);
        }
        
        // Verify file was created
        if (!file_exists($targetPath)) {
            $dirExists = is_dir(dirname($targetPath)) ? 'yes' : 'no';
            $dirWritable = is_writable(dirname($targetPath)) ? 'yes' : 'no';
            $dirContents = is_dir(dirname($targetPath)) ? implode(', ', array_slice(scandir(dirname($targetPath)), 0, 10)) : 'N/A';
            throw new \RuntimeException("Failed to create resized image at: {$targetPath} (directory exists: {$dirExists}, writable: {$dirWritable}, contents: {$dirContents})");
        }
    }

    /**
     * Get image MIME type
     *
     * @param string $filePath
     * @return string
     */
    private function getImageMimeType(string $filePath): string
    {
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
}

