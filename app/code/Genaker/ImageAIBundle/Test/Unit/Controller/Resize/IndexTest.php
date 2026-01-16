<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Test\Unit\Controller\Resize;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Genaker\ImageAIBundle\Controller\Resize\Index;
use Genaker\ImageAIBundle\Api\ImageResizeServiceInterface;
use Genaker\ImageAIBundle\Model\ResizeResult;
use Genaker\ImageAIBundle\Service\LockManager;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Header;
use Magento\Backend\Model\Auth\Session;
use Psr\Log\LoggerInterface;

/**
 * Test for Resize Index Controller
 */
class IndexTest extends TestCase
{
    /** @var Index */
    private $controller;

    /** @var MockObject|Context */
    private $contextMock;

    /** @var MockObject|ImageResizeServiceInterface */
    private $imageResizeServiceMock;

    /** @var MockObject|ScopeConfigInterface */
    private $scopeConfigMock;

    /** @var MockObject|LoggerInterface */
    private $loggerMock;

    /** @var MockObject|Header */
    private $httpHeaderMock;

    /** @var MockObject|LockManager */
    private $lockManagerMock;

    /** @var MockObject|Session */
    private $authSessionMock;

    /** @var MockObject|RequestInterface */
    private $requestMock;

    /** @var MockObject|ResultFactory */
    private $resultFactoryMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->contextMock = $this->createMock(Context::class);
        $this->imageResizeServiceMock = $this->createMock(ImageResizeServiceInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->httpHeaderMock = $this->createMock(Header::class);
        $this->lockManagerMock = $this->createMock(LockManager::class);
        $this->authSessionMock = $this->createMock(Session::class);
        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->resultFactoryMock = $this->createMock(ResultFactory::class);

        // Configure context mock
        $this->contextMock->method('getRequest')
            ->willReturn($this->requestMock);

        // Configure scope config mock
        $this->scopeConfigMock->method('getValue')
            ->willReturnCallback(function ($path) {
                $configMap = [
                    'genaker_imageaibundle/general/signature_enabled' => false,
                    'genaker_imageaibundle/general/regular_url_enabled' => true,
                ];
                return $configMap[$path] ?? null;
            });

        // Configure lock manager mock
        $this->lockManagerMock->method('isAvailable')
            ->willReturn(true);
        $this->lockManagerMock->method('acquireLock')
            ->willReturn(true);
        $this->lockManagerMock->method('releaseLock')
            ->willReturnCallback(function () {
                // void method, do nothing
            });

        // Configure auth session mock
        $this->authSessionMock->method('isLoggedIn')
            ->willReturn(false);

        // Create controller
        $this->controller = new Index(
            $this->contextMock,
            $this->imageResizeServiceMock,
            $this->scopeConfigMock,
            $this->loggerMock,
            $this->httpHeaderMock,
            $this->lockManagerMock,
            $this->authSessionMock
        );

        // Set result factory using reflection
        $reflection = new \ReflectionClass($this->controller);
        $property = $reflection->getProperty('resultFactory');
        $property->setAccessible(true);
        $property->setValue($this->controller, $this->resultFactoryMock);
    }

    /**
     * Test resize image with path URL parameters
     */
    public function testResizeImageWithPathUrl()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $width = 300;
        $height = 300;
        $format = 'webp';
        $quality = 85;

