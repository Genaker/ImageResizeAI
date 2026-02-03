<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Model;

/**
 * Resize Result Model
 */
class ResizeResult
{
    private string $filePath;
    private string $mimeType;
    private int $fileSize;
    private bool $fromCache;
    private string $cacheKey;
    private string $rawCacheKey;

    public function __construct(
        string $filePath,
        string $mimeType,
        int $fileSize,
        bool $fromCache,
        string $cacheKey,
        string $rawCacheKey = ''
    ) {
        $this->filePath = $filePath;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->fromCache = $fromCache;
        $this->cacheKey = $cacheKey;
        $this->rawCacheKey = $rawCacheKey;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function isFromCache(): bool
    {
        return $this->fromCache;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getRawCacheKey(): string
    {
        return $this->rawCacheKey;
    }
}

