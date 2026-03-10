<?php
$filepath = __DIR__ . "/../www/lib/class.erpapi.php";
$lines = file($filepath);
$inBlockComment = false;
$results = [];

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $lineno = $i + 1;
    $stripped = trim($line);

    // Track block comments
    if (strpos($line, "/*") !== false) $inBlockComment = true;
    if (strpos($line, "*/") !== false) { $inBlockComment = false; continue; }
    if ($inBlockComment) continue;

    // Skip line comments
    if (substr($stripped, 0, 2) === "//") continue;

    // Check for DB->Select/SelectRow/SelectArr
    if (preg_match('/->DB->(Select|SelectRow|SelectArr)\b/', $line)) {
        // Check for variable interpolation - $ inside double-quoted string or string concat with $
        if (preg_match('/"\$[a-zA-Z_]|"\.[^;]*\$[a-zA-Z_]|\$[a-zA-Z_][^;]*\."/', $line)) {
            $results[] = [$lineno, rtrim($line)];
        }
        // Also check for string concat pattern: ."$var or ".$var
        if (preg_match("/'\.\s*\\\$[a-zA-Z_]|\\\$[a-zA-Z_][^;]*\.\s*'/", $line)) {
            $results[] = [$lineno, rtrim($line)];
        }
    }
}

// Deduplicate
$seen = [];
foreach ($results as [$lineno, $content]) {
    if (!isset($seen[$lineno])) {
        $seen[$lineno] = true;
        echo "$lineno: $content\n";
    }
}
echo "Total unsafe: " . count($seen) . "\n";
