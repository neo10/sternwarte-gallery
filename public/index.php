<?php

declare(strict_types=1);

use SternwarteGallery\Gallery;
use SternwarteGallery\GalleryConfig;
use SternwarteGallery\GalleryView;

/** @var GalleryConfig $config */
$config = require __DIR__ . '/../config.php';
$gallery = Gallery::fromConfig($config);

// Zugriffsprüfung, vor jeder Ausgabe. Die Prüfung selbst steht in config.php.
// 403 und 404 kommen bewusst als Klartext ohne Layout.
if (!$gallery->isAuthorized()) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$currentFolder = $gallery->browse((string) ($_GET['folder'] ?? ''));
if ($currentFolder === null) {
    http_response_code(404);
    exit('Ordner nicht gefunden.');
}

// Die Bild-URLs dürfen lange gecacht werden, diese Seite nicht: Sie enthält die
// Fingerabdrücke, und nur wenn sie frisch ist, erfährt der Browser von einem
// ausgetauschten Bild.
header('Cache-Control: no-cache');

// Erst vollständig rendern, dann ausgeben: Scheitert das Rendern, hat das
// Layout noch keinen halben Seitenkopf gesendet.
$view = new GalleryView($config->title);
$title = $view->pageTitle($currentFolder);
$content = $view->renderFolder($currentFolder);

if ($config->layout !== null) {
    ($config->layout)($title, $content);
} else {
    echo $view->renderPage($title, $content);
}
