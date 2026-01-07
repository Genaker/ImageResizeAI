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
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Lock Manager Service
 * Wraps Magento's LockManager for image resize operations
 */
class LockManager
{
    private ?LockManagerInterface $lockManager;
    private ScopeConfigInterface $scopeConfig;
    private int $defaultRetryCount;
    private float $defaultTtl;

    public function __construct(
        LockManagerInterface $lockManager = null,
        ScopeConfigInterface $scopeConfig = null,
        int $defaultRetryCount = 3,
        float $defaultTtl = 30.0
    ) {
        $this->lockManager = $lockManager;
        $this->scopeConfig = $scopeConfig ?? \Magento\Framework\App\ObjectManager::getInstance()
            ->get(ScopeConfigInterface::class);
        $this->defaultRetryCount = $defaultRetryCount;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * Check if lock manager is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->lockManager !== null;
    }

    /**
     * Acquire lock for image resize operation
     *
     * @param string $imagePath Image path
     * @param array $params Resize parameters
     * @return bool True if lock acquired, false otherwise
     */
    public function acquireLock(string $imagePath, array $params): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $lockKey = $this->generateLockKey($imagePath, $params);
        $retryCount = $this->getRetryCount();
        $ttl = $this->getTtl();

        // Try to acquire lock with retries
        for ($i = 0; $i < $retryCount; $i++) {
            if ($this->lockManager->lock($lockKey, $ttl)) {
                return true;
            }
            
            // Wait before retry (except on last attempt)
            if ($i < $retryCount - 1) {
                usleep(1000000); // 1 second
            }
        }

        return false;
    }

    /**
     * Release lock for image resize operation
     *
     * @param string $imagePath Image path
     * @param array $params Resize parameters
     * @return void
     */
    public function releaseLock(string $imagePath, array $params): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $lockKey = $this->generateLockKey($imagePath, $params);
        $this->lockManager->unlock($lockKey);
    }

    /**
     * Generate unique lock key from image path and parameters
     *
     * @param string $imagePath
     * @param array $params
     * @return string
     */
    private function generateLockKey(string $imagePath, array $params): string
    {
        $filteredParams = array_filter($params, fn($value) => $value !== null);
        ksort($filteredParams);
        $paramString = http_build_query($filteredParams);
        return 'image_resize_' . md5($imagePath . '|' . $paramString);
    }

    /**
     * Get retry count from configuration
     *
     * @return int
     */
    private function getRetryCount(): int
    {
        if ($this->scopeConfig) {
            $retryCount = (int)$this->scopeConfig->getValue(
                'genaker_imageaibundle/general/lock_retry_count',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $this->defaultRetryCount
            );
            return max(1, $retryCount);
        }
        return $this->defaultRetryCount;
    }

    /**
     * Get TTL from configuration (default 30 seconds)
     *
     * @return float
     */
    private function getTtl(): float
    {
        return $this->defaultTtl;
    }
}

