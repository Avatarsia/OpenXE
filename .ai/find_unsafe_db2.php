<?php
$filepath = __DIR__ . "/../www/lib/class.erpapi.php";
$lines = file($filepath);
$inBlockComment = false;
$results = [];

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    $lineno = $i + 1;
    $stripped = trim($line);

    // Track block comments (simple approach)
    if (strpos($line, "/*") !== false && strpos($line, "*/") === false) {
        $inBlockComment = true;
        continue;
    }
    if ($inBlockComment) {
        if (strpos($line, "*/") !== false) {
            $inBlockComment = false;
        }
        continue;
    }

    // Strip inline // comments for analysis (but only after first non-string occurrence)
    // Simple approach: skip lines where the DB call appears after //
    if (strpos($stripped, "//") !== false) {
        $commentPos = strpos($stripped, "//");
        $dbPos = strpos($stripped, "->DB->");
        if ($dbPos !== false && $commentPos < $dbPos) {
            continue; // DB call is in a comment
        }
        // Also handle: code; // $this->app->DB->...
        // Check if // comes before DB call in stripped line
        if ($dbPos !== false && $commentPos !== false) {
            // naive: if comment is before DB call, skip
            if ($commentPos < $dbPos) continue;
        }
    }

    // Check for DB->Select/SelectRow/SelectArr
    if (!preg_match('/->DB->(Select|SelectRow|SelectArr)\b/', $line)) continue;

    // The SQL string: look for variable interpolation
    // Pattern 1: double-quoted string with embedded $var
    // Pattern 2: string concatenation with .$var. or "$var

    // Extract what looks like the SQL argument
    if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\((.*)/', $line, $m)) {
        $sqlPart = $m[2];
        // Check for variable references in the SQL part
        if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $sqlPart)) {
            // Make sure it's not just the $sql variable name passed as-is
            if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\(\s*\$sql\s*[\),]/', $line)) continue;
            if (preg_match('/->DB->(Select|SelectRow|SelectArr)\s*\(\s*\$[a-zA-Z_]+\s*\)/', $line)) continue;
            $results[$lineno] = rtrim($line);
        }
    }
}

foreach ($results as $lineno => $content) {
    echo "$lineno: $content\n";
}
echo "Total: " . count($results) . "\n";
