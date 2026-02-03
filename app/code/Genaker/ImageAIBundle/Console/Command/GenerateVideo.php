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

use Genaker\ImageAIBundle\Service\GeminiVideoDirectService;
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
 * Console command to generate video from image using Gemini Veo 3.1 API
 */
class GenerateVideo extends Command
{
    const INPUT_KEY_IMAGE_PATH = 'image-path';
    const INPUT_KEY_PROMPT = 'prompt';
    const INPUT_KEY_ASPECT_RATIO = 'aspect-ratio';
    const INPUT_KEY_SILENT_VIDEO = 'silent-video';
    const INPUT_KEY_POLL = 'poll';
    const INPUT_KEY_OUTPUT_FORMAT = 'output-format';

    /**
     * @var State
     */
    private $state;

    /**
     * @var GeminiVideoDirectService
     */
    private $videoService;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param State $state
     * @param GeminiVideoDirectService $videoService
     * @param Filesystem $filesystem
     * @param string|null $name
     */
    public function __construct(
        State $state,
        GeminiVideoDirectService $videoService,
        Filesystem $filesystem,
        $name = null
    ) {
        parent::__construct($name);
        $this->state = $state;
        $this->videoService = $videoService;
        $this->filesystem = $filesystem;
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::INPUT_KEY_IMAGE_PATH,
                'ip',
                InputOption::VALUE_REQUIRED,
                'Path to source image (relative to pub/media/ or absolute path)'
            ),
            new InputOption(
                self::INPUT_KEY_PROMPT,
                'p',
                InputOption::VALUE_REQUIRED,
                'Video generation prompt'
            ),
            new InputOption(
                self::INPUT_KEY_ASPECT_RATIO,
                'ar',
                InputOption::VALUE_OPTIONAL,
                'Aspect ratio (e.g., "16:9", "9:16", "1:1"). Default: 16:9',
                '16:9'
            ),
            new InputOption(
                self::INPUT_KEY_SILENT_VIDEO,
                'sv',
                InputOption::VALUE_NONE,
                'Generate silent video (helps avoid audio-related safety filters)'
            ),
            new InputOption(
                self::INPUT_KEY_POLL,
                null,
                InputOption::VALUE_NONE,
                'Wait for video generation to complete (synchronous mode)'
            ),
            new InputOption(
                self::INPUT_KEY_OUTPUT_FORMAT,
                'of',
                InputOption::VALUE_OPTIONAL,
                'Output format: json (default), plain, or table',
                'json'
            ),
        ];

        $this->setName('agento:video')
             ->setDescription('Generate video from image using Gemini Veo 3.1 API')
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

            $imagePath = $input->getOption(self::INPUT_KEY_IMAGE_PATH);
            $prompt = $input->getOption(self::INPUT_KEY_PROMPT);
            $aspectRatio = $input->getOption(self::INPUT_KEY_ASPECT_RATIO);
            $silentVideo = $input->getOption(self::INPUT_KEY_SILENT_VIDEO);
            $poll = $input->getOption(self::INPUT_KEY_POLL);
            $outputFormat = $input->getOption(self::INPUT_KEY_OUTPUT_FORMAT) ?: 'json';

            // Validate required options
            if (empty($imagePath)) {
                $error = ['success' => false, 'error' => 'Image path is required. Use --image-path or -ip'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            if (empty($prompt)) {
                $error = ['success' => false, 'error' => 'Prompt is required. Use --prompt or -p'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            // Check if service is available
            if (!$this->videoService->isAvailable()) {
                $error = ['success' => false, 'error' => 'Video service is not available. Please configure Gemini API key.'];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            // Resolve image path
            $sourcePath = $this->resolveImagePath($imagePath);
            if (!file_exists($sourcePath)) {
                $error = ['success' => false, 'error' => "Source image not found: {$sourcePath}"];
                $this->outputResult($output, $error, $outputFormat);
                return Cli::RETURN_FAILURE;
            }

            // Generate video
            $operation = $this->videoService->generateVideoFromImage(
                $sourcePath,
                $prompt,
                $aspectRatio,
                $silentVideo
            );

            // Check if video was returned from cache
            if (isset($operation['fromCache']) && $operation['fromCache'] === true) {
                $result = [
                    'success' => true,
                    'status' => 'completed',
                    'videoUrl' => $operation['videoUrl'],
                    'videoPath' => $operation['videoPath'],
                    'cached' => true
                ];
                $this->outputResult($output, $result, $outputFormat);
                return Cli::RETURN_SUCCESS;
            }

            // If poll option is set, wait for completion
            if ($poll) {
                $cacheKey = $operation['cacheKey'] ?? null;
                $result = $this->videoService->pollVideoOperation(
                    $operation['operationName'],
                    300,
                    10,
                    $cacheKey
                );

                $result = [
                    'success' => true,
                    'status' => 'completed',
                    'videoUrl' => $result['videoUrl'],
                    'videoPath' => $result['videoPath'],
                    'embedUrl' => $result['embedUrl'] ?? null
                ];
                $this->outputResult($output, $result, $outputFormat);
                return Cli::RETURN_SUCCESS;
            }

            // Return operation ID for async polling
            $result = [
                'success' => true,
                'status' => 'processing',
                'operationName' => $operation['operationName'],
                'message' => 'Video generation started. Use --poll option to wait for completion.'
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
                $output->writeln('<info>✓ Video generation completed!</info>');
                if (isset($result['videoUrl'])) {
                    $output->writeln("Video URL: {$result['videoUrl']}");
                }
                if (isset($result['videoPath'])) {
                    $output->writeln("Video Path: {$result['videoPath']}");
                }
                if (isset($result['cached']) && $result['cached']) {
                    $output->writeln('<comment>Note: Video was retrieved from cache.</comment>');
                }
            } elseif (isset($result['status']) && $result['status'] === 'processing') {
                $output->writeln('<info>Video generation started (async mode)</info>');
                if (isset($result['operationName'])) {
                    $output->writeln("Operation Name: {$result['operationName']}");
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
            
            if (isset($result['videoUrl'])) {
                $rows[] = ['Video URL', $result['videoUrl']];
            }
            
            if (isset($result['videoPath'])) {
                $rows[] = ['Video Path', $result['videoPath']];
            }
            
            if (isset($result['operationName'])) {
                $rows[] = ['Operation Name', $result['operationName']];
            }
            
            if (isset($result['cached']) && $result['cached']) {
                $rows[] = ['Cached', 'Yes'];
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
