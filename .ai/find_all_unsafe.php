<?php
/**
 * Find ALL unsafe DB calls - comprehensive scanner
 * Tracks block comments properly
 */
$filepath = __DIR__ . "/../www/lib/class.erpapi.php";
$lines = file($filepath);
$inBlockComment = false;
$results = [];

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $lineno = $i + 1;
    $stripped = ltrim($line);

    // Handle /* ... */ block comments
    // First check if we're tracking an open block comment
    if ($inBlockComment) {
        if (strpos($line, '*/') !== false) {
            $inBlockComment = false;
        }
        continue;
    }

    // Check if this line opens a block comment
    $openPos = strpos($line, '/*');
    if ($openPos !== false) {
        $closePos = strpos($line, '*/', $openPos + 2);
        if ($closePos === false) {
            // Block comment opened but not closed on this line
            // Check if there's code before the /*
            $beforeComment = substr($line, 0, $openPos);
            $inBlockComment = true;
            // Analyze the part before the comment
            $line = $beforeComment;
        }
        // If /* and */ on same line, the content is commented out - can still analyze before /*
    }

    // Skip pure line comments
    if (substr($stripped, 0, 2) === '//' || substr($stripped, 0, 1) === '#') continue;

    // Strip inline // comments (naive approach - only if // is not inside a string)
    // For our purposes, just check if // appears before the DB call
    $inlineCommentPos = strpos($line, '//');
    $dbCallPos = strpos($line, '->DB->');
    if ($inlineCommentPos !== false && $dbCallPos !== false && $inlineCommentPos < $dbCallPos) {
        continue; // DB call is after // comment
    }

    // Must have DB->
    if (strpos($line, '->DB->') === false) continue;

    // Check for variable interpolation patterns
    // 1. Double-quoted string with $var embedded: "$var
    // 2. String concat: .".$var or $var."
    // 3. Single-quoted concat: .'.$var or $var.'

    $hasInterpolation = false;

    // Pattern 1: "$var inside double-quoted string (but not part of sprintf format)
    if (preg_match('/"[^"]*\$[a-zA-Z_]/', $line)) {
        $hasInterpolation = true;
    }
    // Pattern 2: string concat with variable
    if (preg_match('/\'\s*\.\s*\$[a-zA-Z_]/', $line)) {
        $hasInterpolation = true;
    }
    if (preg_match('/\$[a-zA-Z_][^;]*\.\s*\'/', $line)) {
        $hasInterpolation = true;
    }

    if (!$hasInterpolation) continue;

    // Now check if it's a DB-> call (not DatabaseService)
    if (strpos($line, '->DatabaseService->') !== false && strpos($line, '->DB->') === false) continue;
    if (!preg_match('/->DB->(Select|SelectRow|SelectArr|Insert|Update|Delete|Query)\b/', $line)) continue;

    // Skip safe patterns:
    // - real_escape_string only (debatable, but keep for now)
    // - sprintf with %d only
    if (preg_match('/sprintf\s*\(\'[^\']*\'/', $line) && !preg_match('/%s/', $line)) {
        // sprintf with format string - check if only %d
        if (!preg_match('/%s/', $line)) continue;
    }

    // Skip InsertWithoutLog
    if (strpos($line, 'InsertWithoutLog') !== false) continue;

    $results[$lineno] = rtrim($line);
}

ksort($results);
foreach ($results as $lineno => $content) {
    echo "$lineno: $content\n";
}
echo "\nTotal: " . count($results) . "\n";
