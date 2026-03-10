<?php
/**
 * Find unsafe DB calls including multi-line SQL strings
 * Groups consecutive lines for multi-line calls
 */
$filepath = __DIR__ . "/../www/lib/class.erpapi.php";
$lines = file($filepath);
$inBlockComment = false;
$results = [];

$n = count($lines);

for ($i = 0; $i < $n; $i++) {
    $line = $lines[$i];
    $lineno = $i + 1;
    $stripped = ltrim($line);

    // Handle block comment tracking
    if ($inBlockComment) {
        if (strpos($line, '*/') !== false) {
            $inBlockComment = false;
        }
        continue;
    }

    // Check if line opens block comment
    if (strpos($line, '/*') !== false) {
        $openPos = strpos($line, '/*');
        $closePos = strpos($line, '*/', $openPos + 2);
        if ($closePos === false) {
            // Block comment opens, no close on this line
            $inBlockComment = true;
            continue;
        }
        // same-line /* ... */ - fall through to check code before /*
    }

    // Skip pure line comments
    if (substr($stripped, 0, 2) === '//') continue;
    if (substr($stripped, 0, 1) === '#') continue;

    // Check for DB->Select*/SelectArr/SelectRow/Insert/Update/Delete calls
    if (!preg_match('/->DB->(Select|SelectRow|SelectArr|Insert\b|Update\b|Delete\b)\b/', $line)) continue;

    // Skip InsertWithoutLog (architectural decision)
    if (strpos($line, 'InsertWithoutLog') !== false) continue;
    if (strpos($line, 'InsertArr') !== false) continue;
    if (strpos($line, 'DeleteArr') !== false) continue;
    if (strpos($line, 'UpdateArr') !== false) continue;

    // Skip if DB call is after // on the line
    $inlinePos = strpos($line, '//');
    $dbPos = strpos($line, '->DB->');
    if ($inlinePos !== false && $dbPos !== false && $inlinePos < $dbPos) continue;

    // Now collect the full call - look ahead for the closing );
    $fullCall = $line;
    $lookAheadLines = [$lineno];
    $depth = substr_count($line, '(') - substr_count($line, ')');
    $j = $i + 1;
    while ($depth > 0 && $j < min($n, $i + 20)) {
        $fullCall .= $lines[$j];
        $lookAheadLines[] = $j + 1;
        $depth += substr_count($lines[$j], '(') - substr_count($lines[$j], ')');
        $j++;
    }

    // Check if there's variable interpolation in the full call
    // Skip sprintf with only %d (no %s)
    if (strpos($fullCall, 'sprintf') !== false && strpos($fullCall, '%s') === false) {
        // Only %d format - safe
        continue;
    }

    // Check for variable interpolation patterns
    $hasInterp = false;

    // "$var embedded in double-quoted string
    if (preg_match('/"[^"]*\$[a-zA-Z_]/', $fullCall)) $hasInterp = true;
    // concat ."$var
    if (preg_match('/\'\s*\.\s*\$[a-zA-Z_]/', $fullCall)) $hasInterp = true;
    // concat $var."
    if (preg_match('/\$[a-zA-Z_][^\'"]*\.\s*\'/', $fullCall)) $hasInterp = true;

    if (!$hasInterp) continue;

    // Skip if it's only a pre-escaped variable
    // real_escape_string() calls - still track them

    // Skip DatabaseService calls
    if (strpos($fullCall, '->DatabaseService->') !== false && strpos($fullCall, '->DB->') === false) continue;

    // Output
    $startLine = $lineno;
    echo "=== Lines {$startLine}+ ===\n";
    echo trim($fullCall) . "\n\n";
}
