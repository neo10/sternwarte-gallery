# Sternwarte-Galerie

PHP-Bildergalerie ohne Composer und ohne Build-Schritt: Originalbilder in einen
Ordner legen, die Galerie erzeugt daraus WebP-Varianten und liefert nur die aus.

## Aufbau

```
config.php          die einzige Datei, die man anpasst
bilder-demo/        Originalbilder, nach Jahr und Album sortiert
var/cache/images/   erzeugte Varianten (thumbnail/ und large/), jederzeit löschbar
public/             die beiden Einstiegspunkte fürs Web
bin/                Wartungsbefehle: Varianten erzeugen, Cache aufräumen
src/                Anwendungscode
```

Wichtige Klassen:

| Klasse                    | Aufgabe                                                       |
| ------------------------- | ------------------------------------------------------------- |
| `GalleryConfig`           | die Einstellungen als Wertobjekt, geprüft beim Start           |
| `Gallery`                 | Fassade; verbindet die Bausteine zur API für Frontend und CLI |
| `GalleryRepository`       | Schnittstelle, gegen die das Frontend programmiert            |
| `GalleryPathResolver`     | einzige Stelle, die über die Sicherheit von Pfaden entscheidet |
| `GalleryDirectoryBrowser` | liest Ordner und Bilder eines Verzeichnisses                  |
| `ImageFile`               | Format, Abmessungen, EXIF-Ausrichtung, Laden einer Bilddatei  |
| `ImageVariant`            | die Bildgrößen samt Breite, Qualität und Skalierung           |
| `ImageVariantStore`       | weiß, wo eine Variante im Cache liegt (Pfad mit Fingerabdruck) |
| `ImageVariantGenerator`   | erzeugt fehlende Varianten                                    |
| `ImageVariantPruner`      | findet und entfernt verwaiste Cache-Dateien                   |

## Bildgrößen

Ausgeliefert wird nie das Original, sondern eine WebP-Variante:

- `thumbnail` – 480 px breit, für das Raster in der Ordneransicht
- `large` – 2560 px breit, für die Lightbox

Ein Album mit 200 Fotos kostet dadurch beim Öffnen wenige Megabyte statt einiger
hundert. Zusätzlich lädt das Raster die Bilder erst beim Scrollen (`loading="lazy"`).

### Der Fingerabdruck

Die zentrale Idee des Caches. Jedes Original bekommt ein Kennzeichen aus
**Dateigröße und Änderungszeit** (`ImageFile::fingerprint()`), und dieses
Kennzeichen steht an zwei Stellen:

```
Cache-Pfad:  var/cache/images/thumbnail/2026/mond.jpg.a1b2c3d4e5f6.webp
Bild-URL:    image.php?file=2026/mond.jpg&variant=thumbnail&v=a1b2c3d4e5f6
```

Wird ein Bild durch ein anderes mit gleichem Namen ersetzt, ändert sich das
Kennzeichen — und damit **beides**: Die alte Variante liegt unter einem anderen
Pfad und wird nie wieder gefunden, und der Browser sieht eine neue URL und holt
das Bild neu, statt seine gespeicherte Kopie zu zeigen.

Der Gewinn ist, was dadurch **wegfällt**: Es gibt nirgends eine Prüfung "ist
diese Variante noch aktuell?". Die Frage kann gar nicht mehr entstehen.
Cache-Invalidierung ist schwer, Benennung ist einfach — also wird die
Invalidierung durch Benennung ersetzt.

Zwei Konsequenzen, die man kennen muss:

- Weil eine URL mit `v` genau eine Fassung bezeichnet, darf sie ein Jahr lang
  gecacht werden (`immutable`). Ohne `v` — etwa bei einem direkten Link — kann
  sich der Inhalt unter derselben URL ändern; dann wird nur 5 Minuten gecacht
  und danach per ETag revalidiert. Die Ordnerseite selbst ist `no-cache`, denn
  nur wenn sie frisch ist, erfährt der Browser von einem neuen Fingerabdruck.
- Beim Schreiben einer neuen Fassung entfernt der Generator die Varianten der
  vorherigen Fassung desselben Bildes. Ein ausgetauschtes Bild hinterlässt
  dadurch keinen Müll. Für gelöschte Bilder und Ordner gibt es
  `bin/prune-cache.php` (siehe unten) — dort fehlt im Code der Anlass.

