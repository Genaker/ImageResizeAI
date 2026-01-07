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
    private string $mediaPath;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        File $filesystem,
        AdapterFactory $imageFactory,
        LoggerInterface $logger,
        GeminiImageModificationService $geminiService = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->filesystem = $filesystem;
        $this->imageFactory = $imageFactory;
        $this->logger = $logger;
        $this->geminiService = $geminiService;
        $this->mediaPath = BP . '/pub/media';
    }

    /**
     * {@inheritdoc}
     */
    public function resizeImage(string $imagePath, array $params, bool $allowPrompt = false): ResizeResult
    {
        // Normalize parameters
        $normalizedParams = $this->normalizeParameters($params);
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($imagePath, $normalizedParams);
        $extension = $normalizedParams['f'] ?? 'jpg';
        
        // Generate cache file path
        $cacheFilePath = $this->generateCacheFilePath($imagePath, $normalizedParams, $extension);
        
        // Check cache FIRST before doing any expensive operations (like Gemini API calls)
        if ($this->filesystem->isExists($cacheFilePath)) {
            $mimeType = $this->getImageMimeType($cacheFilePath);
            $fileSize = $this->filesystem->stat($cacheFilePath)['size'];
            return new ResizeResult($cacheFilePath, $mimeType, $fileSize, true, $cacheKey);
        }
        
        // Resolve source image path
        $sourcePath = $this->resolveSourceImagePath($imagePath);
        if (!$this->filesystem->isExists($sourcePath)) {
            throw new \InvalidArgumentException("Source image not found: {$imagePath}");
        }
        
        // Apply Gemini AI modification if prompt is provided and allowed
        // NOTE: This only runs if cache doesn't exist (cache check happened above)
        $workingImagePath = $sourcePath;
        $isGeminiModified = false;
        
        if (!empty($normalizedParams['prompt']) && $allowPrompt) {
            if ($this->geminiService && $this->geminiService->isAvailable()) {
                try {
                    $geminiWidth = $normalizedParams['w'] ?? null;
                    $geminiHeight = $normalizedParams['h'] ?? null;
                    
                    $modifiedImagePath = $this->geminiService->modifyImage(
                        $sourcePath,
                        $normalizedParams['prompt'],
                        $geminiWidth,
                        $geminiHeight
                    );
                    $workingImagePath = $modifiedImagePath;
                    $isGeminiModified = true;
                } catch (\Exception $e) {
                    $this->logger->error('Gemini image modification failed', [
                        'error' => $e->getMessage(),
                        'image' => $imagePath,
                        'prompt' => $normalizedParams['prompt']
                    ]);
                    throw new \RuntimeException('Gemini AI image modification failed: ' . $e->getMessage(), 0, $e);
                }
            } else {
                throw new \RuntimeException('Gemini AI service is not available. Please configure the Gemini API key.');
            }
        }
        
        // Double-check cache (another process might have created it while we were processing)
        if ($this->filesystem->isExists($cacheFilePath)) {
            // Clean up temporary Gemini-modified file if it was created
            if ($isGeminiModified && file_exists($workingImagePath)) {
                @unlink($workingImagePath);
            }
            $mimeType = $this->getImageMimeType($cacheFilePath);
            $fileSize = $this->filesystem->stat($cacheFilePath)['size'];
            return new ResizeResult($cacheFilePath, $mimeType, $fileSize, true, $cacheKey);
        }
        
        // Process image (use Gemini-modified image if available)
        $this->processImage($workingImagePath, $cacheFilePath, $normalizedParams);
        
        // Clean up temporary Gemini-modified file if created
        if ($isGeminiModified && file_exists($workingImagePath)) {
            @unlink($workingImagePath);
        }
        
        // Get result metadata
        $mimeType = $this->getImageMimeType($cacheFilePath);
        $fileSize = $this->filesystem->stat($cacheFilePath)['size'];
        
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
        return $this->filesystem->isExists($resolvedPath);
    }

    /**
     * {@inheritdoc}
     */
    public function getOriginalImagePath(string $imagePath): string
    {
        $resolvedPath = $this->resolveSourceImagePath($imagePath);
        if (!$this->filesystem->isExists($resolvedPath)) {
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
            'width_min' => (int)$this->scopeConfig->getValue('genaker_imageaibundle/limits/width/min'),
            'width_max' => (int)$this->scopeConfig->getValue('genaker_imageaibundle/limits/width/max'),
            'height_min' => (int)$this->scopeConfig->getValue('genaker_imageaibundle/limits/height/min'),
            'height_max' => (int)$this->scopeConfig->getValue('genaker_imageaibundle/limits/height/max'),
            'quality_min' => (int)$this->scopeConfig->getValue('genaker_imageaibundle/limits/quality/min'),
            'quality_max' => (int)$this->scopeConfig->getValue('genaker_imageaibundle/limits/quality/max'),
            'allowed_format_values' => $this->scopeConfig->getValue('genaker_imageaibundle/allowed_format_values') ?: 'webp,jpg,jpeg,png,gif',
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
     *
     * @param string $imagePath
     * @param array $params
     * @param string $extension
     * @return string
     */
    private function generateCacheFilePath(string $imagePath, array $params, string $extension): string
    {
        $sanitizedPath = ltrim($imagePath, '/');
        $sanitizedPath = str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $sanitizedPath);
        
        $filteredParams = array_filter($params, fn($value) => $value !== null);
        ksort($filteredParams);
        $paramString = rawurlencode(http_build_query($filteredParams));
        
        $cachePath = '/media/cache/resize/' . $sanitizedPath;
        if (!empty($paramString)) {
            $cachePath .= '/' . $paramString;
        }
        $cachePath .= '.' . $extension;
        
        return BP . '/pub' . $cachePath;
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
        if (!$this->filesystem->isDirectory($cacheDir)) {
            $this->filesystem->createDirectory($cacheDir, 0755);
        }
        
        // Create image adapter
        $imageAdapter = $this->imageFactory->create();
        $imageAdapter->open($sourcePath);
        
        // Apply resize
        if (isset($params['w']) || isset($params['h'])) {
            $width = $params['w'] ?? null;
            $height = $params['h'] ?? null;
            $imageAdapter->resize($width, $height);
        }
        
        // Set quality
        if (isset($params['q'])) {
            $imageAdapter->quality($params['q']);
        }
        
        // Save with format
        $format = $params['f'] ?? 'jpg';
        $imageAdapter->save($targetPath, $format);
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

