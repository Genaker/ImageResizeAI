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

/**
 * Gemini Client Factory
 * Creates Gemini API client instance if API key is configured
 */
class GeminiClientFactory
{
    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Create Gemini Client instance
     *
     * @return \Gemini\Client|null
     */
    public function createClient(): ?\Gemini\Client
    {
        // Check if Gemini SDK is available
        if (!class_exists('\Gemini\Client') && !class_exists('\Gemini')) {
            return null;
        }

        // Get API key from config or environment
        $apiKey = $this->getApiKey();
        
        if (empty($apiKey)) {
            return null;
        }

        try {
            // Use Gemini factory to create client with API key
            if (class_exists('\Gemini')) {
                return \Gemini::client($apiKey);
            }
            // Fallback to direct instantiation if factory doesn't exist
            return new \Gemini\Client(apiKey: $apiKey);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get Gemini API key from config or environment
     *
     * @return string
     */
    private function getApiKey(): string
    {
        // Try config first
        $apiKey = $this->scopeConfig->getValue(
            'genaker_imageaibundle/general/gemini_api_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        // Decrypt if encrypted
        if ($apiKey && class_exists('\Magento\Framework\Encryption\EncryptorInterface')) {
            $encryptor = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Encryption\EncryptorInterface::class);
            $apiKey = $encryptor->decrypt($apiKey);
        }

        // Fallback to environment variable
        if (empty($apiKey)) {
            $apiKey = getenv('GEMINI_API_KEY') ?: '';
        }

        return $apiKey;
    }
}

