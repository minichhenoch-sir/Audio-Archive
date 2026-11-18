<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Inhalte der Verwaltung wandern in die Freigaben (ab 1.3.0, Wunsch von
 * Henoch, 18.11.2026).
 *
 * Die Verwaltung (Einstellungen -> Verwaltung -> Audio Archive) haelt nur
 * noch Grundeinstellungen. Was bisher dort als Inhalt oder Vorgabe stand,
 * wird beim Update einmal uebernommen, damit sich fuer Hoerer nichts
 * aendert:
 *
 *   0. Eigene Ansicht: Wer uebers Zahnrad nichts selbst eingestellt hat,
 *      sah bisher das Aussehen der Verwaltung. Es wird in die persoenlichen
 *      Einstellungen jedes Nutzers kopiert, der sich schon einmal angemeldet
 *      hat (Entscheidung von Henoch, 18.11.2026, Moeglichkeit b) - nur, wo
 *      es vom Standard-Aussehen abweicht und der Nutzer selbst nichts
 *      gewaehlt hat.
 *   1. Darstellung: Links ohne eigenes Aussehen bekommen das bisherige
 *      Aussehen der Verwaltung fest eingetragen (Gestaltung, Farben,
 *      bereitgestellte Gestaltung als "Benutzerdefiniert", Hintergrundbild).
 *      Wer die "Vom Administrator"-Gestaltung gewaehlt hatte (Link oder
 *      Freigabe an Personen), bekommt sie als eigene "Benutzerdefiniert".
 *   2. Text ueber den Aufnahmen: Freigaben ohne eigenen Text bekommen den
 *      bisherigen Text der Verwaltung (samt Link).
 *   3. Quellen: der gemeinsame Ordner und jede weitere Quelle werden zu einer
 *      Freigabe an alle angemeldeten Nutzer, angelegt im Namen des
 *      Besitzers des Ordners (Weiterteilen erlaubt, Funktionen wie bisher).
 *      Links, die Nutzer auf diese Ordner angelegt hatten, haengen danach an
 *      der neuen Freigabe (bzw. beim Besitzer an seinen eigenen Dateien).
 *
 * Danach werden Gestaltung, Farben, Hintergrundbild, Text und Quellen der
 * Verwaltung zurueckgesetzt: Neue Freigaben beginnen mit dem Standard-
 * Aussehen der App. Titel, Zusatzzeile und Ordnername der Verwaltung bleiben
 * gespeichert (Name der installierten App, frueherer Admin-Link).
 *
 * Laeuft genau einmal (Kennung in der App-Konfiguration).
 */
class AdminSettingsMigration {

    public const DONE_KEY = 'admin_settings_migrated';
    /** {"shared": id, "src:3": id} - fuer die Uebernahme im Browser (config.js) */
    public const MOVES_KEY = 'source_moves';
    /** {"shared": {"owner": uid, "base": "a/b"}} - beim Besitzer liegen die
     *  Aufnahmen danach in seinen eigenen Dateien (PlayerPage::sourceMovesFor) */
    public const MOVE_OWNERS_KEY = 'source_move_owners';

    public function __construct(
        private IAppConfig $appConfig,
        private IDBConnection $db,
        private ShareService $shares,
        private AudioFolder $audioFolder,
        private Appearance $appearance,
        private BackgroundImage $backgroundImage,
        private IConfig $config,
        private IUserManager $userManager,
    ) {
    }

