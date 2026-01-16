<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Genaker\ImageAIBundle\Console\Command\GenerateVideo;
use Genaker\ImageAIBundle\Service\GeminiVideoDirectService;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Console\Cli;

/**
 * Test for GenerateVideo console command
 */
class GenerateVideoTest extends TestCase
{
    /** @var GenerateVideo */
    private $command;

    /** @var MockObject|State */
    private $stateMock;

    /** @var MockObject|GeminiVideoDirectService */
    private $videoServiceMock;

    /** @var MockObject|Filesystem */
    private $filesystemMock;

    /** @var MockObject|DirectoryList */
    private $directoryReadMock;

    /** @var MockObject|InputInterface */
    private $inputMock;

    /** @var MockObject|OutputInterface */
    private $outputMock;

    /** @var string */
    private $testImagePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Define BP constant if not defined
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }

        $this->testImagePath = BP . '/test_image.jpg';
        $this->createTestImage($this->testImagePath);

        // Create mocks
        $this->stateMock = $this->createMock(State::class);
        $this->videoServiceMock = $this->createMock(GeminiVideoDirectService::class);
        $this->filesystemMock = $this->createMock(Filesystem::class);
        $this->directoryReadMock = $this->createMock(ReadInterface::class);
        $this->inputMock = $this->createMock(InputInterface::class);
        $this->outputMock = $this->createMock(OutputInterface::class);

        // Configure filesystem mock
        $this->filesystemMock->method('getDirectoryRead')
            ->with(DirectoryList::MEDIA)
            ->willReturn($this->directoryReadMock);

        $this->directoryReadMock->method('getAbsolutePath')
            ->willReturn(BP . '/pub/media/');

        // Create command instance
        $this->command = new GenerateVideo(
            $this->stateMock,
            $this->videoServiceMock,
            $this->filesystemMock
        );
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testImagePath)) {
            @unlink($this->testImagePath);
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
     * Test command returns failure when image path is missing
     */
    public function testExecuteReturnsFailureWhenImagePathMissing()
    {
        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) {
                if ($key === 'image-path') {
                    return '';
                }
                if ($key === 'prompt') {
                    return 'test prompt';
                }
                return null;
            });

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === false
                    && isset($data['error'])
                    && strpos($data['error'], 'Image path is required') !== false;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_FAILURE, $result);
    }

    /**
     * Test command returns failure when prompt is missing
     */
    public function testExecuteReturnsFailureWhenPromptMissing()
    {
        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) {
                if ($key === 'image-path') {
                    return 'test/image.jpg';
                }
                if ($key === 'prompt') {
                    return '';
                }
                return null;
            });

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === false
                    && isset($data['error'])
                    && strpos($data['error'], 'Prompt is required') !== false;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_FAILURE, $result);
    }

    /**
     * Test command returns failure when service is not available
     */
    public function testExecuteReturnsFailureWhenServiceUnavailable()
    {
        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) {
                if ($key === 'image-path') {
                    return 'test/image.jpg';
                }
                if ($key === 'prompt') {
                    return 'test prompt';
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(false);

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === false
                    && isset($data['error'])
                    && strpos($data['error'], 'Video service is not available') !== false;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_FAILURE, $result);
    }

    /**
     * Test command returns failure when image file not found
     */
    public function testExecuteReturnsFailureWhenImageNotFound()
    {
        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) {
                if ($key === 'image-path') {
                    return 'non/existent/image.jpg';
                }
                if ($key === 'prompt') {
                    return 'test prompt';
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === false
                    && isset($data['error'])
                    && strpos($data['error'], 'Source image not found') !== false;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_FAILURE, $result);
    }

    /**
     * Test command returns JSON with cached video
     */
    public function testExecuteReturnsJsonWithCachedVideo()
    {
        $imagePath = 'test/image.jpg';
        $prompt = 'test prompt';
        $videoUrl = 'https://example.com/video.mp4';
        $videoPath = '/path/to/video.mp4';

        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) use ($imagePath, $prompt) {
                if ($key === 'image-path') {
                    return $imagePath;
                }
                if ($key === 'prompt') {
                    return $prompt;
                }
                if ($key === 'aspect-ratio') {
                    return '16:9';
                }
                if ($key === 'silent-video') {
                    return false;
                }
                if ($key === 'poll') {
                    return false;
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $cachedVideo = [
            'fromCache' => true,
            'videoUrl' => $videoUrl,
            'videoPath' => $videoPath,
            'status' => 'completed'
        ];

        $this->videoServiceMock->method('generateVideoFromImage')
            ->with(
                $this->stringContains('test_image.jpg'),
                $prompt,
                '16:9',
                false
            )
            ->willReturn($cachedVideo);

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) use ($videoUrl, $videoPath) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === true
                    && $data['videoUrl'] === $videoUrl
                    && $data['videoPath'] === $videoPath
                    && $data['cached'] === true;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_SUCCESS, $result);
    }

    /**
     * Test command returns JSON with operation name in async mode
     */
    public function testExecuteReturnsJsonWithOperationNameAsync()
    {
        $imagePath = 'test/image.jpg';
        $prompt = 'test prompt';
        $operationName = 'operations/test-operation-123';

        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) use ($imagePath, $prompt) {
                if ($key === 'image-path') {
                    return $imagePath;
                }
                if ($key === 'prompt') {
                    return $prompt;
                }
                if ($key === 'aspect-ratio') {
                    return '16:9';
                }
                if ($key === 'silent-video') {
                    return false;
                }
                if ($key === 'poll') {
                    return false; // Async mode
                }
                if ($key === 'json') {
                    return true;
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $operation = [
            'operationName' => $operationName,
            'cacheKey' => 'test_cache_key',
            'status' => 'running'
        ];

        $this->videoServiceMock->method('generateVideoFromImage')
            ->willReturn($operation);

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) use ($operationName) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === true
                    && $data['status'] === 'processing'
                    && $data['operationName'] === $operationName;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_SUCCESS, $result);
    }

    /**
     * Test command returns JSON with completed video when polling
     */
    public function testExecuteReturnsJsonWithCompletedVideoWhenPolling()
    {
        $imagePath = 'test/image.jpg';
        $prompt = 'test prompt';
        $operationName = 'operations/test-operation-123';
        $videoUrl = 'https://example.com/video.mp4';
        $videoPath = '/path/to/video.mp4';
        $embedUrl = '<video>...</video>';

        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) use ($imagePath, $prompt) {
                if ($key === 'image-path') {
                    return $imagePath;
                }
                if ($key === 'prompt') {
                    return $prompt;
                }
                if ($key === 'aspect-ratio') {
                    return '16:9';
                }
                if ($key === 'silent-video') {
                    return false;
                }
                if ($key === 'poll') {
                    return true; // Poll mode
                }
                if ($key === 'json') {
                    return true;
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $operation = [
            'operationName' => $operationName,
            'cacheKey' => 'test_cache_key'
        ];

        $pollResult = [
            'videoUrl' => $videoUrl,
            'videoPath' => $videoPath,
            'embedUrl' => $embedUrl,
            'status' => 'completed'
        ];

        $this->videoServiceMock->method('generateVideoFromImage')
            ->willReturn($operation);

        $this->videoServiceMock->method('pollVideoOperation')
            ->with(
                $operationName,
                300,
                10,
                'test_cache_key'
            )
            ->willReturn($pollResult);

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) use ($videoUrl, $videoPath) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === true
                    && $data['status'] === 'completed'
                    && $data['videoUrl'] === $videoUrl
                    && $data['videoPath'] === $videoPath;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_SUCCESS, $result);
    }

    /**
     * Test command handles exceptions and returns JSON error
     */
    public function testExecuteHandlesExceptionAndReturnsJsonError()
    {
        $imagePath = 'test/image.jpg';
        $prompt = 'test prompt';
        $errorMessage = 'Test error message';

        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) use ($imagePath, $prompt) {
                if ($key === 'image-path') {
                    return $imagePath;
                }
                if ($key === 'prompt') {
                    return $prompt;
                }
                if ($key === 'json') {
                    return true;
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $this->videoServiceMock->method('generateVideoFromImage')
            ->willThrowException(new \RuntimeException($errorMessage));

        $this->outputMock->expects($this->once())
            ->method('writeln')
            ->with($this->callback(function ($output) use ($errorMessage) {
                $data = json_decode($output, true);
                return isset($data['success']) 
                    && $data['success'] === false
                    && $data['error'] === $errorMessage;
            }));

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_FAILURE, $result);
    }

    /**
     * Test command resolves absolute image paths correctly
     */
    public function testExecuteResolvesAbsoluteImagePath()
    {
        $absolutePath = $this->testImagePath;
        $prompt = 'test prompt';

        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) use ($absolutePath, $prompt) {
                if ($key === 'image-path') {
                    return $absolutePath;
                }
                if ($key === 'prompt') {
                    return $prompt;
                }
                if ($key === 'json') {
                    return true;
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $operation = [
            'operationName' => 'operations/test-123',
            'status' => 'running'
        ];

        $this->videoServiceMock->method('generateVideoFromImage')
            ->with($absolutePath, $prompt, '16:9', false)
            ->willReturn($operation);

        $this->outputMock->expects($this->once())
            ->method('writeln');

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_SUCCESS, $result);
    }

    /**
     * Test command handles silent video option
     */
    public function testExecuteHandlesSilentVideoOption()
    {
        $imagePath = 'test/image.jpg';
        $prompt = 'test prompt';

        $this->inputMock->method('getOption')
            ->willReturnCallback(function ($key) use ($imagePath, $prompt) {
                if ($key === 'image-path') {
                    return $imagePath;
                }
                if ($key === 'prompt') {
                    return $prompt;
                }
                if ($key === 'silent-video') {
                    return true;
                }
                if ($key === 'json') {
                    return true;
                }
                return null;
            });

        $this->videoServiceMock->method('isAvailable')
            ->willReturn(true);

        $operation = [
            'operationName' => 'operations/test-123'
        ];

        $this->videoServiceMock->method('generateVideoFromImage')
            ->with(
                $this->anything(),
                $prompt,
                '16:9',
                true // silentVideo should be true
            )
            ->willReturn($operation);

        $this->outputMock->expects($this->once())
            ->method('writeln');

        $result = $this->command->execute($this->inputMock, $this->outputMock);
        $this->assertEquals(Cli::RETURN_SUCCESS, $result);
    }
}
