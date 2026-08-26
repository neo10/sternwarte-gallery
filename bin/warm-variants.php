#!/usr/bin/env php
<?php

declare(strict_types=1);

use SternwarteGallery\Gallery;
use SternwarteGallery\GalleryConfig;
use SternwarteGallery\ImageVariant;

/*
 * Erzeugt vorab alle fehlenden Bildvarianten.
 *
 * Gedacht für einen Cronjob oder als letzter Schritt nach dem Hochladen:
 *
 *     php bin/warm-variants.php            # ganze Galerie
 *     php bin/warm-variants.php 2025/Mond  # nur ein Album
 *
 * Bereits aktuelle Varianten werden übersprungen, der Aufruf ist also billig
 * und beliebig oft wiederholbar. Beendet sich mit Exit-Code 1, sobald ein Bild
 * fehlschlägt – damit der Cronjob den Fehler meldet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Schutz vor Endlosschleifen durch verlinkte Ordner. */
const MAX_DEPTH = 20;

/** @var GalleryConfig $config */
$config = require __DIR__ . '/../config.php';

// Ohne Zugriffsprüfung: Dieser Befehl läuft auf dem Server, nicht als Besucher.
$gallery = Gallery::fromConfig($config);

$startFolder = $argv[1] ?? '';
$created = 0;
$upToDate = 0;
$failed = 0;

$walk = static function (string $folder, int $depth) use (&$walk, $gallery, &$created, &$upToDate, &$failed): void {
    if ($depth > MAX_DEPTH) {
        fwrite(STDERR, sprintf("Maximale Ordnertiefe erreicht, überspringe \"%s\".\n", $folder));

        return;
    }

    $contents = $gallery->browse($folder);
    if ($contents === null) {
        fwrite(STDERR, sprintf("Ordner nicht gefunden: \"%s\".\n", $folder));
        ++$failed;

        return;
    }

    foreach ($contents['images'] as $image) {
        try {
            $newVariants = $gallery->ensureVariants($image['path']);
        } catch (Throwable $error) {
            ++$failed;
            fwrite(STDERR, sprintf("Fehler bei \"%s\": %s\n", $image['path'], $error->getMessage()));
            continue;
        }

        if ($newVariants === []) {
            ++$upToDate;
            continue;
        }

        ++$created;
        $names = array_map(static fn (ImageVariant $variant): string => $variant->value, $newVariants);
        printf("%s: %s\n", $image['path'], implode(', ', $names));
    }

    foreach ($contents['folders'] as $subFolder) {
        $walk($subFolder['path'], $depth + 1);
    }
};

$walk($startFolder, 0);

printf("Fertig: %d erzeugt, %d aktuell, %d fehlgeschlagen.\n", $created, $upToDate, $failed);

exit($failed === 0 ? 0 : 1);
