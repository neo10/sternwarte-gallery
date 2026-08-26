<?php

declare(strict_types=1);

/**
 * Minimaler PSR-4-Autoloader für den Namensraum SternwarteGallery.
 * Das Projekt kommt damit ohne Composer aus; einbinden muss ihn nur der
 * jeweilige Einstiegspunkt (gallery-config.php, Tests, CLI).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'SternwarteGallery\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $filename = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($filename)) {
        require_once $filename;
    }
});
