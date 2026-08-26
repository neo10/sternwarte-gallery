<?php

declare(strict_types=1);

namespace SternwarteGallery;

/** Liest die direkten Ordner und Bilder eines einzelnen Galerieordners. */
final class GalleryDirectoryBrowser
{
    public function __construct(
        private readonly GalleryPathResolver $paths,
        private readonly ImageFile $images,
    ) {
    }

    /**
     * @return array{
     *     path: string,
     *     name: string,
     *     parentPath: ?string,
     *     folders: list<array{name: string, path: string}>,
     *     images: list<array{name: string, path: string, width: int, height: int, version: string}>
     * }|null
     */
    public function browse(string $relativePath = ''): ?array
    {
        $directory = $this->paths->directory($relativePath);
        if ($directory === null) {
            return null;
        }

        $folders = [];
        $images = [];

        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entryPath = $this->paths->join($relativePath, $entry->getFilename());

            if ($entry->isDir()) {
                $folders[] = ['name' => $entry->getFilename(), 'path' => $entryPath];
                continue;
            }

            if (!$entry->isFile() || !$this->images->isSupported($entry->getFilename())) {
                continue;
            }

            $dimensions = $this->images->dimensions($entry->getPathname());
            $version = $this->images->fingerprint($entry->getPathname());
            if ($dimensions === null || $version === null) {
                continue;
            }

            $images[] = [
                'name' => $entry->getFilename(),
                'path' => $entryPath,
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                // Wandert in die Bild-URL. Ändert sich das Bild, ändert sich die
                // URL – und der Browser holt es neu, statt seine alte Kopie zu
                // zeigen.
                'version' => $version,
                'modifiedAt' => $entry->getMTime(),
            ];
        }

        // Ordner absteigend, damit die neuesten Jahrgänge oben stehen.
        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($b['name'], $a['name']));

        // Bilder chronologisch; bei gleichem Zeitstempel entscheidet der Name.
        usort($images, static fn (array $a, array $b): int => [$a['modifiedAt'], $a['name']] <=> [$b['modifiedAt'], $b['name']]);

        $path = $this->paths->normalize($relativePath);

        return [
            'path' => $path,
            'name' => $path === '' ? basename($directory) : basename($path),
            'parentPath' => $this->parentPath($path),
            'folders' => $folders,
            'images' => array_map($this->toPublicImage(...), $images),
        ];
    }

    /**
     * @param array{name: string, path: string, width: int, height: int, version: string, modifiedAt: int} $image
     *
     * @return array{name: string, path: string, width: int, height: int, version: string}
     */
    private function toPublicImage(array $image): array
    {
        unset($image['modifiedAt']);

        return $image;
    }

    private function parentPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $parent = dirname($path);

        return $parent === '.' ? '' : $parent;
    }
}
