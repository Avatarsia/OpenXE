<?php
// SPDX-License-Identifier: AGPL-3.0-only

error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

spl_autoload_register(static function ($class) {
    $prefix = 'Xentral\\';
    if (strpos($class, $prefix) === 0) {
        $path = dirname(__DIR__, 2) . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

function check($condition, $message = 'Assertion failed')
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same($expected, $actual, $message = '')
{
    check($expected === $actual, $message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function runTests(array $tests)
{
    $failed = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            $failed++;
            echo 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL;
        }
    }
    echo count($tests) - $failed . '/' . count($tests) . ' passed; ' . $failed . ' failed' . PHP_EOL;
    exit($failed ? 1 : 0);
}
