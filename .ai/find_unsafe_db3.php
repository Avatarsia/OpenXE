<?php
/**
 * Find genuinely unsafe DB->Select* calls that have variable interpolation in SQL strings
 * (not sprintf %d, not pure variable reference, not commented out)
 */
$filepath = __DIR__ . "/../www/lib/class.erpapi.php";
$content = file_get_contents($filepath);
$lines = explode("\n", $content);

$inBlockComment = false;
$results = [];

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $lineno = $i + 1;
    $stripped = ltrim($line);

    // Handle block comment tracking
    // Count /* and */ occurrences
    $openCount = substr_count($line, '/*');
    $closeCount = substr_count($line, '*/');

    if ($inBlockComment) {
        if ($closeCount > $openCount) {
            $inBlockComment = false;
        }
        continue;
    } else {
        if ($openCount > $closeCount) {
            $inBlockComment = true;
            // Still check portion before /*
        }
    }

    // Skip pure line comments
    if (substr($stripped, 0, 2) === '//' || substr($stripped, 0, 1) === '#') continue;

    // Check for DB->Select/SelectRow/SelectArr
    if (!preg_match('/->DB->(Select|SelectRow|SelectArr)\b/', $line)) continue;

    // Skip if the match comes after // on the same line
    if (preg_match('/^(.*?)\/\/.*->DB->(Select|SelectRow|SelectArr)\b/', $line)) continue;

    // Skip safe patterns:
    // - sprintf with %d only (no %s)
    if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\(sprintf\(\'[^\']*%d[^\'%s]*\'/', $line)) {
        if (!preg_match('/%s/', $line)) continue;
    }
    if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\(sprintf\("/', $line)) {
        if (!preg_match('/%s/', $line)) continue;
    }

    // Skip pure variable references (pre-built SQL string)
    if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*\)/', $line)) continue;

    // Skip static SQL (no variable interpolation)
    // The line has DB->Select* — check if there's variable interpolation in the argument
    if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\((.+)/', $line, $m)) {
        $arg = $m[2];
        // Remove sprintf %d patterns
        $cleaned = preg_replace('/sprintf\s*\(\s*[\'"][^"\']*%d[^"\']*[\'"]/', '', $arg);
        // Look for $ in the remaining argument
        if (!preg_match('/\$[a-zA-Z_]/', $cleaned)) continue;

        // It has a variable — classify:
        // Real escape string only (we accept as safe)
        if (preg_match('/real_escape_string\(\$[a-zA-Z_]/', $arg) && !preg_match('/"[^"]*\$[a-zA-Z_]/', $arg)) continue;

        $results[$lineno] = rtrim($line);
    }
}

foreach ($results as $lineno => $content) {
    echo "$lineno: $content\n";
}
echo "\nTotal potentially unsafe: " . count($results) . "\n";
