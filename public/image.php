<?php

declare(strict_types=1);

use SternwarteGallery\Gallery;
use SternwarteGallery\GalleryConfig;
use SternwarteGallery\ImageVariant;

/** @var GalleryConfig $config */
$config = require __DIR__ . '/../config.php';
$gallery = Gallery::fromConfig($config);

// Zugriffsprüfung, vor jeder Ausgabe. Wichtig: Diese Datei ist direkt
// aufrufbar. Ohne Prüfung hier ist jedes Bild öffentlich, auch wenn die
// Ordnerseite geschützt ist. Siehe config.php.
if (!$gallery->isAuthorized()) {
    http_response_code(403);
    exit;
}

$variant = ImageVariant::fromNameOrDefault((string) ($_GET['variant'] ?? ''), ImageVariant::Large);
$imagePath = $gallery->imagePath((string) ($_GET['file'] ?? ''), $variant);

if ($imagePath === null) {
    http_response_code(404);
    exit;
}

$modifiedAt = filemtime($imagePath);
$size = filesize($imagePath);

if ($modifiedAt === false || $size === false) {
    http_response_code(404);
    exit;
}

$lastModified = gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT';
$eTag = '"' . md5($imagePath . '|' . $modifiedAt . '|' . $size) . '"';

// Eine URL mit Fingerabdruck bezeichnet genau eine Fassung des Bildes: Wird das
// Bild ausgetauscht, entsteht eine andere URL. Sie darf deshalb praktisch ewig
// gecacht werden – das ist der ganze Sinn der Übung.
//
// Ohne Fingerabdruck (direkter Aufruf, alter Link) kann sich der Inhalt unter
// derselben URL ändern. Dann wird nur kurz gecacht und danach über
// ETag/Last-Modified revalidiert, was ein 304 statt des ganzen Bildes kostet.
$hasVersion = ($_GET['v'] ?? '') !== '';

header($hasVersion
    ? 'Cache-Control: public, max-age=31536000, immutable'
    : 'Cache-Control: public, max-age=300');
header('ETag: ' . $eTag);
header('Last-Modified: ' . $lastModified);
header('X-Content-Type-Options: nosniff');

$knownETag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$knownDate = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

if ($knownETag === $eTag || ($knownETag === '' && $knownDate === $lastModified)) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $gallery->mimeType($imagePath));
header('Content-Length: ' . (string) $size);
readfile($imagePath);
