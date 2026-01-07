<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Api;

/**
 * Image Resize Service Interface
 */
interface ImageResizeServiceInterface
{
    /**
     * Resize image with parameters
     *
     * @param string $imagePath Image path to resize
     * @param array $params Resize parameters (w, h, q, a, f, prompt, trim, etc.)
     * @param bool $allowPrompt Whether to allow prompt parameter
     * @return \Genaker\ImageAIBundle\Model\ResizeResult
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function resizeImage(string $imagePath, array $params, bool $allowPrompt = false);

    /**
     * Get public directory path
     *
     * @return string
     */
    public function getPublicDir(): string;

    /**
     * Check if image exists
     *
     * @param string $imagePath Virtual path or resolved path
     * @return bool
     */
    public function imageExists(string $imagePath): bool;

    /**
     * Get original image path
     *
     * @param string $imagePath Image path
     * @return string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function getOriginalImagePath(string $imagePath): string;
}

