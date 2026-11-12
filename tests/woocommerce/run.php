<?php
// SPDX-License-Identifier: AGPL-3.0-only

$failed = 0;
foreach (['orders', 'single_import', 'sync', 'products', 'refunds', 'refund_retry', 'refund_status', 'download_settings', 'admin', 'admin_controller', 'integration', 'state', 'product_integration', 'wordpress'] as $suite) {
    $command = [PHP_BINARY, __DIR__ . '/' . $suite . '.php'];
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start test suite ' . $suite);
    }
    $failed += proc_close($process) === 0 ? 0 : 1;
}
echo 'Suites failed: ' . $failed . PHP_EOL;
exit($failed ? 1 : 0);
