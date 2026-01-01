<?php
/*
 * Theme Management Module for OpenXE
 * Handles theme listing, activation, upload, preview, and configuration
 */

class Themes {
    protected $app;
    
    public function __construct(&$app, $intern = false) {
        $this->app = &$app;
    }
    
    /**
     * Main entry point - theme list view
     */
    public function list() {
        $this->app->Tpl->Set('PAGE_TITLE', 'Theme Verwaltung');
        
        // Get all available themes
        $themes = $this->getAvailableThemes();
        $currentTheme = $this->getCurrentUserTheme();
        
        // Prepare theme grid data
        $themeGrid = '';
        foreach ($themes as $theme) {
            $isActive = ($theme['name'] === $currentTheme) ? 'active' : '';
            $preview = $theme['preview'] ?? 'preview.png';
            $previewPath = 'themes/' . $theme['name'] . '/' . $preview;
            
            if (!file_exists(__DIR__ . '/../' . $previewPath)) {
                $previewPath = 'themes/openxe_default/images/placeholder.png';
            }
            
            $themeGrid .= $this->renderThemeCard($theme, $previewPath, $isActive);
        }
        
        $this->app->Tpl->Set('THEME_GRID', $themeGrid);
        $this->app->Tpl->Set('CURRENT_THEME', $currentTheme);
        
        $this->app->Tpl->Parse('PAGE', 'themes_list.tpl');
    }
    
    /**
     * Activate a theme for current user or firma
     */
    public function activate() {
        $themeName = $this->app->Secure->GetPOST('theme');
        $scope = $this->app->Secure->GetPOST('scope'); // 'user' or 'firma'
        
        if (empty($themeName) || !$this->validateThemeName($themeName)) {
            $this->app->erp->LogFile('Invalid theme name', ['theme' => $themeName], 'themes', 'validation_error');
            header('Location: index.php?module=themes&action=list&msg=' . urlencode('Ungültiger Theme-Name'));
            exit;
        }
        
        // Check if theme exists
        if (!is_dir(__DIR__ . '/../themes/' . $themeName)) {
            header('Location: index.php?module=themes&action=list&msg=' . urlencode('Theme nicht gefunden'));
            exit;
        }
        
        $userId = $this->app->User->GetID();
        
        if ($scope === 'firma') {
            // Set firma default theme (admin only)
            if (!$this->app->erp->RechteVorhanden('firmendaten', 'edit')) {
                header('Location: index.php?module=themes&action=list&msg=' . urlencode('Keine Berechtigung'));
                exit;
            }
            
            $this->app->DB->Update(
                "UPDATE firmendaten SET default_theme = '" . $this->app->DB->real_escape_string($themeName) . "' WHERE id = 1"
            );
            
          $msg = 'Firmen-Theme erfolgreich gesetzt: ' . $themeName;
            
        } else {
            // Set user theme override
            $canChange = $this->app->DB->Select("SELECT can_change_theme FROM user WHERE id = $userId LIMIT 1");
            
            if (!$canChange) {
                header('Location: index.php?module=themes&action=list&msg=' . urlencode('Theme-Wechsel deaktiviert'));
                exit;
            }
            
            // Delete existing setting
            $this->app->DB->Delete("DELETE FROM theme_settings WHERE user_id = $userId");
            
            // Insert new setting
            $this->app->DB->Insert(
                "INSERT INTO theme_settings (user_id, theme_name, is_active) 
                 VALUES ($userId, '" . $this->app->DB->real_escape_string($themeName) . "', 1)"
            );
            
            $msg = 'Theme erfolgreich aktiviert: ' . $themeName;
        }
        
        $this->app->erp->LogFile($msg, ['theme' => $themeName, 'scope' => $scope], 'themes', 'activated');
        header('Location: index.php?module=themes&action=list&msg=' . urlencode($msg));
        exit;
    }
    
    /**
     * Preview theme in iFrame
     */
    public function preview() {
        $themeName = $this->app->Secure->GetGET('theme');
        
        if (!$this->validateThemeName($themeName) || !is_dir(__DIR__ . '/../themes/' . $themeName)) {
            echo 'Ungültiges Theme';
            exit;
        }
        
        // Temporarily override theme for this session
        $_SESSION['theme_preview'] = $themeName;
        
        $this->app->Tpl->Set('PREVIEW_THEME', $themeName);
        $this->app->Tpl->Set('PREVIEW_URL', 'index.php?module=welcome&action=start&preview=1');
        $this->app->Tpl->Parse('PAGE', 'theme_preview.tpl');
    }
    
    /**
     * Test theme temporarily (session-based)
     */
    public function test() {
        $themeName = $this->app->Secure->GetGET('theme');
        
        if ($this->validateThemeName($themeName) && is_dir(__DIR__ . '/../themes/' . $themeName)) {
            $_SESSION['theme_test'] = $themeName;
            header('Location: index.php?module=welcome&action=start');
        } else {
            header('Location: index.php?module=themes&action=list&msg=' . urlencode('Ungültiges Theme'));
        }
        exit;
    }
    
    /**
     * End theme test
     */
    public function endtest() {
        unset($_SESSION['theme_test']);
        header('Location: index.php?module=themes&action=list');
        exit;
    }
    
