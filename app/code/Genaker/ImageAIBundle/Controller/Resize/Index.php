<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Controller\Resize;

use Genaker\ImageAIBundle\Api\ImageResizeServiceInterface;
use Genaker\ImageAIBundle\Service\LockManager;
use Genaker\ImageAIBundle\Service\GeminiVideoDirectService;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\HTTP\Header;
use Magento\Backend\Model\Auth\Session;
use Psr\Log\LoggerInterface;

/**
 * Image Resize Controller
 */
class Index extends Action
{
    private ImageResizeServiceInterface $imageResizeService;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private Header $httpHeader;
    private Session $authSession;
    private LockManager $lockManager;
    private GeminiVideoDirectService $videoService;

    public function __construct(
        Context $context,
        ImageResizeServiceInterface $imageResizeService,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        Header $httpHeader,
        LockManager $lockManager = null,
        Session $authSession = null,
        GeminiVideoDirectService $videoService = null
    ) {
        parent::__construct($context);
        $this->imageResizeService = $imageResizeService;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->httpHeader = $httpHeader;
        $this->videoService = $videoService ?? \Magento\Framework\App\ObjectManager::getInstance()->get(GeminiVideoDirectService::class);
        $this->lockManager = $lockManager ?? \Magento\Framework\App\ObjectManager::getInstance()->get(LockManager::class);
        $this->authSession = $authSession ?? \Magento\Framework\App\ObjectManager::getInstance()->get(Session::class);
    }

