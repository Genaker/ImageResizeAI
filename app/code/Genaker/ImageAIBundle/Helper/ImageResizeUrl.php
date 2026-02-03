<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Helper;

use Genaker\ImageAIBundle\Service\ResizeUrlGenerationService;

/**
 * Image Resize URL Helper
 * Global helper for generating image resize URLs
 * 
 * Usage in templates:
 * $helper = $this->helper(\Genaker\ImageAIBundle\Helper\ImageResizeUrl::class);
 * $url = $helper->getResizeUrl('catalog/product/image.jpg', ['w' => 400, 'h' => 400]);
 */
class ImageResizeUrl
{
    private ResizeUrlGenerationService $urlGenerationService;

    public function __construct(
        ResizeUrlGenerationService $urlGenerationService
    ) {
        $this->urlGenerationService = $urlGenerationService;
    }

    /**
     * Generate image resize URL (base64 format by default)
     *
     * @param string $imagePath Image path (e.g., 'catalog/product/image.jpg')
     * @param array $params Resize parameters ['w' => 400, 'h' => 400, 'f' => 'jpeg', 'q' => 85]
     * @param bool $useBase64 Use base64 format (true, default) or regular query string format (false)
     * @return string Generated URL
     */
    public function getResizeUrl(string $imagePath, array $params = [], bool $useBase64 = true): string
    {
        return $this->urlGenerationService->generateUrl($imagePath, $params, $useBase64);
    }

    /**
     * Generate base64 URL format
     *
     * @param string $imagePath
     * @param array $params
     * @return string
     */
    public function getBase64Url(string $imagePath, array $params = []): string
    {
        return $this->urlGenerationService->generateBase64Url($imagePath, $params);
    }

    /**
     * Generate regular query string URL format
     *
     * @param string $imagePath
     * @param array $params
     * @return string
     */
    public function getRegularUrl(string $imagePath, array $params = []): string
    {
        return $this->urlGenerationService->generateRegularUrl($imagePath, $params);
    }


