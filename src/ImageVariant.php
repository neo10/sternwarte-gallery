<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Die Bildgrößen, die ausgeliefert werden. Jede Variante kennt ihre eigenen
 * Grenzen – dadurch gibt es genau eine Stelle, an der Breite und Qualität stehen.
 */
enum ImageVariant: string
{
    /** Für das Raster in der Ordneransicht. */
    case Thumbnail = 'thumbnail';

    /** Für die Lightbox / Vollbildanzeige. */
    case Large = 'large';

    public static function fromNameOrDefault(string $value, self $default = self::Large): self
    {
        return self::tryFrom($value) ?? $default;
    }

    public function maxWidth(): int
    {
        return match ($this) {
            self::Thumbnail => 480,
            self::Large => 2560,
        };
    }

    public function quality(): int
    {
        return match ($this) {
            self::Thumbnail => 78,
            self::Large => 85,
        };
    }

    /**
     * Abmessungen des Originals, heruntergerechnet auf diese Variante.
     * Kleinere Bilder werden nie vergrößert.
     *
     * @return array{width: int, height: int}
     */
    public function scale(int $width, int $height): array
    {
        $factor = min(1.0, $this->maxWidth() / max(1, $width));

        return [
            'width' => max(1, (int) round($width * $factor)),
            'height' => max(1, (int) round($height * $factor)),
        ];
    }
}