    /** @return list<string> Meldungen fuer das Update-Protokoll */
    public function run(): array {
        $app = Application::APP_ID;
        if ($this->appConfig->getValueString($app, self::DONE_KEY, '') !== '') {
            return ['Einstellungen der Verwaltung wurden bereits übernommen.'];
        }
        $log = [];

        // ---- Bisherige Vorgaben der Verwaltung festhalten ----
        $admin = $this->appearance->adminValues();
        $adminDesign = $this->appearance->adminDesign();
        $adminStyle = $this->appearance->adminStyleOffered() ? $this->appearance->adminStyle() : null;
        $hasAdminBackground = $this->backgroundImage->exists();
        $backgroundInNextcloud = $this->appearance->adminBackgroundInNextcloudDesign();
        $notice = $this->adminNotice();

        // ---- 0. Aussehen der Verwaltung -> eigene Ansicht der Nutzer ----
        $log = array_merge($log, $this->copyLookToUsers($admin, $adminDesign, $adminStyle,
            $hasAdminBackground, $backgroundInNextcloud));

        // ---- 3. Quellen -> Freigaben an alle angemeldeten Nutzer ----
        $sourceMap = [];
        $sharedRoot = $this->audioFolder->rootFor(AudioFolder::SOURCE_SHARED);
        $sharedOwner = $this->audioFolder->sharedOwner();
        if ($sharedRoot !== null && $sharedOwner !== '') {
            $label = trim($this->appConfig->getValueString($app, Application::SETTING_SHARED_LABEL, ''));
            $id = $this->createAllShare($sharedOwner, $sharedRoot,
                $label !== '' ? $label : 'Gemeinsame Aufnahmen', $admin['subtitle'], $notice);
            $sourceMap[AudioFolder::SOURCE_SHARED] = ['id' => $id, 'owner' => $sharedOwner,
                'base' => $this->ownerPath($sharedOwner, $sharedRoot)];
            $log[] = 'Gemeinsamer Ordner als Freigabe ' . $id . ' an alle angemeldeten Nutzer übernommen.';
        }
        foreach ($this->audioFolder->extraSources() as $extra) {
            $source = AudioFolder::SOURCE_EXTRA_PREFIX . $extra['id'];
            $root = $this->audioFolder->rootFor($source);
            if ($root === null) {
                $log[] = 'Quelle „' . $extra['name'] . '“ nicht gefunden – übersprungen.';
                continue;
            }
            $id = $this->createAllShare($extra['owner'], $root, $extra['name'], $admin['subtitle'], $notice);
            $sourceMap[$source] = ['id' => $id, 'owner' => $extra['owner'],
                'base' => $this->ownerPath($extra['owner'], $root)];
            $log[] = 'Quelle „' . $extra['name'] . '“ als Freigabe ' . $id . ' übernommen.';
        }

        // ---- 1./2. Bestehende Freigaben ----
        $newIds = array_column($sourceMap, 'id');
        $changed = 0;
        foreach ($this->allShares() as $row) {
            $id = (int)$row['id'];
            if (in_array($id, $newIds, true)) {
                continue;
            }
            $settings = $this->shares->normalizeSettings(json_decode((string)$row['settings'], true) ?: []);
            $isLink = $row['kind'] === Application::SHARE_KIND_LINK;
            $update = [];

            // Gestaltung
            if ($settings['design'] === Application::DESIGN_ADMIN) {
                $settings['design'] = Application::DESIGN_DEFINED;
                $settings['style'] = $adminStyle ?? StyleTokens::normalize([]);
            } elseif ($isLink && $settings['design'] === '') {
                if ($adminDesign === Application::DESIGN_ADMIN && $adminStyle !== null) {
                    $settings['design'] = Application::DESIGN_DEFINED;
                    $settings['style'] = $adminStyle;
                } else {
                    $settings['design'] = $adminDesign;
                }
            }
            if ($isLink && $settings['design'] === Application::DESIGN_CUSTOM) {
                foreach (['themeAccent', 'themeBar', 'themeBase'] as $key) {
                    if ($settings[$key] === '') {
                        $settings[$key] = ShareService::normalizeColor($admin[$key]);
                    }
                }
            }
            // Titel bleibt leer: Titel und Zusatzzeile der Verwaltung bleiben
            // gespeichert und gelten dort weiter (Ordnerbaum zeigt sonst den
            // Titel statt des Ordnernamens)
            // Text ueber den Aufnahmen
            if ($settings['notice'] === '' && $notice !== '') {
                $settings['notice'] = $notice;
            }
            // Hintergrundbild der Verwaltung fuer Links ohne eigenes
            if ($isLink && $hasAdminBackground
                && !$this->backgroundImage->exists(BackgroundImage::shareKey($id))
                && ($settings['design'] !== Application::DESIGN_NEXTCLOUD || $backgroundInNextcloud)) {
                $this->backgroundImage->copy(BackgroundImage::ADMIN, BackgroundImage::shareKey($id));
            }

            // Links auf die bisherigen Quellen haengen an den neuen Freigaben
            $source = (string)$row['source'];
            if (isset($sourceMap[$source])) {
                $target = $sourceMap[$source];
                if ($row['creator'] === $target['owner']) {
                    // Eigene Dateien des Besitzers
                    $update['source'] = AudioFolder::SOURCE_HOME;
                    $update['path'] = trim($target['base'] . '/' . trim((string)$row['path'], '/'), '/');
                } else {
                    // Weitergeteilt aus der neuen Freigabe - gespeichert wie
                    // beim Weiterteilen: Quelle und Pfad beim Besitzer
                    $update['source'] = AudioFolder::SOURCE_HOME;
                    $update['path'] = trim($target['base'] . '/' . trim((string)$row['path'], '/'), '/');
                    $update['owner'] = $target['owner'];
                    $settings['viaShare'] = $target['id'];
                }
            }

            $update['settings'] = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->updateRow($id, $update);
            $changed++;
        }
        $log[] = $changed . ' bestehende Freigaben angepasst.';

        // ---- Verwaltung zuruecksetzen ----
        foreach ([
            Application::SETTING_DESIGN, Application::SETTING_THEME_ACCENT, Application::SETTING_THEME_BAR,
            Application::SETTING_THEME_BASE, Application::SETTING_ADMIN_STYLE, Application::SETTING_BACKGROUND_NEXTCLOUD,
            Application::SETTING_BETA_TEXT, Application::SETTING_BETA_LINK_URL, Application::SETTING_BETA_LINK_LABEL,
            Application::SETTING_FOLDER, Application::SETTING_FOLDER_OWNER, Application::SETTING_EXTRA_SOURCES,
        ] as $key) {
            $this->appConfig->deleteKey($app, $key);
        }
        $this->appConfig->setValueBool($app, Application::SETTING_ADMIN_STYLE_ENABLED, false);
        $this->appConfig->setValueBool($app, Application::SETTING_NOTICE_ENABLED, false);
        if ($hasAdminBackground) {
            $this->backgroundImage->remove();
        }

        if ($sourceMap !== []) {
            $this->appConfig->setValueString($app, self::MOVES_KEY,
                (string)json_encode(array_map(static fn ($t) => $t['id'], $sourceMap)));
            $this->appConfig->setValueString($app, self::MOVE_OWNERS_KEY,
                (string)json_encode(array_map(static fn ($t) => ['owner' => $t['owner'], 'base' => $t['base']], $sourceMap),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $this->appConfig->setValueString($app, self::DONE_KEY, (string)time());
        return $log;
    }

    /** Standard-Farben der App (gelten nach dem Zuruecksetzen der Verwaltung). */
    private const DEFAULT_COLORS = ['themeAccent' => '#b9793f', 'themeBar' => '#291c12', 'themeBase' => '#a86a3d'];

    /**
     * Kopiert das bisherige Aussehen der Verwaltung in die persoenlichen
     * Einstellungen (Gestaltung, Farben, Hintergrundbild), soweit ein Nutzer
     * dort nichts Eigenes hat und es vom Standard abweicht.
     *
     * @return list<string>
     */
    private function copyLookToUsers(array $admin, string $adminDesign, ?array $adminStyle,
                                     bool $hasBackground, bool $backgroundInNextcloud): array {
        $app = Application::APP_ID;
        $colors = [];
        foreach (self::DEFAULT_COLORS as $key => $default) {
            $value = ShareService::normalizeColor((string)$admin[$key]);
            if ($value !== '' && strcasecmp($value, $default) !== 0) {
                $colors[$key] = $value;
            }
        }
        $designDiffers = $adminDesign !== Application::DESIGN_CUSTOM;
        if (!$designDiffers && $colors === [] && !$hasBackground && $adminStyle === null) {
            return [];
        }
        $colorKeys = ['themeAccent' => Application::USER_THEME_ACCENT,
            'themeBar' => Application::USER_THEME_BAR, 'themeBase' => Application::USER_THEME_BASE];
        $count = 0;
        $this->userManager->callForSeenUsers(function (IUser $user) use (
            $app, $adminDesign, $adminStyle, $colors, $colorKeys, $hasBackground, $backgroundInNextcloud, &$count
        ): void {
            $uid = $user->getUID();
            $touched = false;
            // Gestaltung: eigene Wahl bleibt; "Vom Administrator" und keine
            // Wahl bekommen die bisherige Vorgabe fest eingetragen
            $pref = $this->config->getUserValue($uid, $app, Application::USER_DESIGN, '');
            if (!in_array($pref, Application::DESIGNS, true)) {
                $pref = '';
            }
            $design = $pref;
            if ($pref === Application::DESIGN_ADMIN && $adminStyle !== null) {
                $design = Application::DESIGN_DEFINED;
                $this->config->setUserValue($uid, $app, Application::USER_STYLE, StyleTokens::toJson($adminStyle));
            } elseif ($pref === '' || $pref === Application::DESIGN_ADMIN) {
                $design = $adminDesign === Application::DESIGN_ADMIN && $adminStyle !== null
                    ? Application::DESIGN_DEFINED : $adminDesign;
                if ($design === Application::DESIGN_DEFINED) {
                    $this->config->setUserValue($uid, $app, Application::USER_STYLE, StyleTokens::toJson($adminStyle));
                }
            }
            if ($design !== $pref) {
                if ($design === Application::DESIGN_CUSTOM && $pref === '') {
                    // Standard - nichts eintragen
                } else {
                    $this->config->setUserValue($uid, $app, Application::USER_DESIGN, $design);
                    $touched = true;
                }
            }
            // Farben (gelten bei "Modern")
            $colorsCopied = false;
            foreach ($colors as $key => $value) {
                if ($this->config->getUserValue($uid, $app, $colorKeys[$key], '') === '') {
                    $this->config->setUserValue($uid, $app, $colorKeys[$key], $value);
                    $colorsCopied = true;
                    $touched = true;
                }
            }
            // Mit eigenen Farben steht im Zahnrad sonst "Standard" (Standardfarben)
            // markiert, obwohl die Ansicht die uebernommenen Farben zeigt
            if ($colorsCopied && $pref === '' && $design === Application::DESIGN_CUSTOM) {
                $this->config->setUserValue($uid, $app, Application::USER_DESIGN, $design);
            }
            // Hintergrundbild, sofern es in der Gestaltung des Nutzers zu sehen war
            $effective = $design !== '' ? $design : Application::DESIGN_CUSTOM;
            if ($hasBackground && !$this->backgroundImage->exists(BackgroundImage::userKey($uid))
                && ($effective !== Application::DESIGN_NEXTCLOUD || $backgroundInNextcloud)) {
                $this->backgroundImage->copy(BackgroundImage::ADMIN, BackgroundImage::userKey($uid));
                $touched = true;
            }
            if ($touched) {
                $count++;
            }
        });
        $log = [$count . ' Nutzern das bisherige Aussehen in die eigene Ansicht übernommen.'];
        if (!$this->appearance->userCustomizationAllowed()) {
            $log[] = 'Hinweis: „Nutzer dürfen Gestaltung … wählen“ ist aus – das übernommene Aussehen wirkt erst, wenn es eingeschaltet wird.';
        }
        return $log;
    }

    /** Text der Verwaltung als HTML (mit Link), '' wenn keiner gezeigt wurde. */
    private function adminNotice(): string {
        if (!Application::noticeEnabled($this->appConfig)) {
            return '';
        }
        $app = Application::APP_ID;
        $text = trim($this->appConfig->getValueString($app, Application::SETTING_BETA_TEXT, ''));
        $url = trim($this->appConfig->getValueString($app, Application::SETTING_BETA_LINK_URL, ''));
        $label = trim($this->appConfig->getValueString($app, Application::SETTING_BETA_LINK_LABEL, ''));
        if ($url !== '' && preg_match('~^https?://~i', $url)) {
            $text .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . htmlspecialchars($label !== '' ? $label : $url, ENT_QUOTES) . '</a></p>';
        }
        return trim($text);
    }

    /** Neue interne Freigabe an alle angemeldeten Nutzer; liefert die Kennung. */
    private function createAllShare(string $owner, \OCP\Files\Folder $root, string $title, string $subtitle, string $notice): int {
        $app = Application::APP_ID;
        $settings = [
            'title' => $title,
            'subtitle' => $subtitle,
            'notice' => $notice,
            'featureOffline' => $this->appConfig->getValueBool($app, Application::SETTING_FEATURE_OFFLINE, true),
            'featureDownload' => $this->appConfig->getValueBool($app, Application::SETTING_FEATURE_DOWNLOAD, false),
            'featureFolderDownload' => $this->appConfig->getValueBool($app, Application::SETTING_FEATURE_FOLDER_DOWNLOAD, false),
            'featureComments' => true,
            'allowReshare' => true,
        ];
        $path = $this->ownerPath($owner, $root);
        $share = $this->shares->create(Application::SHARE_KIND_INTERNAL, $owner, $owner, $root,
            AudioFolder::SOURCE_HOME, $path, $settings, '', null, '');
        $this->shares->setMembers($share['id'], [['type' => ShareService::MEMBER_ALL, 'id' => ShareService::MEMBER_ALL_ID]]);
        return (int)$share['id'];
    }

    /** Pfad eines Ordners innerhalb der Dateien seines Besitzers ("a/b"). */
    private function ownerPath(string $owner, \OCP\Files\Folder $folder): string {
        $prefix = '/' . $owner . '/files';
        $path = $folder->getPath();
        return str_starts_with($path, $prefix) ? trim(substr($path, strlen($prefix)), '/') : '';
    }

    /** @return list<array<string, mixed>> */
    private function allShares(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'kind', 'creator', 'owner', 'source', 'path', 'settings')->from(ShareService::TABLE);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function updateRow(int $id, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(ShareService::TABLE);
        foreach ($values as $column => $value) {
            $qb->set($column, $qb->createNamedParameter($value));
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
