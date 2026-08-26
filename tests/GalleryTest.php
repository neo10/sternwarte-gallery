<?php

declare(strict_types=1);

use SternwarteGallery\Gallery;
use SternwarteGallery\GalleryConfig;
use SternwarteGallery\GalleryPathResolver;
use SternwarteGallery\ImageFile;
use SternwarteGallery\ImageVariant;
use SternwarteGallery\ImageVariantGenerator;
use SternwarteGallery\ImageVariantStore;

require_once __DIR__ . '/../src/autoload.php';

/** Sehr kleiner Testläufer – das Projekt soll ohne Composer prüfbar bleiben. */
final class TestRunner
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    public function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->record($expected === $actual, $message, sprintf(
            'erwartet %s, war %s',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }

    public function true(bool $condition, string $message): void
    {
        $this->record($condition, $message, 'erwartet true');
    }
 
    public function throws(string $expectedClass, callable $action, string $message): void
    {
        try {
            $action();
        } catch (Throwable $error) {
            $this->record($error instanceof $expectedClass, $message, 'falscher Ausnahmetyp: ' . $error::class);

            return;
        }

        $this->record(false, $message, 'es wurde keine Ausnahme geworfen');
    }

    public function skip(string $message): void
    {
        echo '  … übersprungen: ', $message, PHP_EOL;
    }

    public function summary(): int
    {
        foreach ($this->failures as $failure) {
            fwrite(STDERR, '  ✗ ' . $failure . PHP_EOL);
        }

        printf("%d Prüfungen erfolgreich, %d fehlgeschlagen.\n", $this->passed, count($this->failures));

        return $this->failures === [] ? 0 : 1;
    }

    private function record(bool $ok, string $message, string $detail): void
    {
        if ($ok) {
            ++$this->passed;

            return;
        }

        $this->failures[] = $message . ' (' . $detail . ')';
    }
}

/** Gültiges 40×20-PNG als Testbild – muss auch von GD lesbar sein, nicht nur von getimagesize(). */
function samplePng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAACgAAAAUCAIAAABwJOjsAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAJ0lEQVRI'
        . 'x2OUs6lgGAjAxDBAYNTiUYtHLR61eNTiUYtHLR61mP4AAIYmAPrnYIOeAAAAAElFTkSuQmCC',
        true,
    );
}

/** Einfarbiges JPEG - die Farbe macht sichtbar, welche Fassung im Cache liegt. */
function writeSolidJpeg(string $path, int $red, int $green, int $blue): void
{
    $image = imagecreatetruecolor(120, 80);
    imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
    imagejpeg($image, $path, 92);
}

/** @return array{0: int, 1: int, 2: int} */
function colorOfWebp(string $path): array
{
    $image = imagecreatefromwebp($path);
    $color = imagecolorat($image, 5, 5);

    return [($color >> 16) & 255, ($color >> 8) & 255, $color & 255];
}

/**
 * JPEG und WebP sind verlustbehaftet, exakte Farbgleichheit gibt es nicht.
 *
 * @param array{0: int, 1: int, 2: int} $actual
 * @param array{0: int, 1: int, 2: int} $expected
 */
function isRoughly(array $actual, array $expected): bool
{
    foreach ($expected as $index => $value) {
        if (abs($actual[$index] - $value) > 12) {
            return false;
        }
    }

    return true;
}

function makeTemporaryDirectory(string $suffix): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sternwarte-gallery-' . $suffix . '-' . bin2hex(random_bytes(4));
    mkdir($path, 0775, true);

    return $path;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($path);
}

$test = new TestRunner();
// realpath(), damit erwartete und zurückgegebene Pfade dieselbe Schreibweise haben.
$root = (string) realpath(makeTemporaryDirectory('images'));
$cache = (string) realpath(makeTemporaryDirectory('cache'));

