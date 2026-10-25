<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Das HTML der Galerie, in zwei Teilen:
 *
 * - renderFolder() liefert den Galerie-Inhalt: Stylesheets, den Container
 *   .sternwarte-gallery und das Lightbox-Skript. Er ist in sich geschlossen –
 *   eine Website, die die Galerie einbettet ("layout" in config.php), gibt ihn
 *   unverändert zwischen ihrem Kopf und Fuß aus.
 * - renderPage() legt das Seitengerüst der eigenständigen Galerie darum.
 *
 * Die Hilfsfunktionen sind private Methoden und keine globalen Funktionen:
 * Eingebettet teilt sich die Galerie den globalen Namensraum mit fremdem Code,
 * und ein e() gibt es dort womöglich schon. Aus demselben Grund tragen alle IDs
 * den Präfix "sternwarte-gallery-".
 *
 * Alle URLs sind relativ. Sie setzen voraus, dass die Seite aus dem Ordner
 * kommt, in dem index.php, image.php und assets/ liegen.
 */
final class GalleryView
{
    public function __construct(private readonly string $galleryTitle)
    {
    }

    /**
     * Der Seitentitel, etwa "Mondfinsternis – Sternwarte-Galerie". Klartext,
     * noch nicht für HTML escaped.
     *
     * @param array{name: string} $folder
     */
    public function pageTitle(array $folder): string
    {
        return $folder['name'] . ' – ' . $this->galleryTitle;
    }

    /**
     * @param array{
     *     path: string,
     *     name: string,
     *     parentPath: ?string,
     *     folders: list<array{name: string, path: string}>,
     *     images: list<array{name: string, path: string, width: int, height: int, version: string}>
     * } $folder Ergebnis von Gallery::browse()
     */
    public function renderFolder(array $folder): string
    {
        return $this->capture(function () use ($folder): void { ?>
            <link rel="stylesheet" href="assets/photoswipe/photoswipe.css">
            <link rel="stylesheet" href="assets/gallery.css">
            <div class="sternwarte-gallery">
              <p><a href="index.php"><?= $this->e($this->galleryTitle) ?></a></p>
              <h1><?= $this->e($folder['name']) ?></h1>

              <?php if ($folder['parentPath'] !== null): ?>
                <p><a href="<?= $this->e($this->folderUrl($folder['parentPath'])) ?>">← Eine Ebene zurück</a></p>
              <?php endif; ?>

              <section aria-labelledby="sternwarte-gallery-folder-title">
                <h2 id="sternwarte-gallery-folder-title">Ordner</h2>
                <?php if ($folder['folders'] === []): ?>
                  <p>Keine Unterordner gefunden.</p>
                <?php else: ?>
                  <ul class="folder-list">
                    <?php foreach ($folder['folders'] as $subfolder): ?>
                      <li><a href="<?= $this->e($this->folderUrl($subfolder['path'])) ?>"><?= $this->e($subfolder['name']) ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </section>

              <?php if ($folder['images'] !== []): ?>
                <section class="images" aria-labelledby="sternwarte-gallery-image-title">
                  <h2 id="sternwarte-gallery-image-title">Bilder in diesem Ordner</h2>
                  <div class="image-grid pswp-gallery" id="sternwarte-gallery-photos">
                    <?php foreach ($folder['images'] as $image): ?>
                      <?php
                        // Das Raster bekommt das kleine Bild, die Lightbox das große.
                        // PhotoSwipe braucht die Maße genau der Datei, die es anzeigt.
                        $thumbnail = ImageVariant::Thumbnail->scale($image['width'], $image['height']);
                        $large = ImageVariant::Large->scale($image['width'], $image['height']);
                      ?>
                      <figure>
                        <a href="<?= $this->e($this->imageUrl($image['path'], ImageVariant::Large, $image['version'])) ?>"
                           data-pswp-width="<?= $large['width'] ?>"
                           data-pswp-height="<?= $large['height'] ?>"
                           data-cropped="true"
                           target="_blank"
                           rel="noopener">
                          <img src="<?= $this->e($this->imageUrl($image['path'], ImageVariant::Thumbnail, $image['version'])) ?>"
                               width="<?= $thumbnail['width'] ?>"
                               height="<?= $thumbnail['height'] ?>"
                               loading="lazy"
                               decoding="async"
                               alt="<?= $this->e($image['name']) ?>">
                        </a>
                        <figcaption><?= $this->e($image['name']) ?></figcaption>
                      </figure>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; ?>
            </div>
            <?php if ($folder['images'] !== []): ?>
              <script type="module">
                // Das "./" ist Pflicht: Ohne hält der Browser "assets/…" für einen
                // Paketnamen und lädt gar nichts. Aufgelöst wird gegen diese Seite.
                import PhotoSwipeLightbox from './assets/photoswipe/photoswipe-lightbox.esm.min.js';

                const lightbox = new PhotoSwipeLightbox({
                  gallery: '#sternwarte-gallery-photos',
                  children: 'a',
                  // Der Kern kommt erst beim ersten Öffnen eines Bildes.
                  pswpModule: () => import('./assets/photoswipe/photoswipe.esm.min.js')
                });

                lightbox.init();
              </script>
            <?php endif; ?>
        <?php });
    }

    /**
     * Das Seitengerüst der eigenständigen Galerie. Hier und nur hier steht, was
     * die ganze Seite gestaltet: Ränder, Schrift, Farben, die zentrierte Spalte.
     * Eingebettet übernimmt das die Website.
     *
     * @param string $title   Klartext, wird hier escaped
     * @param string $content fertiges HTML aus renderFolder()
     */
    public function renderPage(string $title, string $content): string
    {
        return $this->capture(function () use ($title, $content): void { ?>
            <!doctype html>
            <html lang="de">
              <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title><?= $this->e($title) ?></title>
                <style>
                  body { margin: 0; font: 16px/1.5 system-ui, sans-serif; color: #172033; }
                  main { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; }
                  a { color: #1d4ed8; }
                </style>
              </head>
              <body>
                <main>
                  <?= $content ?>
                </main>
              </body>
            </html>
        <?php });
    }

    /** Kurzform für die Ausgabe von Text in HTML. */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function folderUrl(string $path): string
    {
        return $path === '' ? 'index.php' : 'index.php?' . http_build_query(['folder' => $path], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Das "v" ist der Fingerabdruck des Originals. Der Server braucht es nicht – er
     * liest den aktuellen Stand ohnehin selbst. Es steht in der URL, damit ein
     * ausgetauschtes Bild eine *andere* URL bekommt: Nur so merkt der Browser, dass
     * seine gespeicherte Kopie nicht mehr gilt.
     */
    private function imageUrl(string $path, ImageVariant $variant, string $version): string
    {
        return 'image.php?' . http_build_query([
            'file' => $path,
            'variant' => $variant->value,
            'v' => $version,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Fängt die Ausgabe einer Vorlage als Text auf, statt sie zu senden. Bricht
     * die Vorlage ab, wird der Puffer trotzdem geschlossen und nichts gesendet.
     *
     * Die Vorlagen sind für die Lesbarkeit im Code eingerückt; trim() nimmt die
     * Einrückung vorn wieder weg, damit <!doctype html> ganz am Anfang steht.
     */
    private function capture(\Closure $template): string
    {
        ob_start();

        try {
            $template();

            return trim((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