Nicht erkannt wird der Fall, dass eine ersetzte Datei auf die Sekunde denselben
Zeitstempel **und** dieselbe Größe hat wie ihre Vorgängerin.

### Der Server traut dem `v` nicht

`image.php` liest den Fingerabdruck immer selbst aus der Datei; das `v` aus der
URL wird ignoriert. Es ist ausschließlich ein Signal für den Browser-Cache.
Deshalb kann ein alter Link nie eine veraltete Fassung hervorholen — er liefert
das aktuelle Bild.

### Varianten vorab erzeugen

Nach dem Hochladen neuer Bilder – oder per Cronjob:

```sh
php bin/warm-variants.php            # ganze Galerie
php bin/warm-variants.php 2025/Mond  # nur ein Album
```

Der Befehl überspringt Varianten, die bereits aktuell sind, und ist deshalb
beliebig oft wiederholbar. Beispiel für einen Cronjob:

```cron
*/15 * * * * php /pfad/zur/galerie/bin/warm-variants.php >/dev/null
```

Fehlt eine Variante trotzdem, erzeugt `image.php` sie beim ersten Abruf selbst.
Der Warmlauf ist also eine Beschleunigung, keine Voraussetzung.

### Cache aufräumen

Wer ein Bild oder ein ganzes Album per FTP löscht, löst im Code nichts aus — die
Varianten bleiben liegen. Dafür gibt es:

```sh
php bin/prune-cache.php           # zeigt nur an, ändert nichts
php bin/prune-cache.php --force   # entfernt wirklich
```

Entfernt werden Varianten ohne Original, Varianten mit veraltetem Fingerabdruck,
Reste abgebrochener Erzeugungen (`.tmp`) und dadurch leer gewordene Ordner.

Drei Dinge machen das ungefährlich:

- **Suchen und Löschen sind getrennt.** `findGarbage()` ändert nichts; der
  Trockenlauf ist kein Sonderfall im Code, sondern der Verzicht auf den zweiten
  Schritt.
- **Der Bilder-Ordner wird nur gelesen.** Vor dem Löschen prüft
  `ImageVariantPruner::remove()` für *jeden* Pfad, dass er unterhalb des
  Cache-Ordners liegt, und bricht sonst ab, bevor irgendetwas entfernt ist. Auch
  der Cache-Ordner selbst ist geschützt.
- **Ein Fehlgriff bliebe folgenlos.** Der Cache ist ein reines Derivat; eine zu
  viel entfernte Variante entsteht beim nächsten Aufruf neu.

### Ein Cronjob für beides

Nachts warmlaufen und aufräumen in einem Aufruf. Der Warmlauf ist der Teil, der
Besuchern nützt — das Aufräumen kostet nur Millisekunden und fährt mit:

```cron
0 3 * * * cd /pfad/zur/galerie && php bin/warm-variants.php >/dev/null && php bin/prune-cache.php --force >/dev/null
```

Beide Befehle beenden sich mit Exit-Code 1, wenn etwas schiefgeht — dann meldet
sich der Cron. Beim ersten Mal `prune-cache.php` von Hand und ohne `--force`
laufen lassen und die Liste anschauen.

## Arbeitsspeicher

GD hält ein Bild unkomprimiert im Speicher: Breite x Höhe x 4 Byte. Ein
31-Megapixel-Foto (7500x4219) belegt damit rund 121 MB - mehr als PHPs
Standardlimit von 128 MB. Überschritten wird das mitten im Dekodieren, als
fataler Fehler, der die Anfrage abbricht; im Browser erscheint dann ein leeres
Bild ohne jede Fehlermeldung.

Dagegen wirken drei Dinge:

- `ImageFile` rechnet den Bedarf vor dem Laden aus und hebt das Limit an, statt
  hineinzulaufen. Reicht das nicht (abgeschaltetes `ini_set`, absurd großes
  Bild), gibt es eine verständliche Meldung im Protokoll statt eines Absturzes.
- Die Varianten entstehen als Kaskade: Das Original wird höchstens einmal
  geladen, `thumbnail` entsteht aus `large`. Fehlt nur das Thumbnail, wird das
  Original gar nicht angefasst - dann reichen 14 MB statt 121 MB.
- `docker/php.ini` setzt `memory_limit` auf 512 MB, damit der Normalbetrieb ohne
  Anheben auskommt.

