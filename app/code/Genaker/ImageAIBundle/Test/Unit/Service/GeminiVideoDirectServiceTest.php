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
use Genaker\ImageAIBundle\Service\GeminiVideoDirectService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Filesystem\Driver\File;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for GeminiVideoDirectService
 * Tests model selection (Veo vs Imagen) via environment variables
 */
class GeminiVideoDirectServiceTest extends TestCase
{
    private $scopeConfigMock;
    private $httpClientMock;
    private $loggerMock;
    private $filesystemMock;
    private $originalEnvVars = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Define BP constant if not already defined (for unit tests)
        if (!defined('BP')) {
            define('BP', sys_get_temp_dir());
        }

        // Save original environment variables
        $this->originalEnvVars = [
            'VIDEO_MODEL' => getenv('VIDEO_MODEL') ?: false,
            'GEMINI_VIDEO_MODEL' => getenv('GEMINI_VIDEO_MODEL') ?: false,
            'VERTEX_AI_ENDPOINT' => getenv('VERTEX_AI_ENDPOINT') ?: false,
            'GEMINI_API_KEY' => getenv('GEMINI_API_KEY') ?: false,
        ];

        // Create mocks
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->httpClientMock = $this->createMock(Curl::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->filesystemMock = $this->createMock(File::class);
    }

    protected function tearDown(): void
    {
        // Restore original environment variables
        foreach ($this->originalEnvVars as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }

        parent::tearDown();
    }

    /**
     * Test that default model is Veo 3.1 when no environment variable is set
     */
    public function testDefaultModelIsVeo()
    {
        // Clear environment variables
        putenv('VIDEO_MODEL');
        putenv('GEMINI_VIDEO_MODEL');
        putenv('VERTEX_AI_ENDPOINT');
        putenv('GEMINI_API_KEY=test-key');

        $this->scopeConfigMock->method('getValue')
            ->willReturn('');

        $service = new GeminiVideoDirectService(
            $this->scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        // Use reflection to access private properties
        $reflection = new ReflectionClass($service);
        $modelTypeProperty = $reflection->getProperty('modelType');
        $modelTypeProperty->setAccessible(true);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);

        $this->assertEquals('veo', $modelTypeProperty->getValue($service));
        $this->assertEquals('veo-3.1-generate-preview', $modelProperty->getValue($service));
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta', $baseUrlProperty->getValue($service));
    }


    /**
     * Test that Veo model uses correct endpoint format
     */
    public function testVeoEndpointFormat()
    {
        // Clear environment variables to use default Veo
        putenv('VIDEO_MODEL');
        putenv('VERTEX_AI_ENDPOINT');
        putenv('GEMINI_API_KEY=test-key');

        $this->scopeConfigMock->method('getValue')
            ->willReturn('');

        $service = new GeminiVideoDirectService(
            $this->scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        // Use reflection to access private properties
        $reflection = new ReflectionClass($service);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);
        $modelTypeProperty = $reflection->getProperty('modelType');
        $modelTypeProperty->setAccessible(true);

        $baseUrl = $baseUrlProperty->getValue($service);
        $model = $modelProperty->getValue($service);
        $modelType = $modelTypeProperty->getValue($service);

        // Verify Veo model is selected
        $this->assertEquals('veo', $modelType);
        $this->assertEquals('veo-3.1-generate-preview', $model);
        
        // Verify endpoint contains Google AI Studio URL
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta', $baseUrl);
        
        // Verify endpoint format would be :predictLongRunning
        $expectedEndpoint = "{$baseUrl}/models/{$model}:predictLongRunning";
        $this->assertStringContainsString('predictLongRunning', $expectedEndpoint);
    }


    /**
     * Test initializeModel with explicit model=veo parameter
     */
    public function testInitializeModelWithExplicitVeoParameter()
    {
        // Set environment variables to imagen (should be overridden by explicit parameter)
        putenv('VIDEO_MODEL=imagen');
        putenv('VERTEX_AI_ENDPOINT=https://us-central1-aiplatform.googleapis.com/v1/projects/test-project/locations/us-central1/publishers/google/models/imagegeneration@006');
        putenv('GEMINI_API_KEY=test-key');

        $this->scopeConfigMock->method('getValue')
            ->willReturn('');

        $service = new GeminiVideoDirectService(
            $this->scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        // Use reflection to call private initializeModel method
        $reflection = new ReflectionClass($service);
        $initializeModelMethod = $reflection->getMethod('initializeModel');
        $initializeModelMethod->setAccessible(true);

        // Call initializeModel with explicit 'veo' parameter (should override environment variable)
        $initializeModelMethod->invoke($service, 'veo');

        // Verify properties
        $modelTypeProperty = $reflection->getProperty('modelType');
        $modelTypeProperty->setAccessible(true);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);

        // Should use Veo despite environment variable being set to imagen
        $this->assertEquals('veo', $modelTypeProperty->getValue($service));
        $this->assertEquals('veo-3.1-generate-preview', $modelProperty->getValue($service));
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta', $baseUrlProperty->getValue($service));
    }

    /**
     * Test initializeModel always uses Veo regardless of parameter
     */
    public function testInitializeModelAlwaysUsesVeo()
    {
        // Set environment variables (should be ignored)
        putenv('VIDEO_MODEL=imagen');
        putenv('GEMINI_API_KEY=test-key');

        $this->scopeConfigMock->method('getValue')
            ->willReturn('');

        $service = new GeminiVideoDirectService(
            $this->scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        // Use reflection to call private initializeModel method
        $reflection = new ReflectionClass($service);
        $initializeModelMethod = $reflection->getMethod('initializeModel');
        $initializeModelMethod->setAccessible(true);

        // Call initializeModel with various parameters - all should result in Veo
        $initializeModelMethod->invoke($service, 'imagen');
        
        // Verify properties
        $modelTypeProperty = $reflection->getProperty('modelType');
        $modelTypeProperty->setAccessible(true);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);

        // Should always use Veo regardless of parameter
        $this->assertEquals('veo', $modelTypeProperty->getValue($service));
        $this->assertEquals('veo-3.1-generate-preview', $modelProperty->getValue($service));
        $this->assertEquals('https://generativelanguage.googleapis.com/v1beta', $baseUrlProperty->getValue($service));
    }

    /**
     * Test initializeModel always uses Veo regardless of parameter value
     */
    public function testInitializeModelIgnoresParameter()
    {
        // Clear environment variables
        putenv('VIDEO_MODEL');
        putenv('GEMINI_VIDEO_MODEL');
        putenv('GEMINI_API_KEY=test-key');

        $this->scopeConfigMock->method('getValue')
            ->willReturn('');

        $service = new GeminiVideoDirectService(
            $this->scopeConfigMock,
            $this->httpClientMock,
            $this->loggerMock,
            $this->filesystemMock
        );

        // Use reflection to call private initializeModel method
        $reflection = new ReflectionClass($service);
        $initializeModelMethod = $reflection->getMethod('initializeModel');
        $initializeModelMethod->setAccessible(true);

        // Call initializeModel with various parameters - all should result in Veo
        $initializeModelMethod->invoke($service, 'veo');
        
        // Verify properties
        $modelTypeProperty = $reflection->getProperty('modelType');
        $modelTypeProperty->setAccessible(true);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        // Should always use Veo
        $this->assertEquals('veo', $modelTypeProperty->getValue($service));
        $this->assertEquals('veo-3.1-generate-preview', $modelProperty->getValue($service));
    }
}
