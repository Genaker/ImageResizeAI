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

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command proxy to Python agento_video.py script
 * This command acts as a bridge between Magento CLI and the Python implementation
 */
class GenerateVideoPython extends Command
{
    const INPUT_KEY_IMAGE_PATH = 'image-path';
    const INPUT_KEY_SECOND_IMAGE = 'second-image';
    const INPUT_KEY_PROMPT = 'prompt';
    const INPUT_KEY_ASPECT_RATIO = 'aspect-ratio';
    const INPUT_KEY_SILENT_VIDEO = 'silent-video';
    const INPUT_KEY_SYNC = 'sync';
    const INPUT_KEY_NO_AUTO_REFERENCE = 'no-auto-reference';
    const INPUT_KEY_API_KEY = 'api-key';
    const INPUT_KEY_BASE_PATH = 'base-path';
    const INPUT_KEY_SAVE_PATH = 'save-path';
    const INPUT_KEY_BASE_URL = 'base-url';
    const INPUT_KEY_ENV_FILE = 'env-file';
    const INPUT_KEY_OUTPUT_FORMAT = 'output-format';

    /**
     * @var State
     */
    private $state;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param State $state
     * @param DirectoryList $directoryList
     * @param StoreManagerInterface $storeManager
     * @param string|null $name
     */
    public function __construct(
        State $state,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        $name = null
    ) {
        parent::__construct($name);
        $this->state = $state;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
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
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Path(s) to source image(s) (relative to pub/media/ or absolute path). Can specify multiple paths.'
            ),
            new InputOption(
                self::INPUT_KEY_SECOND_IMAGE,
                'si',
                InputOption::VALUE_OPTIONAL,
                'Optional second image path or URL to include in the payload for video generation'
            ),
            new InputOption(
                self::INPUT_KEY_PROMPT,
                'p',
                InputOption::VALUE_REQUIRED,
                'Video generation prompt. When using --second-image, you can reference images as: "image1"/"first image" for the first image, "image2"/"second image" for the second image, or use image filenames.'
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
                self::INPUT_KEY_SYNC,
                null,
                InputOption::VALUE_NONE,
                'Wait for video generation to complete (synchronous mode)'
            ),
            new InputOption(
                self::INPUT_KEY_NO_AUTO_REFERENCE,
                null,
                InputOption::VALUE_NONE,
                'Disable automatic image reference enhancement in prompt (use if you want full control over prompt)'
            ),
            new InputOption(
                self::INPUT_KEY_API_KEY,
                null,
                InputOption::VALUE_OPTIONAL,
                'Google Gemini API key (or set GEMINI_API_KEY environment variable)'
            ),
            new InputOption(
                self::INPUT_KEY_BASE_PATH,
                null,
                InputOption::VALUE_OPTIONAL,
                'Base path for Magento installation (defaults to current directory or MAGENTO_BASE_PATH env)'
            ),
            new InputOption(
                self::INPUT_KEY_SAVE_PATH,
                null,
                InputOption::VALUE_OPTIONAL,
                'Path where videos should be saved, relative to base_path or absolute (defaults to pub/media/video or VIDEO_SAVE_PATH env)'
            ),
            new InputOption(
                self::INPUT_KEY_BASE_URL,
                null,
                InputOption::VALUE_OPTIONAL,
                'Base URL for generating full video URLs (defaults to Magento store base URL or MAGENTO_BASE_URL env)'
            ),
            new InputOption(
                self::INPUT_KEY_ENV_FILE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to .env file (defaults to .env in script directory or current directory)'
            ),
            new InputOption(
                self::INPUT_KEY_OUTPUT_FORMAT,
                'of',
                InputOption::VALUE_OPTIONAL,
                'Output format: json only (Python script always returns JSON)',
                'json'
            ),
        ];

        $this->setName('agento-p:video')
             ->setDescription('Generate video from image using Gemini Veo 3.1 API (Python implementation proxy)')
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

            $imagePaths = $input->getOption(self::INPUT_KEY_IMAGE_PATH);
            $secondImage = $input->getOption(self::INPUT_KEY_SECOND_IMAGE);
            $prompt = $input->getOption(self::INPUT_KEY_PROMPT);
            $aspectRatio = $input->getOption(self::INPUT_KEY_ASPECT_RATIO);
            $silentVideo = $input->getOption(self::INPUT_KEY_SILENT_VIDEO);
            $sync = $input->getOption(self::INPUT_KEY_SYNC);
            $noAutoReference = $input->getOption(self::INPUT_KEY_NO_AUTO_REFERENCE);
            $apiKey = $input->getOption(self::INPUT_KEY_API_KEY);
            $basePath = $input->getOption(self::INPUT_KEY_BASE_PATH);
            $savePath = $input->getOption(self::INPUT_KEY_SAVE_PATH);
            $envFile = $input->getOption(self::INPUT_KEY_ENV_FILE);
            // Get base URL from Magento config (or use provided one)
            $baseUrl = $input->getOption(self::INPUT_KEY_BASE_URL) ?: $this->getBaseUrl();

            // Validate required options
            if (empty($imagePaths)) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => 'Image path is required. Use --image-path or -ip'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::RETURN_FAILURE;
            }

            if (empty($prompt)) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => 'Prompt is required. Use --prompt or -p'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::RETURN_FAILURE;
            }

            // Get Python script path
            $pythonScript = $this->getPythonScriptPath();
            if (!file_exists($pythonScript)) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => "Python script not found: {$pythonScript}"
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::RETURN_FAILURE;
            }

            // Get Python executable
            $pythonExecutable = $this->getPythonExecutable();
            if (!$pythonExecutable) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => 'Python 3 executable not found. Please install Python 3.'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::RETURN_FAILURE;
            }

            // Build command arguments
            $command = escapeshellarg($pythonExecutable) . ' ' . escapeshellarg($pythonScript);
            
            // Add base path (default to Magento root)
            if ($basePath) {
                $command .= ' --base-path ' . escapeshellarg($basePath);
            } else {
                $command .= ' --base-path ' . escapeshellarg(BP);
            }

            // Add image paths (can be multiple)
            foreach ($imagePaths as $imagePath) {
                $command .= ' -ip ' . escapeshellarg($imagePath);
            }

            // Add second image if provided
            if ($secondImage) {
                $command .= ' -si ' . escapeshellarg($secondImage);
            }

            // Add prompt
            $command .= ' -p ' . escapeshellarg($prompt);

            // Add aspect ratio
            if ($aspectRatio) {
                $command .= ' -ar ' . escapeshellarg($aspectRatio);
            }

            // Add silent video flag
            if ($silentVideo) {
                $command .= ' -sv';
            }

            // Add sync flag (replaces poll)
            if ($sync) {
                $command .= ' --sync';
            }

            // Add no-auto-reference flag
            if ($noAutoReference) {
                $command .= ' --no-auto-reference';
            }

            // Add API key if provided
            if ($apiKey) {
                $command .= ' --api-key ' . escapeshellarg($apiKey);
            }

            // Add save path if provided
            if ($savePath) {
                $command .= ' --save-path ' . escapeshellarg($savePath);
            }

            // Always add base URL (from Magento config or provided)
            $command .= ' --base-url ' . escapeshellarg($baseUrl);

            // Add env file if provided
            if ($envFile) {
                $command .= ' --env-file ' . escapeshellarg($envFile);
            }

            // Execute Python script
            $output->writeln('Executing Python script...', OutputInterface::VERBOSITY_VERBOSE);
            
            $exitCode = 0;
            $scriptOutput = [];
            $scriptErrors = [];
            
            $descriptorspec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w']   // stderr
            ];
            
            $process = proc_open($command, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                // Close stdin
                fclose($pipes[0]);
                
                // Read stdout
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                
                // Read stderr
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                
                // Get exit code
                $exitCode = proc_close($process);
                
                // Output the Python script's JSON response
                if (!empty($stdout)) {
                    $output->writeln($stdout);
                }
                
                // Output stderr if verbose
                if (!empty($stderr) && $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln('<comment>Python stderr: ' . $stderr . '</comment>');
                }
            } else {
                $output->writeln(json_encode([
                    'success' => false,
                    'error' => 'Failed to execute Python script'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Cli::RETURN_FAILURE;
            }

            // Return exit code from Python script
            return $exitCode === 0 ? Cli::RETURN_SUCCESS : Cli::RETURN_FAILURE;

        } catch (\Exception $e) {
            $output->writeln(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * Get Python script path
     *
     * @return string
     */
    private function getPythonScriptPath(): string
    {
        // Path relative to module directory
        // __DIR__ = app/code/Genaker/ImageAIBundle/Console/Command
        // Go up to module root: app/code/Genaker/ImageAIBundle
        $modulePath = dirname(dirname(dirname(__DIR__)));
        // Go up to vendor/genaker/imageaibundle
        $vendorPath = dirname(dirname(dirname($modulePath)));
        $scriptPath = $vendorPath . '/pygento/agento_video.py';
        
        // Alternative: use BP constant and relative path
        if (!file_exists($scriptPath)) {
            $scriptPath = BP . '/vendor/genaker/imageaibundle/pygento/agento_video.py';
        }
        
        return $scriptPath;
    }

    /**
     * Get Python executable path
     *
     * @return string|null
     */
    private function getPythonExecutable(): ?string
    {
        // Try python3 first
        $python3 = $this->findExecutable('python3');
        if ($python3) {
            return $python3;
        }
        
        // Fallback to python
        $python = $this->findExecutable('python');
        if ($python) {
            // Verify it's Python 3
            $version = shell_exec($python . ' --version 2>&1');
            if (strpos($version, 'Python 3') !== false) {
                return $python;
            }
        }
        
        return null;
    }

    /**
     * Find executable in PATH
     *
     * @param string $executable
     * @return string|null
     */
    private function findExecutable(string $executable): ?string
    {
        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        
        foreach ($paths as $path) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $executable;
            if (is_executable($fullPath)) {
                return $fullPath;
            }
        }
        
        // Try common locations
        $commonPaths = [
            '/usr/bin/' . $executable,
            '/usr/local/bin/' . $executable,
            '/bin/' . $executable,
        ];
        
        foreach ($commonPaths as $fullPath) {
            if (is_executable($fullPath)) {
                return $fullPath;
            }
        }
        
        return null;
    }

    /**
     * Get base URL from Magento store configuration
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        try {
            $store = $this->storeManager->getStore();
            $baseUrl = $store->getBaseUrl();
            
            // Remove store code from base URL (e.g., /default/) to get clean base URL
            // Media URLs should not include store code - they're accessible directly
            $parsedUrl = parse_url($baseUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'] ?? '';
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            
            // Build clean base URL without store code
            $cleanBaseUrl = $scheme . '://' . $host . $port;
            
            return rtrim($cleanBaseUrl, '/');
        } catch (\Exception $e) {
            // Fallback - try to detect from environment or use default
            $envBaseUrl = getenv('MAGENTO_BASE_URL') ?: getenv('BASE_URL');
            if ($envBaseUrl) {
                return rtrim($envBaseUrl, '/');
            }
            // Last resort fallback
            return 'https://localhost';
        }
    }
}
