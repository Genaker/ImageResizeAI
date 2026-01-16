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

use Genaker\ImageAIBundle\Controller\Resize\Index as ResizeIndex;

/**
 * Image Resize Controller - Short URL format
 * Handles /media/resize/ip/{image_path} URLs
 * 
 * This controller extends the main Index controller and uses 'ip' parameter instead of 'imagePath'
 */
class Ip extends ResizeIndex
{
    /**
     * Execute action
     * Override to use 'ip' parameter instead of 'imagePath'
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        // The parent controller already checks for both 'ip' and 'imagePath' parameters
        // So we can just call parent::execute() directly
        // The parent will handle 'ip' parameter correctly
        return parent::execute();
    }
}
