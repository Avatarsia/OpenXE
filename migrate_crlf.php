<?php
/**
 * Fix remaining patterns that couldn't be found due to CRLF line endings.
 * This script normalizes to LF for matching, then saves back with original encoding.
 */
$filepath = __DIR__ . '/www/lib/class.erpapi.php';
$raw = file_get_contents($filepath);
$hasCrlf = strpos($raw, "\r\n") !== false;
// Normalize to LF for matching
$content = str_replace("\r\n", "\n", $raw);
$changes = 0;

function replace_once(&$content, $old, $new, $label) {
    global $changes;
    // Normalize old/new to LF as well
    $old = str_replace("\r\n", "\n", $old);
    $new = str_replace("\r\n", "\n", $new);
    $pos = strpos($content, $old);
    if ($pos !== false) {
        $content = substr_replace($content, $new, $pos, strlen($old));
        echo "REPLACED: $label\n";
        $changes++;
    } else {
        echo "NOT FOUND: $label\n";
    }
}

// CreateAufgabe
replace_once($content,
    '    $this->app->DB->Insert("INSERT INTO aufgabe (id,adresse,initiator,aufgabe,status,kunde)
          VALUES (\'\',\'$adresse\',\'" . $this->app->User->GetAdresse() . "\',\'$aufgabe\',\'offen\',\'$kunde\')");
    return $this->app->DB->GetInsertID();',
    '    $this->app->DatabaseService->insert(
      "INSERT INTO aufgabe (adresse,initiator,aufgabe,status,kunde) VALUES (:adresse,:initiator,:aufgabe,\'offen\',:kunde)",
      [\':adresse\' => $adresse, \':initiator\' => $this->app->User->GetAdresse(), \':aufgabe\' => $aufgabe, \':kunde\' => $kunde]
    );
    return $this->app->DB->GetInsertID();',
    'CreateAufgabe'
);

// Save back - restore CRLF if original had it
if ($hasCrlf) {
    $content = str_replace("\n", "\r\n", $content);
}
file_put_contents($filepath, $content);
echo "Total changes: $changes\n";
