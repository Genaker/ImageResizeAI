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

use Genaker\ImageAIBundle\Service\ImageResizeService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Image\AdapterFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test ImageResizeService cache path generation
 */
class ImageResizeServiceTest extends TestCase
{
    /**
     * @var ImageResizeService
     */
    private $service;

    /**
     * @var MockObject|ScopeConfigInterface
     */
    private $scopeConfigMock;

    /**
     * @var MockObject|File
     */
    private $filesystemMock;

    /**
     * @var MockObject|AdapterFactory
     */
    private $imageFactoryMock;

    /**
     * @var MockObject|LoggerInterface
     */
    private $loggerMock;

    /**
     * @var string
     */
    private $basePath;

    protected function setUp(): void
    {
        // Define BP constant if not already defined (needed for ImageResizeService)
        if (!defined('BP')) {
            $this->basePath = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/../../../../..';
            define('BP', $this->basePath);
        } else {
            $this->basePath = BP;
        }
        
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->filesystemMock = $this->createMock(File::class);
        $this->imageFactoryMock = $this->createMock(AdapterFactory::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->service = new ImageResizeService(
            $this->scopeConfigMock,
            $this->filesystemMock,
            $this->imageFactoryMock,
            $this->loggerMock
        );
    }

    /**
     * Test cache path generation matches URL structure
     */
    public function testCachePathMatchesUrlStructure(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        // Test case 1: Basic resize
        $imagePath = 'catalog/product/w/t/wt09-white_main_1.jpg';
        $params = ['w' => 320, 'h' => 320, 'f' => 'jpeg'];
        $extension = 'jpeg';

        $cachePath = $method->invoke($this->service, $imagePath, $params, $extension);
        
        // Should be saved at: /media/resize/{base64}.jpeg
        // Format: /media/resize/{base64}.{ext} where base64 encodes: ip/{imagePath}?{params}
        $this->assertStringContainsString('/media/resize/', $cachePath);
        $this->assertStringEndsWith('.jpeg', $cachePath);
        $this->assertStringNotContainsString('/cache/resize/', $cachePath, 'Should not use old cache path');
        $this->assertStringNotContainsString('/ip/', $cachePath, 'Should not have ip/ in path');
        
        // Extract base64 part and verify it's valid base64
        $parts = explode('/', $cachePath);
        $filename = end($parts);
        $base64Part = substr($filename, 0, strrpos($filename, '.'));
        // Base64 should only contain A-Z, a-z, 0-9, -, _ (URL-safe base64)
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $base64Part, 'Base64 params should be URL-safe');
        // Verify we can decode it back to original format: ip/{imagePath}?{params}
        $decoded = base64_decode(strtr($base64Part, '-_', '+/'));
        $this->assertNotFalse($decoded, 'Base64 should be decodable');
        $this->assertStringStartsWith('ip/', $decoded, 'Decoded should start with ip/');
        // Extract params from decoded string
        if (strpos($decoded, '?') !== false) {
            list($pathPart, $queryPart) = explode('?', $decoded, 2);
            parse_str($queryPart, $decodedParams);
            $this->assertEquals('320', $decodedParams['w'] ?? null);
            $this->assertEquals('320', $decodedParams['h'] ?? null);
            $this->assertEquals('jpeg', $decodedParams['f'] ?? null);
            $this->assertStringContainsString('catalog/product/w/t/wt09-white_main_1.jpg', $pathPart);
        }
    }

    /**
     * Test cache path with different parameters
     */
    public function testCachePathWithDifferentParams(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        $imagePath = 'catalog/product/image.jpg';
        $params = ['w' => 400, 'h' => 300, 'f' => 'webp', 'q' => 85];
        $extension = 'webp';

        $cachePath = $method->invoke($this->service, $imagePath, $params, $extension);
        
        $this->assertStringContainsString('/media/resize/', $cachePath);
        $this->assertStringEndsWith('.webp', $cachePath);
        $this->assertStringNotContainsString('/ip/', $cachePath, 'Should not have ip/ in path');
        // Extract and verify base64
        $parts = explode('/', $cachePath);
        $filename = end($parts);
        $base64Part = substr($filename, 0, strrpos($filename, '.'));
        $decoded = base64_decode(strtr($base64Part, '-_', '+/'));
        $this->assertStringStartsWith('ip/', $decoded);
        if (strpos($decoded, '?') !== false) {
            list($pathPart, $queryPart) = explode('?', $decoded, 2);
            parse_str($queryPart, $decodedParams);
            $this->assertEquals('400', $decodedParams['w'] ?? null);
            $this->assertEquals('300', $decodedParams['h'] ?? null);
            $this->assertEquals('webp', $decodedParams['f'] ?? null);
            $this->assertEquals('85', $decodedParams['q'] ?? null);
            $this->assertStringContainsString('catalog/product/image.jpg', $pathPart);
        }
    }

