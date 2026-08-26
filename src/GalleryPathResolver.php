<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Prüft und übersetzt relative Galeriepfade sicher in echte Dateisystempfade.
 *
 * Einzige Stelle im Projekt, die entscheidet, ob ein von außen kommender Pfad
 * benutzt werden darf. Alles andere fragt hier nach, statt selbst zu prüfen.
 */
final class GalleryPathResolver
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /** Existierender Ordner innerhalb des Basisordners, sonst null. */
    public function directory(string $relativePath): ?string
    {
        if (!$this->isSafeRelativePath($relativePath, allowEmpty: true)) {
            return null;
        }

        $path = $this->resolve($relativePath);

        return $path !== null && is_dir($path) ? $path : null;
    }

    /** Existierende Datei innerhalb des Basisordners, sonst null. */
    public function file(string $relativePath): ?string
    {
        if (!$this->isSafeRelativePath($relativePath)) {
            return null;
        }

        $path = $this->resolve($relativePath);

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * Absoluter Pfad für einen relativen Pfad, der noch nicht existieren muss.
     * Wird für Zieldateien gebraucht, die erst geschrieben werden.
     *
     * @throws \InvalidArgumentException bei einem unsicheren Pfad
     */
    public function absolute(string $relativePath): string
    {
        if (!$this->isSafeRelativePath($relativePath, allowEmpty: true)) {
            throw new \InvalidArgumentException(sprintf('Unsicherer relativer Pfad: "%s".', $relativePath));
        }

        $relativePath = $this->normalize($relativePath);
        if ($relativePath === '') {
            return $this->baseDir;
        }

        return $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    public function join(string $directory, string $name): string
    {
        $directory = $this->normalize($directory);

        return $directory === '' ? $name : $directory . '/' . $name;
    }

    public function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Ein Pfad ist sicher, wenn er ausschließlich aus einfachen Namensteilen
     * besteht – kein "..", kein leeres Segment, kein absoluter Pfad.
     */
    public function isSafeRelativePath(string $path, bool $allowEmpty = false): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '') {
            return $allowEmpty;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || basename($part) !== $part) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ob ein absoluter Pfad echt unterhalb des Basisordners liegt.
     *
     * Der Basisordner selbst zählt nicht dazu – wer aufräumt, soll ihn nicht
     * mitlöschen können. Verglichen wird über realpath(), Symlinks und ".."
     * führen also nicht daran vorbei.
     */
    public function contains(string $absolutePath): bool
    {
        $basePath = realpath($this->baseDir);
        $path = realpath($absolutePath);

        if ($basePath === false || $path === false) {
            return false;
        }

        return str_starts_with($path, rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /** Zweite Verteidigungslinie: realpath muss im Basisordner landen. */
    private function resolve(string $relativePath): ?string
    {
        $basePath = realpath($this->baseDir);
        $path = realpath($this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($basePath === false || $path === false) {
            return null;
        }

        return $path === $basePath || $this->contains($path) ? $path : null;
    }
}
