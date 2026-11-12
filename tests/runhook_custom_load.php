<?php
// Regression: RunHook must load a custom module's parent before the extension.
error_reporting(E_ALL & ~E_DEPRECATED);
$root = sys_get_temp_dir() . '/openxe-hook-' . bin2hex(random_bytes(6));
mkdir($root . '/www/lib', 0777, true);
mkdir($root . '/www/pages', 0777, true);
copy(__DIR__ . '/../www/lib/class.erpapi.php', $root . '/www/lib/class.erpapi.php');
require $root . '/www/lib/class.erpapi.php';

class HookLoaderRegressionErp extends erpAPI
{
    public function __construct($app) { $this->app = $app; }
    public function getHookByAlias($alias) { return []; }
    public function GenerateHook($name, $parametercount, $aktiv, $new = false)
    { return ['id' => 1, 'aktiv' => 1, 'parametercount' => $parametercount]; }
    public function ModulVorhanden($module, $showdeactivated = false) { return true; }
}

$app = new class {
    public $DB;
    public function loadModule($module) { return null; }
};
$app->DB = new class {
    public $module;
    public function SelectArr($sql)
    { return [['module' => $this->module, 'function' => 'details', 'module_parameter' => '']]; }
};
$erp = new HookLoaderRegressionErp($app);
$failed = 0;
$passed = 0;
try {
    foreach (['Hookplain', 'Hookcustom', 'Hooklower'] as $class) {
        $file = strtolower($class);
            file_put_contents($root . '/www/pages/' . $file . '.php', '<?php class ' . $class . ' {
                public function __construct($app, $internal = false) {}
                public function details(&$result) { $result = "base"; }
            }');
        if ($class !== 'Hookplain') {
            $parent = ' extends ' . $class;
            file_put_contents($root . '/www/pages/' . $file . '_custom.php', '<?php class ' . $class . 'Custom' . $parent . ' {
                public function __construct($app, $internal = false) {}
                public function details(&$result) { $result = "custom"; }
            }');
        }
        $app->DB->module = $class === 'Hooklower' ? $class : $file;
        try {
            for ($i = 0; $i < 2; $i++) {
                $result = null;
                $erp->RunHook('supersearch_detail', 1, $result);
                $expected = $class === 'Hookplain' ? 'base' : 'custom';
                if ($result !== $expected) { throw new RuntimeException('Expected ' . $expected); }
            }
            echo "PASS $class (initial and repeated load)\n";
            $passed++;
        } catch (Throwable $error) {
            echo "FAIL $class: " . $error->getMessage() . "\n";
            $failed++;
        }
    }
} finally {
    foreach (glob($root . '/www/pages/*') as $file) { unlink($file); }
    unlink($root . '/www/lib/class.erpapi.php');
    rmdir($root . '/www/lib');
    rmdir($root . '/www/pages');
    rmdir($root . '/www');
    rmdir($root);
}
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
