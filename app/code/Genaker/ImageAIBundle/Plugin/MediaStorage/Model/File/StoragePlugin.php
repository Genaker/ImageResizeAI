<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Plugin\MediaStorage\Model\File;

use Magento\MediaStorage\Model\File\Storage;

/**
 * Plugin to add resize paths to allowed resources list
 */
class StoragePlugin
{
    /**
     * Add resize paths to allowed resources
     *
     * @param Storage $subject
     * @param array $result
     * @return array
     */
    public function afterGetScriptConfig(Storage $subject, array $result): array
    {
        // Add resize paths to allowed resources
        // Include both with and without trailing slash to match different path formats
        $resizePaths = [
            'resize/ip',
            'resize/ip/',
            'media/resize/ip',
            'media/resize/ip/',
            'resize/index',
            'resize/index/',
            'media/resize/index',
            'media/resize/index/'
        ];
        
        if (isset($result['allowed_resources']) && is_array($result['allowed_resources'])) {
            foreach ($resizePaths as $resizePath) {
                if (!in_array($resizePath, $result['allowed_resources'])) {
                    $result['allowed_resources'][] = $resizePath;
                }
            }
        }
        
        return $result;
    }
}
