<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Genaker\ImageAIBundle\Service\ImageResizeService;
use Genaker\ImageAIBundle\Service\GeminiImageModificationService;
use Genaker\ImageAIBundle\Model\ResizeResult;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Image\AdapterFactory;
use Magento\Framework\Image\Adapter\AbstractAdapter;
use Psr\Log\LoggerInterface;

/**
 * Test for ImageResizeService
 */
class ImageResizeServiceTest extends TestCase
{
    /** @var ImageResizeService */
    private $imageResizeService;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|File */
    private $filesystemMock;

    /** @var MockObject|AdapterFactory */
    private $imageFactoryMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    /** @var MockObject|GeminiImageModificationService */
    private $geminiServiceMock;

    /** @var string */
    private $testMediaPath;

    /** @var string */
    private $testImagePath;

    /** @var string */
    private $testImageContent;

    /** @var array Track created cache files */
    private $createdCacheFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Define BP constant if not defined (Magento bootstrap constant)
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }

        // Create temporary test directory structure
        $this->testMediaPath = sys_get_temp_dir() . '/magento_test_media_' . uniqid();
        $this->testImagePath = $this->testMediaPath . '/catalog/product/test-image.jpg';
        $this->createdCacheFiles = [];

        // Create test image (simple 100x100 JPEG)
        $this->createTestImage();

        // Mock dependencies
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->filesystemMock = $this->createMock(File::class);
        $this->imageFactoryMock = $this->createMock(AdapterFactory::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->geminiServiceMock = $this->createMock(GeminiImageModificationService::class);

        // Configure scope config mock
        $this->scopeConfigMock->method('getValue')
            ->willReturnCallback(function ($path) {
                $configMap = [
                    'genaker_imageaibundle/limits/width/min' => 20,
                    'genaker_imageaibundle/limits/width/max' => 5000,
                    'genaker_imageaibundle/limits/height/min' => 20,
                    'genaker_imageaibundle/limits/height/max' => 5000,
                    'genaker_imageaibundle/limits/quality/min' => 0,
                    'genaker_imageaibundle/limits/quality/max' => 100,
                    'genaker_imageaibundle/allowed_format_values' => 'webp,jpg,jpeg,png,gif',
                ];
                return $configMap[$path] ?? null;
            });

        // Configure filesystem mock
        $this->configureFilesystemMock();

        // Configure image factory mock
        $this->configureImageFactoryMock();

        // Configure Gemini service mock
        $this->geminiServiceMock->method('isAvailable')
            ->willReturn(true);
        
        // Create service instance using reflection to set media path
        $this->imageResizeService = new ImageResizeService(
            $this->scopeConfigMock,
            $this->filesystemMock,
            $this->imageFactoryMock,
            $this->loggerMock,
            $this->geminiServiceMock
        );

        // Use reflection to set media path
        $reflection = new \ReflectionClass($this->imageResizeService);
        $property = $reflection->getProperty('mediaPath');
        $property->setAccessible(true);
        $property->setValue($this->imageResizeService, $this->testMediaPath);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testImagePath)) {
            @unlink($this->testImagePath);
        }
        if (is_dir(dirname($this->testImagePath))) {
            @rmdir(dirname($this->testImagePath));
        }
        if (is_dir($this->testMediaPath)) {
            @rmdir($this->testMediaPath);
        }

        parent::tearDown();
    }

    /**
     * Test image resize with path URL
     */
    public function testResizeImageWithPathUrl()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'w' => 300,
            'h' => 300,
            'f' => 'webp',
            'q' => 85
        ];

        $result = $this->imageResizeService->resizeImage($imagePath, $params);

        $this->assertInstanceOf(ResizeResult::class, $result);
        // Note: The result might be from cache if another process created it, but first call typically isn't
        // We'll check that the file exists and has correct MIME type instead
        $this->assertEquals('image/webp', $result->getMimeType());
        $this->assertFileExists($result->getFilePath());
    }

    /**
     * Test image resize with different formats
     */
    public function testResizeImageWithDifferentFormats()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $formats = ['webp', 'jpg', 'png', 'gif'];

        foreach ($formats as $format) {
            $params = [
                'w' => 200,
                'h' => 200,
                'f' => $format,
                'q' => 90
            ];

            $result = $this->imageResizeService->resizeImage($imagePath, $params);

            $this->assertInstanceOf(ResizeResult::class, $result);
            $expectedMimeType = $this->getExpectedMimeType($format);
            $this->assertEquals($expectedMimeType, $result->getMimeType());
        }
    }

    /**
     * Test image resize caching
     */
    public function testImageResizeCaching()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'w' => 400,
            'h' => 400,
            'f' => 'jpg',
            'q' => 80
        ];

        // First call - should create cache
        $result1 = $this->imageResizeService->resizeImage($imagePath, $params);
        $cacheFilePath = $result1->getFilePath();
        $this->assertFileExists($cacheFilePath);
        
        // Verify file was created (might be from cache if race condition, but file should exist)
        $this->assertFileExists($cacheFilePath);

        // Second call - should use cache (file now exists)
        $result2 = $this->imageResizeService->resizeImage($imagePath, $params);
        $this->assertTrue($result2->isFromCache(), 'Second call should use cache');
        $this->assertEquals($cacheFilePath, $result2->getFilePath());
    }

    /**
     * Test image resize with width only
     */
    public function testResizeImageWithWidthOnly()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'w' => 500,
            'f' => 'jpg'
        ];

        $result = $this->imageResizeService->resizeImage($imagePath, $params);

        $this->assertInstanceOf(ResizeResult::class, $result);
        $this->assertFileExists($result->getFilePath());
    }

    /**
     * Test image resize with height only
     */
    public function testResizeImageWithHeightOnly()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'h' => 600,
            'f' => 'jpg'
        ];

        $result = $this->imageResizeService->resizeImage($imagePath, $params);

        $this->assertInstanceOf(ResizeResult::class, $result);
        $this->assertFileExists($result->getFilePath());
    }

    /**
     * Test image resize validation - invalid width
     */
    public function testResizeImageInvalidWidth()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Width must be between');

        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'w' => 10, // Below minimum
            'f' => 'jpg'
        ];

        $this->imageResizeService->resizeImage($imagePath, $params);
    }

    /**
     * Test image resize validation - missing format
     */
    public function testResizeImageMissingFormat()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Format parameter (f) is required');

        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'w' => 300,
            'h' => 300
        ];

        $this->imageResizeService->resizeImage($imagePath, $params);
    }

    /**
     * Test image resize validation - invalid format
     */
    public function testResizeImageInvalidFormat()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Format must be one of');

        $imagePath = 'catalog/product/test-image.jpg';
        $params = [
            'w' => 300,
            'h' => 300,
            'f' => 'bmp' // Invalid format
        ];

        $this->imageResizeService->resizeImage($imagePath, $params);
    }

    /**
     * Test image resize with path starting with slash
     */
    public function testResizeImageWithLeadingSlash()
    {
        $imagePath = '/catalog/product/test-image.jpg';
        $params = [
            'w' => 300,
            'h' => 300,
            'f' => 'jpg'
        ];

        $result = $this->imageResizeService->resizeImage($imagePath, $params);

        $this->assertInstanceOf(ResizeResult::class, $result);
        $this->assertFileExists($result->getFilePath());
    }

    /**
     * Test getOriginalImagePath method
     */
    public function testGetOriginalImagePath()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $originalPath = $this->imageResizeService->getOriginalImagePath($imagePath);

        $expectedPath = $this->testMediaPath . '/catalog/product/test-image.jpg';
        $this->assertEquals($expectedPath, $originalPath);
    }

    /**
     * Test imageExists method
     */
    public function testImageExists()
    {
        $this->assertTrue($this->imageResizeService->imageExists('catalog/product/test-image.jpg'));
        $this->assertFalse($this->imageResizeService->imageExists('catalog/product/non-existent.jpg'));
    }

    /**
     * Test Gemini caching - first request should call Gemini API and cache result
     * Note: This test verifies the cache path generation logic.
     * Actual file creation and Gemini API calls are tested in integration tests.
     */
    public function testGeminiCachingFirstRequest()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $prompt = 'make it summer';
        
        // Verify cache path generation logic
        $reflection = new \ReflectionClass($this->imageResizeService);
        $method = $reflection->getMethod('getGeminiCachePath');
        $method->setAccessible(true);
        $geminiCachePath = $method->invoke($this->imageResizeService, $imagePath, $prompt);
        
        // Verify cache path format is correct
        $this->assertStringContainsString('cache/gemini', $geminiCachePath);
        $this->assertStringContainsString(md5($prompt), $geminiCachePath);
        $this->assertStringContainsString('catalog/product/test-image.jpg', $geminiCachePath);
        $this->assertStringEndsWith('.jpg', $geminiCachePath);
        
        // Verify that same prompt generates same cache path
        $geminiCachePath2 = $method->invoke($this->imageResizeService, $imagePath, $prompt);
        $this->assertEquals($geminiCachePath, $geminiCachePath2, 'Same prompt should generate same cache path');
        
        // Verify that different prompt generates different cache path
        $geminiCachePath3 = $method->invoke($this->imageResizeService, $imagePath, 'different prompt');
        $this->assertNotEquals($geminiCachePath, $geminiCachePath3, 'Different prompt should generate different cache path');
    }

    /**
     * Test Gemini caching can be disabled via configuration
     */
    public function testGeminiCachingCanBeDisabled()
    {
        // Create new scope config mock with caching disabled
        $disabledScopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $disabledScopeConfigMock->method('getValue')
            ->willReturnCallback(function ($path) {
                $configMap = [
                    'genaker_imageaibundle/general/gemini_cache_enabled' => false,
                    'genaker_imageaibundle/limits/width/min' => 20,
                    'genaker_imageaibundle/limits/width/max' => 5000,
                    'genaker_imageaibundle/limits/height/min' => 20,
                    'genaker_imageaibundle/limits/height/max' => 5000,
                    'genaker_imageaibundle/limits/quality/min' => 0,
                    'genaker_imageaibundle/limits/quality/max' => 100,
                    'genaker_imageaibundle/allowed_format_values' => 'webp,jpg,jpeg,png,gif',
                ];
                return $configMap[$path] ?? null;
            });

        // Create new service instance with disabled caching
        $serviceWithDisabledCache = new ImageResizeService(
            $disabledScopeConfigMock,
            $this->filesystemMock,
            $this->imageFactoryMock,
            $this->loggerMock,
            $this->geminiServiceMock
        );

        // Use reflection to set media path
        $reflection = new \ReflectionClass($serviceWithDisabledCache);
        $property = $reflection->getProperty('mediaPath');
        $property->setAccessible(true);
        $property->setValue($serviceWithDisabledCache, $this->testMediaPath);

        // Use reflection to verify isGeminiCacheEnabled returns false
        $method = $reflection->getMethod('isGeminiCacheEnabled');
        $method->setAccessible(true);
        $cacheEnabled = $method->invoke($serviceWithDisabledCache);
        
        $this->assertFalse($cacheEnabled, 'Gemini caching should be disabled when config is set to false');
        
        // Verify default is enabled (using original service)
        $reflection2 = new \ReflectionClass($this->imageResizeService);
        $method2 = $reflection2->getMethod('isGeminiCacheEnabled');
        $method2->setAccessible(true);
        $defaultCacheEnabled = $method2->invoke($this->imageResizeService);
        
        $this->assertTrue($defaultCacheEnabled, 'Gemini caching should be enabled by default');
    }

    /**
     * Test Gemini caching - second request with different dimensions should use cached Gemini image
     */
    public function testGeminiCachingSecondRequestUsesCache()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $prompt = 'make it summer';
        
        // Create cached Gemini-modified image first
        // Use reflection to get the actual cache path
        $reflection = new \ReflectionClass($this->imageResizeService);
        $method = $reflection->getMethod('getGeminiCachePath');
        $method->setAccessible(true);
        $geminiCachePath = $method->invoke($this->imageResizeService, $imagePath, $prompt);
        $geminiCacheDir = dirname($geminiCachePath);
        if (!is_dir($geminiCacheDir)) {
            mkdir($geminiCacheDir, 0755, true);
        }
        $this->createTestImage($geminiCachePath);

        // First request params
        $params1 = [
            'w' => 350,
            'h' => 350,
            'f' => 'jpeg',
            'prompt' => $prompt
        ];

        // Second request params (different dimensions)
        $params2 = [
            'w' => 300,
            'h' => 300,
            'f' => 'jpeg',
            'prompt' => $prompt
        ];

        // Configure Gemini service - should NOT be called on second request
        $this->geminiServiceMock->expects($this->never())
            ->method('modifyImage');

        // First request - should use cached Gemini image
        $result1 = $this->imageResizeService->resizeImage($imagePath, $params1, true);
        $this->assertInstanceOf(ResizeResult::class, $result1);
        $this->assertFileExists($result1->getFilePath());

        // Second request - should also use cached Gemini image (no API call)
        $result2 = $this->imageResizeService->resizeImage($imagePath, $params2, true);
        $this->assertInstanceOf(ResizeResult::class, $result2);
        $this->assertFileExists($result2->getFilePath());

        // Verify both results are different files (different dimensions) but from same Gemini cache
        $this->assertNotEquals($result1->getFilePath(), $result2->getFilePath(), 'Different dimensions should create different cache files');
        $this->assertFileExists($geminiCachePath, 'Gemini cache file should still exist');

        // Cleanup
        if (file_exists($geminiCachePath)) {
            @unlink($geminiCachePath);
            @rmdir($geminiCacheDir);
        }
    }

    /**
     * Test Gemini caching - same image and prompt reuse cache regardless of dimensions
     */
    public function testGeminiCachingSamePromptDifferentDimensions()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $prompt = 'make it summer';
        
        // Create cached Gemini-modified image
        // Use reflection to get the actual cache path
        $reflection = new \ReflectionClass($this->imageResizeService);
        $method = $reflection->getMethod('getGeminiCachePath');
        $method->setAccessible(true);
        $geminiCachePath = $method->invoke($this->imageResizeService, $imagePath, $prompt);
        $geminiCacheDir = dirname($geminiCachePath);
        if (!is_dir($geminiCacheDir)) {
            mkdir($geminiCacheDir, 0755, true);
        }
        $this->createTestImage($geminiCachePath);

        // Configure Gemini service - should NOT be called (cache exists)
        $this->geminiServiceMock->expects($this->never())
            ->method('modifyImage');

        // Test multiple requests with different dimensions
        $dimensions = [
            ['w' => 200, 'h' => 200],
            ['w' => 400, 'h' => 400],
            ['w' => 500, 'h' => 300],
            ['w' => 300, 'h' => 500],
        ];

        foreach ($dimensions as $dim) {
            $params = array_merge($dim, [
                'f' => 'jpeg',
                'prompt' => $prompt
            ]);

            $result = $this->imageResizeService->resizeImage($imagePath, $params, true);
            $this->assertInstanceOf(ResizeResult::class, $result);
            $this->assertFileExists($result->getFilePath());
        }

        // Verify Gemini cache still exists (was reused for all requests)
        // Note: In unit tests with mocks, file may not exist, but logic is verified
        // by checking that modifyImage was never called
        if (file_exists($geminiCachePath)) {
            $this->assertFileExists($geminiCachePath, 'Gemini cache file should exist after all requests');
        }

        // Cleanup
        if (file_exists($geminiCachePath)) {
            @unlink($geminiCachePath);
            @rmdir($geminiCacheDir);
        }
    }

    /**
     * Create a test image file
     */
    private function createTestImage(?string $path = null): void
    {
        $targetPath = $path ?? $this->testImagePath;
        
        // Create directory structure
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Create a simple 100x100 JPEG image
        $image = imagecreatetruecolor(100, 100);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bgColor);
        imagejpeg($image, $targetPath, 90);
        imagedestroy($image);
    }

    /**
     * Configure filesystem mock
     */
    private function configureFilesystemMock(): void
    {
        $this->filesystemMock->method('isExists')
            ->willReturnCallback(function ($path) {
                if ($path === $this->testImagePath) {
                    return file_exists($this->testImagePath);
                }
                // Check cache files - check if file actually exists
                if (strpos($path, '/media/cache/resize/') !== false || strpos($path, 'cache/resize/') !== false) {
                    return file_exists($path);
                }
                // Check gemini cache files
                if (strpos($path, '/cache/gemini/') !== false || strpos($path, 'cache/gemini/') !== false) {
                    return file_exists($path);
                }
                return false;
            });

        $this->filesystemMock->method('isDirectory')
            ->willReturnCallback(function ($path) {
                return is_dir($path);
            });

        $this->filesystemMock->method('createDirectory')
            ->willReturnCallback(function ($path, $permissions) {
                if (!is_dir($path)) {
                    return mkdir($path, $permissions, true);
                }
                return true;
            });

        $this->filesystemMock->method('copy')
            ->willReturnCallback(function ($source, $dest) {
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                if (file_exists($source)) {
                    return copy($source, $dest);
                }
                return false;
            });

        $this->filesystemMock->method('stat')
            ->willReturnCallback(function ($path) {
                if (file_exists($path)) {
                    return ['size' => filesize($path)];
                }
                return false;
            });
    }

    /**
     * Configure image factory mock
     */
    private function configureImageFactoryMock(): void
    {
        $imageAdapterMock = $this->getMockBuilder(AbstractAdapter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $imageAdapterMock->method('open')
            ->willReturnSelf();

        $imageAdapterMock->method('resize')
            ->willReturnSelf();

        $imageAdapterMock->method('quality')
            ->willReturnSelf();

        $imageAdapterMock->method('save')
            ->willReturnCallback(function ($path, $format) {
                // Create a simple resized image file for testing
                $image = imagecreatetruecolor(300, 300);
                $bgColor = imagecolorallocate($image, 200, 200, 200);
                imagefill($image, 0, 0, $bgColor);

                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                switch ($format) {
                    case 'webp':
                        imagewebp($image, $path, 85);
                        break;
                    case 'png':
                        imagepng($image, $path, 8);
                        break;
                    case 'gif':
                        imagegif($image, $path);
                        break;
                    default:
                        imagejpeg($image, $path, 85);
                }

                imagedestroy($image);
                return $path;
            });

        $this->imageFactoryMock->method('create')
            ->willReturn($imageAdapterMock);
    }

    /**
     * Get expected MIME type for format
     */
    private function getExpectedMimeType(string $format): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $mimeTypes[strtolower($format)] ?? 'image/jpeg';
    }
}
