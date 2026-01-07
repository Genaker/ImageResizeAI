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
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Raw;
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

    public function __construct(
        Context $context,
        ImageResizeServiceInterface $imageResizeService,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        Header $httpHeader,
        LockManager $lockManager = null,
        Session $authSession = null
    ) {
        parent::__construct($context);
        $this->imageResizeService = $imageResizeService;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->httpHeader = $httpHeader;
        $this->lockManager = $lockManager ?? \Magento\Framework\App\ObjectManager::getInstance()->get(LockManager::class);
        $this->authSession = $authSession ?? \Magento\Framework\App\ObjectManager::getInstance()->get(Session::class);
    }

    /**
     * Execute action
     * Handles both base64 format ({base64}.{extension}) and query string format ({imagePath}?params)
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $imagePath = $this->getRequest()->getParam('imagePath', '');
        
        if (empty($imagePath)) {
            throw new NotFoundException(__('Image path is required'));
        }

        // Check if imagePath is base64 encoded parameters (format: {base64}.{extension})
        if (preg_match('/^([A-Za-z0-9_-]+)\.([a-z]+)$/i', $imagePath, $matches)) {
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
            if ($decoded !== false && $this->isValidQueryString($decoded)) {
                // This is base64 encoded parameters
                parse_str($decoded, $base64Params);
                // Extract image path from parameters
                $actualImagePath = $base64Params['image'] ?? '/media/default.jpg';
                // Extract signature if present
                $providedSignature = $base64Params['sig'] ?? null;
                // Remove 'image' and 'sig' from params for processing
                unset($base64Params['image']);
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
                return $this->processResize($actualImagePath, $base64Params, true);
            }
        }
        
        // Query string format: check if regular URLs are enabled
        if (!$this->isRegularUrlEnabled()) {
            throw new \Magento\Framework\Exception\InputException(__('Regular URL format is disabled. Please use base64 encoded URLs only.'));
        }
        
        // Query string format: extract image path and parameters
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
        
        return $this->processResize($imagePath, $params, false);
    }

    /**
     * Process resize request
     *
     * @param string $imagePath
     * @param array $params
     * @param bool $skipSignatureValidation Skip signature validation (already validated for base64 URLs)
     * @return \Magento\Framework\Controller\ResultInterface
     */
    private function processResize(string $imagePath, array $params, bool $skipSignatureValidation = false)
    {

        try {
            // Start timing
            $startTime = microtime(true);
            
            // Validate signature if enabled (skip for base64 URLs as they're already validated)
            $allowPrompt = false;
            if (!$skipSignatureValidation && $this->isSignatureEnabled()) {
                $this->validateSignature($imagePath, $params);
                // If signature is validated, allow prompts (signature provides security)
                $allowPrompt = true;
            } elseif ($skipSignatureValidation) {
                // Base64 URLs already validated signature, allow prompts
                $allowPrompt = true;
            } else {
                // Check if user is admin (for cases without signature)
                $allowPrompt = $this->isAdmin();
            }

            // Acquire lock for this image resize operation to prevent race conditions
            $lockAcquired = false;
            if ($this->lockManager->isAvailable()) {
                $lockAcquired = $this->lockManager->acquireLock($imagePath, $params);
                
                // If lock cannot be acquired after retries, return original image
                if (!$lockAcquired) {
                    return $this->returnOriginalImage($imagePath);
                }
            }

            try {
                // Resize image
                $result = $this->imageResizeService->resizeImage($imagePath, $params, $allowPrompt);
            } finally {
                // Always release lock if acquired
                if ($lockAcquired) {
                    $this->lockManager->releaseLock($imagePath, $params);
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
            
            $fileContent = file_get_contents($result->getFilePath());
            $resultRaw->setContents($fileContent);

            return $resultRaw;
        } catch (\Exception $e) {
            $this->logger->error('Image resize error: ' . $e->getMessage());
            throw new NotFoundException(__('Image not found or could not be processed'));
        }
    }

    /**
     * Check if signature validation is enabled
     *
     * @return bool
     */
    private function isSignatureEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(
            'genaker_imageaibundle/general/signature_enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
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

        $salt = $this->scopeConfig->getValue(
            'genaker_imageaibundle/general/signature_salt',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

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
        return (bool)$this->scopeConfig->getValue(
            'genaker_imageaibundle/general/regular_url_enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            true // Default to enabled
        );
    }

    /**
     * Get signature salt
     *
     * @return string
     */
    private function getSignatureSalt(): string
    {
        $salt = $this->scopeConfig->getValue(
            'genaker_imageaibundle/general/signature_salt',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

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

