<?php

declare(strict_types=1);

use SternwarteGallery\GalleryConfig;

require_once __DIR__ . '/src/autoload.php';

/*
 * ============================================================================
 *  Die einzige Datei, die beim Einbinden angepasst werden muss.
 * ============================================================================
 *
 * Alles darunter (src/, public/, bin/) bleibt unverändert und kann bei einem
 * Update einfach ersetzt werden.
 */

return new GalleryConfig(

    /*
     * Überschrift der Seite und Titel im Browser-Tab.
     */
    title: 'Sternwarte-Galerie',

    /*
     * Ordner mit den Originalbildern, unterteilt in beliebig tiefe Unterordner
     * (z. B. bilder/2026/Mondfinsternis/). Die Galerie liest hier nur; sie legt
     * nichts an und löscht nichts.
     */
    baseDir: getenv('GALLERY_BASE_DIR') ?: __DIR__ . '/bilder-demo',

    /*
     * Ordner für die erzeugten WebP-Varianten. Muss für PHP beschreibbar sein
     * und sollte nicht öffentlich im Web liegen. Der Inhalt ist ein reines
     * Derivat: Er darf jederzeit komplett gelöscht werden und baut sich neu auf.
     */
    cacheDir: getenv('GALLERY_CACHE_DIR') ?: __DIR__ . '/var/cache/images',

    /*
     * ------------------------------------------------------------------------
     *  ZUGRIFFSSCHUTZ
     * ------------------------------------------------------------------------
     *
     * Diese Funktion wird bei JEDER Web-Anfrage aufgerufen, als allererste
     * Handlung und bevor irgendetwas ausgegeben wird – sowohl beim Anzeigen
     * eines Ordners (public/index.php) als auch beim Ausliefern eines einzelnen
     * Bildes (public/image.php).
     *
     *     true  = Anfrage ist erlaubt
     *     false = Antwort 403, es wird nichts ausgeliefert
     *
     * ACHTUNG: So wie sie hier steht, gibt sie immer true zurück. Die Galerie
     * ist damit ÖFFENTLICH – jeder, der die Adresse kennt, sieht alle Bilder.
     * Für den internen Bereich muss hier die eigene Prüfung eingesetzt werden.
     *
     * Beispiel für ein Login, das eine PHP-Session benutzt:
     *
     *     if (session_status() === PHP_SESSION_NONE) {
     *         session_start();
     *     }
     *
     *     return isset($_SESSION['mitglied_id']);
     *
     * Zwei Dinge, die man dabei wissen muss:
     *
     * 1. Die beiden Wartungsbefehle in bin/ rufen diese Prüfung NICHT auf. Sie
     *    laufen auf dem Server als du, nicht als Besucher – eine Session gibt es
     *    dort nicht. Das ist Absicht.
     *
     * 2. Geschützt wird genau das, was durch public/index.php und
     *    public/image.php läuft. Wenn die Galerie hinter dem Login eines CMS
     *    eingebettet wird und diese Prüfung auf true bleibt, ist zwar die Seite
     *    geschützt – public/image.php aber weiterhin direkt aufrufbar, und damit
     *    jedes Bild. Entweder hier prüfen oder public/image.php serverseitig
     *    unerreichbar machen.
     */
    isAuthorized: static function (): bool {
        return true;
    },

    /*
     * ------------------------------------------------------------------------
     *  EINBETTEN IN EINE BESTEHENDE WEBSITE (optional)
     * ------------------------------------------------------------------------
     *
     * Ohne diese Einstellung ist die Galerie eine eigenständige Seite mit
     * eigenem Seitenkopf. Mit ihr erscheint sie im Seitengerüst der Website:
     * Die Funktion bekommt den fertigen Galerie-Inhalt und gibt ihn zwischen
     * Kopf und Fuß der Website aus.
     *
     *     $title    Seitentitel, z. B. "Mondfinsternis – Sternwarte-Galerie".
     *               KLARTEXT – vor der Ausgabe in HTML selbst escapen.
     *     $content  fertiges HTML, unverändert ausgeben. Bringt seine
     *               Stylesheets und das Lightbox-Skript selbst mit.
     *
     * Zum Einschalten das Beispiel unter diesem Kommentar einkommentieren und
     * an die Website anpassen.
     *
     * Was man dabei wissen muss:
     *
     * 1. Aufgerufen wird die Funktion nur für eine fertige Ordnerseite. 403 und
     *    404 gehen ohne Layout als Klartext hinaus. Bis zum Aufruf ist nichts
     *    ausgegeben; Header und session_start() sind hier also noch möglich.
     *
     * 2. Diese Datei steht unter declare(strict_types=1), und das gilt auch für
     *    die Funktion: Ruft sie Funktionen der Website mit Skalar-Typen auf
     *    (int, string, …), wird strikt geprüft. Eine Zahl als String an einen
     *    int-Parameter ergibt dann einen TypeError statt einer Umwandlung.
     *
     * 3. Die Galerie verlinkt relativ (index.php, image.php, assets/). Die
     *    Seite muss deshalb aus dem Ordner kommen, in dem auch image.php und
     *    assets/ per Web erreichbar sind.
     */
    // layout: static function (string $title, string $content): void {
    //     require_once '/pfad/zur/website/kopf-und-fuss.php';
    //     seitenkopf($title);
    //     echo $content;
    //     seitenfuss();
    // },
);
