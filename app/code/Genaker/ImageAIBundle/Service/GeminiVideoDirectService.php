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

use Psr\Log\LoggerInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Gemini Video Direct Service (Default Implementation)
 * Handles AI-powered video generation using direct HTTP calls to Google Veo 3.1 API
 * 
 * This is the default implementation that bypasses SDK limitations by calling the API directly.
 * It uses Magento's HTTP Client to make direct requests to the Gemini v1beta endpoint.
 * 
 * Benefits:
 * - No SDK dependency - works immediately without waiting for SDK updates
 * - Direct API control - full control over requests and error handling
 * - Reliable - uses stable v1beta endpoint for Veo 3.1
 * - Compatible - works with any Gemini API key that has Veo 3.1 access
 */
class GeminiVideoDirectService
{
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;
    private File $filesystem;
    private Curl $httpClient;
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    private string $model = 'veo-3.1-generate-preview'; // Veo 3.1 preview model for Google AI Studio API key

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $httpClient,
        LoggerInterface $logger = null,
        File $filesystem = null
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->httpClient = $httpClient;
        $this->logger = $logger ?? \Magento\Framework\App\ObjectManager::getInstance()->get(LoggerInterface::class);
        $this->filesystem = $filesystem ?? \Magento\Framework\App\ObjectManager::getInstance()->get(File::class);
        $this->apiKey = $this->getApiKey();
    }

    /**
     * Check if video service is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Generate video from image and prompt
     * This is an asynchronous operation that returns an operation ID
     * Checks cache first and returns cached video if available
     *
     * @param string $imagePath Path to source image
     * @param string $prompt Video generation prompt
     * @param string|null $aspectRatio Aspect ratio (e.g., "16:9", "9:16", "1:1")
     * @param bool $silentVideo If true, appends "silent video" to prompt to avoid audio-related safety filters
     * @return array Operation details with operation name/id, or cached video details
     * @throws \RuntimeException
     */
    public function generateVideoFromImage(string $imagePath, string $prompt, ?string $aspectRatio = '16:9', bool $silentVideo = false): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'Gemini video service is not available. Please configure the Gemini API key in ' .
                'Stores > Configuration > Genaker > Image AI Resize > Gemini API Key, or set the GEMINI_API_KEY environment variable.'
            );
        }

        try {
            // Append "silent video" to prompt if requested (helps avoid audio-related safety filters)
            $finalPrompt = $prompt;
            if ($silentVideo) {
                $finalPrompt = trim($prompt) . ' silent video';
            }
            
            // Generate cache key from parameters (include silentVideo flag in cache key)
            $cacheKey = $this->generateCacheKey($imagePath, $finalPrompt, $aspectRatio);
            
            // Check if cached video exists
            $cachedVideo = $this->getCachedVideo($cacheKey);
            if ($cachedVideo !== null) {
                return $cachedVideo;
            }

            // Read image file
            if (!file_exists($imagePath)) {
                throw new \RuntimeException("Source image not found: {$imagePath}");
            }

            $imageContent = file_get_contents($imagePath);
            if ($imageContent === false) {
                throw new \RuntimeException("Failed to read image file: {$imagePath}");
            }

            // Detect MIME type
            $mimeType = $this->detectMimeType($imagePath, $imageContent);

            // Build API endpoint
            // Veo 3.1 uses predictLongRunning endpoint (as per Google REST API example)
            // For Google AI Studio API keys, use predictLongRunning method with x-goog-api-key header
            $submitUrl = "{$this->baseUrl}/models/{$this->model}:predictLongRunning";
            
            // Log the endpoint for debugging
            $this->logger->warning('Gemini video API request', [
                'url' => $submitUrl,
                'model' => $this->model,
                'baseUrl' => $this->baseUrl,
                'hasApiKey' => !empty($this->apiKey),
                'silentVideo' => $silentVideo,
                'originalPrompt' => $prompt,
                'finalPrompt' => $finalPrompt
            ]);

            // Build payload for Veo 3.1 API predictLongRunning endpoint
            // predictLongRunning expects 'instances' structure (as per Google REST API example)
            // Image field requires both bytesBase64Encoded and mimeType
            $payload = [
                'instances' => [
                    [
                        'prompt' => $finalPrompt,
                        'image' => [
                            'bytesBase64Encoded' => base64_encode($imageContent),
                            'mimeType' => $mimeType
                        ]
                    ]
                ],
                'parameters' => []
            ];

            // Add aspect ratio if specified (in parameters)
            if ($aspectRatio) {
                $payload['parameters']['aspectRatio'] = $aspectRatio;
            }

            // Submit video generation request
            // Note: Video generation is a heavy operation - ensure server max_execution_time > maxWaitSeconds
            // For predictLongRunning, API key must be in header (x-goog-api-key)
            $this->httpClient->setHeaders([
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey
            ]);
            $this->httpClient->setTimeout(30);
            $this->httpClient->post($submitUrl, json_encode($payload));

            $responseCode = $this->httpClient->getStatus();
            $responseBody = $this->httpClient->getBody();
            
            // Reset client for next request
            $this->httpClient->setHeaders([]);

            if ($responseCode !== 200) {
                $errorData = json_decode($responseBody, true);
                $errorMessage = 'Unknown error';
                
                // Try multiple ways to extract error message
                if (is_array($errorData)) {
                    if (isset($errorData['error']['message'])) {
                        $errorMessage = $errorData['error']['message'];
                    } elseif (isset($errorData['error']['status'])) {
                        $errorMessage = $errorData['error']['status'];
                        if (isset($errorData['error']['message'])) {
                            $errorMessage .= ': ' . $errorData['error']['message'];
                        }
                    } elseif (isset($errorData['error'])) {
                        $errorMessage = is_string($errorData['error']) ? $errorData['error'] : json_encode($errorData['error']);
                    } elseif (isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                    }
                } elseif (!empty($responseBody)) {
                    // Try to extract meaningful error from raw response
                    $errorMessage = substr($responseBody, 0, 500); // Show more of the response
                }
                
                // Log full response for debugging
                $this->logger->error('Gemini API error response', [
                    'status' => $responseCode,
                    'response' => $responseBody,
                    'endpoint' => $submitUrl,
                    'model' => $this->model,
                    'apiKey' => substr($this->apiKey, 0, 10) . '...', // Log partial key for debugging
                    'payload' => json_encode($payload) // Log payload for debugging
                ]);
                
                // Include response body in exception message for better debugging
                $fullError = "Gemini API error ({$responseCode}): {$errorMessage}";
                if (!empty($responseBody) && strlen($responseBody) < 1000) {
                    $fullError .= " | Response: " . $responseBody;
                } elseif (!empty($responseBody)) {
                    $fullError .= " | Response (first 500 chars): " . substr($responseBody, 0, 500);
                }
                
                throw new \RuntimeException($fullError);
            }

            $data = json_decode($responseBody, true);
            
            if (!isset($data['name'])) {
                throw new \RuntimeException('Invalid API response: operation name not found');
            }

            $operationName = $data['name'];

            // Return operation details for polling (include cache key for saving video)
            return [
                'operationName' => $operationName,
                'cacheKey' => $cacheKey,
                'done' => isset($data['done']) && $data['done'] === true,
                'status' => (isset($data['done']) && $data['done'] === true) ? 'completed' : 'running'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Gemini video generation failed', [
                'error' => $e->getMessage(),
                'image' => $imagePath,
                'prompt' => $prompt,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Gemini video generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Poll operation status and get video when ready
     * 
     * IMPORTANT: Ensure PHP max_execution_time > maxWaitSeconds (default 300s)
     * Video generation is a heavy operation that can take several minutes.
     *
     * @param string $operationName Operation name/ID
     * @param int $maxWaitSeconds Maximum time to wait in seconds (default 300 = 5 minutes)
     * @param int $pollIntervalSeconds Interval between polls in seconds (default 10)
     * @param string|null $cacheKey Cache key for saving video (optional, uses operation name if not provided)
     * @return array Video details with URL and embed code
     * @throws \RuntimeException
     */
    public function pollVideoOperation(string $operationName, int $maxWaitSeconds = 300, int $pollIntervalSeconds = 10, ?string $cacheKey = null): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Gemini video service is not available.');
        }

        $startTime = time();
        $pollUrl = "{$this->baseUrl}/{$operationName}";

        try {
            // Poll until operation is done or timeout
            while (true) {
                // Get operation status
                // Note: Polling timeout should be shorter than server max_execution_time
                // Default maxWaitSeconds is 300s (5 min), ensure PHP max_execution_time > 300
                // For predictLongRunning, API key must be in header (x-goog-api-key)
                $this->httpClient->setHeaders([
                    'x-goog-api-key' => $this->apiKey
                ]);
                $this->httpClient->setTimeout($pollIntervalSeconds + 5); // Slightly longer than poll interval
                $this->httpClient->get($pollUrl);

                $responseCode = $this->httpClient->getStatus();
                $responseBody = $this->httpClient->getBody();

                if ($responseCode !== 200) {
                    $errorData = json_decode($responseBody, true);
                    $errorMessage = 'Unknown error';
                    
                    // Try to extract error message from various response formats
                    if (is_array($errorData)) {
                        if (isset($errorData['error']['message'])) {
                            $errorMessage = $errorData['error']['message'];
                        } elseif (isset($errorData['error'])) {
                            $errorMessage = is_string($errorData['error']) ? $errorData['error'] : json_encode($errorData['error']);
                        } elseif (isset($errorData['message'])) {
                            $errorMessage = $errorData['message'];
                        }
                    } elseif (!empty($responseBody)) {
                        $errorMessage = substr($responseBody, 0, 500);
                    }
                    
                    // Log full error details
                    $this->logger->error('Gemini API polling error', [
                        'operationName' => $operationName,
                        'responseCode' => $responseCode,
                        'errorMessage' => $errorMessage,
                        'responseBody' => $responseBody
                    ]);
                    
                    throw new \RuntimeException("Gemini API error ({$responseCode}): {$errorMessage}");
                }

                $status = json_decode($responseBody, true);

                // Check if processing is finished
                if (isset($status['done']) && $status['done'] === true) {
                    if (isset($status['error'])) {
                        // Extract detailed error information
                        $error = $status['error'];
                        $errorMessage = $error['message'] ?? 'Unknown error';
                        $errorCode = $error['code'] ?? null;
                        $errorStatus = $error['status'] ?? null;
                        
                        // Log full error details for debugging
                        $this->logger->error('Gemini video generation error', [
                            'operationName' => $operationName,
                            'errorCode' => $errorCode,
                            'errorStatus' => $errorStatus,
                            'errorMessage' => $errorMessage,
                            'fullError' => $error,
                            'fullResponse' => $status
                        ]);
                        
                        // Build detailed error message with helpful guidance
                        $detailedError = "Video generation error: {$errorMessage}";
                        
                        // Add error code interpretation and actionable guidance
                        if ($errorCode) {
                            $detailedError .= " (Code: {$errorCode})";
                            
                            // Provide helpful guidance based on error code
                            switch ($errorCode) {
                                case 13: // INTERNAL
                                    $detailedError .= " - This is an internal server error from Gemini API. This is usually a temporary issue.";
                                    $detailedError .= " The video generation operation has failed and cannot be retried.";
                                    $detailedError .= " Please start a new video generation request with the same parameters.";
                                    $detailedError .= " If the problem persists, check Gemini API status or contact support.";
                                    break;
                                case 8: // RESOURCE_EXHAUSTED
                                    $detailedError .= " - API quota or rate limit exceeded.";
                                    $detailedError .= " Please wait before retrying or check your API quota in Google Cloud Console.";
                                    break;
                                case 3: // INVALID_ARGUMENT
                                    $detailedError .= " - Invalid request parameters.";
                                    $detailedError .= " Please check: 1) Image format is supported (JPEG, PNG, WebP), 2) Prompt is valid, 3) Aspect ratio is correct (e.g., 16:9, 9:16, 1:1).";
                                    break;
                                case 4: // DEADLINE_EXCEEDED
                                    $detailedError .= " - Request timed out.";
                                    $detailedError .= " The video generation took too long. Try again with a simpler prompt or smaller image.";
                                    break;
                                default:
                                    $detailedError .= " - Please check the error details and try again.";
                            }
                        }
                        
                        if ($errorStatus) {
                            $detailedError .= " (Status: {$errorStatus})";
                        }
                        
                        // Log operation failure for monitoring
                        $this->logger->warning('Video generation operation failed', [
                            'operationName' => $operationName,
                            'errorCode' => $errorCode,
                            'errorMessage' => $errorMessage,
                            'suggestion' => 'Operation cannot be retried. User must start a new video generation request.'
                        ]);
                        
                        throw new \RuntimeException($detailedError);
                    }

                    // Extract video from response
                    $video = $this->extractVideoFromResponse($status);

                    // Use cache key if provided, otherwise use operation name
                    $saveKey = $cacheKey ?? $operationName;

                    // Always save video file locally to pub/media/video
                    $videoPath = $this->saveVideo($video, $saveKey);
                    
                    // Generate URLs from saved file
                    $videoUrl = $this->getVideoUrl($videoPath);
                    $embedUrl = $this->getEmbedUrl($videoUrl);
                    
                    return [
                        'videoUrl' => $videoUrl,
                        'embedUrl' => $embedUrl,
                        'videoPath' => $videoPath,
                        'status' => 'completed',
                        'cached' => true
                    ];
                }

                // Check timeout
                if ((time() - $startTime) > $maxWaitSeconds) {
                    throw new \RuntimeException("Video generation timeout after {$maxWaitSeconds} seconds");
                }

                // Wait before next poll
                sleep($pollIntervalSeconds);
            }

        } catch (\Exception $e) {
            $this->logger->error('Video operation polling failed', [
                'error' => $e->getMessage(),
                'operationName' => $operationName,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Video operation polling failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract video data from API response
     * predictLongRunning returns video in: response.generateVideoResponse.generatedSamples[0].video.uri
     *
     * @param array $response API response data
     * @return mixed Video data (URL or object)
     * @throws \RuntimeException
     */
    private function extractVideoFromResponse(array $response)
    {
        // Check for operation response format (async operation)
        if (isset($response['name']) && isset($response['done']) && $response['done'] === true) {
            // Operation completed - check response field
            if (isset($response['response'])) {
                return $this->extractVideoFromResponse($response['response']);
            }
        }
        
        // Check for Safety/RAI Filters first (Responsible AI filters)
        // This happens when Google's safety filters block the video generation
        if (isset($response['generateVideoResponse']['raiMediaFilteredReasons'])) {
            $reasons = $response['generateVideoResponse']['raiMediaFilteredReasons'];
            $filteredCount = $response['generateVideoResponse']['raiMediaFilteredCount'] ?? count($reasons);
            
            // Build helpful error message
            $errorMessage = "Video generation was blocked by safety filters. ";
            if (is_array($reasons) && !empty($reasons)) {
                $errorMessage .= "Reason(s): " . implode(' ', $reasons);
            } else {
                $errorMessage .= "The content may violate safety guidelines.";
            }
            
            $errorMessage .= " Suggestions: ";
            $errorMessage .= "1) Simplify your prompt (remove brand names, celebrities, or copyrighted content), ";
            $errorMessage .= "2) If audio is the issue, retry with parameter 'silentVideo=true' or add 'silent video' to your prompt, ";
            $errorMessage .= "3) Check that your image doesn't contain restricted content. ";
            $errorMessage .= "You have not been charged for this attempt.";
            
            // Log safety filter block
            $this->logger->warning('Video generation blocked by safety filters', [
                'raiMediaFilteredReasons' => $reasons,
                'raiMediaFilteredCount' => $filteredCount,
                'response' => $response
            ]);
            
            throw new \RuntimeException($errorMessage);
        }
        
        // Check for predictLongRunning response format: response.generateVideoResponse.generatedSamples[0].video.uri
        if (isset($response['generateVideoResponse']['generatedSamples']) && !empty($response['generateVideoResponse']['generatedSamples'])) {
            $sample = $response['generateVideoResponse']['generatedSamples'][0];
            if (isset($sample['video']['uri'])) {
                return ['uri' => $sample['video']['uri']];
            }
            if (isset($sample['video']['bytesBase64Encoded'])) {
                return ['data' => $sample['video']['bytesBase64Encoded']];
            }
        }
        
        // Check for direct response format: response.candidates[0].content.parts[0] (Google AI Studio)
        if (isset($response['candidates']) && !empty($response['candidates'])) {
            $candidate = $response['candidates'][0];
            if (isset($candidate['content']['parts']) && !empty($candidate['content']['parts'])) {
                foreach ($candidate['content']['parts'] as $part) {
                    if (isset($part['inlineData']['data'])) {
                        return ['data' => $part['inlineData']['data']];
                    }
                    if (isset($part['fileData']['fileUri'])) {
                        return ['uri' => $part['fileData']['fileUri']];
                    }
                }
            }
        }
        
        // Check for predictions format (Vertex AI style - fallback)
        if (isset($response['predictions']) && !empty($response['predictions'])) {
            $prediction = $response['predictions'][0];
            if (isset($prediction['uri'])) {
                return ['uri' => $prediction['uri']];
            }
            if (isset($prediction['bytesBase64Encoded'])) {
                return ['data' => $prediction['bytesBase64Encoded']];
            }
        }
        
        // Check for generatedVideos format (alternative format)
        if (isset($response['generatedVideos']) && !empty($response['generatedVideos'])) {
            $generatedVideo = $response['generatedVideos'][0];
            if (isset($generatedVideo['video']['uri'])) {
                return ['uri' => $generatedVideo['video']['uri']];
            } elseif (isset($generatedVideo['uri'])) {
                return ['uri' => $generatedVideo['uri']];
            }
        }
        
        // Check if generateVideoResponse exists but has no samples (might be filtered or empty)
        if (isset($response['generateVideoResponse'])) {
            $generateVideoResponse = $response['generateVideoResponse'];
            
            // Check if it was filtered (should have been caught above, but double-check)
            if (isset($generateVideoResponse['raiMediaFilteredReasons'])) {
                $reasons = $generateVideoResponse['raiMediaFilteredReasons'];
                throw new \RuntimeException("Video generation was blocked by safety filters: " . implode(' ', $reasons));
            }
            
            // If generateVideoResponse exists but no samples, provide helpful error
            if (empty($generateVideoResponse['generatedSamples'])) {
                $this->logger->error('Video generation completed but no samples generated', [
                    'generateVideoResponse' => $generateVideoResponse,
                    'response_keys' => array_keys($response)
                ]);
                
                throw new \RuntimeException(
                    'Video generation completed but no video was generated. ' .
                    'This may be due to safety filters or processing issues. ' .
                    'Please try modifying your prompt or image and try again.'
                );
            }
        }
        
        // Log response structure for debugging
        $this->logger->error('Unexpected API response structure', [
            'response_keys' => array_keys($response),
            'response' => json_encode($response)
        ]);
        
        throw new \RuntimeException('No video found in API response. Response structure: ' . json_encode($response));
    }

    /**
     * Save video file to media directory
     *
     * @param mixed $video Video data (URL or binary)
     * @param string $key Cache key or operation name for filename
     * @return string Saved video file path
     * @throws \RuntimeException
     */
    private function saveVideo($video, string $key): string
    {
        try {
            // Create video directory in media
            $videoDir = BP . '/pub/media/video/';
            if (!is_dir($videoDir)) {
                $this->filesystem->createDirectory($videoDir, 0755);
            }

            // Generate filename from cache key
            $filename = 'veo_' . md5($key) . '.mp4';
            $videoPath = $videoDir . $filename;

            // Handle video data (could be URL or binary)
            if (is_string($video) && filter_var($video, FILTER_VALIDATE_URL)) {
                // Download from URL - append API key if Google API URI
                $downloadUri = $this->prepareAuthenticatedUri($video);
                $videoContent = $this->downloadVideoWithRedirects($downloadUri);
                $this->filesystem->filePutContents($videoPath, $videoContent);
            } elseif (is_string($video)) {
                // Binary data (base64 encoded)
                $decoded = base64_decode($video, true);
                if ($decoded === false) {
                    throw new \RuntimeException('Failed to decode base64 video data');
                }
                $this->filesystem->filePutContents($videoPath, $decoded);
            } elseif (is_array($video)) {
                // Video object with URI or base64 data
                if (isset($video['uri']) && is_string($video['uri']) && filter_var($video['uri'], FILTER_VALIDATE_URL)) {
                    // Download from URI - append API key if Google API URI
                    $downloadUri = $this->prepareAuthenticatedUri($video['uri']);
                    $videoContent = $this->downloadVideoWithRedirects($downloadUri);
                    $this->filesystem->filePutContents($videoPath, $videoContent);
                } elseif (isset($video['data']) && is_string($video['data'])) {
                    // Base64 encoded video data
                    $videoContent = base64_decode($video['data'], true);
                    if ($videoContent === false) {
                        throw new \RuntimeException("Failed to decode base64 video data");
                    }
                    $this->filesystem->filePutContents($videoPath, $videoContent);
                } else {
                    throw new \RuntimeException('Unsupported video data format. Video structure: ' . json_encode($video));
                }
            } else {
                throw new \RuntimeException('Unsupported video data format. Expected string URL or array with uri/data, got: ' . gettype($video));
            }

            return $videoPath;

        } catch (\Exception $e) {
            $this->logger->error('Failed to save video', [
                'error' => $e->getMessage(),
                'key' => $key,
                'trace' => $e->getTraceAsString()
            ]);
            throw new \RuntimeException('Failed to save video: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Download video with redirect handling
     * Handles 302 redirects that Google API may return for video downloads
     * 
     * Magento's HTTP Client Curl doesn't follow redirects by default.
     * We use native PHP cURL with CURLOPT_FOLLOWLOCATION enabled to handle
     * Google's redirects to temporary GCS signed URLs.
     *
     * @param string $uri Video download URI
     * @return string Video content
     * @throws \RuntimeException
     */
    private function downloadVideoWithRedirects(string $uri): string
    {
        // Use native cURL with redirect following enabled
        // Magento's HTTP Client Curl doesn't support CURLOPT_FOLLOWLOCATION by default
        $ch = curl_init($uri);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true, // Enable redirect following (required for Google API 302 responses)
            CURLOPT_MAXREDIRS => 5, // Maximum number of redirects to follow
            CURLOPT_TIMEOUT => 300, // 5 minutes timeout for large video files
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' . $this->apiKey
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $videoContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // Get final URL after redirects
        curl_close($ch);
        
        if ($videoContent === false || !empty($error)) {
            $this->logger->error('Video download failed', [
                'uri' => $uri,
                'finalUrl' => $finalUrl,
                'error' => $error,
                'httpCode' => $httpCode
            ]);
            throw new \RuntimeException("Failed to download video from URI: {$uri}. Error: {$error}");
        }
        
        if ($httpCode !== 200) {
            $this->logger->error('Video download returned non-200 status', [
                'uri' => $uri,
                'finalUrl' => $finalUrl,
                'httpCode' => $httpCode
            ]);
            throw new \RuntimeException("Failed to download video from URI: {$uri} (Status: {$httpCode})");
        }
        
        // Log successful download (with redirect info if applicable)
        if ($uri !== $finalUrl) {
            $this->logger->warning('Video download followed redirect', [
                'originalUri' => $uri,
                'finalUrl' => $finalUrl
            ]);
        }
        
        return $videoContent;
    }

    /**
     * Prepare authenticated URI for Google API downloads
     * Appends API key to query string if URI is a Google API endpoint
     *
     * @param string $uri Original URI
     * @return string URI with API key appended if needed
     */
    private function prepareAuthenticatedUri(string $uri): string
    {
        // Check if the URI is a Google API link and append the key if missing
        if (strpos($uri, 'generativelanguage.googleapis.com') !== false && 
            strpos($uri, 'key=') === false) {
            
            $originalUri = $uri;
            $separator = (strpos($uri, '?') !== false) ? '&' : '?';
            $uri .= $separator . 'key=' . urlencode($this->apiKey);
            
            $this->logger->warning('Added API key to Google API download URI', [
                'original_uri' => $originalUri,
                'has_api_key' => !empty($this->apiKey)
            ]);
        }
        
        return $uri;
    }

    /**
     * Get public URL for video
     *
     * @param string $videoPath Full path to video file
     * @return string Public URL
     */
    private function getVideoUrl(string $videoPath): string
    {
        // Extract relative path from pub/media
        $relativePath = str_replace(BP . '/pub/media/', '', $videoPath);
        $baseUrl = rtrim($this->getBaseUrl(), '/');
        
        // Remove store code from base URL (e.g., /default/) to get clean base URL
        // Media URLs should not include store code - they're accessible directly
        // Base URL might be: https://app.lc.test/default/ -> https://app.lc.test/
        $parsedUrl = parse_url($baseUrl);
        $scheme = $parsedUrl['scheme'] ?? 'http';
        $host = $parsedUrl['host'] ?? '';
        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        
        // Build clean base URL without store code
        $cleanBaseUrl = $scheme . '://' . $host . $port . '/';
        
        return $cleanBaseUrl . 'media/' . $relativePath;
    }

    /**
     * Get HTML embed code for video
     *
     * @param string $videoUrl Video URL
     * @return string HTML embed code
     */
    private function getEmbedUrl(string $videoUrl): string
    {
        return '<video controls width="100%" height="auto"><source src="' . htmlspecialchars($videoUrl) . '" type="video/mp4">Your browser does not support the video tag.</video>';
    }

    /**
     * Get base URL
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            return $storeManager->getStore()->getBaseUrl();
        } catch (\Exception $e) {
            // Fallback to REQUEST_URI
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $protocol . $host;
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
            try {
                $encryptor = \Magento\Framework\App\ObjectManager::getInstance()
                    ->get(\Magento\Framework\Encryption\EncryptorInterface::class);
                $apiKey = $encryptor->decrypt($apiKey);
            } catch (\Exception $e) {
                // Ignore decryption errors
            }
        }

        // Fallback to environment variable
        if (empty($apiKey)) {
            $apiKey = getenv('GEMINI_API_KEY') ?: '';
        }

        return $apiKey;
    }

    /**
     * Detect MIME type from file path and content
     *
     * @param string $filePath
     * @param string $content
     * @return string
     */
    private function detectMimeType(string $filePath, string $content): string
    {
        // Try finfo first
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $content);
            finfo_close($finfo);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Fallback to extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        return $mimeTypes[$extension] ?? 'image/jpeg';
    }

    /**
     * Generate cache key from video generation parameters
     *
     * @param string $imagePath Path to source image
     * @param string $prompt Video generation prompt
     * @param string|null $aspectRatio Aspect ratio
     * @return string Cache key (MD5 hash)
     */
    private function generateCacheKey(string $imagePath, string $prompt, ?string $aspectRatio = '16:9'): string
    {
        // Normalize image path (remove pub/media prefix if present, use relative path)
        $normalizedPath = $imagePath;
        if (strpos($imagePath, BP . '/pub/media/') === 0) {
            $normalizedPath = str_replace(BP . '/pub/media/', '', $imagePath);
        } elseif (strpos($imagePath, '/pub/media/') === 0) {
            $normalizedPath = str_replace('/pub/media/', '', $imagePath);
        }
        
        // Create cache key from normalized parameters
        $cacheString = $normalizedPath . '|' . trim($prompt) . '|' . ($aspectRatio ?? '16:9');
        return md5($cacheString);
    }

    /**
     * Get cached video if it exists
     *
     * @param string $cacheKey Cache key
     * @return array|null Video details if cached, null if not found
     */
    private function getCachedVideo(string $cacheKey): ?array
    {
        try {
            $videoDir = BP . '/pub/media/video/';
            $filename = 'veo_' . md5($cacheKey) . '.mp4';
            $videoPath = $videoDir . $filename;

            // Check if cached video file exists
            if (file_exists($videoPath) && is_file($videoPath)) {
                // Generate URLs from cached file
                $videoUrl = $this->getVideoUrl($videoPath);
                $embedUrl = $this->getEmbedUrl($videoUrl);

                return [
                    'videoUrl' => $videoUrl,
                    'embedUrl' => $embedUrl,
                    'videoPath' => $videoPath,
                    'status' => 'completed',
                    'cached' => true,
                    'fromCache' => true
                ];
            }
        } catch (\Exception $e) {
            // Log error but don't fail - just proceed with generation
            $this->logger->warning('Error checking video cache', [
                'error' => $e->getMessage(),
                'cacheKey' => $cacheKey
            ]);
        }

        return null;
    }

}
