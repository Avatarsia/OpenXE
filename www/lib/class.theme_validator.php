<?php
/**
 * Theme Validator - Security validation for uploaded themes
 * Checks ZIP structure, scans for malicious code, validates metadata
 */

class ThemeValidator {
    private $app;
    private $allowedExtensions = ['css', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'tpl'];
    private $maxFileSize = 10485760; // 10MB
    private $maxFiles = 500;
    
    public function __construct(&$app) {
        $this->app = $app;
    }
    
    /**
     * Validate uploaded theme ZIP
     * @param string $zipPath Path to ZIP file
     * @return array ['valid' => bool, 'error' => string, 'theme_name' => string]
     */
    public function validate($zipPath) {
        $result = ['valid' => false, 'error' => '', 'theme_name' => ''];
        
        // 1. Check ZIP exists and is readable
        if (!file_exists($zipPath) || !is_readable($zipPath)) {
            $result['error'] = 'ZIP file not found or not readable';
            return $result;
        }
        
        // 2. Check file size
        if (filesize($zipPath) > $this->maxFileSize) {
            $result['error'] = 'ZIP file too large (max 10MB)';
            return $result;
        }
        
        // 3. Open ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== TRUE) {
            $result['error'] = 'Invalid ZIP file';
            return $result;
        }
        
        // 4. Check number of files
        if ($zip->numFiles > $this->maxFiles) {
            $zip->close();
            $result['error'] = 'Too many files in ZIP (max 500)';
            return $result;
        }
        
        // 5. Validate structure
        $structureCheck = $this->validateStructure($zip);
        if (!$structureCheck['valid']) {
            $zip->close();
            $result['error'] = $structureCheck['error'];
            return $result;
        }
        
        $result['theme_name'] = $structureCheck['theme_name'];
        
        // 6. Scan for malicious code
        $securityCheck = $this->scanForMaliciousCode($zip);
        if (!$securityCheck['valid']) {
            $zip->close();
            $result['error'] = 'Security check failed: ' . $securityCheck['error'];
            return $result;
        }
        
        // 7. Validate theme.json
        $metadataCheck = $this->validateMetadata($zip);
        if (!$metadataCheck['valid']) {
            $zip->close();
            $result['error'] = 'Invalid theme.json: ' . $metadataCheck['error'];
            return $result;
        }
        
        $zip->close();
        
        $result['valid'] = true;
        return $result;
    }
    
    /**
     * Check if ZIP has required structure (theme.json, css/, images/, templates/)
     */
    private function validateStructure($zip) {
        $result = ['valid' => false, 'error' => '', 'theme_name' => ''];
        
        $hasThemeJson = false;
        $themeName = '';
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // Extract theme name from path
            if (empty($themeName)) {
                $parts = explode('/', $filename);
                if (count($parts) > 0 && !empty($parts[0])) {
                    $themeName = $parts[0];
                }
            }
            
            // Check for theme.json
            if (preg_match('#/theme\.json$#', $filename) || $filename === 'theme.json') {
                $hasThemeJson = true;
            }
        }
        
        if (!$hasThemeJson) {
            $result['error'] = 'Missing theme.json file';
            return $result;
        }
        
        if (empty($themeName)) {
            $result['error'] = 'Could not determine theme name';
            return $result;
        }
        
        // Validate theme name
        if (!preg_match('/^[a-z0-9_]+$/i', $themeName)) {
            $result['error'] = 'Invalid theme name (only alphanumeric and underscore allowed)';
            return $result;
        }
        
        $result['valid'] = true;
        $result['theme_name'] = $themeName;
        return $result;
    }
    
    /**
     * Scan ZIP contents for malicious code
     */
    private function scanForMaliciousCode($zip) {
        $result = ['valid' => true, 'error' => ''];
        
        // Dangerous patterns to check for
        $dangerousPatterns = [
            '/<\?php/i',
            '/eval\s*\(/i',
            '/base64_decode\s*\(/i',
            '/exec\s*\(/i',
            '/system\s*\(/i',
            '/passthru\s*\(/i',
            '/shell_exec\s*\(/i',
            '/`[^`]+`/i', // Backtick execution
            '/<script[^>]*>/i',
            '/on\w+\s*=\s*["\']/', // Event handlers
        ];
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // Skip directories
            if (substr($filename, -1) === '/') continue;
            
            // Check file extension
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowedExtensions) && $ext !== '') {
                $result['valid'] = false;
                $result['error'] = "Forbidden file type: .$ext in $filename";
                return $result;
            }
            
            // Get file contents
            $content = $zip->getFromIndex($i);
            if ($content === false) continue;
            
            // Skip binary files (images)
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'])) continue;
            
            // Check for dangerous patterns
            foreach ($dangerousPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $result['valid'] = false;
                    $result['error'] = "Suspicious code detected in $filename";
                    return $result;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Validate theme.json schema
     */
    private function validateMetadata($zip) {
        $result = ['valid' => false, 'error' => ''];
        
        // Find and read theme.json
        $themeJson = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('#/theme\.json$#', $filename) || $filename === 'theme.json') {
                $themeJson = $zip->getFromIndex($i);
                break;
            }
        }
        
        if ($themeJson === null) {
            $result['error'] = 'theme.json not found';
            return $result;
        }
        
        // Parse JSON
        $data = json_decode($themeJson, true);
        if ($data === null) {
            $result['error'] = 'Invalid JSON in theme.json';
            return $result;
        }
        
        // Check required fields
        $requiredFields = ['name', 'display_name', 'version'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $result['error'] = "Missing required field: $field";
                return $result;
            }
        }
        
        // Validate name
        if (!preg_match('/^[a-z0-9_]+$/i', $data['name'])) {
            $result['error'] = 'Invalid theme name in metadata';
            return $result;
        }
        
        $result['valid'] = true;
        return $result;
    }
}
