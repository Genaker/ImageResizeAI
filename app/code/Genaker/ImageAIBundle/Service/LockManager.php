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
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\App\Cache\Type\FrontendPool;

/**
 * Lock Manager Service
 * Uses Redis cache for distributed locking across image resize operations
 */
class LockManager
{
    private ?FrontendInterface $cache;
    private ScopeConfigInterface $scopeConfig;
    private int $defaultRetryCount;
    private float $defaultTtl;
    private string $lockPrefix;

    /**
     * @param FrontendPool|null $cachePool Cache pool to get Redis cache frontend
     * @param ScopeConfigInterface|null $scopeConfig Scope config for retry count
     * @param int $defaultRetryCount Default number of retry attempts (default: 3)
     * @param float $defaultTtl Default lock TTL in seconds (default: 30.0 seconds)
     * @param string $lockPrefix Prefix for lock keys in Redis
     */
    public function __construct(
        FrontendPool $cachePool = null,
        ScopeConfigInterface $scopeConfig = null,
        int $defaultRetryCount = 3,
        float $defaultTtl = 30.0, // TTL in seconds
        string $lockPrefix = 'IMAGE_RESIZE_LOCK_'
    ) {
        // Use default cache frontend (which should be Redis if configured)
        $this->cache = $cachePool ? $cachePool->get('default') : null;
        if (!$this->cache) {
            // Fallback: try to get cache from object manager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            try {
                $this->cache = $objectManager->get(FrontendPool::class)->get('default');
            } catch (\Exception $e) {
                $this->cache = null;
            }
        }
        
        $this->scopeConfig = $scopeConfig ?? \Magento\Framework\App\ObjectManager::getInstance()
            ->get(ScopeConfigInterface::class);
        $this->defaultRetryCount = $defaultRetryCount;
        $this->defaultTtl = $defaultTtl;
        $this->lockPrefix = $lockPrefix;
    }

    /**
     * Check if Redis cache is available for locking
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->cache !== null;
    }

    /**
     * Acquire lock for image resize operation using Redis cache
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
        $ttl = (int)$this->getTtl();
        $lockValue = $this->generateLockValue();
        $cacheKey = $this->lockPrefix . $lockKey;

        // Try to acquire lock with retries
        for ($i = 0; $i < $retryCount; $i++) {
            // Try to set lock in Redis cache with TTL
            // If key doesn't exist, we acquire the lock
            $existingLock = $this->cache->load($cacheKey);
            
            if ($existingLock === false) {
                // Lock doesn't exist, try to acquire it
                // Use save() with tags and lifetime to set TTL
                $this->cache->save($lockValue, $cacheKey, [], $ttl);
                
                // Verify we got the lock (double-check pattern)
                $verifyLock = $this->cache->load($cacheKey);
                if ($verifyLock === $lockValue) {
                    return true;
                }
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
        $cacheKey = $this->lockPrefix . $lockKey;
        
        // Remove lock from Redis cache
        $this->cache->remove($cacheKey);
    }

    /**
     * Generate unique lock value (process ID + timestamp)
     *
     * @return string
     */
    private function generateLockValue(): string
    {
        return getmypid() . '_' . microtime(true);
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
     * Get TTL from configuration
     *
     * @return float TTL in seconds (default: 30.0 seconds)
     */
    private function getTtl(): float
    {
        return $this->defaultTtl;
    }
}

