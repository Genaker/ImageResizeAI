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
use Genaker\ImageAIBundle\Service\GeminiVideoDirectService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Filesystem\Driver\File;
use Psr\Log\LoggerInterface;

/**
 * Test for GeminiVideoDirectService
 */
class GeminiVideoDirectServiceTest extends TestCase
{
    /** @var GeminiVideoDirectService */
    private $videoService;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|Curl */
    private $httpClientMock;

    /** @var MockObject|File */
    private $filesystemMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    /** @var string */
    private $testImagePath;

    /** @var string */
    private $testVideoDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Define BP constant if not defined
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }

        $this->testImagePath = BP . '/test_video_image.jpg';
        $this->testVideoDir = BP . '/pub/media/video/';

        // Create test image
        $this->createTestImage($this->testImagePath);

        // Create mocks
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->httpClientMock = $this->createMock(Curl::class);
        $this->filesystemMock = $this->createMock(File::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Configure scope config to return API key
        $this->scopeConfigMock->method('getValue')
            ->willReturnCallback(function ($path) {
                if ($path === 'genaker_imageaibundle/general/gemini_api_key') {
                    return 'test_api_key_12345';
                }
                return null;
            });

        // Create service instance
        $this->videoService = new GeminiVideoDirectService(
            $this->scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testImagePath)) {
            @unlink($this->testImagePath);
        }
        if (is_dir($this->testVideoDir)) {
            $this->rrmdir($this->testVideoDir);
        }
        parent::tearDown();
    }

    /**
     * Helper to create a dummy JPEG image
     *
     * @param string $targetPath
     */
    private function createTestImage(string $targetPath): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $image = imagecreatetruecolor(100, 100);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bgColor);
        imagejpeg($image, $targetPath, 90);
        imagedestroy($image);
    }

    /**
     * Recursively remove directory
     *
     * @param string $dir
     */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Test service is available when API key is configured
     */
    public function testIsAvailable()
    {
        $this->assertTrue($this->videoService->isAvailable());
    }

    /**
     * Test service is not available when API key is empty
     */
    public function testIsNotAvailableWhenApiKeyEmpty()
    {
        $scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $scopeConfigMock->method('getValue')
            ->willReturn(''); // Empty API key

        $service = new GeminiVideoDirectService(
            $scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        $this->assertFalse($service->isAvailable());
    }

    /**
     * Test generate video throws exception when image not found
     */
    public function testGenerateVideoThrowsExceptionWhenImageNotFound()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source image not found');
        $this->videoService->generateVideoFromImage('/non/existent/image.jpg', 'test prompt');
    }

    /**
     * Test generate video throws exception when service unavailable
     */
    public function testGenerateVideoThrowsExceptionWhenServiceUnavailable()
    {
        $scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $scopeConfigMock->method('getValue')
            ->willReturn(''); // Empty API key

        $service = new GeminiVideoDirectService(
            $scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini video service is not available');

        $service->generateVideoFromImage($this->testImagePath, 'test prompt');
    }

    /**
     * Test generate video returns operation details on success
     */
    public function testGenerateVideoFromImageReturnsOperation()
    {
        // Mock HTTP client response
        $operationName = 'operations/test-operation-123';
        $mockResponse = json_encode([
            'name' => $operationName,
            'done' => false
        ]);

        // setHeaders is called twice: once to set headers, once to reset (empty array)
        $this->httpClientMock->expects($this->exactly(2))
            ->method('setHeaders')
            ->willReturnCallback(function ($headers) {
                // First call sets headers, second call resets (empty array)
                return $this->httpClientMock;
            });

        $this->httpClientMock->expects($this->once())
            ->method('setTimeout')
            ->with(30)
            ->willReturnSelf();

        $this->httpClientMock->expects($this->once())
            ->method('post')
            ->with(
                $this->stringContains('predictLongRunning'),
                $this->callback(function ($payload) {
                    $data = json_decode($payload, true);
                    return isset($data['instances']) && 
                           isset($data['instances'][0]['prompt']) &&
                           isset($data['instances'][0]['image']['bytesBase64Encoded']) &&
                           isset($data['instances'][0]['image']['mimeType']) &&
                           isset($data['parameters']);
                })
            )
            ->willReturnSelf();

        $this->httpClientMock->method('getStatus')
            ->willReturn(200);

        $this->httpClientMock->method('getBody')
            ->willReturn($mockResponse);

        // Execute
        $result = $this->videoService->generateVideoFromImage(
            $this->testImagePath,
            'test prompt',
            '16:9'
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals($operationName, $result['operationName']);
        $this->assertFalse($result['done'] ?? true);
    }

    /**
     * Test generate video handles API error response
     */
    public function testGenerateVideoHandlesApiError()
    {
        $errorResponse = json_encode([
            'error' => [
                'code' => 404,
                'message' => 'Model not found'
            ]
        ]);

        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('post')->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(404);
        $this->httpClientMock->method('getBody')->willReturn($errorResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini API error (404)');

        $this->videoService->generateVideoFromImage(
            $this->testImagePath,
            'test prompt'
        );
    }

    /**
     * Test poll video operation returns video details when completed (real API format)
     * Uses predictLongRunning response format: response.generateVideoResponse.generatedSamples[0].video.uri
     */
    public function testPollVideoOperationReturnsVideoDetailsWithRealApiFormat()
    {
        $operationName = 'operations/test-operation-123';
        $videoUri = 'https://generativelanguage.googleapis.com/v1beta/files/q0rfot9fvz8w:download?alt=media';
        $videoContent = 'fake video mp4 content';

        // Real API response format from predictLongRunning
        $completedResponse = json_encode([
            'name' => $operationName,
            'done' => true,
            'response' => [
                'generateVideoResponse' => [
                    'generatedSamples' => [
                        [
                            'video' => [
                                'uri' => $videoUri
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        // Mock polling request
        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        
        // Mock get() calls: first for polling, second for downloading video
        $callCount = 0;
        $this->httpClientMock->method('get')
            ->willReturnCallback(function ($url) use (&$callCount, $videoUri) {
                $callCount++;
                return $this->httpClientMock;
            });
        
        $this->httpClientMock->method('getStatus')
            ->willReturn(200);
        
        $this->httpClientMock->method('getBody')
            ->willReturnCallback(function () use (&$callCount, $completedResponse, $videoContent) {
                if ($callCount === 1) {
                    return $completedResponse; // Polling response
                }
                return $videoContent; // Video download response
            });

        // Mock filesystem operations
        $this->filesystemMock->method('createDirectory')
            ->willReturnCallback(function ($path) {
                if (!is_dir($path)) {
                    return mkdir($path, 0755, true);
                }
                return true;
            });
        $this->filesystemMock->method('filePutContents')
            ->willReturnCallback(function ($path, $content) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                return file_put_contents($path, $content);
            });

        // Execute
        $result = $this->videoService->pollVideoOperation($operationName, 60, 1);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('completed', $result['status']);
        $this->assertTrue($result['cached']); // Videos are always cached now
        $this->assertNotNull($result['videoPath']);
        $this->assertFileExists($result['videoPath']);
        $this->assertEquals($videoContent, file_get_contents($result['videoPath']));
        $this->assertStringContainsString('/media/video/', $result['videoUrl']);
        $this->assertStringContainsString('<video', $result['embedUrl']);
    }

    /**
     * Test poll video operation handles timeout
     */
    public function testPollVideoOperationThrowsExceptionOnTimeout()
    {
        $operationName = 'operations/test-operation-123';
        $runningResponse = json_encode([
            'name' => $operationName,
            'done' => false
        ]);

        $this->httpClientMock->method('setHeaders')->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('get')->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($runningResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timeout');

        // Use very short timeout for testing
        // Mock sleep to speed up test
        $this->httpClientMock->method('setHeaders')->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        
        // Simulate timeout by making done=false persist
        $this->httpClientMock->method('get')->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($runningResponse);

        // Use very short timeout for testing
        $this->videoService->pollVideoOperation($operationName, 1, 1);
    }

    /**
     * Test poll video operation handles API error
     */
    public function testPollVideoOperationHandlesApiError()
    {
        $operationName = 'operations/test-operation-123';
        $errorResponse = json_encode([
            'error' => [
                'code' => 500,
                'message' => 'Internal server error'
            ]
        ]);

        $this->httpClientMock->method('setHeaders')->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('get')->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(500);
        $this->httpClientMock->method('getBody')->willReturn($errorResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini API error (500)');

        $this->videoService->pollVideoOperation($operationName);
    }

    /**
     * Test video is always saved locally (caching is always enabled)
     */
    public function testVideoIsAlwaysSavedLocally()
    {
        $operationName = 'operations/test-operation-123';
        $videoUri = 'https://generativelanguage.googleapis.com/v1beta/files/q0rfot9fvz8w:download?alt=media';
        $videoContent = 'fake video content';

        // Real API response format
        $completedResponse = json_encode([
            'name' => $operationName,
            'done' => true,
            'response' => [
                'generateVideoResponse' => [
                    'generatedSamples' => [
                        [
                            'video' => [
                                'uri' => $videoUri
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $callCount = 0;
        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('get')
            ->willReturnCallback(function ($url) use (&$callCount) {
                $callCount++;
                return $this->httpClientMock;
            });
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')
            ->willReturnCallback(function () use (&$callCount, $completedResponse, $videoContent) {
                if ($callCount === 1) {
                    return $completedResponse;
                }
                return $videoContent;
            });

        // Mock filesystem operations
        $this->filesystemMock->method('createDirectory')
            ->willReturnCallback(function ($path) {
                if (!is_dir($path)) {
                    return mkdir($path, 0755, true);
                }
                return true;
            });
        $this->filesystemMock->method('filePutContents')
            ->willReturnCallback(function ($path, $content) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                return file_put_contents($path, $content);
            });

        // Execute
        $result = $this->videoService->pollVideoOperation($operationName, 60, 1);

        // Assert video is always saved locally
        $this->assertIsArray($result);
        $this->assertEquals('completed', $result['status']);
        $this->assertTrue($result['cached']); // Always cached now
        $this->assertNotNull($result['videoPath']);
        $this->assertFileExists($result['videoPath']);
        $this->assertEquals($videoContent, file_get_contents($result['videoPath']));
        $this->assertStringContainsString('/pub/media/video/', $result['videoPath']);
    }

    /**
     * Test detect MIME type
     */
    public function testDetectMimeType()
    {
        // Create test images with different extensions
        $jpgPath = BP . '/test.jpg';
        $pngPath = BP . '/test.png';

        // Create actual JPEG image
        $this->createTestImage($jpgPath);
        
        // Create actual PNG image
        $pngImage = imagecreatetruecolor(100, 100);
        $bgColor = imagecolorallocate($pngImage, 255, 255, 255);
        imagefill($pngImage, 0, 0, $bgColor);
        imagepng($pngImage, $pngPath);
        imagedestroy($pngImage);

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->videoService);
        $method = $reflection->getMethod('detectMimeType');
        $method->setAccessible(true);

        $jpgMime = $method->invoke($this->videoService, $jpgPath, file_get_contents($jpgPath));
        $pngMime = $method->invoke($this->videoService, $pngPath, file_get_contents($pngPath));

        $this->assertEquals('image/jpeg', $jpgMime);
        $this->assertEquals('image/png', $pngMime);

        // Cleanup
        @unlink($jpgPath);
        @unlink($pngPath);
    }

    /**
     * Test poll video operation handles base64 encoded video data
     */
    public function testPollVideoOperationHandlesBase64VideoData()
    {
        $operationName = 'operations/test-operation-123';
        $base64VideoData = base64_encode('fake video content');

        $completedResponse = json_encode([
            'name' => $operationName,
            'done' => true,
            'response' => [
                'predictions' => [
                    [
                        'bytesBase64Encoded' => $base64VideoData
                    ]
                ]
            ]
        ]);

        // Videos are always cached now, no config needed

        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('get')->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($completedResponse);

        // Mock filesystem operations
        $this->filesystemMock->method('createDirectory')
            ->willReturnCallback(function ($path) {
                if (!is_dir($path)) {
                    return mkdir($path, 0755, true);
                }
                return true;
            });
        $this->filesystemMock->method('filePutContents')
            ->willReturnCallback(function ($path, $content) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                return file_put_contents($path, $content);
            });

        // Execute
        $result = $this->videoService->pollVideoOperation($operationName, 60, 1);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('completed', $result['status']);
        $this->assertTrue($result['cached']);
        $this->assertNotNull($result['videoPath']);
        $this->assertFileExists($result['videoPath']);
        $this->assertEquals('fake video content', file_get_contents($result['videoPath']));
    }

    /**
     * Test poll video operation handles error in response
     */
    public function testPollVideoOperationHandlesErrorResponse()
    {
        $operationName = 'operations/test-operation-123';
        $errorResponse = json_encode([
            'name' => $operationName,
            'done' => true,
            'error' => [
                'code' => 500,
                'message' => 'Video generation failed'
            ]
        ]);

        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('get')->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($errorResponse);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Video generation error');

        $this->videoService->pollVideoOperation($operationName);
    }

    /**
     * Test generate video validates aspect ratio
     */
    public function testGenerateVideoValidatesAspectRatio()
    {
        $operationName = 'operations/test-operation-123';
        $mockResponse = json_encode([
            'name' => $operationName,
            'done' => false
        ]);

        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($payload) {
                    $data = json_decode($payload, true);
                    return isset($data['generationConfig']['aspectRatio']) &&
                           $data['generationConfig']['aspectRatio'] === '9:16';
                })
            )
            ->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($mockResponse);

        // Execute with different aspect ratio
        $result = $this->videoService->generateVideoFromImage(
            $this->testImagePath,
            'test prompt',
            '9:16'
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals($operationName, $result['operationName']);
    }

    /**
     * Test video download with authenticated Google API URI
     * Verifies API key is appended to download URI
     */
    public function testVideoDownloadWithAuthenticatedUri()
    {
        $operationName = 'operations/test-operation-123';
        $videoUri = 'https://generativelanguage.googleapis.com/v1beta/files/q0rfot9fvz8w:download?alt=media';
        $videoContent = 'fake video mp4 content';

        // Real API response format
        $completedResponse = json_encode([
            'name' => $operationName,
            'done' => true,
            'response' => [
                'generateVideoResponse' => [
                    'generatedSamples' => [
                        [
                            'video' => [
                                'uri' => $videoUri
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        // Track download URI to verify API key was added
        $actualDownloadUri = null;

        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnCallback(function ($headers) {
                // Verify API key header is set
                if (is_array($headers) && isset($headers['x-goog-api-key'])) {
                    $this->assertEquals('test_api_key_12345', $headers['x-goog-api-key']);
                }
                return $this->httpClientMock;
            });

        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        
        $callCount = 0;
        $this->httpClientMock->method('get')
            ->willReturnCallback(function ($url) use (&$callCount, &$actualDownloadUri) {
                $callCount++;
                if ($callCount === 2) {
                    $actualDownloadUri = $url; // Capture download URI
                }
                return $this->httpClientMock;
            });
        
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')
            ->willReturnCallback(function () use (&$callCount, $completedResponse, $videoContent) {
                if ($callCount === 1) {
                    return $completedResponse;
                }
                return $videoContent;
            });

        // Mock filesystem
        $this->filesystemMock->method('createDirectory')
            ->willReturnCallback(function ($path) {
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
                return true;
            });
        $this->filesystemMock->method('filePutContents')
            ->willReturnCallback(function ($path, $content) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                return file_put_contents($path, $content);
            });

        // Execute
        $result = $this->videoService->pollVideoOperation($operationName, 60, 1);

        // Assert API key was appended to URI
        $this->assertNotNull($actualDownloadUri);
        $this->assertStringContainsString('key=', $actualDownloadUri);
        $this->assertStringContainsString('test_api_key_12345', $actualDownloadUri);
        
        // Assert video was saved
        $this->assertFileExists($result['videoPath']);
        $this->assertEquals($videoContent, file_get_contents($result['videoPath']));
    }

    /**
     * Test cache key generation and video caching
     * Verifies that same image+prompt+aspectRatio returns cached video
     */
    public function testVideoCachingWithCacheKey()
    {
        $imagePath = '/media/test/image.jpg';
        $prompt = 'make it summer';
        $aspectRatio = '16:9';
        
        // Create a cached video file
        $cacheKey = md5($imagePath . '|' . trim($prompt) . '|' . $aspectRatio);
        $cachedVideoPath = BP . '/pub/media/video/veo_' . $cacheKey . '.mp4';
        $cachedVideoContent = 'cached video content';
        
        // Ensure directory exists
        $videoDir = dirname($cachedVideoPath);
        if (!is_dir($videoDir)) {
            mkdir($videoDir, 0755, true);
        }
        file_put_contents($cachedVideoPath, $cachedVideoContent);

        // Mock filesystem to check file exists
        $this->filesystemMock->method('createDirectory')
            ->willReturnCallback(function ($path) {
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
                return true;
            });

        // Use reflection to test getCachedVideo method
        $reflection = new \ReflectionClass($this->videoService);
        $method = $reflection->getMethod('getCachedVideo');
        $method->setAccessible(true);

        // Test cache retrieval
        $cachedResult = $method->invoke($this->videoService, $cacheKey);

        // Assert cached video was found
        $this->assertNotNull($cachedResult);
        $this->assertIsArray($cachedResult);
        $this->assertTrue($cachedResult['fromCache']);
        $this->assertEquals($cachedVideoPath, $cachedResult['videoPath']);
        $this->assertStringContainsString('/media/video/', $cachedResult['videoUrl']);

        // Cleanup
        @unlink($cachedVideoPath);
    }

    /**
     * Test generateVideoFromImage returns cached video if exists
     */
    public function testGenerateVideoFromImageReturnsCachedVideo()
    {
        $imagePath = BP . '/pub/media/test/image.jpg';
        $prompt = 'make it summer';
        $aspectRatio = '16:9';
        
        // Create test image
        $this->createTestImage($imagePath);
        
        // Create cached video
        $cacheKey = md5('test/image.jpg|make it summer|16:9');
        $cachedVideoPath = BP . '/pub/media/video/veo_' . $cacheKey . '.mp4';
        $cachedVideoContent = 'cached video content';
        
        $videoDir = dirname($cachedVideoPath);
        if (!is_dir($videoDir)) {
            mkdir($videoDir, 0755, true);
        }
        file_put_contents($cachedVideoPath, $cachedVideoContent);

        // Execute - should return cached video
        $result = $this->videoService->generateVideoFromImage($imagePath, $prompt, $aspectRatio);

        // Assert cached video was returned
        $this->assertIsArray($result);
        $this->assertTrue($result['fromCache'] ?? false);
        $this->assertEquals('completed', $result['status']);
        $this->assertFileExists($result['videoPath']);
        $this->assertEquals($cachedVideoContent, file_get_contents($result['videoPath']));

        // Cleanup
        @unlink($imagePath);
        @unlink($cachedVideoPath);
    }

    /**
     * Test aspect ratio is included in parameters
     */
    public function testGenerateVideoIncludesAspectRatioInParameters()
    {
        $operationName = 'operations/test-operation-123';
        $mockResponse = json_encode([
            'name' => $operationName,
            'done' => false
        ]);

        $this->httpClientMock->expects($this->atLeastOnce())
            ->method('setHeaders')
            ->willReturnSelf();
        $this->httpClientMock->method('setTimeout')->willReturnSelf();
        $this->httpClientMock->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($payload) {
                    $data = json_decode($payload, true);
                    return isset($data['parameters']['aspectRatio']) &&
                           $data['parameters']['aspectRatio'] === '9:16';
                })
            )
            ->willReturnSelf();
        $this->httpClientMock->method('getStatus')->willReturn(200);
        $this->httpClientMock->method('getBody')->willReturn($mockResponse);

        // Execute with aspect ratio
        $result = $this->videoService->generateVideoFromImage(
            $this->testImagePath,
            'test prompt',
            '9:16'
        );

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals($operationName, $result['operationName']);
    }
}
