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
use Genaker\ImageAIBundle\Controller\Resize\Ip;
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
 * Test for Resize Ip Controller (Short URL format)
 */
class IpTest extends TestCase
{
    /** @var Ip */
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
        $this->contextMock->method('getResultFactory')
            ->willReturn($this->resultFactoryMock);

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

        // Create controller
        $this->controller = new Ip(
            $this->contextMock,
            $this->imageResizeServiceMock,
            $this->scopeConfigMock,
            $this->loggerMock,
            $this->httpHeaderMock,
            $this->lockManagerMock,
            $this->authSessionMock
        );
    }

    /**
     * Test resize image with 'ip' parameter (short format)
     */
    public function testResizeImageWithIpParameter()
    {
        $imagePath = 'catalog/product/test-image.jpg';
        $width = 300;
        $height = 300;
        $format = 'jpeg';
        $quality = 85;

        // Configure request mock to use 'ip' parameter
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($imagePath, $width, $height, $format, $quality) {
                $params = [
                    'ip' => $imagePath, // Use 'ip' instead of 'imagePath'
                    'w' => $width,
                    'h' => $height,
                    'f' => $format,
                    'q' => $quality,
                ];
                return $params[$key] ?? null;
            });

        // Create test result file - must exist before controller reads it
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.jpg';
        $this->createTestImage($testImagePath);
        
        // Ensure file exists and is readable
        $this->assertFileExists($testImagePath, 'Test image file must exist');
        $fileSize = filesize($testImagePath);
        $this->assertGreaterThan(0, $fileSize, 'Test image file must have content');
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/jpeg',
            $fileSize,
            false,
            'test_cache_key'
        );

        // Configure image resize service mock
        $this->imageResizeServiceMock->method('imageExists')
            ->willReturn(true);
        
        $this->imageResizeServiceMock->method('getOriginalImagePath')
            ->willReturn(sys_get_temp_dir() . '/test_original.jpg');
        
        // When signature validation is disabled, allowPrompt should be true
        $this->imageResizeServiceMock->method('resizeImage')
            ->with(
                $this->equalTo('/' . $imagePath),
                $this->callback(function ($params) use ($width, $height, $format, $quality) {
                    return $params['w'] == $width
                        && $params['h'] == $height
                        && $params['f'] == $format
                        && $params['q'] == $quality;
                }),
                $this->equalTo(true)  // allowPrompt = true when signature validation is disabled
            )
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
     * Test that 'ip' parameter takes precedence over 'imagePath'
     */
    public function testIpParameterTakesPrecedence()
    {
        $ipPath = 'catalog/product/ip-image.jpg';
        $imagePath = 'catalog/product/imagePath-image.jpg';

        // Configure request mock to return both parameters
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($ipPath, $imagePath) {
                $params = [
                    'ip' => $ipPath,
                    'imagePath' => $imagePath,
                    'w' => 300,
                    'h' => 300,
                    'f' => 'jpeg',
                ];
                return $params[$key] ?? null;
            });

        // Create test result file
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.jpg';
        $this->createTestImage($testImagePath);
        
        $this->assertFileExists($testImagePath, 'Test image file must exist');
        $fileSize = filesize($testImagePath);
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/jpeg',
            $fileSize,
            false,
            'test_cache_key'
        );

        $this->imageResizeServiceMock->method('imageExists')
            ->willReturn(true);
        $this->imageResizeServiceMock->method('getOriginalImagePath')
            ->willReturn(sys_get_temp_dir() . '/test_original.jpg');
        $this->imageResizeServiceMock->method('resizeImage')
            ->with(
                $this->equalTo('/' . $ipPath), // Should use 'ip' path, not 'imagePath'
                $this->anything(),
                $this->anything()
            )
            ->willReturn($resizeResult);

        $resultRawMock = $this->createMock(Raw::class);
        $resultRawMock->expects($this->atLeastOnce())
            ->method('setHeader')
            ->willReturnSelf();
        $resultRawMock->expects($this->once())
            ->method('setContents')
            ->willReturnSelf();
        $this->resultFactoryMock->method('create')
            ->willReturn($resultRawMock);

        $result = $this->controller->execute();

        $this->assertInstanceOf(Raw::class, $result);
        if (file_exists($testImagePath)) {
            @unlink($testImagePath);
        }
    }

    /**
     * Test fallback to 'imagePath' when 'ip' is empty
     */
    public function testFallbackToImagePathWhenIpEmpty()
    {
        $imagePath = 'catalog/product/fallback-image.jpg';

        // Configure request mock - 'ip' is empty, 'imagePath' has value
        $this->requestMock->method('getParam')
            ->willReturnCallback(function ($key) use ($imagePath) {
                $params = [
                    'ip' => '', // Empty
                    'imagePath' => $imagePath, // Has value
                    'w' => 300,
                    'h' => 300,
                    'f' => 'jpeg',
                ];
                return $params[$key] ?? null;
            });

        // Create test result file
        $testImagePath = sys_get_temp_dir() . '/test_resized_' . uniqid() . '.jpg';
        $this->createTestImage($testImagePath);
        
        $this->assertFileExists($testImagePath, 'Test image file must exist');
        $fileSize = filesize($testImagePath);
        
        $resizeResult = new ResizeResult(
            $testImagePath,
            'image/jpeg',
            $fileSize,
            false,
            'test_cache_key'
        );

        $this->imageResizeServiceMock->method('imageExists')
            ->willReturn(true);
        $this->imageResizeServiceMock->method('getOriginalImagePath')
            ->willReturn(sys_get_temp_dir() . '/test_original.jpg');
        $this->imageResizeServiceMock->method('resizeImage')
            ->with(
                $this->equalTo('/' . $imagePath), // Should use 'imagePath' as fallback
                $this->anything(),
                $this->anything()
            )
            ->willReturn($resizeResult);

        $resultRawMock = $this->createMock(Raw::class);
        $resultRawMock->expects($this->atLeastOnce())
            ->method('setHeader')
            ->willReturnSelf();
        $resultRawMock->expects($this->once())
            ->method('setContents')
            ->willReturnSelf();
        $this->resultFactoryMock->method('create')
            ->willReturn($resultRawMock);

        $result = $this->controller->execute();

        $this->assertInstanceOf(Raw::class, $result);
        if (file_exists($testImagePath)) {
            @unlink($testImagePath);
        }
    }

    /**
     * Create a test image file
     *
     * @param string $path
     * @return void
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
                if (function_exists('imagewebp')) {
                    imagewebp($image, $path, 90);
                } else {
                    imagejpeg($image, $path, 90);
                }
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
