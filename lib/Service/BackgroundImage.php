<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Verwaltet die hochgeladenen Hintergrundbilder.
 *
 * Es gibt mehrere Ebenen, jede mit eigenem Schluessel:
 *   - ADMIN:            vom Administrator, gilt als Vorgabe fuer alle
 *   - userKey($uid):    vom einzelnen Nutzer fuer seine eigene Ansicht
 *   - shareKey($id):    fuer eine einzelne Freigabe (ab 0.12)
 *   - shareCoverKey($id): eigenes Bild fuer Aufnahmen ohne Cover (ab 0.20)
 * Welches Bild eine Seite tatsaechlich zeigt, entscheidet PlayerPage.
 *
 * Abgelegt wird im AppData-Bereich von Nextcloud, NICHT im App-Ordner.
 * Bei der Code-Signierung fuer den App Store werden Pruefsummen aller
 * Dateien im App-Ordner hinterlegt; ein Upload dorthin wuerde die
 * Integritaetspruefung bei jedem Speichern anschlagen lassen.
 */
class BackgroundImage {

    /** Schluessel des Administrator-Bildes (Dateiname seit 0.6, unveraendert). */
    public const ADMIN = 'background';
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Erlaubte Typen, geprueft am tatsaechlichen Inhalt - nicht am Dateinamen. */
    private const ALLOWED_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private IAppData $appData;

    public function __construct(IAppDataFactory $appDataFactory) {
        $this->appData = $appDataFactory->get(Application::APP_ID);
    }

    /**
     * Nimmt eine hochgeladene Datei entgegen.
     *
     * @param string $tmpPath Pfad der hochgeladenen Datei
     * @param int $size Groesse in Bytes
     * @return string Leerer String bei Erfolg, sonst die Fehlermeldung
     */
    /** Schluessel fuer das Bild eines Nutzers. Gehasht, damit beliebige Kennungen gueltige Dateinamen ergeben. */
    public static function userKey(string $uid): string {
        return 'background-user-' . md5($uid);
    }

    /** Schluessel fuer das Bild einer Freigabe. */
    public static function shareKey(int $shareId): string {
        return 'background-share-' . $shareId;
    }

    /**
     * Schluessel fuer das eigene Cover-Ersatzbild einer Freigabe (ab 0.20).
     * Gleiche Ablage und gleiche Pruefung wie die Hintergrundbilder.
     */
    /** Eigenes Cover-Ersatzbild des Administrator-Links (ab 0.21.1). */
    public const ADMIN_COVER = 'cover-admin';

    public static function shareCoverKey(int $shareId): string {
        return 'cover-share-' . $shareId;
    }

    public function store(string $tmpPath, int $size, string $key = self::ADMIN): string {
        if ($size <= 0) {
            return 'Die Datei ist leer.';
        }

        if ($size > self::MAX_BYTES) {
            return 'Das Bild ist zu groß (höchstens 8 MB).';
        }

        // Typ am Inhalt bestimmen: Ein passender Dateiname sagt nichts darueber
        // aus, was tatsaechlich in der Datei steht.
        $mime = @mime_content_type($tmpPath);
        if ($mime === false || !isset(self::ALLOWED_TYPES[$mime])) {
            return 'Nur PNG, JPEG oder WebP sind erlaubt.';
        }

        $content = @file_get_contents($tmpPath);
        if ($content === false) {
            return 'Die Datei konnte nicht gelesen werden.';
        }

        try {
            $folder = $this->folder();
        } catch (\Throwable $e) {
            return 'Der Speicherort konnte nicht angelegt werden.';
        }

        try {
            $folder->getFile($key)->putContent($content);
        } catch (NotFoundException $e) {
            $folder->newFile($key, $content);
        }

        return '';
    }

    /**
     * Liefert den Ablageordner und legt ihn beim ersten Mal an.
     *
     * Wichtig: Der AppData-Ordner einer App existiert nicht von sich aus. Ein
     * blosses getFolder('/') wirft deshalb beim allerersten Upload einen
     * Fehler - genau daran scheiterte der erste Versuch.
     */
    private function folder(): ISimpleFolder {
        try {
            return $this->appData->getFolder('/');
        } catch (NotFoundException $e) {
            return $this->appData->newFolder('/');
        }
    }

    public function remove(string $key = self::ADMIN): void {
        try {
            $this->appData->getFolder('/')->getFile($key)->delete();
        } catch (\Throwable $e) {
            // Nichts vorhanden - dann ist nichts zu tun.
        }
    }

    public function exists(string $key = self::ADMIN): bool {
        return $this->get($key) !== null;
    }

    /** Aenderungszeitpunkt - als Versionskennung fuer die Bild-Adresse. */
    public function version(string $key = self::ADMIN): string {
        $file = $this->get($key);
        return $file === null ? '' : (string)$file->getMTime();
    }

    /** Liefert die Datei zur Ausgabe, oder null wenn keine gesetzt ist. */
    public function get(string $key = self::ADMIN): ?ISimpleFile {
        try {
            return $this->appData->getFolder('/')->getFile($key);
        } catch (\Throwable $e) {
            // Auch der Fall "Ordner existiert noch nicht" landet hier.
            return null;
        }
    }
}