try {
    mkdir($root . DIRECTORY_SEPARATOR . '2024');
    mkdir($root . DIRECTORY_SEPARATOR . '2025');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'bild.png', samplePng());
    file_put_contents($root . DIRECTORY_SEPARATOR . '2024' . DIRECTORY_SEPARATOR . 'notiz.txt', 'kein Bild');

    $allowEverything = static fn (): bool => true;
    $gallery = Gallery::create($root, $cache, $allowEverything);

    echo "Ordner lesen\n";
    $rootContents = $gallery->browse();
    $test->same(['2025', '2024'], array_column($rootContents['folders'], 'name'), 'Ordner werden absteigend sortiert.');
    $test->same(['bild.png'], array_column($rootContents['images'], 'name'), 'Bilder im aktuellen Ordner werden erkannt.');
    $test->same(null, $rootContents['parentPath'], 'Der Wurzelordner hat keinen übergeordneten Ordner.');
    $test->same(40, $rootContents['images'][0]['width'], 'Die Bildbreite wird gelesen.');
    $test->same(20, $rootContents['images'][0]['height'], 'Die Bildhöhe wird gelesen.');

    $subContents = $gallery->browse('2024');
    $test->same([], $subContents['images'], 'Dateien ohne Bildendung werden ignoriert.');
    $test->same('', $subContents['parentPath'], 'Ein Unterordner verweist auf den Wurzelordner.');

    echo "Pfade außerhalb des Basisordners\n";
    $test->same(null, $gallery->browse('../'), 'Ein Ordnerpfad außerhalb des Basisordners wird abgewiesen.');
    $test->same(null, $gallery->browse('2024/../..'), 'Ein zusammengesetzter Ausbruchsversuch wird abgewiesen.');
    $test->same(null, $gallery->imagePath('../bild.png', ImageVariant::Large), 'Ein Bildpfad außerhalb des Basisordners wird abgewiesen.');
    $test->same(null, $gallery->imagePath('2024/notiz.txt', ImageVariant::Large), 'Eine Nicht-Bilddatei wird nicht ausgeliefert.');

    echo "GalleryPathResolver\n";
    $paths = new GalleryPathResolver($root);
    $test->true($paths->isSafeRelativePath('2024/album/bild.jpg'), 'Ein einfacher relativer Pfad ist sicher.');
    $test->true(!$paths->isSafeRelativePath('../bild.jpg'), '".." macht einen Pfad unsicher.');
    $test->true(!$paths->isSafeRelativePath('/etc/passwd'), 'Ein absoluter Pfad ist unsicher.');
    $test->true(!$paths->isSafeRelativePath('2024//bild.jpg'), 'Ein leeres Segment ist unsicher.');
    $test->true(!$paths->isSafeRelativePath(''), 'Der leere Pfad gilt standardmäßig als unsicher.');
    $test->true($paths->isSafeRelativePath('', allowEmpty: true), 'Der leere Pfad kann ausdrücklich erlaubt werden.');
    $test->same($root, $paths->absolute(''), 'Der leere Pfad zeigt auf den Basisordner.');
    $test->throws(InvalidArgumentException::class, static fn () => $paths->absolute('../x'), 'absolute() weist unsichere Pfade ab.');

    echo "Bildvarianten\n";
    $test->same(['width' => 480, 'height' => 240], ImageVariant::Thumbnail->scale(4000, 2000), 'Große Bilder werden auf die Variantenbreite verkleinert.');
    $test->same(['width' => 100, 'height' => 50], ImageVariant::Large->scale(100, 50), 'Kleine Bilder werden nicht vergrößert.');
    $test->same(ImageVariant::Large, ImageVariant::fromNameOrDefault('unbekannt'), 'Ein unbekannter Variantenname fällt auf die Standardvariante zurück.');

    echo "Fingerabdruck\n";
    $images = new ImageFile();
    $samplePath = $root . DIRECTORY_SEPARATOR . 'bild.png';
    $sampleVersion = (string) $images->fingerprint($samplePath);
    $test->true($sampleVersion !== '', 'Eine vorhandene Datei hat einen Fingerabdruck.');
    $test->same($sampleVersion, (string) $images->fingerprint($samplePath), 'Derselbe Inhalt ergibt denselben Fingerabdruck.');
    $test->same(null, $images->fingerprint($root . DIRECTORY_SEPARATOR . 'gibtsnicht.png'), 'Eine fehlende Datei hat keinen Fingerabdruck.');

    // Der Fingerabdruck steckt im Cache-Pfad. Genau deshalb gibt es nirgends
    // eine Prüfung "ist die Variante noch aktuell?": Anderer Inhalt, anderer Pfad.
    $store = ImageVariantStore::inDirectory($cache);
    $expectedDestination = $cache . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, 'thumbnail/2024/bild.png.' . $sampleVersion . '.webp');
    $test->same($expectedDestination, $store->destinationPath(ImageVariant::Thumbnail, '2024/bild.png', $sampleVersion), 'Der Cache-Pfad enthält Galeriestruktur und Fingerabdruck.');
    $test->same(null, $store->existingPath(ImageVariant::Thumbnail, '2024/bild.png', $sampleVersion), 'Eine noch nicht erzeugte Variante gibt es nicht.');
    $test->throws(InvalidArgumentException::class, static fn () => $store->destinationPath(ImageVariant::Large, '../bild.png', $sampleVersion), 'Der Cache weist unsichere Quellpfade ab.');

    $test->same($sampleVersion, $gallery->browse()['images'][0]['version'], 'browse() liefert den Fingerabdruck für die Bild-URL mit.');

    echo "Erzeugung\n";
    if (!$images->canCreateVariants()) {
        $test->skip('GD mit WebP-Unterstützung ist in dieser PHP-Installation nicht verfügbar.');
        $test->same($root . DIRECTORY_SEPARATOR . 'bild.png', $gallery->imagePath('bild.png', ImageVariant::Thumbnail), 'Ohne GD wird das Original ausgeliefert.');
    } else {
        $created = $gallery->ensureVariants('bild.png');
        $test->same([ImageVariant::Large, ImageVariant::Thumbnail], $created, 'Beide Varianten werden erzeugt – die größte zuerst.');
        $test->same([], $gallery->ensureVariants('bild.png'), 'Vorhandene Varianten werden nicht neu erzeugt.');

        $thumbnail = $gallery->imagePath('bild.png', ImageVariant::Thumbnail);
        $test->same($store->destinationPath(ImageVariant::Thumbnail, 'bild.png', $sampleVersion), $thumbnail, 'Ausgeliefert wird die erzeugte Variante.');
        $test->same('image/webp', $gallery->mimeType((string) $thumbnail), 'Varianten werden als WebP ausgeliefert.');

        $variantSize = getimagesize((string) $thumbnail);
        $test->same([40, 20], [$variantSize[0], $variantSize[1]], 'Ein kleines Original wird nicht vergrößert.');

        echo "Kaskade\n";
        // Wer nur das Thumbnail anfordert, bekommt die große Fassung mit: Das
        // Laden des Originals ist der teure Teil und soll sich lohnen.
        removeTree($cache);
        $gallery->imagePath('bild.png', ImageVariant::Thumbnail);
        $test->true(
            $store->existingPath(ImageVariant::Large, 'bild.png', $sampleVersion) !== null,
            'Eine Thumbnail-Anfrage erzeugt beide Varianten aus einem einzigen Ladevorgang.',
        );

        // Beweis, dass kleinere Varianten aus der größeren entstehen: Als
        // Originalpfad wird einer übergeben, den es gar nicht gibt. Gelingt die
        // Erzeugung trotzdem, kann nur "large" die Vorlage gewesen sein.
        $generator = new ImageVariantGenerator($images, $store);
        $thumbnailPath = $store->destinationPath(ImageVariant::Thumbnail, 'bild.png', $sampleVersion);
        unlink($thumbnailPath);
        clearstatcache();

        $rebuilt = $generator->generate($root . DIRECTORY_SEPARATOR . 'gibtsnicht.png', 'bild.png', $sampleVersion, [ImageVariant::Thumbnail]);
        $test->same([ImageVariant::Thumbnail], $rebuilt, 'Das Thumbnail wird aus der großen Variante erzeugt, nicht aus dem Original.');
        $test->true(is_file($thumbnailPath), 'Die aus der Kaskade erzeugte Datei liegt im Cache.');

        echo "Speichergrenze\n";
        // Der Bedarf wird vor dem Laden berechnet: Ein überschrittenes memory_limit
        // ist ein fataler Fehler, der die Anfrage abbricht - abfangen lässt er sich
        // nicht, verhindern schon.
        $test->throws(
            RuntimeException::class,
            static fn () => (new ImageFile())->load($thumbnailPath, 8 * 1024 ** 3),
            'Ein unerfüllbarer Speicherbedarf wird als Ausnahme gemeldet, nicht als fataler Abbruch.',
        );

        echo "Ausgetauschte Datei\n";
        // Der Kernfall: gleicher Dateiname, anderer Inhalt.
        $swapPath = $root . DIRECTORY_SEPARATOR . 'tausch.jpg';

        writeSolidJpeg($swapPath, 220, 30, 30);
        clearstatcache();
        $redVersion = (string) $images->fingerprint($swapPath);
        $gallery->ensureVariants('tausch.jpg');
        $redThumbnail = $store->destinationPath(ImageVariant::Thumbnail, 'tausch.jpg', $redVersion);
        $test->true(isRoughly(colorOfWebp($redThumbnail), [220, 30, 30]), 'Die erste Fassung landet im Cache.');

        // Ersetzt durch ein anderes Bild – mit absichtlich ÄLTEREM Zeitstempel,
        // so kopieren Windows-Explorer, cp -p, rsync und viele FTP-Clients.
        writeSolidJpeg($swapPath, 30, 60, 220);
        touch($swapPath, time() - 600);
        clearstatcache();
        $blueVersion = (string) $images->fingerprint($swapPath);
        $test->true($blueVersion !== $redVersion, 'Ein anderer Inhalt ergibt einen anderen Fingerabdruck.');

        $newThumbnail = (string) $gallery->imagePath('tausch.jpg', ImageVariant::Thumbnail);
        $newLarge = (string) $gallery->imagePath('tausch.jpg', ImageVariant::Large);
        $test->true(isRoughly(colorOfWebp($newThumbnail), [30, 60, 220]), 'Nach dem Austausch zeigt das Thumbnail das neue Bild.');
        $test->true(isRoughly(colorOfWebp($newLarge), [30, 60, 220]), 'Nach dem Austausch zeigt die große Fassung das neue Bild.');

        // Der Punkt der ganzen Übung: Auch die URL ändert sich, sonst zeigt der
        // Browser weiter seine gespeicherte Kopie.
        $versions = array_column($gallery->browse()['images'], 'version', 'name');
        $test->same($blueVersion, $versions['tausch.jpg'], 'Die Ordneransicht liefert den neuen Fingerabdruck – damit ändert sich die Bild-URL.');

        echo "Aufräumen beim Erzeugen\n";
        $test->true(!is_file($redThumbnail), 'Die Variante der Vorgängerfassung wird beim Erzeugen entfernt.');
        $test->true(
            !is_file($store->destinationPath(ImageVariant::Large, 'tausch.jpg', $redVersion)),
            'Das gilt für jede Variante, nicht nur für das Thumbnail.',
        );
        $test->true(is_file($newThumbnail), 'Die aktuelle Fassung bleibt selbstverständlich liegen.');

        // Aufgeräumt wird nur, was wirklich zu diesem Bild gehört. Zwei Fallen:
        // eine Variante eines anderen Bildes und eine, deren Name zufällig mit
        // "tausch.jpg." beginnt, weil das Original "tausch.jpg.alt.jpg" hieße.
        $thumbnailDirectory = dirname($newThumbnail);
        $foreignVariant = $thumbnailDirectory . DIRECTORY_SEPARATOR . 'anderes.jpg.0123456789ab.webp';
        $lookalikeVariant = $thumbnailDirectory . DIRECTORY_SEPARATOR . 'tausch.jpg.alt.jpg.0123456789ab.webp';
        file_put_contents($foreignVariant, 'fremd');
        file_put_contents($lookalikeVariant, 'nur ähnlich');

        writeSolidJpeg($swapPath, 250, 200, 40);
        clearstatcache();
        $gallery->imagePath('tausch.jpg', ImageVariant::Thumbnail);
        clearstatcache();

        $test->true(is_file($foreignVariant), 'Varianten anderer Bilder bleiben unangetastet.');
        $test->true(is_file($lookalikeVariant), 'Ein nur ähnlich beginnender Dateiname wird nicht mitgelöscht.');
        $test->true(!is_file($newThumbnail), 'Die vorletzte Fassung ist nach dem nächsten Austausch weg.');

        unlink($foreignVariant);
        unlink($lookalikeVariant);

        echo "Verwaiste Cache-Dateien\n";
        // Frischer Cache mit genau zwei gültigen Varianten, damit die Zahlen
        // eindeutig sind.
        removeTree($cache);
        $gallery->ensureVariants('bild.png');
        $validThumbnail = $store->destinationPath(ImageVariant::Thumbnail, 'bild.png', $sampleVersion);

        // Variante eines gelöschten Bildes, in einem eigenen Ordner.
        $orphanDirectory = $cache . DIRECTORY_SEPARATOR . 'thumbnail' . DIRECTORY_SEPARATOR . 'geloeschtes-album';
        mkdir($orphanDirectory, 0775, true);
        $orphanFile = $orphanDirectory . DIRECTORY_SEPARATOR . 'foto.jpg.0123456789ab.webp';
        file_put_contents($orphanFile, 'Original gibt es nicht mehr');

        // Original existiert, aber der Fingerabdruck gehört zu einer alten Fassung.
        $staleFile = $cache . DIRECTORY_SEPARATOR . 'thumbnail' . DIRECTORY_SEPARATOR . 'bild.png.ffffffffffff.webp';
        file_put_contents($staleFile, 'alte Fassung');

        // Rest einer abgebrochenen Erzeugung.
        $temporaryLeftover = $cache . DIRECTORY_SEPARATOR . 'thumbnail' . DIRECTORY_SEPARATOR . 'bild.png.' . $sampleVersion . '.webp.a1b2c3.tmp';
        file_put_contents($temporaryLeftover, 'halb fertig');

        // Ordner, der zu keiner bekannten Variante gehört.
        $unknownDirectory = $cache . DIRECTORY_SEPARATOR . 'unbekannt';
        mkdir($unknownDirectory, 0775, true);
        $unknownFile = $unknownDirectory . DIRECTORY_SEPARATOR . 'irgendwas.webp';
        file_put_contents($unknownFile, 'keine bekannte Variante');

        $garbage = $gallery->findCacheGarbage();
        $foundFiles = $garbage['files'];
        $expectedFiles = [$orphanFile, $staleFile, $temporaryLeftover, $unknownFile];
        sort($foundFiles);
        sort($expectedFiles);
        $test->same($expectedFiles, $foundFiles, 'Gefunden werden genau die verwaisten Dateien.');

        $foundDirectories = $garbage['directories'];
        $expectedDirectories = [$orphanDirectory, $unknownDirectory];
        sort($foundDirectories);
        sort($expectedDirectories);
        $test->same($expectedDirectories, $foundDirectories, 'Ordner, die dadurch leer werden, kommen mit.');
        $test->same(2, $garbage['kept'], 'Die gültigen Varianten werden als bleibend gezählt.');
        $test->true(is_file($orphanFile), 'Das Suchen allein löscht nichts – der Trockenlauf ist kein Sonderfall.');

        echo "Nur im Cache wird gelöscht\n";
        // Die wichtigste Zusicherung: Ein Pfad außerhalb des Caches bricht ab,
        // bevor irgendetwas entfernt wurde.
        $outsidePath = $root . DIRECTORY_SEPARATOR . 'bild.png';
        $test->throws(
            RuntimeException::class,
            static fn () => $gallery->removeCacheGarbage([$orphanFile, $outsidePath]),
            'Ein Pfad außerhalb des Caches wird abgewiesen.',
        );
        $test->true(is_file($outsidePath), 'Die Datei außerhalb des Caches bleibt unangetastet.');
        $test->true(is_file($orphanFile), 'Auch der zulässige Pfad aus demselben Aufruf wurde nicht gelöscht.');
        $test->throws(
            RuntimeException::class,
            static fn () => $gallery->removeCacheGarbage([$cache]),
            'Der Cache-Ordner selbst darf nicht entfernt werden.',
        );

        echo "Aufräumen\n";
        $removed = $gallery->removeCacheGarbage([...$garbage['files'], ...$garbage['directories']]);
        $test->same(6, $removed, 'Vier Dateien und zwei Ordner werden entfernt.');
        $test->true(!is_file($orphanFile) && !is_dir($orphanDirectory), 'Variante und Ordner eines gelöschten Bildes sind weg.');
        $test->true(!is_dir($unknownDirectory), 'Der unbekannte Ordner ist weg.');
        $test->true(is_file($validThumbnail), 'Die gültige Variante überlebt.');
        $test->same(['files' => [], 'directories' => [], 'kept' => 2], $gallery->findCacheGarbage(), 'Ein zweiter Lauf findet nichts mehr.');
    }

    echo "Zugriffsschutz\n";
    $test->true(Gallery::create($root, $cache, $allowEverything)->isAuthorized(), 'Eine zustimmende Prüfung lässt durch.');
    $test->true(!Gallery::create($root, $cache, static fn (): bool => false)->isAuthorized(), 'Eine ablehnende Prüfung sperrt die Galerie.');

    $callCount = 0;
    $counting = static function () use (&$callCount): bool {
        ++$callCount;

        return true;
    };
    $countingGallery = Gallery::create($root, $cache, $counting);
    $countingGallery->isAuthorized();
    $countingGallery->isAuthorized();
    $test->same(2, $callCount, 'Die Prüfung wird bei jeder Anfrage neu ausgewertet, nicht einmal zwischengespeichert.');

    echo "Einstellungen\n";
    $config = new GalleryConfig(
        title: 'Testgalerie',
        baseDir: $root,
        cacheDir: $cache,
        isAuthorized: static fn (): bool => false,
    );
    $test->same('Testgalerie', $config->title, 'Der Titel kommt aus den Einstellungen.');
    $test->true(!Gallery::fromConfig($config)->isAuthorized(), 'fromConfig() reicht die Zugriffsprüfung durch.');
    $test->same(
        $gallery->browse()['name'],
        Gallery::fromConfig($config)->browse()['name'],
        'fromConfig() benutzt den eingestellten Bilderordner.',
    );

    // Falsch eingestellt soll beim Start auffallen, nicht später im Betrieb.
    $test->throws(
        InvalidArgumentException::class,
        static fn () => new GalleryConfig(title: '  ', baseDir: $root, cacheDir: $cache, isAuthorized: $allowEverything),
        'Ein leerer Titel wird abgewiesen.',
    );
    $test->throws(
        InvalidArgumentException::class,
        static fn () => new GalleryConfig(title: 'X', baseDir: $root . DIRECTORY_SEPARATOR . 'gibtsnicht', cacheDir: $cache, isAuthorized: $allowEverything),
        'Ein nicht vorhandener Bilderordner wird abgewiesen.',
    );
} finally {
    removeTree($root);
    removeTree($cache);
}

exit($test->summary());
