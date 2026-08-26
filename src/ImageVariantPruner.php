<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Findet und entfernt verwaiste Dateien im Varianten-Cache.
 *
 * Nötig wird das durch alles, was außerhalb der Anwendung passiert: Wer ein
 * Bild oder einen ganzen Ordner per FTP löscht, löst im Code nichts aus – die
 * zugehörigen Varianten bleiben liegen. Ein *ausgetauschtes* Bild räumt der
 * ImageVariantGenerator dagegen selbst auf, dort ist der Anlass bekannt.
 *
 * Zwei Entscheidungen machen das Aufräumen ungefährlich:
 *
 * 1. Suchen und Löschen sind getrennt. findGarbage() ändert nichts. Ein
 *    Trockenlauf ist deshalb keine Sonderbehandlung im Code, sondern schlicht
 *    der Verzicht auf den zweiten Schritt.
 * 2. remove() prüft *vorab für alle* Pfade, dass sie unterhalb des
 *    Cache-Ordners liegen, und bricht sonst ab, bevor irgendetwas gelöscht ist.
 *    Der Bilder-Ordner wird ausschließlich gelesen.
 *
 * Und selbst ein Fehlgriff bliebe folgenlos: Der Cache ist ein reines Derivat,
 * eine zu viel gelöschte Variante entsteht beim nächsten Aufruf neu.
 */
final class ImageVariantPruner
{
    /** Endung jeder erzeugten Variante. */
    private const SUFFIX = '.webp';

    public function __construct(
        private readonly GalleryPathResolver $galleryPaths,
        private readonly GalleryPathResolver $cachePaths,
        private readonly ImageFile $images,
    ) {
    }

    /**
     * Sucht Cache-Einträge, zu denen es kein passendes Original mehr gibt.
     * Ändert nichts.
     *
     * @return array{files: list<string>, directories: list<string>, kept: int}
     */
    public function findGarbage(): array
    {
        $files = [];
        $directories = [];
        $kept = 0;

        $cacheDir = $this->cachePaths->baseDir();
        if (is_dir($cacheDir)) {
            $this->scan($cacheDir, '', $files, $directories, $kept);
        }

        return ['files' => $files, 'directories' => $directories, 'kept' => $kept];
    }

    /**
     * Löscht die übergebenen Pfade – ausschließlich innerhalb des Caches.
     *
     * @param list<string> $paths
     *
     * @return int Anzahl tatsächlich entfernter Einträge
     *
     * @throws \RuntimeException wenn ein Pfad außerhalb des Caches liegt; dann
     *                           wurde noch nichts gelöscht
     */
    public function remove(array $paths): int
    {
        foreach ($paths as $path) {
            if (!$this->cachePaths->contains($path)) {
                throw new \RuntimeException(sprintf('Pfad liegt außerhalb des Caches: "%s".', $path));
            }
        }

        // Erst die Dateien, dann die Ordner von innen nach außen – ein Ordner
        // lässt sich nur entfernen, wenn er bereits leer ist.
        $directories = array_values(array_filter($paths, 'is_dir'));
        $files = array_values(array_filter($paths, static fn (string $path): bool => !is_dir($path)));
        usort($directories, fn (string $a, string $b): int => $this->depth($b) <=> $this->depth($a));

        $removed = 0;

        foreach ($files as $path) {
            if (@unlink($path)) {
                ++$removed;
            }
        }

        foreach ($directories as $path) {
            if (@rmdir($path)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Läuft einen Ordner durch und sammelt Müll ein.
     *
     * @param list<string> $files
     * @param list<string> $directories
     *
     * @return bool ob nach dem Aufräumen nichts von diesem Ordner übrig bliebe
     */
    private function scan(string $directory, string $relativeDirectory, array &$files, array &$directories, int &$kept): bool
    {
        $becomesEmpty = true;

        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $relativePath = $relativeDirectory === ''
                ? $entry->getFilename()
                : $relativeDirectory . '/' . $entry->getFilename();

            if ($entry->isDir()) {
                // Unterordner zuerst: So stehen tiefere Ordner vor ihren
                // Elternordnern in der Liste und lassen sich in dieser
                // Reihenfolge entfernen.
                if ($this->scan($entry->getPathname(), $relativePath, $files, $directories, $kept)) {
                    $directories[] = $entry->getPathname();
                } else {
                    $becomesEmpty = false;
                }

                continue;
            }

            if ($this->isGarbage($relativePath)) {
                $files[] = $entry->getPathname();
            } else {
                ++$kept;
                $becomesEmpty = false;
            }
        }

        return $becomesEmpty;
    }

    /**
     * Ein Cache-Eintrag ist Müll, wenn sich aus seinem Pfad kein Original mit
     * genau diesem Fingerabdruck ableiten lässt.
     *
     * Erwartet wird: <variante>/<pfad zum bild>.<fingerabdruck>.webp
     */
    private function isGarbage(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);
        $variant = array_shift($segments);

        // Alles, was nicht unter einem bekannten Variantennamen liegt, gehört
        // uns nicht – etwa eine umbenannte oder abgeschaffte Variante.
        if (ImageVariant::tryFrom($variant) === null || $segments === []) {
            return true;
        }

        $name = implode('/', $segments);
        if (!str_ends_with($name, self::SUFFIX)) {
            // Zum Beispiel eine liegengebliebene .tmp-Datei eines Abbruchs.
            return true;
        }

        $name = substr($name, 0, -strlen(self::SUFFIX));
        $separator = strrpos($name, '.');
        if ($separator === false) {
            return true;
        }

        $fingerprint = substr($name, $separator + 1);
        $sourceRelativePath = substr($name, 0, $separator);

        $sourcePath = $this->galleryPaths->file($sourceRelativePath);
        if ($sourcePath === null) {
            return true;
        }

        return $this->images->fingerprint($sourcePath) !== $fingerprint;
    }

    private function depth(string $path): int
    {
        return substr_count(str_replace('\\', '/', $path), '/');
    }
}
