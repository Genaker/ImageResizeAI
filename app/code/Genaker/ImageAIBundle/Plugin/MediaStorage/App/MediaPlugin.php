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
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\Action\Context;

/**
 * Plugin to route /media/resize/ URLs to our controller
 */
class MediaPlugin
{

    /**
     * Intercept Media application launch and route /resize/ URLs to our controller
     *
     * @param Media $subject
     * @param callable $proceed
     * @return ResponseInterface
     */
    public function aroundLaunch(Media $subject, callable $proceed): ResponseInterface
    {
        // Get relative file name from REQUEST_URI (works for both base64 and regular URLs)
        $relativeFileName = $_SERVER['REQUEST_URI'] ?? '';
        
        // Check if this is a /media/resize/ URL (base64 or regular format)
        if ($relativeFileName && (strpos($relativeFileName, '/media/resize/') === 0 || strpos($relativeFileName, 'media/resize/') === 0)) {
            // Parse the full URL to get path and query
            $parsedUrl = parse_url($relativeFileName);
            $pathOnly = $parsedUrl['path'] ?? $relativeFileName;
            $queryString = $parsedUrl['query'] ?? '';
            
            // Extract the path after /media/resize/
            $resizePath = str_replace(['/media/resize/', 'media/resize/'], '', $pathOnly);
            
            // Check if it's base64 format: {base64}.{extension}
            $isBase64 = preg_match('/^([A-Za-z0-9_-]+)\.([a-z]+)$/i', basename($resizePath));
            
            // Route both base64 and regular formats to our controller
            // The controller handles both formats internally
            // Get object manager
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            
            // Use direct controller instantiation (more reliable for base64 URLs)
            try {
                // Build the pathInfo that the controller expects
                // For base64 format: /media/resize/{base64}.{ext}
                // For regular format: /media/resize/ip/{path}?params
                $originalPathInfo = '/media/resize/' . $resizePath;
                if ($queryString) {
                    $originalPathInfo .= '?' . $queryString;
                }
                
                // Create request with correct pathInfo
                $request = $objectManager->create(\Magento\Framework\App\Request\Http::class);
                $request->setPathInfo($originalPathInfo);
                $request->setRequestUri($originalPathInfo);
                
                // Set query parameters if present
                if ($queryString) {
                    parse_str($queryString, $queryParams);
                    foreach ($queryParams as $key => $value) {
                        $request->setParam($key, $value);
                    }
                }
                
                // Create context with the request
                $context = $objectManager->create(
                    \Magento\Framework\App\Action\Context::class,
                    [
                        'request' => $request,
                        'response' => $objectManager->get(\Magento\Framework\App\ResponseInterface::class),
                        'resultFactory' => $objectManager->get(\Magento\Framework\Controller\ResultFactory::class),
                    ]
                );
                
                // Create controller instance with context
                $controller = $objectManager->create(
                    \Genaker\ImageAIBundle\Controller\Resize\Index::class,
                    ['context' => $context]
                );
                
                // Execute controller
                $result = $controller->execute();
                
                // Handle result
                if ($result instanceof ResponseInterface) {
                    return $result;
                }
                
                if (method_exists($result, 'renderResult')) {
                    $response = $objectManager->get(\Magento\Framework\App\ResponseInterface::class);
                    $result->renderResult($response);
                    return $response;
                }
            } catch (\Exception $e) {
                // Fallback: try HTTP application routing
                try {
                    // Build the controller URL for routing
                    $controllerUrl = '/index.php/media/resize/index/index';
                    if ($resizePath) {
                        $controllerUrl .= '/' . $resizePath;
                    }
                    if ($queryString) {
                        $controllerUrl .= '?' . $queryString;
                    }
                    
                    // Create a new bootstrap and run the controller
                    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
                    $objectManager = $bootstrap->getObjectManager();
                    
                    // Create HTTP application to handle the request
                    $request = $objectManager->create(\Magento\Framework\App\Request\Http::class);
                    $request->setRequestUri($controllerUrl);
                    $request->setPathInfo('/media/resize/' . $resizePath . ($queryString ? '?' . $queryString : ''));
                    
                    // Create HTTP application
                    $httpApp = $objectManager->create(\Magento\Framework\App\Http::class);
                    $response = $httpApp->launch();
                    
                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }
                } catch (\Exception $e2) {
                    // Log both errors
                    try {
                        $logger = $objectManager->get(\Psr\Log\LoggerInterface::class);
                        $logger->error('Failed to route resize request (direct): ' . $e->getMessage());
                        $logger->error('Failed to route resize request (HTTP app): ' . $e2->getMessage());
                    } catch (\Exception $logError) {
                        // Ignore logging errors
                    }
                }
            }
        }
        
        return $proceed();
    }
}
