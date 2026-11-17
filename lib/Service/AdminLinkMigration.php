<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Uebernimmt den frueheren Administrator-Link als Freigabe (ab 1.2.0,
 * Vikunja #68).
 *
 * Bis 1.1.0 gab es in Einstellungen -> Verwaltung den Bereich "Oeffentlicher
 * Zugang": einen Link auf den gemeinsamen Ordner mit gemeinsamem Passwort.
 * Links werden inzwischen in der App angelegt und verwaltet; der Bereich ist
 * deshalb weggefallen. Damit die Hoerer nichts merken, wird der bestehende
 * Link beim Update zu einer ganz normalen Link-Freigabe:
 *
 *   - gleicher Token (Adresse, auch in installierten Apps), gleicher
 *     Passwort-Hash (gleiches Passwort, gemerkte Anmeldungen gelten weiter)
 *   - Funktionen wie bisher fuer den Link eingestellt (Offline, Herunter-
 *     laden, Ordner als ZIP, Kommentare, Bild ohne Cover)
 *   - angelegt im Namen des Besitzers des gemeinsamen Ordners; die
 *     Verwaltung (Administratoren) kann sie ausserdem immer bearbeiten
 *
 * War der Link abgeschaltet oder ohne Passwort (dann kam ohnehin niemand
 * hinein), wird er als abgelaufene Freigabe uebernommen: Er bleibt sichtbar
 * und laesst sich in der App mit neuem Ablaufdatum wieder einschalten, oeffnet
 * aber nichts. Ohne Passwort waere er sonst fuer jeden offen.
 *
 * Laeuft genau einmal (Kennung in der App-Konfiguration).
 */
class AdminLinkMigration {

    public const DONE_KEY = 'admin_link_migrated';

    public function __construct(
        private IAppConfig $appConfig,
        private ShareService $shares,
        private AudioFolder $audioFolder,
        private BackgroundImage $backgroundImage,
    ) {
    }

    /** @return string Kurze Meldung fuer das Update-Protokoll */
    public function run(): string {
        $app = Application::APP_ID;
        if ($this->appConfig->getValueString($app, self::DONE_KEY, '') !== '') {
            return 'Administrator-Link wurde bereits übernommen.';
        }

        $token = $this->appConfig->getValueString($app, Application::SETTING_PUBLIC_TOKEN, '');
        $hash = $this->appConfig->getValueString($app, Application::SETTING_PUBLIC_PASSWORD, '');
        $enabled = $this->appConfig->getValueBool($app, Application::SETTING_PUBLIC_ENABLED, false);

        if ($token === '') {
            $this->finish('none');
            return 'Kein Administrator-Link vorhanden.';
        }

        // Schon einmal als Freigabe angelegt (etwa abgebrochenes Update)?
        $existing = $this->shares->findByToken($token);
        if ($existing !== null) {
            $this->clearAdminLink();
            $this->finish((string)$existing['id']);
            return 'Administrator-Link war bereits Freigabe ' . $existing['id'] . '.';
        }

        $root = $this->audioFolder->rootFor(AudioFolder::SOURCE_SHARED);
        $owner = $this->audioFolder->ownerOf(AudioFolder::SOURCE_SHARED);
        if ($root === null || $owner === '') {
            // Ohne gemeinsamen Ordner hat der Link nie etwas gezeigt
            $this->clearAdminLink();
            $this->finish('no-folder');
            return 'Administrator-Link ohne gültigen Ordner – nicht übernommen.';
        }

        $active = $enabled && $hash !== '';
        $coverIcon = $this->appConfig->getValueString($app, Application::SETTING_PUBLIC_COVER_ICON, '');
        $settings = [
            'featureOffline' => $this->appConfig->getValueBool($app, Application::SETTING_FEATURE_OFFLINE, true),
            'featureDownload' => $this->appConfig->getValueBool($app, Application::SETTING_FEATURE_DOWNLOAD, false),
            'featureFolderDownload' => $this->appConfig->getValueBool($app, Application::SETTING_FEATURE_FOLDER_DOWNLOAD, false),
            'featureComments' => $this->appConfig->getValueBool($app, Application::SETTING_PUBLIC_COMMENTS, false),
            'coverIcon' => $coverIcon,
            'fromAdmin' => true,
        ];

        // Abgeschaltet: als abgelaufen uebernehmen (gestern, Tagesende)
        $expires = $active ? null : strtotime('yesterday 23:59:59');
        $share = $this->shares->create(Application::SHARE_KIND_LINK, $owner, $owner, $root,
            AudioFolder::SOURCE_SHARED, '', $settings, '', $expires, $token);
        if ($hash !== '') {
            $this->shares->setPasswordHash($share['id'], $hash);
        }
        if ($coverIcon === AppIcon::COVER_CUSTOM) {
            $this->backgroundImage->copy(BackgroundImage::ADMIN_COVER, BackgroundImage::shareCoverKey($share['id']));
        }

        $this->clearAdminLink();
        $this->finish((string)$share['id']);
        return 'Administrator-Link als Freigabe ' . $share['id'] . ' übernommen'
            . ($active ? '.' : ' (abgeschaltet – als abgelaufen).');
    }

    private function clearAdminLink(): void {
        $app = Application::APP_ID;
        $this->appConfig->setValueBool($app, Application::SETTING_PUBLIC_ENABLED, false);
        $this->appConfig->deleteKey($app, Application::SETTING_PUBLIC_TOKEN);
        $this->appConfig->deleteKey($app, Application::SETTING_PUBLIC_PASSWORD);
    }

    private function finish(string $value): void {
        $this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, $value);
    }
}
