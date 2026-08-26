<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Erzeugt aus einem Originalbild die WebP-Varianten für Raster und Lightbox.
 *
 * Erzeugt wird nur, was noch nicht da ist. Weil der Cache-Pfad das Kennzeichen
 * des Originals enthält, ist "noch nicht da" gleichbedeutend mit "veraltet" –
 * beides ist derselbe fehlende Pfad. Eine Frische-Prüfung braucht es deshalb
 * nicht, und der Aufruf bleibt beliebig oft wiederholbar.
 *
 * Beim Schreiben einer neuen Fassung fliegen die Varianten der vorherigen
 * Fassung desselben Bildes gleich mit raus. Ein ausgetauschtes Bild hinterlässt
 * dadurch keinen Müll. Varianten gelöschter Bilder und Ordner bleiben liegen –
 * für die braucht es einen eigenen Aufräumlauf, hier fehlt der Anlass.
 *
 * Der teure Teil ist nicht das Verkleinern, sondern das Laden: GD hält ein Bild
 * unkomprimiert im Speicher, ein 31-Megapixel-Foto belegt rund 121 MB. Deshalb
 * werden die Varianten als Kaskade erzeugt – von der größten zur kleinsten,
 * jede aus der vorherigen. Das Original wird höchstens einmal geladen, und gar
 * nicht, wenn schon eine ausreichend große Variante desselben Originals vorliegt.
 */
final class ImageVariantGenerator
{
    /** So viel Speicher belegt GD je Bildpunkt eines Truecolor-Bildes. */
    private const BYTES_PER_PIXEL = 4;

    public function __construct(
        private readonly ImageFile $images,
        private readonly ImageVariantStore $variants,
    ) {
    }

    /**
     * @param string                  $fingerprint Kennzeichen des Originals, siehe ImageFile::fingerprint()
     * @param list<ImageVariant>|null $wanted      null bedeutet "alle Varianten"
     *
     * @return list<ImageVariant> die tatsächlich neu erzeugten Varianten
     *
     * @throws \RuntimeException wenn keine Vorlage gelesen oder die Variante nicht geschrieben werden kann
     */
    public function generate(string $sourcePath, string $relativePath, string $fingerprint, ?array $wanted = null): array
    {
        $missing = [];

        foreach ($wanted ?? ImageVariant::cases() as $variant) {
            if ($this->variants->existingPath($variant, $relativePath, $fingerprint) !== null) {
                continue;
            }

            $missing[] = [
                'variant' => $variant,
                'destination' => $this->variants->destinationPath($variant, $relativePath, $fingerprint),
            ];
        }

        if ($missing === []) {
            return [];
        }

        // Größte zuerst – jede weitere Variante entsteht dann aus der vorherigen.
        usort($missing, static fn (array $a, array $b): int => $b['variant']->maxWidth() <=> $a['variant']->maxWidth());

        $image = $this->openSource($sourcePath, $relativePath, $fingerprint, $missing);

        foreach ($missing as ['variant' => $variant, 'destination' => $destination]) {
            // Das verkleinerte Bild ersetzt die Vorlage: Die größere Fassung wird
            // nicht mehr gebraucht und gibt ihren Speicher sofort frei.
            $image = $this->writeVariant($image, $variant, $destination);

            // Erst schreiben, dann aufräumen – in dieser Reihenfolge gibt es
            // keinen Moment ohne gültige Variante.
            $this->variants->removeOtherVersions($variant, $relativePath, $fingerprint);
        }

        return array_column($missing, 'variant');
    }

    /**
     * Billigste taugliche Vorlage: eine bereits erzeugte Variante desselben
     * Originals, die mindestens so breit ist wie das größte Ziel – sonst das
     * Original selbst.
     *
     * @param non-empty-list<array{variant: ImageVariant, destination: string}> $missing
     *
     * @throws \RuntimeException
     */
    private function openSource(string $sourcePath, string $relativePath, string $fingerprint, array $missing): \GdImage
    {
        $largest = $missing[0]['variant'];

        // Während des Verkleinerns liegen zwei Bilder gleichzeitig im Speicher:
        // die Vorlage und das Ziel. Der Lader muss das wissen, sonst reserviert
        // er zu knapp und PHP bricht mitten im Skalieren fatal ab.
        $targetBytes = $this->largestTargetBytes($sourcePath, $largest);

        foreach ($this->usableVariants($largest, array_column($missing, 'variant')) as $candidate) {
            $candidatePath = $this->variants->existingPath($candidate, $relativePath, $fingerprint);
            if ($candidatePath === null) {
                continue;
            }

            $image = $this->images->load($candidatePath, $targetBytes);
            if ($image !== null) {
                return $image;
            }
        }

        $image = $this->images->load($sourcePath, $targetBytes);
        if ($image === null) {
            throw new \RuntimeException(sprintf('Bild kann nicht gelesen werden: "%s".', $sourcePath));
        }

        return $image;
    }

    /**
     * Varianten, die als Vorlage taugen: breit genug und nicht selbst gerade
     * fällig. Kleinste zuerst, denn die ist am billigsten zu laden.
     *
     * @param list<ImageVariant> $beingCreated
     *
     * @return list<ImageVariant>
     */
    private function usableVariants(ImageVariant $target, array $beingCreated): array
    {
        $usable = array_filter(
            ImageVariant::cases(),
            static fn (ImageVariant $variant): bool => $variant->maxWidth() >= $target->maxWidth()
                && !\in_array($variant, $beingCreated, true),
        );

        usort($usable, static fn (ImageVariant $a, ImageVariant $b): int => $a->maxWidth() <=> $b->maxWidth());

        return $usable;
    }

    /** Speicherbedarf des größten Zielbildes, das gleich erzeugt wird. */
    private function largestTargetBytes(string $sourcePath, ImageVariant $largest): int
    {
        $dimensions = $this->images->dimensions($sourcePath);
        if ($dimensions === null) {
            return 0;
        }

        ['width' => $width, 'height' => $height] = $largest->scale($dimensions['width'], $dimensions['height']);

        return $width * $height * self::BYTES_PER_PIXEL;
    }

    /** @return \GdImage das verkleinerte Bild, als Vorlage für die nächstkleinere Variante */
    private function writeVariant(\GdImage $source, ImageVariant $variant, string $destination): \GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        ['width' => $width, 'height' => $height] = $variant->scale($sourceWidth, $sourceHeight);

        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        $this->ensureDirectory(dirname($destination));

        // Eindeutiger Zwischenname: parallele Erzeuger dürfen sich nicht
        // gegenseitig die halbfertige Datei überschreiben. rename() ist atomar,
        // Leser sehen deshalb nie ein unvollständiges Bild.
        $temporaryPath = $destination . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            if (!imagewebp($target, $temporaryPath, $variant->quality()) || !rename($temporaryPath, $destination)) {
                throw new \RuntimeException(sprintf('Bildvariante kann nicht gespeichert werden: "%s".', $destination));
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return $target;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Zielordner kann nicht erstellt werden: "%s".', $directory));
        }
    }
}
