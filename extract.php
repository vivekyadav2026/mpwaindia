<?php
$log_file = 'C:\\Users\\ranje\\.gemini\\antigravity\\brain\\6a9d99f5-ce58-40cd-8297-f4ad30de5604\\.system_generated\\logs\\transcript.jsonl';
$handle = fopen($log_file, "r");
if ($handle) {
    $line = fgets($handle);
    $data = json_decode($line, true);
    file_put_contents('hindi_text.txt', $data['content']);
    fclose($handle);
}
?>
