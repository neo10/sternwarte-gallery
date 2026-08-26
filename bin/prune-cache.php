#!/usr/bin/env php
<?php

declare(strict_types=1);

use SternwarteGallery\Gallery;
use SternwarteGallery\GalleryConfig;

/*
 * Entfernt verwaiste Dateien aus dem Varianten-Cache: Varianten gelöschter
 * Bilder, Reste abgebrochener Erzeugungen und dadurch leer gewordene Ordner.
 *
 *     php bin/prune-cache.php           # zeigt nur an, ändert nichts
 *     php bin/prune-cache.php --force   # entfernt wirklich
 *
 * Ohne --force passiert nichts. Beim ersten Mal lohnt sich der Blick auf die
 * Liste.
 *
 * Der Befehl liest im Bilder-Ordner und schreibt ausschließlich im Cache; jeder
 * Pfad wird vorher geprüft. Ein Fehlgriff wäre ohnehin harmlos: Der Cache ist
 * ein reines Derivat, entfernte Varianten entstehen beim nächsten Aufruf neu.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** So viele Pfade werden höchstens einzeln aufgelistet. */
const MAX_LISTED = 50;

/** @param list<string> $paths */
function listPaths(string $title, array $paths): void
{
    if ($paths === []) {
        return;
    }

    printf("%s (%d):\n", $title, count($paths));

    foreach (array_slice($paths, 0, MAX_LISTED) as $path) {
        echo '  ', $path, PHP_EOL;
    }

    if (count($paths) > MAX_LISTED) {
        printf("  … und %d weitere\n", count($paths) - MAX_LISTED);
    }
}

/** @var GalleryConfig $config */
$config = require __DIR__ . '/../config.php';

// Ohne Zugriffsprüfung: Dieser Befehl läuft auf dem Server, nicht als Besucher.
$gallery = Gallery::fromConfig($config);

$force = in_array('--force', array_slice($argv, 1), true);
$garbage = $gallery->findCacheGarbage();
$paths = [...$garbage['files'], ...$garbage['directories']];

if ($paths === []) {
    printf("Nichts aufzuräumen. %d Varianten sind in Ordnung.\n", $garbage['kept']);
    exit(0);
}

listPaths('Verwaiste Dateien', $garbage['files']);
listPaths('Leer gewordene Ordner', $garbage['directories']);

if (!$force) {
    printf(
        "\nTrockenlauf: %d Dateien und %d Ordner wären zu entfernen, %d Varianten bleiben.\n",
        count($garbage['files']),
        count($garbage['directories']),
        $garbage['kept'],
    );
    echo "Mit --force wirklich aufräumen.\n";

    exit(0);
}

$removed = $gallery->removeCacheGarbage($paths);
printf("\n%d von %d Einträgen entfernt, %d Varianten bleiben.\n", $removed, count($paths), $garbage['kept']);

if ($removed < count($paths)) {
    fwrite(STDERR, "Einige Einträge ließen sich nicht entfernen – Schreibrechte im Cache prüfen.\n");

    exit(1);
}

exit(0);