    /**
     * Upload theme ZIP file
     */
    public function upload() {
        // Check permissions
        if (!$this->app->erp->RechteVorhanden('themes', 'upload')) {
            header('Location: index.php?module=themes&action=list&msg=' . urlencode('Keine Berechtigung'));
            exit;
        }
        
        // Process upload
        if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['theme_zip']['tmp_name'];
            
            // Load validator
            require_once(__DIR__ . '/../lib/class.theme_validator.php');
            $validator = new ThemeValidator($this->app);
            
            // Validate ZIP
            $result = $validator->validate($tmpPath);
            
            if (!$result['valid']) {
                header('Location: index.php?module=themes&action=list&msg=' . urlencode('Upload fehlgeschlagen: ' . $result['error']));
                exit;
            }
            
            // Extract ZIP to themes directory
            $themeName = $result['theme_name'];
            $targetDir = __DIR__ . '/../themes/' . $themeName;
            
            // Check if theme already exists
            if (is_dir($targetDir)) {
                header('Location: index.php?module=themes&action=list&msg=' . urlencode('Theme existiert bereits: ' . $themeName));
                exit;
            }
            
            // Extract
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) === TRUE) {
                $zip->extractTo(__DIR__ . '/../themes/');
                $zip->close();
                
                // Add to database
                $themeJsonPath = $targetDir . '/theme.json';
                if (file_exists($themeJsonPath)) {
                    $themeData = json_decode(file_get_contents($themeJsonPath), true);
                    
                    $this->app->DB->Insert(
                        "INSERT INTO themes (name, display_name, description, author, version, is_system, is_enabled) 
                         VALUES (
                             '" . $this->app->DB->real_escape_string($themeName) . "',
                             '" . $this->app->DB->real_escape_string($themeData['display_name'] ?? $themeName) . "',
                             '" . $this->app->DB->real_escape_string($themeData['description'] ?? '') . "',
                             '" . $this->app->DB->real_escape_string($themeData['author'] ?? 'Unknown') . "',
                             '" . $this->app->DB->real_escape_string($themeData['version'] ?? '1.0.0') . "',
                             0,
                             1
                         )"
                    );
                }
                
                $this->app->erp->LogFile('Theme uploaded', ['theme' => $themeName], 'themes', 'upload');
                header('Location: index.php?module=themes&action=list&msg=' . urlencode('Theme erfolgreich hochgeladen: ' . $themeName));
                exit;
            }
        }
        
        // Show upload form
        $this->app->Tpl->Parse('PAGE', 'theme_upload.tpl');
    }
    
    // ==================== HELPER FUNCTIONS ====================
    
    /**
     * Get all available themes from directory and database
     */
    private function getAvailableThemes() {
        $themes = [];
        $themeDir = __DIR__ . '/../themes';
        
        if (!is_dir($themeDir)) return $themes;
        
        $dirs = scandir($themeDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $themePath = $themeDir . '/' . $dir;
            if (!is_dir($themePath)) continue;
            
            // Load theme.json if exists
            $jsonPath = $themePath . '/theme.json';
            if (file_exists($jsonPath)) {
                $themeData = json_decode(file_get_contents($jsonPath), true);
                if ($themeData) {
                    $themeData['name'] = $dir;
                    $themes[] = $themeData;
                    continue;
                }
            }
            
            // Fallback: create basic theme info
            $themes[] = [
                'name' => $dir,
                'display_name' => ucfirst(str_replace('_', ' ', $dir)),
                'description' => 'No description available',
                'version' => '1.0.0'
            ];
        }
        
        return $themes;
    }
    
    /**
     * Get current user's active theme
     */
    private function getCurrentUserTheme() {
        // Check test mode
        if (isset($_SESSION['theme_test'])) {
            return $_SESSION['theme_test'];
        }
        
        $userId = $this->app->User->GetID();
        
        // Check user override
        $userTheme = $this->app->DB->Select(
            "SELECT theme_name FROM theme_settings WHERE user_id = $userId AND is_active = 1 LIMIT 1"
        );
        
        if ($userTheme) return $userTheme;
        
        // Check firma default
        $firmaTheme = $this->app->erp->Firmendaten('default_theme');
        if ($firmaTheme) return $firmaTheme;
        
        return 'openxe_default';
    }
    
    /**
     * Render a single theme card for the grid
     */
    private function renderThemeCard($theme, $previewPath, $isActive) {
        $activeClass = $isActive ? 'theme-active' : '';
        $activeBadge = $isActive ? '<div class="active-badge">Aktiv</div>' : '';
        
        return '
        <div class="theme-card ' . $activeClass . '">
            <div class="theme-preview">
                <img src="' . htmlspecialchars($previewPath) . '" alt="' . htmlspecialchars($theme['display_name']) . '">
                ' . $activeBadge . '
            </div>
            <div class="theme-info">
                <h3>' . htmlspecialchars($theme['display_name']) . '</h3>
                <p class="theme-description">' . htmlspecialchars($theme['description'] ?? '') . '</p>
                <p class="theme-meta">Version ' . htmlspecialchars($theme['version'] ?? '1.0.0') . '</p>
            </div>
            <div class="theme-actions">
                <form method="post" action="index.php?module=themes&action=activate" style="display:inline;">
                    <input type="hidden" name="theme" value="' . htmlspecialchars($theme['name']) . '">
                    <input type="hidden" name="scope" value="user">
                    <button type="submit" class="button button-primary">Aktivieren</button>
                </form>
                <a href="index.php?module=themes&action=preview&theme=' . urlencode($theme['name']) . '" 
                   class="button button-secondary" target="_blank">Vorschau</a>
                <a href="index.php?module=themes&action=test&theme=' . urlencode($theme['name']) . '" 
                   class="button button-ghost">Testen</a>
            </div>
        </div>';
    }
    
    /**
     * Validate theme name (security)
     */
    private function validateThemeName($name) {
        return !empty($name) && preg_match('/^[a-z0-9_]+$/i', $name);
    }
}
