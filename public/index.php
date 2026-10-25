<?php

declare(strict_types=1);

use SternwarteGallery\Gallery;
use SternwarteGallery\GalleryConfig;
use SternwarteGallery\ImageVariant;

/** @var GalleryConfig $config */
$config = require __DIR__ . '/../config.php';
$gallery = Gallery::fromConfig($config);

// Zugriffsprüfung, vor jeder Ausgabe. Die Prüfung selbst steht in config.php.
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

/** Kurzform für die Ausgabe von Text in HTML. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function folderUrl(string $path): string
{
    return $path === '' ? 'index.php' : 'index.php?' . http_build_query(['folder' => $path], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Das "v" ist der Fingerabdruck des Originals. Der Server braucht es nicht – er
 * liest den aktuellen Stand ohnehin selbst. Es steht in der URL, damit ein
 * ausgetauschtes Bild eine *andere* URL bekommt: Nur so merkt der Browser, dass
 * seine gespeicherte Kopie nicht mehr gilt.
 */
function imageUrl(string $path, ImageVariant $variant, string $version): string
{
    return 'image.php?' . http_build_query([
        'file' => $path,
        'variant' => $variant->value,
        'v' => $version,
    ], '', '&', PHP_QUERY_RFC3986);
}
?>
<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($currentFolder['name']) ?> – <?= e($config->title) ?></title>
    <link rel="stylesheet" href="assets/photoswipe/photoswipe.css">
    <style>
      body { margin: 0; font: 16px/1.5 system-ui, sans-serif; color: #172033; }
      main { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; }
      a { color: #1d4ed8; }
      ul { padding: 0; list-style: none; }
      li { padding: .4rem 0; border-bottom: 1px solid #e2e8f0; }
      .images { margin-top: 3rem; }
      .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
      figure { margin: 0; }
      img { display: block; width: 100%; height: 145px; object-fit: cover; border-radius: .4rem; background: #e2e8f0; }
      figcaption { overflow: hidden; margin-top: .35rem; text-overflow: ellipsis; white-space: nowrap; font-size: .9rem; }
    </style>
  </head>
  <body>
    <main>
      <p><a href="index.php"><?= e($config->title) ?></a></p>
      <h1><?= e($currentFolder['name']) ?></h1>

      <?php if ($currentFolder['parentPath'] !== null): ?>
        <p><a href="<?= e(folderUrl($currentFolder['parentPath'])) ?>">← Eine Ebene zurück</a></p>
      <?php endif; ?>

      <section aria-labelledby="folder-title">
        <h2 id="folder-title">Ordner</h2>
        <?php if ($currentFolder['folders'] === []): ?>
          <p>Keine Unterordner gefunden.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($currentFolder['folders'] as $folder): ?>
              <li><a href="<?= e(folderUrl($folder['path'])) ?>"><?= e($folder['name']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <?php if ($currentFolder['images'] !== []): ?>
        <section class="images" aria-labelledby="image-title">
          <h2 id="image-title">Bilder in diesem Ordner</h2>
          <div class="image-grid pswp-gallery" id="photo-gallery">
            <?php foreach ($currentFolder['images'] as $image): ?>
              <?php
                // Das Raster bekommt das kleine Bild, die Lightbox das große.
                // PhotoSwipe braucht die Maße genau der Datei, die es anzeigt.
                $thumbnail = ImageVariant::Thumbnail->scale($image['width'], $image['height']);
                $large = ImageVariant::Large->scale($image['width'], $image['height']);
              ?>
              <figure>
                <a href="<?= e(imageUrl($image['path'], ImageVariant::Large, $image['version'])) ?>"
                   data-pswp-width="<?= $large['width'] ?>"
                   data-pswp-height="<?= $large['height'] ?>"
                   data-cropped="true"
                   target="_blank"
                   rel="noopener">
                  <img src="<?= e(imageUrl($image['path'], ImageVariant::Thumbnail, $image['version'])) ?>"
                       width="<?= $thumbnail['width'] ?>"
                       height="<?= $thumbnail['height'] ?>"
                       loading="lazy"
                       decoding="async"
                       alt="<?= e($image['name']) ?>">
                </a>
                <figcaption><?= e($image['name']) ?></figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
          <script type="module">
            // Das "./" ist Pflicht: Ohne hält der Browser "assets/…" für einen
            // Paketnamen und lädt gar nichts. Aufgelöst wird gegen diese Seite.
            import PhotoSwipeLightbox from './assets/photoswipe/photoswipe-lightbox.esm.min.js';

            const lightbox = new PhotoSwipeLightbox({
              gallery: '#photo-gallery',
              children: 'a',
              // Der Kern kommt erst beim ersten Öffnen eines Bildes.
              pswpModule: () => import('./assets/photoswipe/photoswipe.esm.min.js')
            });

            lightbox.init();
          </script>
        </section>
      <?php endif; ?>
    </main>
  </body>
</html>
