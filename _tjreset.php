<?php
// One-shot OPcache reset helper for the TOP JEMLA export endpoint.
// The server runs with opcache.validate_timestamps=0, so re-deployed PHP files
// are ignored until the compiled bytecode cache is cleared. Hit this URL once
// after deploying export-sales.php to force a fresh compile. Safe & read-only.
header('Content-Type: text/plain; charset=utf-8');
if (function_exists('opcache_reset')) {
    $ok = @opcache_reset();
    echo $ok ? "opcache reset OK\n" : "opcache_reset() returned false (maybe disabled for CLI/web)\n";
} else {
    echo "opcache not available on this PHP\n";
}
if (function_exists('opcache_get_status')) {
    $s = @opcache_get_status(false);
    if (is_array($s)) {
        echo "opcache.enabled = " . (!empty($s['opcache_enabled']) ? "1" : "0") . "\n";
        if (isset($s['opcache_statistics']['num_cached_scripts'])) {
            echo "cached scripts   = " . $s['opcache_statistics']['num_cached_scripts'] . "\n";
        }
    }
}
