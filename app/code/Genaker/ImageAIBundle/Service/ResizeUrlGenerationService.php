<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Resize URL Generation Service
 * Generates image resize URLs in both base64 (default) and regular formats
 */
class ResizeUrlGenerationService
{
    private ScopeConfigInterface $scopeConfig;
    private UrlInterface $urlBuilder;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Generate image resize URL
     * 
     * @param string $imagePath Image path (e.g., 'catalog/product/image.jpg')
     * @param array $params Resize parameters ['w' => 400, 'h' => 400, 'f' => 'jpeg', 'q' => 85]
     * @param bool $useBase64 Use base64 format (true, default) or regular query string format (false)
     * @param bool $includeDomain Include full domain in URL (default: true)
     * @return string Generated URL
     */
    public function generateUrl(
        string $imagePath,
        array $params = [],
        bool $useBase64 = true,
        bool $includeDomain = true
    ): string {
        if ($useBase64) {
            return $this->generateBase64Url($imagePath, $params, $includeDomain);
        } else {
            return $this->generateRegularUrl($imagePath, $params, $includeDomain);
        }
    }

    /**
     * Generate base64 encoded URL format: /media/resize/{base64}.{extension}
     * This format is optimized for nginx caching
     *
     * @param string $imagePath
     * @param array $params
     * @param bool $includeDomain
     * @return string
     */
    public function generateBase64Url(
        string $imagePath,
        array $params = [],
        bool $includeDomain = true
    ): string {
        // Normalize image path
        $sanitizedPath = ltrim($imagePath, '/');
        // Remove unsafe characters
        $sanitizedPath = str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $sanitizedPath);

        // Filter and sort parameters
        $filteredParams = array_filter($params, fn($value) => $value !== null);
        ksort($filteredParams);

        // Generate signature if enabled
        if ($this->isSignatureEnabled()) {
            $salt = $this->getSignatureSalt();
            if (!empty($salt)) {
                $signature = $this->generateSignature($sanitizedPath, $filteredParams, $salt);
                $filteredParams['sig'] = $signature;
                ksort($filteredParams); // Re-sort after adding signature
            }
        }

        // Build query string
        $paramString = http_build_query($filteredParams);

        // Build the string to encode: ip/{imagePath}?{params}
        $stringToEncode = 'ip/' . $sanitizedPath;
        if (!empty($paramString)) {
            $stringToEncode .= '?' . $paramString;
        }

        // Encode as base64 (URL-safe, no padding)
        $base64Params = base64_encode($stringToEncode);
        // Make URL-safe: replace + / = with - _ (no padding needed)
        $base64Params = strtr($base64Params, '+/', '-_');
        // Remove padding if present
        $base64Params = rtrim($base64Params, '=');

        // Get extension from format parameter or infer from image path
        $extension = $params['f'] ?? $this->getFormatFromPath($imagePath);
        // Normalize jpg to jpeg
        if ($extension === 'jpg') {
            $extension = 'jpeg';
        }

        // Build path: /media/resize/{base64}.{ext}
        $path = '/media/resize/' . $base64Params . '.' . $extension;

        if ($includeDomain) {
            return $this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]) . ltrim($path, '/');
        }

        return $path;
    }

    /**
     * Generate regular query string URL format: /media/resize/ip/{image_path}?{params}
     *
     * @param string $imagePath
     * @param array $params
     * @param bool $includeDomain
     * @return string
     */
    public function generateRegularUrl(
        string $imagePath,
        array $params = [],
        bool $includeDomain = true
    ): string {
        // Normalize image path
        $sanitizedPath = ltrim($imagePath, '/');

        // Filter parameters
        $filteredParams = array_filter($params, fn($value) => $value !== null);
        ksort($filteredParams);

        // Generate signature if enabled
        if ($this->isSignatureEnabled()) {
            $salt = $this->getSignatureSalt();
            if (!empty($salt)) {
                $signature = $this->generateSignature($sanitizedPath, $filteredParams, $salt);
                $filteredParams['sig'] = $signature;
            }
        }

        // Build query string
        $queryString = http_build_query($filteredParams);

        // Build path: /media/resize/ip/{image_path}
        $path = '/media/resize/ip/' . $sanitizedPath;
        if (!empty($queryString)) {
            $path .= '?' . $queryString;
        }

        if ($includeDomain) {
            return $this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]) . ltrim($path, '/');
        }

        return $path;
    }

    /**
     * Generate signature for URL validation
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
     * Check if signature validation is enabled
     *
     * @return bool
     */
    private function isSignatureEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(
            'genaker_imageaibundle/general/signature_enabled',
            ScopeInterface::SCOPE_STORE
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
            ScopeInterface::SCOPE_STORE
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
     * Get format from image path
     *
     * @param string $imagePath
     * @return string
     */
    private function getFormatFromPath(string $imagePath): string
    {
        $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if ($extension === 'jpg') {
            return 'jpeg';
        }
        return $extension ?: 'jpeg';
    }
}
