<?php
/**
 * Genaker ImageAIBundle
 *
 * @category    Genaker
 * @package     Genaker_ImageAIBundle
 * @author      Genaker
 * @copyright   Copyright (c) 2024 Genaker
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Genaker_ImageAIBundle',
    __DIR__
);

// Intercept resize/ paths early in bootstrap to prevent Media Storage app from handling them
// Check REQUEST_URI directly using raw PHP (no Magento dependencies)
// Only run in web context (not CLI)
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/media/resize/ip/') === 0) {
    // Extract the image path part (everything after /media/resize/ip/)
    // Format: /media/resize/ip/catalog/product/image.jpg -> catalog/product/image.jpg
    $requestUri = $_SERVER['REQUEST_URI'];
    $pathPrefix = '/media/resize/ip/';
    $imagePath = substr($requestUri, strlen($pathPrefix));
    
    // Remove query string from image path if present
    $queryPos = strpos($imagePath, '?');
    if ($queryPos !== false) {
        $imagePath = substr($imagePath, 0, $queryPos);
    }
    
    if ($imagePath) {
        // Construct REQUEST_URI with image path as parameter: /media/resize/ip?ip=catalog/product/image.jpg
        $queryString = 'ip=' . urlencode($imagePath);
        if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
            $queryString .= '&' . $_SERVER['QUERY_STRING'];
        }
        
        // Update server variables for proper routing
        // These modifications will be picked up by Magento's router during normal bootstrap
        $_SERVER['REQUEST_URI'] = '/media/resize/ip?' . $queryString;
        $_SERVER['PATH_INFO'] = '/media/resize/ip';
        $_SERVER['QUERY_STRING'] = $queryString;
        
        // Set GET parameter
        $_GET['ip'] = $imagePath;
        
        // Merge existing query params
        if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING']) {
            parse_str($_SERVER['QUERY_STRING'], $existingParams);
            $_GET = array_merge($_GET, $existingParams);
        }
        
        // Note: We don't restart execution here because registration.php runs during bootstrap
        // The modified REQUEST_URI will be picked up by Magento's router automatically
        // For requests that go through pub/get.php, the check there will handle the restart
    }
}

