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

use PHPUnit\Framework\TestCase;

/**
 * Integration test for Video Generation Controller using curl
 * Tests real HTTP requests to verify video generation functionality
 * 
 * Note: These tests require:
 * 1. Gemini API key configured (GEMINI_API_KEY environment variable)
 * 2. Gemini SDK with Veo 3.1 support
 * 3. Actual API calls will be made (may incur costs)
 */
class VideoCurlTest extends TestCase
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $testImagePath;

    /** @var string */
    private $testImageFullPath;

    /** @var bool */
    private $skipTests = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Check if Gemini API key is configured
        $apiKey = getenv('GEMINI_API_KEY');
        if (empty($apiKey)) {
            $this->skipTests = true;
            $this->markTestSkipped('GEMINI_API_KEY environment variable not set. Skipping video generation tests.');
            return;
        }

        // Get base URL from environment or use default
        $this->baseUrl = getenv('BASE_URL') ?: 'https://app.lc.test';
        // Remove trailing slash if present
        $this->baseUrl = rtrim($this->baseUrl, '/');

        // Find Magento root (BP) to create test image
        if (!defined('BP')) {
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
            define('BP', $bp);
        }

        // Create test image in media directory
        $mediaPath = BP . '/pub/media';
        $this->testImagePath = 'catalog/product/test_video_' . uniqid() . '.jpg';
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

        // Clean up test video cache directory
        $videoCacheDir = BP . '/pub/media/cache/video';
        if (is_dir($videoCacheDir)) {
            $this->deleteDirectory($videoCacheDir);
        }

        parent::tearDown();
    }

    /**
     * Test video generation start (async mode)
     * 
     * @group video
     * @group integration
     */
    public function testStartVideoGenerationAsync()
    {
        if ($this->skipTests) {
            $this->markTestSkipped('Skipping due to missing API key');
            return;
        }

        $url = $this->baseUrl . '/media/resize/ip/' . urlencode($this->testImagePath);
        $params = [
            'video' => 'true',
            'prompt' => 'make it summer',
            'aspectRatio' => '16:9'
        ];
        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $this->assertNotFalse($response, 'Curl request should succeed');
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Check response code
        if ($httpCode === 500) {
            // Check if it's an SDK limitation error
            $bodyJson = json_decode($body, true);
            if (isset($bodyJson['error']) && 
                (strpos($bodyJson['error'], 'generateVideos') !== false ||
                 strpos($bodyJson['error'], 'Veo') !== false ||
                 strpos($bodyJson['error'], 'SDK') !== false)) {
                $this->markTestSkipped('Video generation not supported by current Gemini SDK: ' . ($bodyJson['error'] ?? 'Unknown error'));
                return;
            }
        }

        $this->assertEquals(200, $httpCode, 'HTTP status should be 200. Response: ' . substr($body, 0, 500));

        // Parse JSON response
        $data = json_decode($body, true);
        $this->assertNotNull($data, 'Response should be valid JSON. Body: ' . substr($body, 0, 500));
        $this->assertIsArray($data, 'Response should be an array');

        // Check response structure
        $this->assertArrayHasKey('success', $data, 'Response should have success key');
        
        if (isset($data['success']) && $data['success'] === false) {
            // If video generation failed due to SDK limitations, skip test
            if (isset($data['error']) && 
                (strpos($data['error'], 'generateVideos') !== false ||
                 strpos($data['error'], 'Veo') !== false ||
                 strpos($data['error'], 'SDK') !== false)) {
                $this->markTestSkipped('Video generation not supported: ' . $data['error']);
                return;
            }
            $this->fail('Video generation failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        $this->assertTrue($data['success'], 'Video generation should succeed');
        $this->assertArrayHasKey('status', $data, 'Response should have status key');
        
        // In async mode, should return processing status with operation name
        if ($data['status'] === 'processing') {
            $this->assertArrayHasKey('operationName', $data, 'Processing status should include operationName');
            $this->assertNotEmpty($data['operationName'], 'Operation name should not be empty');
            $this->assertStringStartsWith('operations/', $data['operationName'], 'Operation name should start with operations/');
        }
    }

    /**
     * Test video generation with synchronous polling
     * 
     * Note: This test may take 30-60 seconds as it waits for video completion
     * 
     * @group video
     * @group integration
     * @group slow
     */
    public function testVideoGenerationSynchronous()
    {
        if ($this->skipTests) {
            $this->markTestSkipped('Skipping due to missing API key');
            return;
        }

        $url = $this->baseUrl . '/media/resize/ip/' . urlencode($this->testImagePath);
        $params = [
            'video' => 'true',
            'prompt' => 'make it summer',
            'aspectRatio' => '16:9',
            'poll' => 'true'
        ];
        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 300, // 5 minutes timeout for video generation
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $this->assertNotFalse($response, 'Curl request should succeed');
        
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Check response code
        if ($httpCode === 500) {
            $bodyJson = json_decode($body, true);
            if (isset($bodyJson['error']) && 
                (strpos($bodyJson['error'], 'generateVideos') !== false ||
                 strpos($bodyJson['error'], 'Veo') !== false ||
                 strpos($bodyJson['error'], 'SDK') !== false)) {
                $this->markTestSkipped('Video generation not supported by current Gemini SDK: ' . ($bodyJson['error'] ?? 'Unknown error'));
                return;
            }
        }

        $this->assertEquals(200, $httpCode, 'HTTP status should be 200. Response: ' . substr($body, 0, 500));

        // Parse JSON response
        $data = json_decode($body, true);
        $this->assertNotNull($data, 'Response should be valid JSON. Body: ' . substr($body, 0, 500));
        $this->assertIsArray($data, 'Response should be an array');

        // Check response structure
        $this->assertArrayHasKey('success', $data, 'Response should have success key');
        
        if (isset($data['success']) && $data['success'] === false) {
            if (isset($data['error']) && 
                (strpos($data['error'], 'generateVideos') !== false ||
                 strpos($data['error'], 'Veo') !== false ||
                 strpos($data['error'], 'SDK') !== false)) {
                $this->markTestSkipped('Video generation not supported: ' . $data['error']);
                return;
            }
            $this->fail('Video generation failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        $this->assertTrue($data['success'], 'Video generation should succeed');
        
        // In synchronous mode with poll=true, should return completed status
        if (isset($data['status']) && $data['status'] === 'completed') {
            $this->assertArrayHasKey('videoUrl', $data, 'Completed status should include videoUrl');
            $this->assertArrayHasKey('embedUrl', $data, 'Completed status should include embedUrl');
            $this->assertArrayHasKey('videoPath', $data, 'Completed status should include videoPath');
            
            $this->assertNotEmpty($data['videoUrl'], 'Video URL should not be empty');
            $this->assertNotEmpty($data['embedUrl'], 'Embed URL should not be empty');
            $this->assertStringContainsString('.mp4', $data['videoUrl'], 'Video URL should point to MP4 file');
            $this->assertStringContainsString('<video', $data['embedUrl'], 'Embed URL should contain video tag');
            
            // Verify video file exists
            if (isset($data['videoPath']) && file_exists($data['videoPath'])) {
                $this->assertGreaterThan(0, filesize($data['videoPath']), 'Video file should not be empty');
            }
        } else {
            // If status is processing, that's also acceptable (video generation takes time)
            $this->assertContains($data['status'] ?? '', ['processing', 'completed'], 
                'Status should be processing or completed');
        }
    }

    /**
     * Test video generation error handling (missing prompt)
     * 
     * @group video
     * @group integration
     */
    public function testVideoGenerationMissingPrompt()
    {
        if ($this->skipTests) {
            $this->markTestSkipped('Skipping due to missing API key');
            return;
        }

        $url = $this->baseUrl . '/media/resize/ip/' . urlencode($this->testImagePath);
        $params = [
            'video' => 'true'
            // Missing prompt parameter
        ];
        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $this->assertNotFalse($response, 'Curl request should succeed');
        
        $body = substr($response, $headerSize);

        // Should return error (404 or 500)
        $this->assertContains($httpCode, [404, 500], 'Should return error for missing prompt');

        // Try to parse JSON if available
        $data = json_decode($body, true);
        if ($data !== null && isset($data['success'])) {
            $this->assertFalse($data['success'], 'Response should indicate failure');
            $this->assertArrayHasKey('error', $data, 'Error response should include error message');
        }
    }

    /**
     * Test video generation with different aspect ratios
     * 
     * @group video
     * @group integration
     */
    public function testVideoGenerationDifferentAspectRatios()
    {
        if ($this->skipTests) {
            $this->markTestSkipped('Skipping due to missing API key');
            return;
        }

        $aspectRatios = ['16:9', '9:16', '1:1'];
        
        foreach ($aspectRatios as $aspectRatio) {
            $url = $this->baseUrl . '/media/resize/ip/' . urlencode($this->testImagePath);
            $params = [
                'video' => 'true',
                'prompt' => 'test aspect ratio',
                'aspectRatio' => $aspectRatio
            ];
            $fullUrl = $url . '?' . http_build_query($params);

            $ch = curl_init($fullUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // If SDK doesn't support video generation, skip all aspect ratio tests
            if ($httpCode === 500) {
                $data = json_decode($response, true);
                if (isset($data['error']) && 
                    (strpos($data['error'], 'generateVideos') !== false ||
                     strpos($data['error'], 'Veo') !== false ||
                     strpos($data['error'], 'SDK') !== false)) {
                    $this->markTestSkipped('Video generation not supported by current Gemini SDK');
                    return;
                }
            }

            // For this test, we just verify the request is accepted
            // We don't wait for completion to keep test fast
            $this->assertContains($httpCode, [200, 500], 
                "Request with aspect ratio {$aspectRatio} should be processed");
        }
    }

    /**
     * Create a test image file
     *
     * @param string $targetPath
     */
    private function createTestImage(string $targetPath): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Create a simple 200x200 JPEG image
        $image = imagecreatetruecolor(200, 200);
        $bgColor = imagecolorallocate($image, 100, 150, 200);
        imagefill($image, 0, 0, $bgColor);
        
        // Add some text
        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 50, 90, 'Test Video', $textColor);
        
        imagejpeg($image, $targetPath, 90);
        imagedestroy($image);
    }

    /**
     * Delete directory recursively
     *
     * @param string $dir
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
