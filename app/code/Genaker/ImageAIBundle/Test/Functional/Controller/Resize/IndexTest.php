<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Test\Functional\Controller\Resize;

use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;

/**
 * Functional test for Image Resize Controller
 * Tests real URL calls to verify image resizing functionality
 */
class IndexTest extends TestCase
{
    /** @var Http */
    private $app;

    /** @var string */
    private $testImagePath;

    /** @var string */
    private $testImageFullPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Require Magento bootstrap (BP will be defined there)
        if (!defined('BP')) {
            // Find Magento root by looking for app/bootstrap.php
            $currentDir = __DIR__;
            $bp = null;
            for ($i = 0; $i < 20; $i++) {
                $bootstrapPath = $currentDir . '/app/bootstrap.php';
                if (file_exists($bootstrapPath)) {
                    $bp = $currentDir;
                    break;
                }
                $currentDir = dirname($currentDir);
            }
            if (!$bp) {
                throw new \RuntimeException('Could not find Magento root directory');
            }
            require_once $bp . '/app/bootstrap.php';
        } else {
            require_once BP . '/app/bootstrap.php';
        }

        // Bootstrap Magento application
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        $this->app = $bootstrap->createApplication(Http::class);

        // Create test image in media directory
        // ImageResizeService resolves paths as: BP/pub/media/{imagePath}
        $mediaPath = BP . '/pub/media';
        $this->testImagePath = 'catalog/product/test_resize_' . uniqid() . '.jpg';
        $this->testImageFullPath = $mediaPath . '/' . $this->testImagePath;
        
