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
    /** @var int Default number of retry attempts */
    private const DEFAULT_RETRY_COUNT = 3;

    /** @var float Default lock TTL in seconds */
    private const DEFAULT_TTL = 3.0;

    /** @var string Prefix for lock keys in Redis */
    private const LOCK_PREFIX = 'IMAGE_RESIZE_LOCK_';

    private ?FrontendInterface $cache;
    private ScopeConfigInterface $scopeConfig;
    private int $defaultRetryCount;
    private float $defaultTtl;
    private string $lockPrefix;

    /**
     * @param FrontendPool|null $cachePool Cache pool to get Redis cache frontend
     * @param ScopeConfigInterface|null $scopeConfig Scope config for retry count
     * @param int|null $defaultRetryCount Number of retry attempts (defaults to DEFAULT_RETRY_COUNT)
     * @param float|null $defaultTtl Lock TTL in seconds (defaults to DEFAULT_TTL)
     * @param string|null $lockPrefix Prefix for lock keys in Redis (defaults to LOCK_PREFIX)
     */
    public function __construct(
        FrontendPool $cachePool = null,
        ScopeConfigInterface $scopeConfig = null,
        ?int $defaultRetryCount = null,
        ?float $defaultTtl = null,
        ?string $lockPrefix = null
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
        $this->defaultRetryCount = $defaultRetryCount ?? self::DEFAULT_RETRY_COUNT;
        $this->defaultTtl = $defaultTtl ?? self::DEFAULT_TTL;
        $this->lockPrefix = $lockPrefix ?? self::LOCK_PREFIX;
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
     * @return float TTL in seconds
     */
    private function getTtl(): float
    {
        return $this->defaultTtl;
    }
}

