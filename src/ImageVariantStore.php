<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Weiß, wo eine erzeugte Bildvariante im Cache liegt.
 *
 * Der Cache spiegelt die Ordnerstruktur der Galerie unterhalb des
 * Variantennamens und trägt zusätzlich das Kennzeichen des Originals im
 * Dateinamen:
 *
 *     <cache>/thumbnail/2024/Album/bild.jpg.a1b2c3d4e5f6.webp
 *                                           ^^^^^^^^^^^^
 *                                           ImageFile::fingerprint()
 *
 * Dadurch bekommt ein ausgetauschtes Bild automatisch einen neuen Pfad. Die
 * Frage "ist diese Variante noch aktuell?" kann hier gar nicht erst entstehen:
 * Gefunden wird nur, was zum aktuellen Original gehört.
 */
final class ImageVariantStore
{
    /** Endung jeder erzeugten Variante. */
    private const SUFFIX = '.webp';

    public function __construct(private readonly GalleryPathResolver $cachePaths)
    {
    }

    public static function inDirectory(string $cacheDir): self
    {
        return new self(new GalleryPathResolver($cacheDir));
    }

    /** Pfad der bereits erzeugten Variante, sonst null. */
    public function existingPath(ImageVariant $variant, string $sourceRelativePath, string $fingerprint): ?string
    {
        $relativePath = $this->relativePath($variant, $sourceRelativePath, $fingerprint);

        return $relativePath === null ? null : $this->cachePaths->file($relativePath);
    }

    /**
     * Pfad, unter dem die Variante abgelegt wird – existiert noch nicht zwingend.
     *
     * @throws \InvalidArgumentException bei einem unsicheren Quellpfad
     */
    public function destinationPath(ImageVariant $variant, string $sourceRelativePath, string $fingerprint): string
    {
        $relativePath = $this->relativePath($variant, $sourceRelativePath, $fingerprint);
        if ($relativePath === null) {
            throw new \InvalidArgumentException(sprintf('Ungültiger Bildpfad: "%s".', $sourceRelativePath));
        }

        return $this->cachePaths->absolute($relativePath);
    }

    /**
     * Entfernt die Varianten früherer Fassungen desselben Bildes.
     *
     * Wird direkt nach dem Schreiben einer neuen Fassung aufgerufen: Ab dann
     * sind die älteren unerreichbar, weil niemand mehr ihren Fingerabdruck
     * kennt. Sie hier gleich mitzunehmen ist genauer als jedes spätere
     * Aufräumen – an dieser Stelle steht ohne Suchen fest, welche Dateien
     * gemeint sind.
     *
     * Fehlschläge werden bewusst geschluckt: Eine nicht gelöschte Karteileiche
     * kostet Platz, mehr nicht.
     *
     * @return int Anzahl entfernter Dateien
     */
    public function removeOtherVersions(ImageVariant $variant, string $sourceRelativePath, string $keepFingerprint): int
    {
        $keepPath = $this->destinationPath($variant, $sourceRelativePath, $keepFingerprint);
        $directory = dirname($keepPath);

        $entries = @scandir($directory);
        if ($entries === false) {
            return 0;
        }

        $keepName = basename($keepPath);
        $prefix = basename($this->cachePaths->normalize($sourceRelativePath)) . '.';
        $removed = 0;

        foreach ($entries as $entry) {
            if ($entry === $keepName || !str_starts_with($entry, $prefix) || !str_ends_with($entry, self::SUFFIX)) {
                continue;
            }

            // Zwischen Bildname und ".webp" darf nur der Fingerabdruck stehen.
            // Ohne diese Prüfung würde zu "bild.jpg" auch die Variante eines
            // Bildes namens "bild.jpg.alt.jpg" gelöscht – deren Name enthält an
            // dieser Stelle einen weiteren Punkt.
            $fingerprint = substr($entry, strlen($prefix), -strlen(self::SUFFIX));
            if ($fingerprint === '' || str_contains($fingerprint, '.')) {
                continue;
            }

            if (@unlink($directory . DIRECTORY_SEPARATOR . $entry)) {
                ++$removed;
            }
        }

        return $removed;
    }

    private function relativePath(ImageVariant $variant, string $sourceRelativePath, string $fingerprint): ?string
    {
        if (!$this->cachePaths->isSafeRelativePath($sourceRelativePath)) {
            return null;
        }

        return $variant->value . '/' . $this->cachePaths->normalize($sourceRelativePath) . '.' . $fingerprint . self::SUFFIX;
    }
}
