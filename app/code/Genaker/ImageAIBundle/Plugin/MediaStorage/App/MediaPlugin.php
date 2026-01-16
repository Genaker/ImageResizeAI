<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

namespace Genaker\ImageAIBundle\Plugin\MediaStorage\App;

use Magento\MediaStorage\App\Media;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;

/**
 * Plugin to intercept resize paths and route them through standard router
 */
class MediaPlugin
{
    /**
     * Intercept launch method to check for resize paths
     *
     * @param Media $subject
     * @param callable $proceed
     * @return ResponseInterface
     * @throws \Exception
     */
    public function aroundLaunch(Media $subject, callable $proceed): ResponseInterface
    {
        // Get relativeFileName from Media app
        $relativePath = null;
        try {
            $reflection = new \ReflectionClass(get_parent_class($subject) ?: Media::class);
            $property = $reflection->getProperty('relativeFileName');
            $property->setAccessible(true);
            $relativePath = $property->getValue($subject);
        } catch (\Exception $e) {
            // Reflection failed
        }
        
        // Get current REQUEST_URI - check if it's already been processed (has ip parameter)
        $currentRequestUri = $_SERVER['REQUEST_URI'] ?? '';
        $hasIpParam = isset($_GET['ip']) || (strpos($currentRequestUri, 'ip=') !== false);
        
        // If relativePath is just "media/resize/ip" and we already have ip param, 
        // this is a second call after bootstrap - let HTTP app handle it
        if ($relativePath === 'media/resize/ip' && $hasIpParam) {
            // This is the second call after bootstrap - route to HTTP app
            try {
                $bootstrap = Bootstrap::create(BP, $_SERVER);
                /** @var Http $app */
                $app = $bootstrap->createApplication(Http::class);
                $response = $bootstrap->run($app);
                
                // Ensure we always return a ResponseInterface
                if ($response instanceof ResponseInterface) {
                    return $response;
                }
                
                // Fallback: proceed with normal flow if response is invalid
                return $proceed();
            } catch (\Exception $e) {
                // If bootstrap fails, proceed with normal media handling
                return $proceed();
            }
        }
        
        // Check if this is a resize or video generation request
        // Check query string for video parameter first (video requests should be intercepted)
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        $isVideoRequest = isset($_GET['video']) || (!empty($queryString) && strpos($queryString, 'video=') !== false);
        
        // Check if this is an original resize path request
        // relativePath from get.php for /media/resize/ip/image.jpg would be "resize/ip/image.jpg"
        $isResizePath = false;
        $imagePath = null;
        
        if ($relativePath && strpos($relativePath, 'resize/ip/') === 0) {
            // Extract image path - everything after "resize/ip/"
            $imagePath = substr($relativePath, strlen('resize/ip/'));
            $isResizePath = true;
        } elseif ($currentRequestUri && strpos($currentRequestUri, '/media/resize/ip/') === 0 && !$hasIpParam) {
            // Fallback: check REQUEST_URI if relativePath check failed and not already processed
            $imagePath = substr($currentRequestUri, strlen('/media/resize/ip/'));
            // Remove query string if present
            $queryPos = strpos($imagePath, '?');
            if ($queryPos !== false) {
                $imagePath = substr($imagePath, 0, $queryPos);
            }
            $isResizePath = true;
        }
        
        // If it's a video request, always intercept (don't let Media app handle it)
        if ($isVideoRequest && $isResizePath && $imagePath) {
            // Video generation request - route to controller
            $queryString = 'ip=' . urlencode($imagePath);
            if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
                $queryString .= '&' . $_SERVER['QUERY_STRING'];
            }
            
            $_SERVER['REQUEST_URI'] = '/media/resize/ip?' . $queryString;
            $_SERVER['PATH_INFO'] = '/media/resize/ip';
            $_SERVER['QUERY_STRING'] = $queryString;
            $_GET['ip'] = $imagePath;
            
            if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
                parse_str($_SERVER['QUERY_STRING'], $existingParams);
                $_GET = array_merge($_GET, $existingParams);
            }
            
            try {
                $bootstrap = Bootstrap::create(BP, $_SERVER);
                /** @var Http $app */
                $app = $bootstrap->createApplication(Http::class);
                $response = $bootstrap->run($app);
                
                if ($response instanceof ResponseInterface) {
                    return $response;
                }
                return $proceed();
            } catch (\Exception $e) {
                return $proceed();
            }
        }
        
        if ($isResizePath && $imagePath) {
            // Construct query string
            $queryString = 'ip=' . urlencode($imagePath);
            if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
                $queryString .= '&' . $_SERVER['QUERY_STRING'];
            }
            
            // Update server variables for routing
            $_SERVER['REQUEST_URI'] = '/media/resize/ip?' . $queryString;
            $_SERVER['PATH_INFO'] = '/media/resize/ip';
            $_SERVER['QUERY_STRING'] = $queryString;
            $_GET['ip'] = $imagePath;
            
            // Merge existing query params
            if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
                parse_str($_SERVER['QUERY_STRING'], $existingParams);
                $_GET = array_merge($_GET, $existingParams);
            }
            
            // Restart execution by bootstrapping standard HTTP app
            try {
                $bootstrap = Bootstrap::create(BP, $_SERVER);
                /** @var Http $app */
                $app = $bootstrap->createApplication(Http::class);
                $response = $bootstrap->run($app);
                
                // Ensure we always return a ResponseInterface
                if ($response instanceof ResponseInterface) {
                    return $response;
                }
                
                // If bootstrap didn't return ResponseInterface, proceed with normal flow
                return $proceed();
            } catch (\Exception $e) {
                // If bootstrap fails, proceed with normal media handling
                return $proceed();
            }
        }

        // Not a resize path, proceed with normal media handling
        return $proceed();
    }
}