    /**
     * Execute action
     * Handles both base64 format (/media/resize/{base64}.{extension}) and query string format (/media/resize/ip/{imagePath}?params)
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        // Check if URL path contains base64 format: /media/resize/{base64}.{extension}
        $requestPath = $this->getRequest()->getPathInfo();
        // Remove leading slash and check if it matches base64 pattern
        $pathParts = explode('/', trim($requestPath, '/'));
        
        // Check if last part is base64.{extension} format
        if (!empty($pathParts)) {
            $lastPart = end($pathParts);
            // Match pattern: {base64}.{extension} where base64 encodes: ip/{imagePath}?{params}
            if (preg_match('/^([A-Za-z0-9_-]+)\.([a-z]+)$/i', $lastPart, $matches)) {
                $possibleBase64 = $matches[1];
                $extension = $matches[2];
                
                // Try to decode base64
                $base64String = strtr($possibleBase64, '-_', '+/');
                // Add padding if needed
                $padding = strlen($base64String) % 4;
                if ($padding > 0) {
                    $base64String .= str_repeat('=', 4 - $padding);
                }
                
                $decoded = @base64_decode($base64String, true);
                if ($decoded !== false) {
                    // Decoded string should be: ip/{imagePath}?{params}
                    // Parse it to extract image path and parameters
                    if (strpos($decoded, '?') !== false) {
                        list($pathPart, $queryPart) = explode('?', $decoded, 2);
                        parse_str($queryPart, $base64Params);
                    } else {
                        $pathPart = $decoded;
                        $base64Params = [];
                    }
                    
                    // Extract image path (remove 'ip/' prefix if present)
                    $actualImagePath = $pathPart;
                    if (strpos($actualImagePath, 'ip/') === 0) {
                        $actualImagePath = substr($actualImagePath, 3);
                    }
                    
                    // Trim trailing slashes (common issue when URL has trailing slash)
                    $actualImagePath = rtrim($actualImagePath, '/');
                    
                    // Ensure image path starts with /
                    if (!str_starts_with($actualImagePath, '/')) {
                        $actualImagePath = '/' . $actualImagePath;
                    }
                    
                    // Extract signature if present
                    $providedSignature = $base64Params['sig'] ?? null;
                    // Remove 'sig' from params for processing
                    unset($base64Params['sig']);
                    
                    // Ensure format matches extension
                    if (!isset($base64Params['f'])) {
                        $base64Params['f'] = $extension;
                    }
                    
                    // Sort parameters to match cache file format
                    ksort($base64Params);
                    
                    // Validate signature if enabled
                    if ($this->isSignatureEnabled()) {
                        $salt = $this->getSignatureSalt();
                        if (!empty($salt)) {
                            if (empty($providedSignature)) {
                                throw new \Magento\Framework\Exception\SecurityException(__('Missing signature parameter (sig) in base64 URL'));
                            }
                            
                            $expectedSignature = $this->generateSignature($actualImagePath, $base64Params, $salt);
                            if (!hash_equals($expectedSignature, $providedSignature)) {
                                throw new \Magento\Framework\Exception\SecurityException(__('Invalid signature in base64 URL'));
                            }
                        }
                    }
                    
                    // Use decoded parameters (signature already validated)
                    return $this->processResize($actualImagePath, $base64Params, true, $possibleBase64);
                }
            }
        }
        
        // Support both 'ip' (short) and 'imagePath' (long) parameters for backward compatibility (query string format)
        $imagePath = $this->getRequest()->getParam('ip', '');
        if (empty($imagePath)) {
            $imagePath = $this->getRequest()->getParam('imagePath', '');
        }
        
        if (empty($imagePath)) {
            throw new NotFoundException(__('Image path is required'));
        }
        
        // Query string format: check if regular URLs are enabled
        if (!$this->isRegularUrlEnabled()) {
            throw new \Magento\Framework\Exception\InputException(__('Regular URL format is disabled. Please use base64 encoded URLs only.'));
        }
        
        // Query string format: extract image path and parameters
        // Trim trailing slashes (common issue when URL has trailing slash before query params)
        $imagePath = rtrim($imagePath, '/');
        
        // Ensure image path starts with /
        if (!str_starts_with($imagePath, '/')) {
            $imagePath = '/' . $imagePath;
        }
        
        // URL decode the image path in case it contains encoded characters
        $imagePath = urldecode($imagePath);

        // Extract parameters from request
        $params = [
            'w' => $this->getRequest()->getParam('w'),
            'h' => $this->getRequest()->getParam('h'),
            'q' => $this->getRequest()->getParam('q'),
            'a' => $this->getRequest()->getParam('a'),
            'f' => $this->getRequest()->getParam('f'),
            'prompt' => $this->getRequest()->getParam('prompt'),
            'trim' => $this->getRequest()->getParam('trim'),
        ];
        
        // If format is not provided, infer from image extension
        if (empty($params['f'])) {
            $ext = pathinfo($imagePath, PATHINFO_EXTENSION);
            if ($ext) {
                $ext = strtolower($ext);
                if ($ext === 'jpg') {
                    $ext = 'jpeg';
                }
                $params['f'] = $ext;
            } else {
                $params['f'] = 'jpeg';
            }
        }
        
        // Check if video generation is requested
        $isVideo = $this->getRequest()->getParam('video') === 'true' || $this->getRequest()->getParam('video') === '1';
        
        if ($isVideo) {
            return $this->processVideoGeneration($imagePath);
        }
        
        return $this->processResize($imagePath, $params, false);
    }

    /**
     * Process video generation request
     *
     * @param string $imagePath
     * @return \Magento\Framework\Controller\ResultInterface
     */
    private function processVideoGeneration(string $imagePath)
    {
        try {
            // Get video parameters
            $prompt = $this->getRequest()->getParam('prompt', '');
            $aspectRatio = $this->getRequest()->getParam('aspectRatio', '16:9');
            $poll = $this->getRequest()->getParam('poll') === 'true' || $this->getRequest()->getParam('poll') === '1';
            $operationName = $this->getRequest()->getParam('operation');
            $silentVideo = $this->getRequest()->getParam('silentVideo') === 'true' || 
                          $this->getRequest()->getParam('silentVideo') === '1' ||
                          $this->getRequest()->getParam('silent') === 'true' ||
                          $this->getRequest()->getParam('silent') === '1';
            $returnVideo = $this->getRequest()->getParam('return') === 'video';
            // Model parameter is ignored - only Veo 3.1 is supported
            $model = null;

            if (empty($prompt) && empty($operationName)) {
                throw new NotFoundException(__('Prompt parameter is required for video generation'));
            }

            // Check if video service is available
            if (!$this->videoService->isAvailable()) {
                // Provide more helpful error message
                $errorMsg = 'Video generation service is not available. ';
                if (!$this->videoService->isAvailable()) {
                    $errorMsg .= 'Please ensure: 1) Gemini API key is configured, 2) Gemini SDK supports Veo 3.1 API (generateVideos method).';
                }
                throw new NotFoundException(__($errorMsg));
            }

            // Validate signature for video generation
            // If signature validation is enabled, signature is REQUIRED (no admin bypass)
            $allowVideoGeneration = false;
            try {
                if ($this->isSignatureEnabled()) {
                    // Signature validation enabled - signature is REQUIRED
                    $params = ['prompt' => $prompt];
                    $this->validateSignature($imagePath, $params);
                    $allowVideoGeneration = true; // Signature validated
                } else {
                    // Signature validation disabled - allow video generation
                    $allowVideoGeneration = true;
                }
            } catch (\Exception $e) {
                // If signature validation is enabled and validation failed, deny access
                if ($this->isSignatureEnabled()) {
                    $this->logger->warning('Video generation denied: signature validation failed', [
                        'imagePath' => $imagePath,
                        'error' => $e->getMessage()
                    ]);
                    throw new NotFoundException(__('Video generation requires valid signature. Signature validation is enabled.'));
                } else {
                    // Signature validation disabled - allow
                    $allowVideoGeneration = true;
                }
            }

            if (!$allowVideoGeneration) {
                throw new NotFoundException(__('Video generation is not allowed'));
            }

            // Resolve source image path
            $sourcePath = BP . '/pub/media/' . $imagePath;
            if (!file_exists($sourcePath)) {
                throw new NotFoundException(__('Source image not found: %1', $imagePath));
            }

            // If operation name provided, poll for completion
            if (!empty($operationName)) {
                $result = $this->videoService->pollVideoOperation($operationName);
                
                // If return=video, return video content directly
                if ($returnVideo && isset($result['videoPath']) && file_exists($result['videoPath'])) {
                    return $this->returnVideoContent($result['videoPath']);
                }
                
                /** @var Json $resultJson */
                $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
                $resultJson->setData([
                    'success' => true,
                    'status' => 'completed',
                    'videoUrl' => $result['videoUrl'],
                    'embedUrl' => $result['embedUrl'],
                    'videoPath' => $result['videoPath']
                ]);
                
                return $resultJson;
            }

            // Start video generation
            $operation = $this->videoService->generateVideoFromImage($sourcePath, $prompt, $aspectRatio, $silentVideo, $model);

            // Check if video was returned from cache
            if (isset($operation['fromCache']) && $operation['fromCache'] === true) {
                // If return=video, return video content directly
                if ($returnVideo && isset($operation['videoPath']) && file_exists($operation['videoPath'])) {
                    return $this->returnVideoContent($operation['videoPath']);
                }
                
                // Video was cached, return JSON
                /** @var Json $resultJson */
                $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
                $resultJson->setData([
                    'success' => true,
                    'status' => 'completed',
                    'videoUrl' => $operation['videoUrl'],
                    'embedUrl' => $operation['embedUrl'],
                    'videoPath' => $operation['videoPath'],
                    'cached' => true
                ]);
                
                return $resultJson;
            }

            // If poll=true, wait for completion (synchronous)
            if ($poll) {
                // Pass cache key if available for proper caching
                $cacheKey = $operation['cacheKey'] ?? null;
                $result = $this->videoService->pollVideoOperation($operation['operationName'], 300, 10, $cacheKey);
                
                // If return=video, return video content directly
                if ($returnVideo && isset($result['videoPath']) && file_exists($result['videoPath'])) {
                    return $this->returnVideoContent($result['videoPath']);
                }
                
                /** @var Json $resultJson */
                $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
                $resultJson->setData([
                    'success' => true,
                    'status' => 'completed',
                    'videoUrl' => $result['videoUrl'],
                    'embedUrl' => $result['embedUrl'],
                    'videoPath' => $result['videoPath']
                ]);
                
                return $resultJson;
            }

            // Return operation ID for async polling
            /** @var Json $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData([
                'success' => true,
                'status' => 'processing',
                'operationName' => $operation['operationName'],
                'message' => 'Video generation started. Poll with ?operation=' . $operation['operationName'] . '&poll=true'
            ]);
            
            return $resultJson;

        } catch (\Exception $e) {
            $this->logger->error('Video generation error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'image' => $imagePath,
                'trace' => $e->getTraceAsString()
            ]);

            /** @var Json $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            $resultJson->setHttpResponseCode(500);
            
            return $resultJson;
        }
    }

    /**
     * Process resize request
     *
     * @param string $imagePath
     * @param array $params
     * @param bool $skipSignatureValidation Skip signature validation (already validated for base64 URLs)
     * @param string|null $base64String Base64 string for cache path (if using base64 URL format)
     * @return \Magento\Framework\Controller\ResultInterface
     */
    private function processResize(string $imagePath, array $params, bool $skipSignatureValidation = false, ?string $base64String = null)
    {

        try {
            // Start timing
            $startTime = microtime(true);
            
            // Validate signature if enabled (skip for base64 URLs as they're already validated)
            $allowPrompt = false;
            try {
                if (!$skipSignatureValidation && $this->isSignatureEnabled()) {
                    $this->validateSignature($imagePath, $params);
                    // If signature is validated, allow prompts (signature provides security)
                    $allowPrompt = true;
                } elseif ($skipSignatureValidation) {
                    // Base64 URLs already validated signature, allow prompts
                    $allowPrompt = true;
                } else {
                    // If signature validation is disabled, allow prompts without admin login
                    // Otherwise, check if user is admin
                    $signatureEnabled = $this->isSignatureEnabled();
                    if (!$signatureEnabled) {
                        $allowPrompt = true; // Signature disabled = allow prompts
                    } else {
                        $allowPrompt = $this->isAdmin(); // Signature enabled but not provided = require admin
                    }
                }
            } catch (\Exception $e) {
                // Store not found or other config errors - check if signature is disabled
                if (strpos($e->getMessage(), 'store') !== false || strpos($e->getMessage(), 'Store') !== false) {
                    $this->logger->warning('Store/config error, skipping signature validation: ' . $e->getMessage());
                    // If signature validation is disabled, allow prompts even on config errors
                    try {
                        if (!$this->isSignatureEnabled()) {
                            $allowPrompt = true;
                        }
                    } catch (\Exception $e2) {
                        // Ignore errors checking signature status
                    }
                }
            }

            // Acquire lock for this image resize operation to prevent race conditions
            $lockAcquired = false;
            try {
                if ($this->lockManager->isAvailable()) {
                    $lockAcquired = $this->lockManager->acquireLock($imagePath, $params);
                    
                    // If lock cannot be acquired after retries, return original image
                    if (!$lockAcquired) {
                        return $this->returnOriginalImage($imagePath);
                    }
                }
            } catch (\Exception $e) {
                // Lock manager errors - continue without lock
                $this->logger->warning('Lock manager error: ' . $e->getMessage());
            }

            try {
                // Resize image (pass base64 string if available for cache path)
                $result = $this->imageResizeService->resizeImage($imagePath, $params, $allowPrompt, $base64String);
            } catch (\Exception $e) {
                // If resize fails due to store/config errors, try to return original image
                if (stripos($e->getMessage(), 'store') !== false) {
                    $this->logger->warning('Store error during resize, attempting to return original: ' . $e->getMessage());
                    try {
                        return $this->returnOriginalImage($imagePath);
                    } catch (\Exception $e2) {
                        // If returning original also fails, re-throw original exception
                        throw $e;
                    }
                }
                throw $e;
            } finally {
                // Always release lock if acquired
                if ($lockAcquired) {
                    try {
                        $this->lockManager->releaseLock($imagePath, $params);
                    } catch (\Exception $e) {
                        // Ignore lock release errors
                    }
                }
            }
            
            // Calculate duration
            $duration = microtime(true) - $startTime;
            $durationMs = round($duration * 1000, 2);

            // Return image file
            /** @var Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setHeader('Content-Type', $result->getMimeType());
            $resultRaw->setHeader('X-Cache-Status', $result->isFromCache() ? 'HIT' : 'MISS');
            $resultRaw->setHeader('X-Generation-Duration', (string)$durationMs . 'ms');
            $resultRaw->setHeader('Cache-Control', 'public, max-age=31536000');
            
            $filePath = $result->getFilePath();
            // Verify file exists and is not a directory
            if (!file_exists($filePath) || is_dir($filePath)) {
                throw new NotFoundException(__('Resized image file not found: %1', $filePath));
            }
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                throw new NotFoundException(__('Failed to read resized image file: %1', $filePath));
            }
            $resultRaw->setContents($fileContent);

            return $resultRaw;
        } catch (\Exception $e) {
            $errorDetails = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            if ($e->getPrevious()) {
                $errorDetails['previous'] = $e->getPrevious()->getMessage();
            }
            $this->logger->error('Image resize error: ' . $e->getMessage(), $errorDetails);
            
            // Return proper 404 response instead of throwing exception
            // This prevents Magento's Media application from trying to serve a placeholder
            /** @var Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setHttpResponseCode(404);
            $resultRaw->setHeader('Content-Type', 'text/plain');
            $resultRaw->setContents('Image not found or could not be processed: ' . $e->getMessage());
            return $resultRaw;
        }
    }

    /**
     * Check if signature validation is enabled
     *
     * @return bool
     */
    private function isSignatureEnabled(): bool
    {
        try {
            return (bool)$this->scopeConfig->getValue(
                'genaker_imageaibundle/general/signature_enabled',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
        } catch (\Exception $e) {
            // Store not found or other errors, use default scope or return false
            try {
                return (bool)$this->scopeConfig->getValue(
                    'genaker_imageaibundle/general/signature_enabled',
                    \Magento\Store\Model\ScopeInterface::SCOPE_DEFAULT
                );
            } catch (\Exception $e2) {
                return false; // Default to disabled if config unavailable
            }
        }
    }

    /**
     * Validate signature
     *
     * @param string $imagePath
     * @param array $params
     * @return void
     * @throws \Magento\Framework\Exception\SecurityException
     */
    private function validateSignature(string $imagePath, array $params): void
    {
        $providedSignature = $this->getRequest()->getParam('sig');
        if (empty($providedSignature)) {
            throw new \Magento\Framework\Exception\SecurityException(__('Missing signature parameter'));
        }

        try {
            $salt = $this->scopeConfig->getValue(
                'genaker_imageaibundle/general/signature_salt',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
        } catch (\Exception $e) {
            // Store not found or other errors, use default scope
            try {
                $salt = $this->scopeConfig->getValue(
                    'genaker_imageaibundle/general/signature_salt',
                    \Magento\Store\Model\ScopeInterface::SCOPE_DEFAULT
                );
            } catch (\Exception $e2) {
                $salt = ''; // Default to empty salt if config unavailable
            }
        }

        $expectedSignature = $this->generateSignature($imagePath, $params, $salt);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            throw new \Magento\Framework\Exception\SecurityException(__('Invalid signature'));
        }
    }

    /**
     * Generate signature
     *
     * @param string $imagePath
     * @param array $params
     * @param string $salt
     * @return string
     */
    private function generateSignature(string $imagePath, array $params, string $salt): string
    {
        $filteredParams = array_filter($params, fn($value) => $value !== null);
        ksort($filteredParams);
        $paramString = http_build_query($filteredParams);
        $signatureString = $imagePath . '|' . $paramString . '|' . $salt;
        return md5($signatureString);
    }

    /**
     * Check if current user is admin
     *
     * @return bool
     */
    private function isAdmin(): bool
    {
        try {
            if ($this->authSession && $this->authSession->isLoggedIn()) {
                $user = $this->authSession->getUser();
                return $user && $user->getId();
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
        return false;
    }

    /**
     * Check if regular URL format is enabled
     *
     * @return bool
     */
    private function isRegularUrlEnabled(): bool
    {
        try {
            return (bool)$this->scopeConfig->getValue(
                'genaker_imageaibundle/general/regular_url_enabled',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
        } catch (\Exception $e) {
            // Store not found or other errors, use default scope or return default value
            try {
                return (bool)$this->scopeConfig->getValue(
                    'genaker_imageaibundle/general/regular_url_enabled',
                    \Magento\Store\Model\ScopeInterface::SCOPE_DEFAULT
                );
            } catch (\Exception $e2) {
                return true; // Default to enabled
            }
        }
    }

    /**
     * Get signature salt
     *
     * @return string
     */
    private function getSignatureSalt(): string
    {
        try {
            $salt = $this->scopeConfig->getValue(
                'genaker_imageaibundle/general/signature_salt',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
        } catch (\Exception $e) {
            // Store not found or other errors, use default scope
            try {
                $salt = $this->scopeConfig->getValue(
                    'genaker_imageaibundle/general/signature_salt',
                    \Magento\Store\Model\ScopeInterface::SCOPE_DEFAULT
                );
            } catch (\Exception $e2) {
                $salt = ''; // Default to empty salt if config unavailable
            }
        }

        // Decrypt if encrypted
        if ($salt && class_exists('\Magento\Framework\Encryption\EncryptorInterface')) {
            $encryptor = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Encryption\EncryptorInterface::class);
            $salt = $encryptor->decrypt($salt);
        }

        return $salt ?: '';
    }

    /**
     * Check if decoded string is a valid query string
     *
     * @param string $string
     * @return bool
     */
    private function isValidQueryString(string $string): bool
    {
        // Check if it contains at least one key=value pair
        return preg_match('/^[a-zA-Z0-9_\.\[\]-]+=[^&]*(&[a-zA-Z0-9_\.\[\]-]+=[^&]*)*$/', $string) === 1;
    }

    /**
     * Return video content directly with proper headers
     *
     * @param string $videoPath Full path to video file
     * @return Raw
     * @throws NotFoundException
     */
    private function returnVideoContent(string $videoPath): Raw
    {
        // Validate video file exists and is readable
        if (!file_exists($videoPath) || !is_readable($videoPath)) {
            throw new NotFoundException(__('Video file not found: %1', $videoPath));
        }

        // Read video content
        $videoContent = file_get_contents($videoPath);
        if ($videoContent === false) {
            throw new NotFoundException(__('Failed to read video file: %1', $videoPath));
        }

        // Create response with video content
        /** @var Raw $resultRaw */
        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setHeader('Content-Type', 'video/mp4');
        $resultRaw->setHeader('Content-Length', (string)strlen($videoContent));
        $resultRaw->setHeader('Accept-Ranges', 'bytes');
        $resultRaw->setHeader('Cache-Control', 'public, max-age=31536000');
        $resultRaw->setHeader('X-Content-Type-Options', 'nosniff');
        
        $resultRaw->setContents($videoContent);

        return $resultRaw;
    }

    /**
     * Return original image with no-cache headers when lock cannot be acquired
     *
     * @param string $imagePath
     * @return Raw
     * @throws NotFoundException
     */
    private function returnOriginalImage(string $imagePath): Raw
    {
        try {
            $sourcePath = $this->imageResizeService->getOriginalImagePath($imagePath);
        } catch (\Exception $e) {
            throw new NotFoundException(__('Image not found: %1', $imagePath));
        }

        // Validate source exists and is readable
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            throw new NotFoundException(__('Image not found: %1', $imagePath));
        }

        // Get MIME type
        $mimeType = mime_content_type($sourcePath) ?: 'image/jpeg';

        // Create response with original image
        /** @var Raw $resultRaw */
        $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultRaw->setHeader('Content-Type', $mimeType);
        
        // Set no-cache headers to prevent caching of original image
        $resultRaw->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $resultRaw->setHeader('Pragma', 'no-cache');
        $resultRaw->setHeader('Expires', '0');
        
        // Add header to indicate this is the original image (not resized)
        $resultRaw->setHeader('X-Image-Original', 'true');
        $resultRaw->setHeader('X-Lock-Timeout', 'true');
        
        $fileContent = file_get_contents($sourcePath);
        $resultRaw->setContents($fileContent);

        return $resultRaw;
    }
}