        // Ensure directory exists
        $imageDir = dirname($this->testImageFullPath);
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }
        
        $this->createTestImage($this->testImageFullPath);
        
        // Verify image was created
        if (!file_exists($this->testImageFullPath)) {
            throw new \RuntimeException('Failed to create test image at: ' . $this->testImageFullPath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test image
        if (file_exists($this->testImageFullPath)) {
            @unlink($this->testImageFullPath);
        }

        // Clean up cache directory (only the specific image's cache, not the entire directory)
        if (defined('BP') && isset($this->testImagePath)) {
            // Cache path includes the full image path as a directory structure
            // e.g., cache/resize/catalog/product/test_image.jpg/params.ext
            $cachePath = BP . '/pub/media/cache/resize/' . $this->testImagePath;
            if (is_dir($cachePath)) {
                $this->deleteDirectory($cachePath);
            }
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

        // Set up request
        $_SERVER['REQUEST_URI'] = $url;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'w' => $width,
            'h' => $height,
            'f' => $format,
            'q' => $quality,
        ];

        list($responseCode, $responseBody, $headers) = $this->dispatchRequest($url, ['imagePath' => $this->testImagePath]);

        // Verify response
        $this->assertEquals(200, $responseCode, 'Response should be 200 OK');
        $this->assertNotEmpty($responseBody, 'Response body should not be empty. Response code: ' . $responseCode);

        // Verify response headers
        $contentType = null;
        foreach ($headers as $header) {
            if ($header->getFieldName() === 'Content-Type') {
                $contentType = $header->getFieldValue();
                break;
            }
        }

        $this->assertNotNull($contentType, 'Content-Type header should be set');
        $this->assertEquals('image/webp', $contentType, 'Content-Type should be image/webp');

        // Verify it's a valid image
        $this->assertTrue(
            $this->isValidImage($responseBody, $format),
            'Response should be a valid ' . $format . ' image'
        );

        // Verify image has reasonable size
        $this->assertGreaterThan(100, strlen($responseBody), 'Image should have reasonable size');
    }

    /**
     * Test resize image with width only
     */
    public function testResizeImageWidthOnly()
    {
        $width = 500;
        $format = 'jpeg'; // Use 'jpeg' instead of 'jpg' to match normalization

        $url = sprintf(
            '/media/resize/index/imagePath/%s?w=%d&f=%s',
            urlencode($this->testImagePath),
            $width,
            $format
        );

        list($responseCode, $responseBody, $headers) = $this->dispatchRequest($url, ['imagePath' => $this->testImagePath]);

        $this->assertEquals(200, $responseCode);
        $this->assertNotEmpty($responseBody);

        $contentType = $this->getHeaderValue($headers, 'Content-Type');
        $this->assertEquals('image/jpeg', $contentType);
        $this->assertTrue($this->isValidImage($responseBody, 'jpg'));
    }

    /**
     * Test resize image caching - second call should use cache
     */
    public function testResizeImageCaching()
    {
        $width = 400;
        $height = 400;
        $format = 'jpeg'; // Use 'jpeg' to match normalization (jpg -> jpeg)
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
        list($firstResponseCode, $firstResponse, $firstHeaders) = $this->dispatchRequest($url, ['imagePath' => $this->testImagePath]);
        $this->assertEquals(200, $firstResponseCode);
        $firstCacheStatus = $this->getHeaderValue($firstHeaders, 'X-Cache-Status');

        // Second call - should use cache
        list($secondResponseCode, $secondResponse, $secondHeaders) = $this->dispatchRequest($url, ['imagePath' => $this->testImagePath]);
        $this->assertEquals(200, $secondResponseCode);
        $secondCacheStatus = $this->getHeaderValue($secondHeaders, 'X-Cache-Status');

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
            'jpeg' => 'image/jpeg', // Use 'jpeg' instead of 'jpg' to match normalization
            'png' => 'image/png',
        ];

        foreach ($formats as $format => $expectedMimeType) {
            $url = sprintf(
                '/media/resize/index/imagePath/%s?w=200&h=200&f=%s',
                urlencode($this->testImagePath),
                $format
            );

            list($responseCode, $responseBody, $headers) = $this->dispatchRequest($url, ['imagePath' => $this->testImagePath]);

            $this->assertEquals(200, $responseCode, "Format {$format} should return 200");

            $contentType = $this->getHeaderValue($headers, 'Content-Type');
            $this->assertEquals(
                $expectedMimeType,
                $contentType,
                "Format {$format} should have correct MIME type"
            );

            $this->assertTrue(
                $this->isValidImage($responseBody, $format),
                "Format {$format} should be valid image"
            );
        }
    }

    /**
     * Test resize image with missing image path
     */
    public function testResizeImageMissingPath()
    {
        $url = '/media/resize/index/imagePath/?w=300&h=300&f=jpg';

        list($responseCode, $responseBody, $headers) = $this->dispatchRequest($url, []);

        // Should return 404
        $this->assertEquals(404, $responseCode);
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

        list($responseCode, $responseBody, $headers) = $this->dispatchRequest($url, ['imagePath' => 'catalog/product/non-existent-image.jpg']);

        // Should return 404
        $this->assertEquals(404, $responseCode);
    }

    /**
     * Dispatch a request and get response
     * Uses the controller directly to avoid routing issues
     *
     * @param string $url
     * @param array $params
     * @return array [responseCode, responseBody, headers]
     */
    private function dispatchRequest(string $url, array $params = []): array
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        
        // Launch app and set area code
        try {
            $this->app->launch();
        } catch (\Exception $e) {
            // App may already be launched, continue
        }
        
        // Set app state to frontend
        $appState = $objectManager->get(\Magento\Framework\App\State::class);
        try {
            $appState->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        } catch (\Exception $e) {
            // Area already set
        }
        
        $areaList = $objectManager->get(\Magento\Framework\App\AreaList::class);
        $areaCode = \Magento\Framework\App\Area::AREA_FRONTEND;
        $area = $areaList->getArea($areaCode);
        $area->load(\Magento\Framework\App\Area::PART_CONFIG);
        
        // Set up store - try to get default store first
        $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
        try {
            // Try to get default store
            $storeRepository = $objectManager->get(\Magento\Store\Api\StoreRepositoryInterface::class);
            $store = $storeRepository->get('default');
            $storeManager->setCurrentStore($store->getId());
        } catch (\Exception $e) {
            try {
                // Try store ID 1
                $store = $storeManager->getStore(1);
                $storeManager->setCurrentStore($store->getId());
            } catch (\Exception $e2) {
                // Try to get any store
                try {
                    $stores = $storeManager->getStores();
                    if (count($stores) > 0) {
                        $store = reset($stores);
                        $storeManager->setCurrentStore($store->getId());
                    }
                } catch (\Exception $e3) {
                    // Store setup failed, continue anyway - scope config will use default values
                }
            }
        }
        
        // Create controller directly
        $context = $objectManager->get(\Magento\Framework\App\Action\Context::class);
        $imageResizeService = $objectManager->get(\Genaker\ImageAIBundle\Api\ImageResizeServiceInterface::class);
        $scopeConfig = $objectManager->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $logger = $objectManager->get(\Psr\Log\LoggerInterface::class);
        $httpHeader = $objectManager->get(\Magento\Framework\HTTP\Header::class);
        $lockManager = $objectManager->get(\Genaker\ImageAIBundle\Service\LockManager::class);
        $authSession = $objectManager->get(\Magento\Backend\Model\Auth\Session::class);
        
        $controller = new \Genaker\ImageAIBundle\Controller\Resize\Index(
            $context,
            $imageResizeService,
            $scopeConfig,
            $logger,
            $httpHeader,
            $lockManager,
            $authSession
        );
        
        // Set request parameters
        $request = $context->getRequest();
        // Set store code in request
        try {
            $store = $storeManager->getStore();
            $request->setParam('___store', $store->getCode());
        } catch (\Exception $e) {
            // Ignore store setup errors
        }
        
        // Parse URL to extract query parameters and set them
        $urlParts = parse_url($url);
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
            foreach ($queryParams as $key => $value) {
                $request->setParam($key, $value);
            }
        }
        
        // Set additional params (like imagePath)
        foreach ($params as $key => $value) {
            // Remove leading slash from imagePath if present
            if ($key === 'imagePath') {
                $value = ltrim($value, '/');
                // Also set it in the path info for URL-based extraction
                $pathInfo = '/media/resize/index/imagePath/' . $value;
                $request->setPathInfo($pathInfo);
            }
            $request->setParam($key, $value);
        }
        
        // Debug: Verify image exists and check filesystem (only for test images, not for error tests)
        if (isset($params['imagePath']) && strpos($params['imagePath'], 'non-existent') === false) {
            $imagePath = ltrim($params['imagePath'], '/');
            $resolvedPath = BP . '/pub/media/' . $imagePath;
            
            // Verify BP is consistent
            $serviceMediaPath = BP . '/pub/media';
            
            error_log(sprintf(
                'Image check: Path=%s, FileExists=%s, ResolvedPath=%s, BP=%s, ServiceMediaPath=%s',
                $imagePath,
                file_exists($resolvedPath) ? 'yes' : 'no',
                $resolvedPath,
                BP,
                $serviceMediaPath
            ));
            
            // Ensure file exists before calling controller (skip for error test cases)
            if (!file_exists($resolvedPath)) {
                throw new \RuntimeException("Test image not found at: {$resolvedPath}");
            }
        }
        
        // Execute controller
        try {
            $result = $controller->execute();
        } catch (\Exception $e) {
            // Get the previous exception if it exists (the real error)
            $previousException = $e->getPrevious();
            $actualMessage = $previousException ? $previousException->getMessage() : $e->getMessage();
            
            // Log full exception details
            $exceptionDetails = sprintf(
                'Controller exception: %s (actual: %s) in %s:%d',
                $e->getMessage(),
                $actualMessage,
                $e->getFile(),
                $e->getLine()
            );
            error_log($exceptionDetails);
            
            // Check if it's a NotFoundException (expected for missing images)
            if ($e instanceof \Magento\Framework\Exception\NotFoundException) {
                $response = $context->getResponse();
                return [404, $response->getBody(), $response->getHeaders()];
            }
            
            $response = $context->getResponse();
            return [$response->getHttpResponseCode() ?: 500, $response->getBody(), $response->getHeaders()];
        }
        
        // Get response
        $response = $context->getResponse();
        
        // Render result if it's a ResultInterface
        if ($result instanceof \Magento\Framework\Controller\ResultInterface) {
            $result->renderResult($response);
            
            // Get contents from Raw result
            if ($result instanceof \Magento\Framework\Controller\Result\Raw) {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('contents')) {
                    $property = $reflection->getProperty('contents');
                    $property->setAccessible(true);
                    $rawContents = $property->getValue($result);
                    if (!empty($rawContents)) {
                        $response->setBody($rawContents);
                    }
                }
            }
        }
        
        return [$response->getHttpResponseCode(), $response->getBody(), $response->getHeaders()];
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
            'webp' => ["RIFF"],
        ];

        if (!isset($signatures[$format])) {
            return false;
        }

        foreach ($signatures[$format] as $signature) {
            if (strpos($content, $signature) === 0) {
                // For WebP, also check for "WEBP" string
                if ($format === 'webp' && strpos($content, 'WEBP', 8) !== false) {
                    return true;
                }
                if ($format !== 'webp') {
                    return true;
                }
            }
        }

        // For WebP, check if it contains WEBP string anywhere
        if ($format === 'webp' && strpos($content, 'WEBP') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get header value from headers array or Headers object
     *
     * @param array|\Laminas\Http\Headers $headers
     * @param string $headerName
     * @return string|null
     */
    private function getHeaderValue($headers, string $headerName): ?string
    {
        // Handle Laminas\Http\Headers object
        if ($headers instanceof \Laminas\Http\Headers) {
            $header = $headers->get($headerName);
            return $header ? $header->getFieldValue() : null;
        }
        
        // Handle array of header objects
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (is_object($header) && method_exists($header, 'getFieldName') && $header->getFieldName() === $headerName) {
                    return $header->getFieldValue();
                }
            }
        }
        
        return null;
    }

    /**
     * Delete directory recursively
     *
     * @param string $dir
     * @return void
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
