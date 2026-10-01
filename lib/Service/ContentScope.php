<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Bestimmt fuer eine Anfrage an Ordnerliste oder Aufnahme, welcher Ordner
 * die Wurzel ist, ob der Aufrufer zugreifen darf und welche Funktionen
 * gelten.
 *
 * Vier Faelle:
 *   - $shareToken gesetzt:  ein Link eines Nutzers (ab 0.12)
 *   - $source 'in:<id>':    eine interne Freigabe, die mit dem angemeldeten
 *                           Nutzer geteilt ist (ab 0.13)
 *   - $source 'home':       die eigenen Dateien (nur angemeldet)
 *   - sonst:                der gemeinsame Ordner (angemeldet oder ueber
 *                           den Administrator-Link)
 */
class ContentScope {

    public function __construct(
        private AccessGuard $guard,
        private AudioFolder $audioFolder,
        private ShareService $shares,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
    ) {
    }

    /** Kennung der Quelle einer internen Freigabe: 'in:<id>' -> id, sonst null. */
    public static function incomingId(string $source): ?int {
        return preg_match('/^in:(\d{1,18})$/', $source, $m) ? (int)$m[1] : null;
    }

    /**
     * Einheitliche Schreibweise einer Quelle fuer Antworten: 'home',
     * 'in:<id>' oder 'shared'.
     */
    public static function canonicalSource(string $source): string {
        if ($source === AudioFolder::SOURCE_HOME) {
            return AudioFolder::SOURCE_HOME;
        }
        $id = self::incomingId($source);
        return $id !== null ? 'in:' . $id : AudioFolder::SOURCE_SHARED;
    }

    /**
     * @return array{root: Folder, offline: bool, download: bool, countFolders: bool}|int
     *         Die Angaben, oder ein HTTP-Status bei fehlendem Zugang
     */
    public function resolve(string $source, string $shareToken): array|int {
        if ($shareToken !== '') {
            $share = $this->shares->findActive($shareToken);
            if ($share === null) {
                return Http::STATUS_NOT_FOUND;
            }
            if (!$this->shares->hasAccess($share)) {
                return Http::STATUS_UNAUTHORIZED;
            }
            $root = $this->shares->rootFolder($share);
            if ($root === null) {
                return Http::STATUS_NOT_FOUND;
            }
            return [
                'root' => $root,
                'offline' => $share['settings']['featureOffline'],
                'download' => $share['settings']['featureDownload'],
                'folderDownload' => $share['settings']['featureFolderDownload'],
                'countFolders' => true,
            ];
        }

        $incomingId = self::incomingId($source);
        if ($incomingId !== null) {
            $uid = $this->userSession->getUser()?->getUID();
            if ($uid === null) {
                return Http::STATUS_UNAUTHORIZED;
            }
            // Nicht (mehr) Empfaenger, abgelaufen, geloescht: wie nicht vorhanden
            $share = $this->shares->findIncoming($incomingId, $uid);
            $root = $share !== null ? $this->shares->rootFolder($share) : null;
            if ($share === null || $root === null) {
                return Http::STATUS_NOT_FOUND;
            }
            return [
                'root' => $root,
                'offline' => $share['settings']['featureOffline'],
                'download' => $share['settings']['featureDownload'],
                'folderDownload' => $share['settings']['featureFolderDownload'],
                'countFolders' => true,
            ];
        }

        $source = $source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED;
        if (!$this->guard->canUseSource($source)) {
            return Http::STATUS_UNAUTHORIZED;
        }

        $root = $this->audioFolder->rootFor($source);
        if ($root === null) {
            return Http::STATUS_NOT_FOUND;
        }

        $isHome = $source === AudioFolder::SOURCE_HOME;
        return [
            'root' => $root,
            'offline' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, true
            ),
            // Die eigenen Dateien darf man immer herunterladen
            'download' => $isHome || $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, false
            ),
            // Unterordner als ZIP (ab 0.25.0): eigene Dateien immer
            'folderDownload' => $isHome || $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_FOLDER_DOWNLOAD, false
            ),
            /*
             * In den eigenen Dateien wird nicht gezaehlt: Das hiesse, den
             * kompletten Dateibestand bei jedem Oeffnen eines Ordners zu
             * durchlaufen.
             */
            'countFolders' => !$isHome,
        ];
    }
}
