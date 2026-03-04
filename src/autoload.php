<?php
spl_autoload_register(function (string $class): void {
    $prefix = 'BiddingMonitor\\';
    $base = __DIR__ . '/';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $base . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
