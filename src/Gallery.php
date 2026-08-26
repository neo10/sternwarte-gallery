<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Fassade der Anwendung: bündelt Pfadprüfung, Ordnerlesen und Bildvarianten zu
 * der kleinen API, die Frontend und CLI benutzen.
 *
 * Die Zusammenbau-Logik steckt in create(); der Konstruktor nimmt fertige
 * Bausteine entgegen, damit sich im Test einzelne Teile austauschen lassen.
 */
final class Gallery implements GalleryRepository
{
    private bool $missingGdReported = false;

    public function __construct(
        private readonly GalleryPathResolver $paths,
        private readonly GalleryDirectoryBrowser $browser,
        private readonly ImageFile $images,
        private readonly ImageVariantStore $variants,
        private readonly ImageVariantGenerator $generator,
        private readonly ImageVariantPruner $pruner,
        private readonly \Closure $authorizationCheck,
    ) {
    }

    /** Baut die Galerie aus den Einstellungen in config.php. */
    public static function fromConfig(GalleryConfig $config): self
    {
        return self::create($config->baseDir, $config->cacheDir, $config->isAuthorized);
    }

    /**
     * @param string   $baseDir            Ordner mit den Originalbildern
     * @param string   $cacheDir           Ordner für die erzeugten Varianten
     * @param \Closure $authorizationCheck Zugriffsprüfung; bewusst ohne
     *                                     Standardwert, damit niemand aus
     *                                     Versehen eine offene Galerie baut
     */
    public static function create(string $baseDir, string $cacheDir, \Closure $authorizationCheck): self
    {
        $paths = new GalleryPathResolver($baseDir);
        $cachePaths = new GalleryPathResolver($cacheDir);
        $images = new ImageFile();
        $variants = new ImageVariantStore($cachePaths);

        return new self(
            $paths,
            new GalleryDirectoryBrowser($paths, $images),
            $images,
            $variants,
            new ImageVariantGenerator($images, $variants),
            new ImageVariantPruner($paths, $cachePaths, $images),
            $authorizationCheck,
        );
    }

    /**
     * Darf der aktuelle Besucher die Galerie sehen?
     *
     * Muss von jedem Web-Einstiegspunkt als erste Handlung aufgerufen werden.
     * Die Prüfung selbst steht in config.php. Die Wartungsbefehle in bin/
     * rufen sie bewusst nicht auf – sie laufen ohne Besucher.
     */
    public function isAuthorized(): bool
    {
        return (bool) ($this->authorizationCheck)();
    }

    public function browse(string $relativePath = ''): ?array
    {
        return $this->browser->browse($relativePath);
    }

    public function imagePath(string $relativePath, ImageVariant $variant): ?string
    {
        $originalPath = $this->originalPath($relativePath);
        if ($originalPath === null) {
            return null;
        }

        // Das Kennzeichen wird immer neu aus der Datei gelesen. Ein "v" aus der
        // Anfrage-URL fließt bewusst nicht ein: Es dient nur dem Browser-Cache,
        // ausgeliefert wird stets die aktuelle Fassung.
        $fingerprint = $this->images->fingerprint($originalPath);
        if ($fingerprint === null) {
            return $originalPath;
        }

        return $this->servableVariantPath($originalPath, $this->paths->normalize($relativePath), $fingerprint, $variant)
            ?? $originalPath;
    }

    /**
     * Erzeugt alle fehlenden Varianten eines Bildes. Für den Warmlauf per CLI
     * oder Cron gedacht und deshalb absichtlich laut: Fehler werden geworfen.
     *
     * @return list<ImageVariant> die neu erzeugten Varianten
     *
     * @throws \RuntimeException
     */
    public function ensureVariants(string $relativePath): array
    {
        $originalPath = $this->originalPath($relativePath);
        if ($originalPath === null) {
            throw new \RuntimeException(sprintf('Kein Bild unter "%s".', $relativePath));
        }

        if (!$this->images->canCreateVariants()) {
            throw new \RuntimeException('PHP-Erweiterung GD mit WebP-Unterstützung fehlt.');
        }

        $fingerprint = $this->images->fingerprint($originalPath);
        if ($fingerprint === null) {
            throw new \RuntimeException(sprintf('Datei nicht lesbar: "%s".', $originalPath));
        }

        return $this->generator->generate($originalPath, $this->paths->normalize($relativePath), $fingerprint);
    }

    /**
     * Sucht verwaiste Cache-Einträge – Varianten gelöschter Bilder, Reste
     * abgebrochener Erzeugungen, leer gewordene Ordner. Ändert nichts.
     *
     * @return array{files: list<string>, directories: list<string>, kept: int}
     */
    public function findCacheGarbage(): array
    {
        return $this->pruner->findGarbage();
    }

    /**
     * Entfernt zuvor gefundene Cache-Einträge.
     *
     * @param list<string> $paths
     *
     * @return int Anzahl tatsächlich entfernter Einträge
     *
     * @throws \RuntimeException wenn ein Pfad außerhalb des Caches liegt
     */
    public function removeCacheGarbage(array $paths): int
    {
        return $this->pruner->remove($paths);
    }

    /** MIME-Typ einer von imagePath() gelieferten Datei. */
    public function mimeType(string $path): string
    {
        return $this->images->mimeType($path);
    }

    /** Pfad des Originals, oder null wenn es keine unterstützte Bilddatei ist. */
    public function originalPath(string $relativePath): ?string
    {
        $path = $this->paths->file($relativePath);

        return $path !== null && $this->images->isSupported($path) ? $path : null;
    }

    /**
     * Beim Ausliefern darf ein Variantenfehler die Seite nicht kaputt machen –
     * hier wird deshalb protokolliert und mit null das Original signalisiert.
     */
    private function servableVariantPath(string $originalPath, string $relativePath, string $fingerprint, ImageVariant $variant): ?string
    {
        if (!$this->images->canCreateVariants()) {
            $this->reportMissingGdOnce();

            return null;
        }

        try {
            // Bewusst ohne Einschränkung auf die angefragte Variante: Das Laden
            // des Originals ist der teure Teil, das Verkleinern kostet fast
            // nichts. Wer das Thumbnail anfordert, bekommt die große Fassung
            // also gleich mit – der spätere Klick in die Lightbox ist dann
            // sofort bedient, statt das Original erneut zu laden.
            $this->generator->generate($originalPath, $relativePath, $fingerprint);
        } catch (\Throwable $error) {
            error_log(sprintf('Bildvariante "%s" für "%s" fehlgeschlagen: %s', $variant->value, $relativePath, $error->getMessage()));

            return null;
        }

        return $this->variants->existingPath($variant, $relativePath, $fingerprint);
    }

    private function reportMissingGdOnce(): void
    {
        if ($this->missingGdReported) {
            return;
        }

        $this->missingGdReported = true;
        error_log('GD mit WebP fehlt – es werden Originalbilder in voller Größe ausgeliefert.');
    }
}
