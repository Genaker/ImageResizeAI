<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\ViewModel;

use Genaker\ImageAIBundle\Service\ResizeUrlGenerationService;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Resize URL ViewModel
 * Provides methods for generating image resize URLs in templates
 * 
 * Usage in template (.phtml):
 * <?php
 * /** @var \Genaker\ImageAIBundle\ViewModel\ResizeUrl $resizeUrlViewModel *\/
 * $resizeUrl = $resizeUrlViewModel->getResizeUrl('catalog/product/image.jpg', ['w' => 400, 'h' => 400]);
 * ?>
 * <img src="<?= $escaper->escapeUrl($resizeUrl) ?>" alt="Product Image" />
 */
class ResizeUrl implements ArgumentInterface
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
     * Generate base64 URL format (optimized for nginx caching)
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
}
