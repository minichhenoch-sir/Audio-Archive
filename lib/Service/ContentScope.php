<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\IAppConfig;

/**
 * Bestimmt fuer eine Anfrage an Ordnerliste oder Aufnahme, welcher Ordner
 * die Wurzel ist, ob der Aufrufer zugreifen darf und welche Funktionen
 * gelten.
 *
 * Drei Faelle:
 *   - $shareToken gesetzt:  eine Freigabe eines Nutzers (ab 0.12)
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
    ) {
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
            /*
             * In den eigenen Dateien wird nicht gezaehlt: Das hiesse, den
             * kompletten Dateibestand bei jedem Oeffnen eines Ordners zu
             * durchlaufen.
             */
            'countFolders' => !$isHome,
        ];
    }
}