        // Configure request mock
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($imagePath, $width, $height, $format, $quality) {
                $params = [
                    'imagePath' => $imagePath,
                    'w' => $width,
                    'h' => $height,
                    'f' => $format,
                    'q' => $quality,
                ];
                return $params[$key] ?? null;
            });

        // Create test result
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.webp';
        $this->createTestImage($testImagePath);
        
        // Ensure file exists and is readable
        $this->assertFileExists($testImagePath, 'Test image file must exist');
        $fileSize = filesize($testImagePath);
        $this->assertGreaterThan(0, $fileSize, 'Test image file must have content');
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/webp',
            $fileSize,
            false,
            'test_cache_key'
        );

        // Configure image resize service mock - ensure it doesn't throw exceptions
        $this->imageResizeServiceMock->method('imageExists')
            ->willReturn(true);
        
        $this->imageResizeServiceMock->method('getOriginalImagePath')
            ->willReturn(sys_get_temp_dir() . '/test_original.jpg');
        
        $this->imageResizeServiceMock->method('resizeImage')
            ->willReturn($resizeResult);

        // Configure result factory mock
        $resultRawMock = $this->createMock(Raw::class);
        $resultRawMock->expects($this->atLeastOnce())
            ->method('setHeader')
            ->willReturnSelf();
        
        $resultRawMock->expects($this->once())
            ->method('setContents')
            ->with($this->callback(function ($content) use ($testImagePath) {
                // Content should be the file contents
                return is_string($content) && strlen($content) > 0;
            }))
            ->willReturnSelf();

        $this->resultFactoryMock->method('create')
            ->with(ResultFactory::TYPE_RAW)
            ->willReturn($resultRawMock);

        // Execute controller
        $result = $this->controller->execute();

        // Assertions
        $this->assertInstanceOf(Raw::class, $result);

        // Cleanup
        if (file_exists($testImagePath)) {
            @unlink($testImagePath);
        }
    }

    /**
     * Test resize image with width only
     */
    public function testResizeImageWithWidthOnly()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $width = 500;
        $format = 'jpg';

        // Configure request mock
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($imagePath, $width, $format) {
                $params = [
                    'imagePath' => $imagePath,
                    'w' => $width,
                    'f' => $format,
                ];
                return $params[$key] ?? null;
            });

        // Create test result
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.jpg';
        $this->createTestImage($testImagePath);
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/jpeg',
            filesize($testImagePath),
            false,
            'test_cache_key'
        );

        // Configure image resize service mock
        $this->imageResizeServiceMock->method('imageExists')
            ->willReturn(true);
        
        $this->imageResizeServiceMock->method('getOriginalImagePath')
            ->willReturn(sys_get_temp_dir() . '/test_original.jpg');
        
        $this->imageResizeServiceMock->method('resizeImage')
            ->willReturn($resizeResult);

        // Configure result factory mock
        $resultRawMock = $this->createMock(Raw::class);
        $resultRawMock->method('setHeader')
            ->willReturnSelf();
        $resultRawMock->method('setContents')
            ->willReturnSelf();

        $this->resultFactoryMock->method('create')
            ->willReturn($resultRawMock);

        // Execute controller
        $result = $this->controller->execute();

        // Assertions
        $this->assertInstanceOf(Raw::class, $result);

        // Cleanup
        if (file_exists($testImagePath)) {
            @unlink($testImagePath);
        }
    }

    /**
     * Test resize image with path starting with slash
     */
    public function testResizeImageWithLeadingSlash()
    {
        $imagePath = '/catalog/product/test-image.jpg';
        $width = 300;
        $height = 300;
        $format = 'png';

        // Configure request mock
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($imagePath, $width, $height, $format) {
                $params = [
                    'imagePath' => $imagePath,
                    'w' => $width,
                    'h' => $height,
                    'f' => $format,
                ];
                return $params[$key] ?? null;
            });

        // Create test result
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.png';
        $this->createTestImage($testImagePath);
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/png',
            filesize($testImagePath),
            true, // From cache
            'test_cache_key'
        );

        // Configure image resize service mock
        // When signature validation is disabled, allowPrompt should be true
        $this->imageResizeServiceMock->method('resizeImage')
            ->with(
                $imagePath,
                $this->callback(function ($params) use ($width, $height, $format) {
                    return $params['w'] === $width 
                        && $params['h'] === $height 
                        && $params['f'] === $format;
                }),
                true  // allowPrompt = true when signature validation is disabled
            )
            ->willReturn($resizeResult);

        // Configure result factory mock
        $resultRawMock = $this->createMock(Raw::class);
        $resultRawMock->method('setHeader')
            ->willReturnSelf();
        $resultRawMock->method('setContents')
            ->willReturnSelf();

        $this->resultFactoryMock->method('create')
            ->willReturn($resultRawMock);

        // Execute controller
        $result = $this->controller->execute();

        // Assertions
        $this->assertInstanceOf(Raw::class, $result);

        // Cleanup
        if (file_exists($testImagePath)) {
            @unlink($testImagePath);
        }
    }

    /**
     * Test resize image with format inferred from extension
     */
    public function testResizeImageWithInferredFormat()
    {
        $imagePath = 'catalog/product/test-image.png';
        $width = 400;
        $height = 400;

        // Configure request mock
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($imagePath, $width, $height) {
                $params = [
                    'imagePath' => $imagePath,
                    'w' => $width,
                    'h' => $height,
                    // No 'f' parameter - should be inferred from extension
                ];
                return $params[$key] ?? null;
            });

        // Create test result - ensure file exists and is readable
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.png';
        $this->createTestImage($testImagePath);
        
        // Verify file was created
        $this->assertFileExists($testImagePath, 'Test resized image must exist');
        $fileSize = filesize($testImagePath);
        $this->assertGreaterThan(0, $fileSize, 'Test resized image must have content');
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/png',
            $fileSize,
            false,
            'test_cache_key'
        );

        // Create original image file that the service might check
        $originalImagePath = sys_get_temp_dir() . '/test_original_' . uniqid() . '.png';
        $this->createTestImage($originalImagePath);
        
        // Configure image resize service mock
        $this->imageResizeServiceMock->method('imageExists')
            ->willReturn(true);
        
        $this->imageResizeServiceMock->method('getOriginalImagePath')
            ->willReturn($originalImagePath);
        
        $this->imageResizeServiceMock->method('resizeImage')
            ->willReturn($resizeResult);

        // Configure result factory mock
        $resultRawMock = $this->createMock(Raw::class);
        $resultRawMock->method('setHeader')
            ->willReturnSelf();
        $resultRawMock->method('setContents')
            ->willReturnSelf();

        $this->resultFactoryMock->method('create')
            ->willReturn($resultRawMock);

        // Execute controller
        $result = $this->controller->execute();

        // Assertions
        $this->assertInstanceOf(Raw::class, $result);

        // Cleanup
        if (file_exists($testImagePath)) {
            @unlink($testImagePath);
        }
        if (isset($originalImagePath) && file_exists($originalImagePath)) {
            @unlink($originalImagePath);
        }
    }

    /**
     * Test resize image with missing image path
     */
    public function testResizeImageMissingPath()
    {
        $this->expectException(\Magento\Framework\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Image path is required');

        // Configure request mock to return empty imagePath
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) {
                return $key === 'imagePath' ? '' : null;
            });

        // Execute controller
        $this->controller->execute();
    }

    /**
     * Create a test image file
     */
    private function createTestImage(string $path): void
    {
        $image = imagecreatetruecolor(100, 100);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $bgColor);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        switch ($extension) {
            case 'png':
                imagepng($image, $path);
                break;
            case 'webp':
                imagewebp($image, $path);
                break;
            case 'gif':
                imagegif($image, $path);
                break;
            default:
                imagejpeg($image, $path, 90);
        }

        imagedestroy($image);
    }
}
