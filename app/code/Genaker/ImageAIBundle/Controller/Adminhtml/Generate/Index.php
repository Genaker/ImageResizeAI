<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Controller\Adminhtml\Generate;

use Genaker\ImageAIBundle\Api\ImageResizeServiceInterface;
use Genaker\ImageAIBundle\Helper\ImageResizeUrl;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Admin Image Resize URL Generator Controller
 */
class Index extends Action
{
    const ADMIN_RESOURCE = 'Genaker_ImageAIBundle::config';

    private ImageResizeServiceInterface $imageResizeService;
    private ImageResizeUrl $urlHelper;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        ImageResizeServiceInterface $imageResizeService,
        ImageResizeUrl $urlHelper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->imageResizeService = $imageResizeService;
        $this->urlHelper = $urlHelper;
        $this->logger = $logger;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $imagePath = $this->getRequest()->getParam('imagePath', '');
        $params = [
            'w' => $this->getRequest()->getParam('w'),
            'h' => $this->getRequest()->getParam('h'),
            'q' => $this->getRequest()->getParam('q'),
            'a' => $this->getRequest()->getParam('a'),
            'f' => $this->getRequest()->getParam('f'),
            'prompt' => $this->getRequest()->getParam('prompt'),
        ];

        try {
            // Filter out null values
            $filteredParams = array_filter($params, fn($value) => $value !== null);
            
            // Generate base64 URL
            $base64Url = $this->urlHelper->generateImageResizeUrl($imagePath, $filteredParams, true);
            
            // Generate regular URL
            $regularUrl = $this->urlHelper->generateImageResizeUrl($imagePath, $filteredParams, false);
            
            // Get full URLs
            $base64FullUrl = $this->_url->getBaseUrl() . ltrim($base64Url, '/');
            $regularFullUrl = $regularUrl;

            /** @var Json $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData([
                'success' => true,
                'base64_url' => $base64Url,
                'base64_full_url' => $base64FullUrl,
                'regular_url' => $regularUrl,
                'regular_full_url' => $regularFullUrl,
            ]);

            return $resultJson;
        } catch (\Exception $e) {
            $this->logger->error('Error generating image resize URL: ' . $e->getMessage());
            
            /** @var Json $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData([
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            return $resultJson;
        }
    }
}

