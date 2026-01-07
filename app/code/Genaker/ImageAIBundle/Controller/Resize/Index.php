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

    public function __construct(
        Context $context,
        ImageResizeServiceInterface $imageResizeService,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        Header $httpHeader,
        Session $authSession = null
    ) {
        parent::__construct($context);
        $this->imageResizeService = $imageResizeService;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->httpHeader = $httpHeader;
        $this->authSession = $authSession ?? \Magento\Framework\App\ObjectManager::getInstance()->get(Session::class);
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $imagePath = $this->getRequest()->getParam('imagePath', '');
        
        if (empty($imagePath)) {
            throw new NotFoundException(__('Image path is required'));
        }

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

        try {
            // Validate signature if enabled
            $allowPrompt = false;
            if ($this->isSignatureEnabled()) {
                $this->validateSignature($imagePath, $params);
                // If signature is validated, allow prompts (signature provides security)
                $allowPrompt = true;
            } else {
                // Check if user is admin (for cases without signature)
                $allowPrompt = $this->isAdmin();
            }

            // Resize image
            $result = $this->imageResizeService->resizeImage($imagePath, $params, $allowPrompt);

            // Return image file
            /** @var Raw $resultRaw */
            $resultRaw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $resultRaw->setHeader('Content-Type', $result->getMimeType());
            $resultRaw->setHeader('X-Cache-Status', $result->isFromCache() ? 'HIT' : 'MISS');
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
}