    /**
     * Test cache path with only format parameter
     */
    public function testCachePathWithFormatOnly(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        $imagePath = 'catalog/product/image.jpg';
        $params = ['f' => 'png'];
        $extension = 'png';

        $cachePath = $method->invoke($this->service, $imagePath, $params, $extension);
        
        $this->assertStringContainsString('/media/resize/', $cachePath);
        $this->assertStringEndsWith('.png', $cachePath);
        $this->assertStringNotContainsString('/ip/', $cachePath, 'Should not have ip/ in path');
        // Extract and verify base64
        $parts = explode('/', $cachePath);
        $filename = end($parts);
        $base64Part = substr($filename, 0, strrpos($filename, '.'));
        $decoded = base64_decode(strtr($base64Part, '-_', '+/'));
        $this->assertStringStartsWith('ip/', $decoded);
        if (strpos($decoded, '?') !== false) {
            list($pathPart, $queryPart) = explode('?', $decoded, 2);
            parse_str($queryPart, $decodedParams);
            $this->assertEquals('png', $decodedParams['f'] ?? null);
            $this->assertStringContainsString('catalog/product/image.jpg', $pathPart);
        }
    }

    /**
     * Test cache path preserves directory structure
     */
    public function testCachePathPreservesDirectoryStructure(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        $imagePath = 'catalog/product/a/b/c/d/e/image.jpg';
        $params = ['w' => 200, 'f' => 'jpeg'];
        $extension = 'jpeg';

        $cachePath = $method->invoke($this->service, $imagePath, $params, $extension);
        
        $this->assertStringContainsString('/media/resize/', $cachePath);
        $this->assertStringEndsWith('.jpeg', $cachePath);
        // Verify base64 contains the path
        $parts = explode('/', $cachePath);
        $filename = end($parts);
        $base64Part = substr($filename, 0, strrpos($filename, '.'));
        $decoded = base64_decode(strtr($base64Part, '-_', '+/'));
        $this->assertStringContainsString('catalog/product/a/b/c/d/e/image.jpg', $decoded);
    }

    /**
     * Test cache path with special characters in image path
     */
    public function testCachePathWithSpecialCharacters(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        $imagePath = 'catalog/product/image-with-dashes.jpg';
        $params = ['w' => 100, 'f' => 'jpeg'];
        $extension = 'jpeg';

        $cachePath = $method->invoke($this->service, $imagePath, $params, $extension);
        
        // Should handle dashes correctly - verify in decoded base64
        $this->assertStringContainsString('/media/resize/', $cachePath);
        $parts = explode('/', $cachePath);
        $filename = end($parts);
        $base64Part = substr($filename, 0, strrpos($filename, '.'));
        $decoded = base64_decode(strtr($base64Part, '-_', '+/'));
        $this->assertStringContainsString('image-with-dashes', $decoded);
    }

    /**
     * Test that cache paths are deterministic (same params = same path)
     */
    public function testCachePathDeterministic(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        $imagePath = 'catalog/product/image.jpg';
        $params1 = ['w' => 320, 'h' => 240, 'f' => 'jpeg', 'q' => 85];
        $params2 = ['h' => 240, 'q' => 85, 'f' => 'jpeg', 'w' => 320]; // Same params, different order
        $extension = 'jpeg';

        $cachePath1 = $method->invoke($this->service, $imagePath, $params1, $extension);
        $cachePath2 = $method->invoke($this->service, $imagePath, $params2, $extension);
        
        // Should be the same path regardless of param order (ksort is used)
        $this->assertEquals($cachePath1, $cachePath2);
    }

    /**
     * Test cache path excludes null parameters
     */
    public function testCachePathExcludesNullParams(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCacheFilePath');
        $method->setAccessible(true);

        $imagePath = 'catalog/product/image.jpg';
        $params = ['w' => 320, 'h' => null, 'f' => 'jpeg', 'q' => null];
        $extension = 'jpeg';

        $cachePath = $method->invoke($this->service, $imagePath, $params, $extension);
        
        // Should not include null params in base64 encoding
        // Extract and decode base64 to verify
        $parts = explode('/', $cachePath);
        $filename = end($parts);
        $base64Part = substr($filename, 0, strrpos($filename, '.'));
        $decoded = base64_decode(strtr($base64Part, '-_', '+/'));
        $this->assertStringStartsWith('ip/', $decoded);
        // Decoded format: ip/{imagePath}?{params}
        if (strpos($decoded, '?') !== false) {
            list($pathPart, $queryPart) = explode('?', $decoded, 2);
            parse_str($queryPart, $decodedParams);
            // Decoded params should not contain null values
            $this->assertArrayNotHasKey('h', $decodedParams, 'Null params should not be included');
            $this->assertArrayNotHasKey('q', $decodedParams, 'Null params should not be included');
            $this->assertEquals('320', $decodedParams['w'] ?? null);
            $this->assertEquals('jpeg', $decodedParams['f'] ?? null);
            $this->assertStringContainsString('catalog/product/image.jpg', $pathPart);
        }
    }
}