Auf fremdem Hosting mit festem, niedrigem Limit bleibt der Warmlauf per CLI der
verlässliche Weg - dort gilt oft ein höheres Limit als für Web-Anfragen.

## Einstellungen

Alles steht in **`config.php`** im Projektstamm. Das ist die einzige Datei, die
beim Einbinden angefasst wird — `src/`, `public/` und `bin/` bleiben unverändert
und lassen sich bei einem Update einfach ersetzen.

```php
return new GalleryConfig(
    title: 'Sternwarte-Galerie',
    baseDir: __DIR__ . '/bilder-demo',
    cacheDir: __DIR__ . '/var/cache/images',
    isAuthorized: static function (): bool { ... },
);
```

| Einstellung    | Bedeutung                                                        |
| -------------- | ---------------------------------------------------------------- |
| `title`        | Überschrift und Browser-Titel                                     |
| `baseDir`      | Ordner mit den Originalbildern; wird nur gelesen                  |
| `cacheDir`     | Ordner für die erzeugten Varianten; darf jederzeit gelöscht werden |
| `isAuthorized` | Zugriffsprüfung, siehe unten                                      |

`baseDir` und `cacheDir` lesen standardmäßig `GALLERY_BASE_DIR` und
`GALLERY_CACHE_DIR` aus der Umgebung — praktisch fürs Deployment, kann man in
der Datei aber auch einfach durch feste Pfade ersetzen.

Ein leerer Titel oder ein nicht vorhandener Bilderordner wirft sofort beim
Start, nicht erst irgendwann im Betrieb.

## Zugriffsschutz

`isAuthorized` wird bei **jeder** Web-Anfrage aufgerufen, als erste Handlung und
bevor irgendetwas ausgegeben wird — sowohl in `public/index.php` als auch in
`public/image.php`. `true` heißt erlaubt, `false` heißt `403` ohne jede Ausgabe.

**Im Auslieferungszustand gibt sie immer `true` zurück, die Galerie ist also
öffentlich.** Für den internen Bereich gehört hier die eigene Prüfung hinein,
zum Beispiel gegen eine bestehende PHP-Session:

```php
isAuthorized: static function (): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['mitglied_id']);
},
```

Zwei Dinge, die man dabei wissen muss:

- Die Befehle in `bin/` rufen die Prüfung **nicht** auf. Sie laufen auf dem
  Server als du, nicht als Besucher; eine Session gibt es dort nicht. Das ist
  Absicht — sonst könnte der Cronjob nicht arbeiten.
- Geschützt wird, was durch `public/index.php` und `public/image.php` läuft.
  Wird die Galerie hinter dem Login eines CMS eingebettet und `isAuthorized`
  bleibt auf `true`, ist zwar die Seite geschützt, `public/image.php` aber
  weiterhin direkt aufrufbar — und damit jedes Bild. Entweder hier prüfen oder
  `public/image.php` serverseitig unerreichbar machen.

`Gallery::create()` verlangt die Prüfung ohne Standardwert. Eine offene Galerie
entsteht dadurch nie aus Versehen, sondern nur, wenn jemand ausdrücklich `true`
hinschreibt.

## Voraussetzungen

PHP 8.1 oder neuer mit den Erweiterungen `gd` (mit WebP-Unterstützung) und
`exif`. Fehlt `gd`, liefert die Galerie die Originale aus und schreibt einen
Hinweis ins Fehlerprotokoll.

Geprüft gegen PHP 8.5 (Docker-Image und Testlauf, siehe unten) – die Version,
die auch im netcup-Webhosting als Standard auswählbar ist. Läuft die Galerie
sauber im Docker-Container, läuft sie damit auch dort.

## Lokaler Start mit Docker

```sh
docker compose -f docker/docker-compose.yml up --build
```

Danach ist das Frontend unter <http://localhost:8080> erreichbar.
Testbilder gehören in `bilder-demo/<Jahr>/<Album>/`.

Der eingebaute PHP-Testserver ist nur für lokale Tests gedacht; im
Produktivbetrieb gehört ein Webserver vor PHP.

## Prüfung

```sh
php tests/GalleryTest.php
```

Im Container, damit auch die Variantenerzeugung geprüft wird:

```sh
docker compose -f docker/docker-compose.yml run --rm gallery php tests/GalleryTest.php
```
