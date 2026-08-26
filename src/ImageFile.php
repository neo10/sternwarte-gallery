<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Alles, was über eine einzelne Bilddatei bekannt sein muss: ob sie unterstützt
 * wird, wie groß sie ist, welcher MIME-Typ dazugehört und wie man sie in den
 * Speicher lädt.
 */
final class ImageFile
{
    /** @var array<string, string> Dateiendung => MIME-Typ */
    private const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /** EXIF-Ausrichtungen, bei denen Breite und Höhe vertauscht sind. */
    private const ROTATED_ORIENTATIONS = [5, 6, 7, 8];

    /** EXIF-Ausrichtungen, die zusätzlich horizontal gespiegelt werden müssen. */
    private const MIRRORED_ORIENTATIONS = [2, 4, 5, 7];

    /** So viel Speicher belegt GD je Bildpunkt eines Truecolor-Bildes. */
    private const BYTES_PER_PIXEL = 4;

    /** Aufschlag für das Zielbild und den übrigen Skriptbedarf. */
    private const MEMORY_HEADROOM_BYTES = 32 * 1024 * 1024;

    /** Obergrenze, bis zu der das Speicherlimit angehoben wird. */
    private const MEMORY_CEILING_BYTES = 1024 * 1024 * 1024;

    public function isSupported(string $path): bool
    {
        return isset(self::MIME_TYPES[$this->extension($path)]);
    }

    public function mimeType(string $path): string
    {
        return self::MIME_TYPES[$this->extension($path)] ?? 'application/octet-stream';
    }

    /** Ob diese PHP-Installation überhaupt WebP-Varianten erzeugen kann. */
    public function canCreateVariants(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagewebp');
    }

    /**
     * Kennzeichen des aktuellen Inhalts, gebildet aus Größe und Änderungszeit.
     *
     * Das Kennzeichen wandert in den Pfad jeder abgeleiteten Variante und in
     * die Bild-URL. Ändert sich die Datei, ändert sich das Kennzeichen – und
     * damit beides. Eine veraltete Variante liegt dann unter einem anderen
     * Namen und wird nie wieder gefunden; ein Browser sieht eine neue URL und
     * holt das Bild neu.
     *
     * Deshalb gibt es nirgends mehr eine Prüfung "ist der Cache noch aktuell?".
     * Die Frage stellt sich nicht: Existiert die Datei, stammt sie von genau
     * diesem Original.
     *
     * Nicht erkannt wird der Fall, dass eine ersetzte Datei auf die Sekunde
     * denselben Zeitstempel und dieselbe Größe hat wie ihre Vorgängerin.
     */
    public function fingerprint(string $path): ?string
    {
        $size = @filesize($path);
        $modifiedAt = @filemtime($path);

        if ($size === false || $modifiedAt === false) {
            return null;
        }

        return substr(sha1($size . ':' . $modifiedAt), 0, 12);
    }

    /**
     * Abmessungen so, wie das Bild angezeigt wird – EXIF-Drehung ist bereits
     * eingerechnet, damit sie zu den erzeugten Varianten passen.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(string $path): ?array
    {
        $size = @getimagesize($path);
        if ($size === false) {
            return null;
        }

        [$width, $height] = [$size[0], $size[1]];
        if (\in_array($this->orientation($path), self::ROTATED_ORIENTATIONS, true)) {
            [$width, $height] = [$height, $width];
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Lädt das Bild in den Speicher und richtet es nach EXIF aus.
     * Gibt null zurück, wenn das Format nicht gelesen werden kann.
     *
     * @param int $additionalBytes Speicher, den der Aufrufer zusätzlich braucht,
     *                             während dieses Bild geladen ist – etwa das
     *                             Zielbild einer Verkleinerung.
     *
     * @throws \RuntimeException wenn das Bild nicht in den erlaubten Speicher passt
     */
    public function load(string $path, int $additionalBytes = 0): ?\GdImage
    {
        $size = @getimagesize($path);
        if ($size === false || !\extension_loaded('gd')) {
            return null;
        }

        $orientation = $this->orientation($path);
        $this->reserveMemoryFor($path, $size[0], $size[1], $orientation !== 1, $additionalBytes);

        $image = $this->createFrom($path, $size[2]);
        if ($image === null) {
            return null;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $this->applyOrientation($image, $orientation);
    }

    /**
     * GD legt jedes Bild unkomprimiert als Bitmap ab: Breite x Höhe x 4 Byte.
     * Ein 31-Megapixel-Foto braucht damit rund 121 MB – mehr als PHPs
     * Standardlimit von 128 MB, und überschritten wird das mitten in
     * imagecreatefromjpeg() mit einem fatalen Fehler, der die ganze Anfrage
     * abbricht. Deshalb wird der Bedarf vorher berechnet und das Limit
     * angehoben, solange es im Rahmen bleibt.
     *
     * @throws \RuntimeException
     */
    private function reserveMemoryFor(string $path, int $width, int $height, bool $rotates, int $additionalBytes): void
    {
        $limit = $this->memoryLimitInBytes();
        if ($limit < 0) {
            return;
        }

        // imagerotate() legt ein zweites Bitmap an, bevor es das erste freigibt.
        $bitmaps = $rotates ? 2 : 1;
        $needed = $width * $height * self::BYTES_PER_PIXEL * $bitmaps
            + $additionalBytes
            + self::MEMORY_HEADROOM_BYTES;
        $required = memory_get_usage(true) + $needed;

        if ($limit >= $required) {
            return;
        }

        // Das Limit anzuheben belegt noch keinen Speicher – es erlaubt nur, ihn
        // zu belegen. Scheitert das (abgeschaltetes ini_set, absurd großes Bild),
        // ist eine Ausnahme das einzig Brauchbare: Ein überschrittenes Limit
        // bricht die Anfrage sonst fatal ab, mitten in der Bildausgabe.
        if ($required <= self::MEMORY_CEILING_BYTES && $this->raiseMemoryLimit($required)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Bild "%s" (%dx%d) braucht rund %d MB Arbeitsspeicher, erlaubt sind %d MB.',
            basename($path),
            $width,
            $height,
            intdiv($needed, 1048576),
            intdiv($limit, 1048576),
        ));
    }

    private function raiseMemoryLimit(int $bytes): bool
    {
        if (!\function_exists('ini_set')) {
            return false;
        }

        return @\ini_set('memory_limit', (string) $bytes) !== false;
    }

    private function memoryLimitInBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $factor = match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) $limit * $factor;
    }

    private function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function createFrom(string $path, int $imageType): ?\GdImage
    {
        $creator = match ($imageType) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            default => null,
        };

        if ($creator === null || !\function_exists($creator)) {
            return null;
        }

        $image = @$creator($path);

        return $image === false ? null : $image;
    }

    /** EXIF-Ausrichtung (1–8); 1 bedeutet "keine Korrektur nötig". */
    private function orientation(string $path): int
    {
        if (!\function_exists('exif_read_data') || $this->mimeType($path) !== 'image/jpeg') {
            return 1;
        }

        $exif = @exif_read_data($path);
        $orientation = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return \is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated === false) {
            return $image;
        }

        if (\in_array($orientation, self::MIRRORED_ORIENTATIONS, true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        imagesavealpha($rotated, true);

        return $rotated;
    }
}
