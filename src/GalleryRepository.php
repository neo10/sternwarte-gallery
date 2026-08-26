<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Lesender Zugriff auf die Galerie – die Schnittstelle, gegen die das Frontend
 * programmiert. Bewusst klein gehalten: nur Ordner ansehen und einen Bildpfad
 * zum Ausliefern holen.
 */
interface GalleryRepository
{
    /**
     * Inhalt eines Ordners, oder null wenn es ihn nicht gibt bzw. er außerhalb
     * des Basisordners läge.
     *
     * @return array{
     *     path: string,
     *     name: string,
     *     parentPath: ?string,
     *     folders: list<array{name: string, path: string}>,
     *     images: list<array{name: string, path: string, width: int, height: int, version: string}>
     * }|null
     */
    public function browse(string $relativePath = ''): ?array;

    /**
     * Datei, die für dieses Bild ausgeliefert werden soll – bevorzugt die
     * angeforderte Variante, sonst das Original. null, wenn es das Bild nicht gibt.
     *
     * Geliefert wird immer die aktuelle Fassung; ein "version" aus einer alten
     * URL kann nie eine veraltete Datei hervorholen.
     */
    public function imagePath(string $relativePath, ImageVariant $variant): ?string;
}
