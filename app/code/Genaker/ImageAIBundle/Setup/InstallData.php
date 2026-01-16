<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Install Data
 * Copies test image to pub/media for testing purposes
 */
class InstallData implements InstallDataInterface
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        try {
            // Get module directory
            $moduleDir = dirname(dirname(__DIR__));
            $testImageSource = $moduleDir . '/media/test/wt09-white_main_1.jpg';

            // Check if source file exists
            if (!file_exists($testImageSource)) {
                $setup->endSetup();
                return;
            }

            // Get media directory
            $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $targetPath = 'catalog/product/w/t/wt09-white_main_1.jpg';

            // Create target directory if it doesn't exist
            $targetDir = dirname($targetPath);
            if (!$mediaDirectory->isDirectory($targetDir)) {
                $mediaDirectory->create($targetDir);
            }

            // Copy test image to media directory
            $mediaDirectory->getDriver()->filePutContents(
                $mediaDirectory->getAbsolutePath($targetPath),
                file_get_contents($testImageSource)
            );

            $setup->endSetup();
        } catch (\Exception $e) {
            $setup->endSetup();
            // Silently fail - test image is optional
        }
    }
}
