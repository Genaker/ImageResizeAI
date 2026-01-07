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
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        ImageResizeServiceInterface $imageResizeService,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->imageResizeService = $imageResizeService;
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
            // Generate resize URL
            $baseUrl = $this->_url->getUrl('media/resize/index', ['imagePath' => $imagePath]);
            $queryParams = array_filter($params, fn($value) => $value !== null);
            $url = $baseUrl . '?' . http_build_query($queryParams);

            /** @var Json $resultJson */
            $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $resultJson->setData([
                'success' => true,
                'url' => $url,
                'base64_url' => $url, // Simplified for Magento
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

