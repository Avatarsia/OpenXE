<?php
/**
 * Theme Cache - CSS caching and minification
 * Combines and caches theme CSS files for performance
 */

class ThemeCache {
    private $app;
    private $cacheDir = 'cache/themes/';
    private $themesDir = 'themes/';
    
    public function __construct(&$app) {
        $this->app = $app;
        
        // Ensure cache directory exists
        if (!is_dir(__DIR__ . '/../' . $this->cacheDir)) {
            mkdir(__DIR__ . '/../' . $this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get cached CSS for a theme
     * @param string $themeName Theme name
     * @param string $customCSS Optional custom CSS to append
     * @return string CSS content
     */
    public function get($themeName, $customCSS = '') {
        $cacheKey = $this->getCacheKey($themeName, $customCSS);
        $cacheFile = __DIR__ . '/../' . $this->cacheDir . $cacheKey . '.css';
        $sourceDir = __DIR__ . '/../' . $this->themesDir . $themeName;
        
        // Check if cache is valid
        if ($this->isCacheValid($cacheFile, $sourceDir)) {
            return file_get_contents($cacheFile);
        }
        
        // Rebuild cache
        return $this->rebuild($themeName, $customCSS, $cacheFile);
    }
    
    /**
     * Clear cache for a specific theme or all themes
     * @param string $themeName Optional specific theme name
     */
    public function clear($themeName = null) {
        $cacheDir = __DIR__ . '/../' . $this->cacheDir;
        
        if ($themeName) {
            // Clear specific theme
            $pattern = $cacheDir . $themeName . '_*.css';
            foreach (glob($pattern) as $file) {
                unlink($file);
            }
        } else {
            // Clear all
            foreach (glob($cacheDir . '*.css') as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Check if cache file is still valid
     */
    private function isCacheValid($cacheFile, $sourceDir) {
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $cacheTime = filemtime($cacheFile);
        
        // Check if any CSS file in theme is newer than cache
        $cssFiles = glob($sourceDir . '/css/*.css');
        foreach ($cssFiles as $cssFile) {
            if (filemtime($cssFile) > $cacheTime) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Rebuild CSS cache
     */
    private function rebuild($themeName, $customCSS, $cacheFile) {
        $sourceDir = __DIR__ . '/../' . $this->themesDir . $themeName;
        $combinedCSS = '';
        
        // Combine all CSS files
        $cssFiles = glob($sourceDir . '/css/*.css');
        foreach ($cssFiles as $cssFile) {
            $combinedCSS .= file_get_contents($cssFile) . "\n\n";
        }
        
        // Add custom CSS if provided
        if (!empty($customCSS)) {
            $combinedCSS .= "\n/* Custom CSS */\n" . $customCSS;
        }
        
        // Minify CSS (basic)
        $minified = $this->minifyCSS($combinedCSS);
        
        // Save to cache
        file_put_contents($cacheFile, $minified);
        
        return $minified;
    }
    
    /**
     * Basic CSS minification
     */
    private function minifyCSS($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);
        
        // Remove spaces around selectors and braces
        $css = preg_replace('/\s*([{}|:;,])\s*/', '$1', $css);
        
        return $css;
    }
    
    /**
     * Generate cache key
     */
    private function getCacheKey($themeName, $customCSS) {
        $hash = md5($themeName . $customCSS);
        return $themeName . '_' . substr($hash, 0, 8);
    }
}
