<?php

declare(strict_types=1);

namespace SternwarteGallery;

/**
 * Alle Einstellungen der Galerie an einem Ort.
 *
 * Diese Klasse wird nicht bearbeitet – sie legt nur fest, welche Einstellungen
 * es gibt und prüft sie beim Start. Gesetzt werden die Werte in `config.php`
 * im Projektstamm; das ist die einzige Datei, die man beim Einbinden anfassen
 * muss.
 *
 * Fehler fallen dadurch beim ersten Aufruf auf und nicht irgendwann mitten im
 * Betrieb.
 */
final class GalleryConfig
{
    /**
     * @param string   $title        Überschrift und Browser-Titel der Galerie
     * @param string   $baseDir      Ordner mit den Originalbildern; wird nur gelesen
     * @param string   $cacheDir     Ordner für die erzeugten Varianten; wird beschrieben
     *                               und darf jederzeit gelöscht werden
     * @param \Closure $isAuthorized Zugriffsprüfung für jede Web-Anfrage,
     *                               ausführlich beschrieben in config.php
     *
     * @throws \InvalidArgumentException bei einer unbrauchbaren Einstellung
     */
    public function __construct(
        public readonly string $title,
        public readonly string $baseDir,
        public readonly string $cacheDir,
        public readonly \Closure $isAuthorized,
    ) {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('config.php: "title" darf nicht leer sein.');
        }

        if (!is_dir($baseDir)) {
            throw new \InvalidArgumentException(sprintf('config.php: "baseDir" ist kein Ordner: "%s".', $baseDir));
        }

        if ($cacheDir === '') {
            throw new \InvalidArgumentException('config.php: "cacheDir" darf nicht leer sein.');
        }
    }
}
