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
 * Verwaltet das vom Administrator hochgeladene Hintergrundbild.
 *
 * Abgelegt wird im AppData-Bereich von Nextcloud, NICHT im App-Ordner.
 * Bei der Code-Signierung fuer den App Store werden Pruefsummen aller
 * Dateien im App-Ordner hinterlegt; ein Upload dorthin wuerde die
 * Integritaetspruefung bei jedem Speichern anschlagen lassen.
 */
class BackgroundImage {

    private const FILE_NAME = 'background';
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
    public function store(string $tmpPath, int $size): string {
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
            $folder->getFile(self::FILE_NAME)->putContent($content);
        } catch (NotFoundException $e) {
            $folder->newFile(self::FILE_NAME, $content);
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

    public function remove(): void {
        try {
            $this->appData->getFolder('/')->getFile(self::FILE_NAME)->delete();
        } catch (\Throwable $e) {
            // Nichts vorhanden - dann ist nichts zu tun.
        }
    }

    public function exists(): bool {
        return $this->get() !== null;
    }

    /** Liefert die Datei zur Ausgabe, oder null wenn keine gesetzt ist. */
    public function get(): ?ISimpleFile {
        try {
            return $this->appData->getFolder('/')->getFile(self::FILE_NAME);
        } catch (\Throwable $e) {
            // Auch der Fall "Ordner existiert noch nicht" landet hier.
            return null;
        }
    }
}
