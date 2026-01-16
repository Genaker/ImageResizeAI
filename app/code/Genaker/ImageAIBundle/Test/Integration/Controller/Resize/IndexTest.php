<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Test\Integration\Controller\Resize;

use Magento\TestFramework\TestCase\AbstractController;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Integration test for Image Resize Controller
 * Tests real URL calls to verify image resizing functionality
 *
 * @magentoAppIsolation enabled
 * @magentoAppArea frontend
 */
class IndexTest extends AbstractController
{
    /** @var WriteInterface */
    private $mediaDirectory;

    /** @var string */
    private $testImagePath;

    /** @var string */
    private $testImageFullPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Get media directory
        $filesystem = $this->_objectManager->get(Filesystem::class);
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        // Create test image in media directory
        $this->testImagePath = 'catalog/product/test_resize_' . uniqid() . '.jpg';
        $this->testImageFullPath = $this->mediaDirectory->getAbsolutePath($this->testImagePath);
        $this->createTestImage($this->testImageFullPath);
    }

    protected function tearDown(): void
    {
        // Clean up test image
        if ($this->mediaDirectory->isExist($this->testImagePath)) {
            $this->mediaDirectory->delete($this->testImagePath);
        }

        // Clean up cache directory
        $cachePath = 'cache/resize/' . dirname($this->testImagePath);
        if ($this->mediaDirectory->isExist($cachePath)) {
            $this->mediaDirectory->delete($cachePath);
        }

        parent::tearDown();
    }

    /**
     * Test resize image via real URL with path parameters
     */
    public function testResizeImageViaUrl()
    {
        $width = 300;
        $height = 300;
        $format = 'webp';
        $quality = 85;

        // Build URL: /media/resize/index/imagePath/{path}?w=300&h=300&f=webp&q=85
        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=%d&h=%d&f=%s&q=%d',
            urlencode($this->testImagePath),
            $width,
            $height,
            $format,
            $quality
        );

        // Dispatch request
        $this->dispatch($url);

        // Verify response
        $this->assertResponseStatusCode(200);
        $responseBody = $this->getResponse()->getBody();

        // Verify response is not empty
        $this->assertNotEmpty($responseBody, 'Response body should not be empty');

        // Verify response headers
        $headers = $this->getResponse()->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('image/webp', $headers['Content-Type']->getFieldValue());

        // Verify cache header
        if (isset($headers['X-Cache-Status'])) {
            $cacheStatus = $headers['X-Cache-Status']->getFieldValue();
            $this->assertContains($cacheStatus, ['HIT', 'MISS']);
        }

        // Verify it's a valid image (check file signature)
        $this->assertTrue(
            $this->isValidImage($responseBody, $format),
            'Response should be a valid ' . $format . ' image'
        );

        // Verify image dimensions (basic check - file should exist)
        $this->assertGreaterThan(100, strlen($responseBody), 'Image should have reasonable size');
    }

    /**
     * Test resize image with width only
     */
    public function testResizeImageWidthOnly()
    {
        $width = 500;
        $format = 'jpg';

        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=%d&f=%s',
            urlencode($this->testImagePath),
            $width,
            $format
        );

        $this->dispatch($url);

        $this->assertResponseStatusCode(200);
        $responseBody = $this->getResponse()->getBody();
        $this->assertNotEmpty($responseBody);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('image/jpeg', $headers['Content-Type']->getFieldValue());
        $this->assertTrue($this->isValidImage($responseBody, 'jpg'));
    }

    /**
     * Test resize image with height only
     */
    public function testResizeImageHeightOnly()
    {
        $height = 600;
        $format = 'png';

        $url = sprintf(
            '/media/resize/index/imagePath/%s?h=%d&f=%s',
            urlencode($this->testImagePath),
            $height,
            $format
        );

        $this->dispatch($url);

        $this->assertResponseStatusCode(200);
        $responseBody = $this->getResponse()->getBody();
        $this->assertNotEmpty($responseBody);

        $headers = $this->getResponse()->getHeaders();
        $this->assertEquals('image/png', $headers['Content-Type']->getFieldValue());
        $this->assertTrue($this->isValidImage($responseBody, 'png'));
    }

    /**
     * Test resize image caching - second call should use cache
     */
    public function testResizeImageCaching()
    {
        $width = 400;
        $height = 400;
        $format = 'jpg';
        $quality = 80;

        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=%d&h=%d&f=%s&q=%d',
            urlencode($this->testImagePath),
            $width,
            $height,
            $format,
            $quality
        );

        // First call
        $this->dispatch($url);
        $this->assertResponseStatusCode(200);
        $firstResponse = $this->getResponse()->getBody();
        $firstHeaders = $this->getResponse()->getHeaders();
        $firstCacheStatus = isset($firstHeaders['X-Cache-Status']) 
            ? $firstHeaders['X-Cache-Status']->getFieldValue() 
            : null;

        // Reset response
        $this->reset();

        // Second call - should use cache
        $this->dispatch($url);
        $this->assertResponseStatusCode(200);
        $secondResponse = $this->getResponse()->getBody();
        $secondHeaders = $this->getResponse()->getHeaders();
        $secondCacheStatus = isset($secondHeaders['X-Cache-Status']) 
            ? $secondHeaders['X-Cache-Status']->getFieldValue() 
            : null;

        // Verify responses are identical
        $this->assertEquals($firstResponse, $secondResponse, 'Cached response should be identical');

        // Verify cache was used (second call should be HIT)
        if ($secondCacheStatus) {
            $this->assertEquals('HIT', $secondCacheStatus, 'Second call should use cache');
        }
    }

    /**
     * Test resize image with different formats
     */
    public function testResizeImageDifferentFormats()
    {
        $formats = [
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
        ];

        foreach ($formats as $format => $expectedMimeType) {
            $url = sprintf(
                '/media/resize/index/imagePath/%s?w=200&h=200&f=%s',
                urlencode($this->testImagePath),
                $format
            );

            $this->dispatch($url);
            $this->assertResponseStatusCode(200, "Format {$format} should return 200");

            $headers = $this->getResponse()->getHeaders();
            $this->assertEquals(
                $expectedMimeType,
                $headers['Content-Type']->getFieldValue(),
                "Format {$format} should have correct MIME type"
            );

            $responseBody = $this->getResponse()->getBody();
            $this->assertTrue(
                $this->isValidImage($responseBody, $format),
                "Format {$format} should be valid image"
            );

            // Reset for next iteration
            $this->reset();
        }
    }

    /**
     * Test resize image with path starting with slash
     */
    public function testResizeImageWithLeadingSlash()
    {
        $imagePathWithSlash = '/' . $this->testImagePath;
        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=300&h=300&f=jpg',
            urlencode($imagePathWithSlash)
        );

        $this->dispatch($url);

        $this->assertResponseStatusCode(200);
        $responseBody = $this->getResponse()->getBody();
        $this->assertNotEmpty($responseBody);
    }

    /**
     * Test resize image with invalid format
     */
    public function testResizeImageInvalidFormat()
    {
        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=300&h=300&f=bmp',
            urlencode($this->testImagePath)
        );

        $this->dispatch($url);

        // Should return error (404 or 400)
        $this->assertNotEquals(200, $this->getResponse()->getHttpResponseCode());
    }

    /**
     * Test resize image with missing image path
     */
    public function testResizeImageMissingPath()
    {
        $url = '/media/resize/index/imagePath/?w=300&h=300&f=jpg';

        $this->dispatch($url);

        // Should return 404
        $this->assertEquals(404, $this->getResponse()->getHttpResponseCode());
    }

    /**
     * Test resize image with non-existent image
     */
    public function testResizeImageNonExistent()
    {
        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=300&h=300&f=jpg',
            urlencode('catalog/product/non-existent-image.jpg')
        );

        $this->dispatch($url);

        // Should return 404
        $this->assertEquals(404, $this->getResponse()->getHttpResponseCode());
    }

    /**
     * Test resize image with invalid dimensions
     */
    public function testResizeImageInvalidDimensions()
    {
        // Width too small
        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=10&h=300&f=jpg',
            urlencode($this->testImagePath)
        );

        $this->dispatch($url);

        // Should return error
        $this->assertNotEquals(200, $this->getResponse()->getHttpResponseCode());
    }

    /**
     * Create a test image file
     *
     * @param string $filePath
     * @return void
     */
    private function createTestImage(string $filePath): void
    {
        // Ensure directory exists
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Create a simple 800x600 JPEG image
        $image = imagecreatetruecolor(800, 600);
        $bgColor = imagecolorallocate($image, 100, 150, 200);
        imagefill($image, 0, 0, $bgColor);
        
        // Add some colored rectangles for visual testing
        $red = imagecolorallocate($image, 255, 0, 0);
        $green = imagecolorallocate($image, 0, 255, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        
        imagefilledrectangle($image, 50, 50, 200, 150, $red);
        imagefilledrectangle($image, 250, 200, 400, 350, $green);
        imagefilledrectangle($image, 450, 400, 600, 550, $blue);
        
        imagejpeg($image, $filePath, 90);
        imagedestroy($image);
    }

    /**
     * Validate if content is a valid image
     *
     * @param string $content
     * @param string $format
     * @return bool
     */
    private function isValidImage(string $content, string $format): bool
    {
        if (empty($content)) {
            return false;
        }

        // Check file signatures (magic bytes)
        $signatures = [
            'jpg' => ["\xFF\xD8\xFF"],
            'jpeg' => ["\xFF\xD8\xFF"],
            'png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'gif' => ["GIF87a", "GIF89a"],
            'webp' => ["RIFF"], // WebP starts with RIFF, but we need to check further
        ];

        if (!isset($signatures[$format])) {
            return false;
        }

        foreach ($signatures[$format] as $signature) {
            if (strpos($content, $signature) === 0) {
                // For WebP, also check for "WEBP" string
                if ($format === 'webp' && strpos($content, 'WEBP', 8) === false) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }
}
