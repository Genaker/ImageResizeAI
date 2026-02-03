<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Console\Command;

use Genaker\ImageAIBundle\Service\GeminiImageModificationService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to generate image using Gemini API
 */
class GenerateImage extends Command
{
    const INPUT_KEY_MODEL_IMAGE = 'model-image';
    const INPUT_KEY_LOOK_IMAGE = 'look-image';
    const INPUT_KEY_PROMPT = 'prompt';
    const INPUT_KEY_OUTPUT_FORMAT = 'output-format';

    /**
     * @var State
     */
    private $state;

    /**
     * @var GeminiImageModificationService
     */
    private $imageService;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param State $state
     * @param GeminiImageModificationService $imageService
     * @param Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        State $state,
        GeminiImageModificationService $imageService,
        Filesystem $filesystem,
        $name = null
    ) {
        parent::__construct($name);
        $this->state = $state;
        $this->imageService = $imageService;
        $this->filesystem = $filesystem;
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::INPUT_KEY_MODEL_IMAGE,
                'mi',
                InputOption::VALUE_REQUIRED,
                'Path to model image (relative to pub/media/ or absolute path)'
            ),
            new InputOption(
                self::INPUT_KEY_LOOK_IMAGE,
                'li',
                InputOption::VALUE_REQUIRED,
                'Path to look/apparel image (relative to pub/media/ or absolute path)'
            ),
            new InputOption(
                self::INPUT_KEY_PROMPT,
                'p',
                InputOption::VALUE_REQUIRED,
                'Image generation prompt'
            ),
            new InputOption(
                self::INPUT_KEY_OUTPUT_FORMAT,
                'of',
                InputOption::VALUE_OPTIONAL,
                'Output format: json (default), plain, or table',
                'json'
            ),
        ];

        $this->setName('agento:image')
             ->setDescription('Generate image using Gemini API')
             ->setDefinition($options);

        parent::configure();
    }

    /**
     * Execute command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);

            $modelImagePath = $input->getOption(self::INPUT_KEY_MODEL_IMAGE);
            $lookImagePath = $input->getOption(self::INPUT_KEY_LOOK_IMAGE);
            $prompt = $input->getOption(self::INPUT_KEY_PROMPT);
            $outputFormat = $input->getOption(self::INPUT_KEY_OUTPUT_FORMAT) ?: 'json';

            // Validate required options
            if (empty($modelImagePath)) {
                $error = ['success' => false, 'error' => 'Model image path is required. Use --model-image or -mi'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            if (empty($lookImagePath)) {
                $error = ['success' => false, 'error' => 'Look image path is required. Use --look-image or -li'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            if (empty($prompt)) {
                $error = ['success' => false, 'error' => 'Prompt is required. Use --prompt or -p'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            // Check if service is available
            if (!$this->imageService->isAvailable()) {
                $error = ['success' => false, 'error' => 'Image service is not available. Please configure Gemini API key.'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            // Resolve image paths
            $modelSourcePath = $this->resolveImagePath($modelImagePath);
            if (!file_exists($modelSourcePath)) {
                $error = ['success' => false, 'error' => "Model image not found: {$modelSourcePath}"];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            $lookSourcePath = $this->resolveImagePath($lookImagePath);
            if (!file_exists($lookSourcePath)) {
                $error = ['success' => false, 'error' => "Look image not found: {$lookSourcePath}"];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            // Generate image using combined images and prompt
            $result = $this->imageService->generateImageFromImages(
                $modelSourcePath,
                $lookSourcePath,
                $prompt
            );

            $result = [
                'success' => true,
                'status' => 'completed',
                'imagePath' => $result['imagePath'],
                'message' => 'Image generated and saved successfully'
            ];
            $this->outputResult($output, $result, $outputFormat);

            return Cli::RETURN_SUCCESS;

        } catch (\Exception $e) {
            $error = ['success' => false, 'error' => $e->getMessage()];
            $this->outputResult($output, $error, $outputFormat);
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Resolve image path (handle relative and absolute paths)
     *
     * @param string $imagePath
     * @return string
     */
    private function resolveImagePath(string $imagePath): string
    {
        // If absolute path, use as is
        if (strpos($imagePath, '/') === 0 || strpos($imagePath, '\\') === 0) {
            return $imagePath;
        }

        // If path starts with pub/media/, remove it
        if (strpos($imagePath, 'pub/media/') === 0) {
            $imagePath = substr($imagePath, 10);
        }

        // Resolve relative to pub/media/
        $basePath = $this->filesystem
            ->getDirectoryRead(DirectoryList::MEDIA)
            ->getAbsolutePath();

        return $basePath . ltrim($imagePath, '/');
    }

    /**
     * Output result in specified format
     *
     * @param OutputInterface $output
     * @param array $result
     * @param string $format Output format: json, plain, or table
     */
    private function outputResult(OutputInterface $output, array $result, string $format = 'json'): void
    {
        switch (strtolower($format)) {
            case 'plain':
                $this->outputPlain($output, $result);
                break;
            case 'table':
                $this->outputTable($output, $result);
                break;
            case 'json':
            default:
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                break;
        }
    }

    /**
     * Output result as plain text
     *
     * @param OutputInterface $output
     * @param array $result
     */
    private function outputPlain(OutputInterface $output, array $result): void
    {
        if (isset($result['success']) && $result['success']) {
            if (isset($result['status']) && $result['status'] === 'completed') {
                $output->writeln('<info>✓ Image generation completed!</info>');
                if (isset($result['imagePath'])) {
                    $output->writeln("Image Path: {$result['imagePath']}");
                }
                if (isset($result['message'])) {
                    $output->writeln("<comment>{$result['message']}</comment>");
                }
            }
        } else {
            $error = $result['error'] ?? 'Unknown error';
            $output->writeln("<error>Error: {$error}</error>");
        }
    }

    /**
     * Output result as table
     *
     * @param OutputInterface $output
     * @param array $result
     */
    private function outputTable(OutputInterface $output, array $result): void
    {
        if (isset($result['success']) && $result['success']) {
            $rows = [];

            if (isset($result['status'])) {
                $rows[] = ['Status', $result['status']];
            }

            if (isset($result['imagePath'])) {
                $rows[] = ['Image Path', $result['imagePath']];
            }

            if (isset($result['message'])) {
                $rows[] = ['Message', $result['message']];
            }

            if (!empty($rows)) {
                $table = new Table($output);
                $table->setHeaders(['Field', 'Value']);
                $table->setRows($rows);
                $table->render();
            }
        } else {
            $error = $result['error'] ?? 'Unknown error';
            $output->writeln("<error>Error: {$error}</error>");
        }
    }
}