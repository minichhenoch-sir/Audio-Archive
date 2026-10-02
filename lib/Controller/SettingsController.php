<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\AppIcon;
use OCA\AudioArchive\Service\BackgroundImage;
use OCA\AudioArchive\Service\RememberLogin;
use OCA\AudioArchive\Service\ShareService;
use OCA\AudioArchive\Service\StyleTokens;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

/**
 * Speichert die Verwaltungs-Einstellungen.
 *
 * Bewusst OHNE die Attribute NoAdminRequired/PublicPage: In Nextcloud setzt
 * eine Controller-Methode ohne solche Angaben von sich aus
 * Administratorrechte und ein gueltiges Anfrage-Token voraus. Genau das ist
 * hier gewuenscht.
 */
class SettingsController extends Controller {

    /**
     * Weitere Quellen speichern (ab 0.32.0). Jeder Eintrag: {id, name, path}.
     * Neue Eintraege und geaenderte Ordner gehoeren dem speichernden
     * Administrator (wie beim gemeinsamen Ordner); unveraenderte behalten
     * ihren Besitzer. Ordner muessen existieren.
     */
    private function saveExtraSources(array $input): string {
        $audioFolder = \OCP\Server::get(\OCA\AudioArchive\Service\AudioFolder::class);
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'Nicht angemeldet.';
        }
        $existing = [];
        foreach ($audioFolder->extraSources() as $extra) {
            $existing[$extra['id']] = $extra;
        }
        $nextId = $existing === [] ? 1 : max(array_keys($existing)) + 1;
        $list = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = '/' . trim((string)($item['path'] ?? ''), '/');
            $name = trim(strip_tags((string)($item['name'] ?? '')));
            if ($path === '/' && trim((string)($item['path'] ?? '')) === '') {
                return 'Bitte für jede weitere Quelle einen Ordner wählen' . ($name !== '' ? ' („' . $name . '“)' : '') . '.';
            }
            $id = (int)($item['id'] ?? 0);
            $old = $existing[$id] ?? null;
            if ($old !== null && $old['path'] === $path) {
                $owner = $old['owner'];
            } else {
                $owner = $user->getUID();
                if ($audioFolder->folderOf($owner, $path) === null) {
                    return 'Der Ordner „' . $path . '“ wurde nicht gefunden.';
                }
            }
            if ($old === null) {
                $id = $nextId++;
            }
            $list[] = ['id' => $id, 'name' => $name, 'owner' => $owner, 'path' => $path];
        }
        $list = \OCA\AudioArchive\Service\AudioFolder::normalizeExtraSources($list);
        $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_EXTRA_SOURCES, json_encode($list));
        return '';
    }

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IHasher $hasher,
        private ISecureRandom $secureRandom,
        private IURLGenerator $urlGenerator,
        private BackgroundImage $backgroundImage,
        private ShareService $shares,
        private Appearance $appearance,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string|null $sourceFolder Pfad innerhalb der Dateien des Administrators
     * @param bool|null $publicEnabled Oeffentlichen Zugang ein-/ausschalten
     * @param string|null $publicPassword Neues Passwort (leer = unveraendert)
     * @param string|null $publicSlug Wunschname fuer den Link (ab 0.13),
     *                                '' = wieder zufaellig
     * @param string|null $headerTitle
     * @param string|null $headerSubtitle
     * @param string|null $themeAccent
     * @param string|null $themeBar
     * @param string|null $themeBase
     * @param string|null $design 'custom' oder 'nextcloud'
     * @param bool|null $featureOffline
     * @param bool|null $featureDownload
     */
    public function setAdmin(
        ?string $sourceFolder = null,
        ?bool $publicEnabled = null,
        ?string $publicPassword = null,
        ?string $publicSlug = null,
        ?string $headerTitle = null,
        ?string $headerSubtitle = null,
        ?string $themeAccent = null,
        ?string $themeBar = null,
        ?string $themeBase = null,
        ?string $design = null,
        ?array $adminStyle = null,
        ?bool $adminStyleEnabled = null,
        ?bool $backgroundNextcloud = null,
        ?bool $userCustomization = null,
        ?bool $userShares = null,
        ?bool $featureOffline = null,
        ?bool $featureDownload = null,
        ?bool $betaEnabled = null,
        ?bool $noticeEnabled = null,
        ?string $betaText = null,
        ?string $betaLinkUrl = null,
        ?string $betaLinkLabel = null,
        ?string $publicCoverIcon = null,
        ?string $sortDefault = null,
        ?bool $showFolderCount = null,
        ?bool $showFolderDate = null,
        ?bool $showTrackDuration = null,
        ?bool $showTrackDate = null,
        ?bool $prettyFolderNames = null,
        ?bool $titleFromTags = null,
        ?string $starColor = null,
        ?string $searchScope = null,
        ?string $repeatDefault = null,
        ?bool $featureComments = null,
        ?bool $featureRating = null,
        ?bool $publicComments = null,
        ?string $commentNotifyGroup = null,
        ?int $rememberDays = null,
        ?string $sharedLabel = null,
        ?bool $featureFavorites = null,
        ?bool $featureFolderDownload = null,
        ?bool $transcode = null,
        ?array $extraSources = null,
        ?array $shareGroups = null,
    ): DataResponse {

        // ---------- Quellordner ----------
        if ($sourceFolder !== null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new DataResponse(['error' => 'Nicht angemeldet.'], Http::STATUS_UNAUTHORIZED);
            }

            $path = '/' . trim($sourceFolder, '/');

            if ($path !== '/') {
                try {
                    $node = $this->rootFolder->getUserFolder($user->getUID())->get($path);
                } catch (NotFoundException $e) {
                    return new DataResponse(
                        ['error' => 'Der Ordner wurde nicht gefunden.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }

                if ($node->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                    return new DataResponse(
                        ['error' => 'Der angegebene Pfad ist kein Ordner.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_FOLDER, $path);
            // Wem die Dateien gehoeren, muss mitgespeichert werden: Beim
            // oeffentlichen Zugang gibt es keinen angemeldeten Nutzer, ueber
            // dessen Dateien sich der Ordner sonst aufloesen liesse.
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_FOLDER_OWNER, $user->getUID()
            );
        }

        // ---------- Weitere Quellen (ab 0.32.0, Vikunja #8) ----------
        if ($extraSources !== null) {
            $error = $this->saveExtraSources($extraSources);
            if ($error !== '') {
                return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
            }
        }
        if ($shareGroups !== null) {
            $groupManager = \OCP\Server::get(\OCP\IGroupManager::class);
            $clean = [];
            foreach ($shareGroups as $gid) {
                $gid = (string)$gid;
                if ($gid !== '' && $groupManager->groupExists($gid) && !in_array($gid, $clean, true)) {
                    $clean[] = $gid;
                }
            }
            $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_SHARE_GROUPS, json_encode($clean));
        }

        // ---------- Oeffentlicher Zugang ----------
        if ($publicEnabled !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_PUBLIC_ENABLED, $publicEnabled
            );

            // Beim ersten Einschalten einen Token erzeugen. Er bleibt danach
            // bestehen, damit bereits verteilte Links gueltig bleiben.
            if ($publicEnabled) {
                $token = $this->appConfig->getValueString(
                    Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
                );
                if ($token === '') {
                    $token = $this->secureRandom->generate(
                        24,
                        ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
                    );
                    $this->appConfig->setValueString(
                        Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, $token
                    );
                }
            }
        }

        // ---------- Wunschname fuer den Link (ab 0.13) ----------
        if ($publicSlug !== null) {
            $current = $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
            );
            $slug = ShareService::normalizeSlug($publicSlug);
            $newToken = null;

            if ($slug === '') {
                // Wunschname entfernen: wieder ein zufaelliger Token
                if ($current !== '' && preg_match(ShareService::SLUG_PATTERN, $current)) {
                    do {
                        $newToken = $this->secureRandom->generate(
                            24,
                            ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
                        );
                    } while (!$this->shares->isTokenAvailable($newToken));
                }
            } elseif ($slug !== $current) {
                if (!preg_match(ShareService::SLUG_PATTERN, $slug)) {
                    return new DataResponse(
                        ['error' => 'Der Wunschname braucht 3 bis 64 Zeichen: Buchstaben a–z, Ziffern und Bindestriche.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
                // Gegen die Links der Nutzer pruefen. Der bisherige eigene
                // Token zaehlt dabei nicht als Konflikt.
                if (strtolower($current) !== $slug && !$this->shares->isTokenAvailable($slug)) {
                    return new DataResponse(['error' => 'Dieser Name ist bereits vergeben.'], Http::STATUS_BAD_REQUEST);
                }
                $newToken = $slug;
            }

            /*
             * Ein neuer Token macht den alten Link ungueltig - und damit
             * auch bestehende Sitzungen und die Startadresse bereits
             * installierter Apps. Das ist gewollt: Wer den Link aendert,
             * will den alten meist nicht mehr gelten lassen.
             */
            if ($newToken !== null) {
                $this->appConfig->setValueString(
                    Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, $newToken
                );
            }
        }

        if ($publicPassword !== null && $publicPassword !== '') {
            $this->appConfig->setValueString(
                Application::APP_ID,
                Application::SETTING_PUBLIC_PASSWORD,
                $this->hasher->hash($publicPassword)
            );
        }

        // ---------- Texte ----------
        if ($headerTitle !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_HEADER_TITLE, trim($headerTitle)
            );
        }
        if ($headerSubtitle !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_HEADER_SUBTITLE, trim($headerSubtitle)
            );
        }

        // ---------- Farben ----------
        $colors = [
            Application::SETTING_THEME_ACCENT => $themeAccent,
            Application::SETTING_THEME_BAR => $themeBar,
            Application::SETTING_THEME_BASE => $themeBase,
        ];
        foreach ($colors as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                return new DataResponse(
                    ['error' => 'Ungültiger Farbwert: ' . $value],
                    Http::STATUS_BAD_REQUEST
                );
            }
            $this->appConfig->setValueString(Application::APP_ID, $key, strtolower($value));
        }

        // ---------- Gestaltung ----------
        if ($design !== null) {
            // Als Vorgabe taugen Klassisch, Modern und die eigene
            // Gestaltung - "benutzerdefiniert" gibt es nur fuer Nutzer
            // und Freigaben
            if (!in_array($design, [Application::DESIGN_CUSTOM, Application::DESIGN_NEXTCLOUD, Application::DESIGN_ADMIN], true)) {
                return new DataResponse(
                    ['error' => 'Unbekannte Gestaltung: ' . $design],
                    Http::STATUS_BAD_REQUEST
                );
            }
            $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_DESIGN, $design);
        }

        // ---------- Vom Administrator bereitgestellte Gestaltung (ab 0.17) ----------
        if ($adminStyle !== null) {
            $this->appearance->setAdminStyle(StyleTokens::normalize($adminStyle));
        }
        if ($adminStyleEnabled !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_ADMIN_STYLE_ENABLED, $adminStyleEnabled
            );
        }

        // Bild bei Aufnahmen ohne Cover fuer den Administrator-Link (ab 0.21.1)
        if ($publicCoverIcon !== null) {
            $icon = ($publicCoverIcon === AppIcon::COVER_CUSTOM || in_array($publicCoverIcon, AppIcon::COVER_ICONS, true))
                ? $publicCoverIcon : '';
            $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_PUBLIC_COVER_ICON, $icon);
        }

        if ($backgroundNextcloud !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_BACKGROUND_NEXTCLOUD, $backgroundNextcloud
            );
        }
        if ($userShares !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_USER_SHARES, $userShares
            );
        }
        if ($userCustomization !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_USER_CUSTOMIZATION, $userCustomization
            );
        }

        // ---------- Funktionen ----------
        if ($featureOffline !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, $featureOffline
            );
        }
        if ($rememberDays !== null) {
            $this->appConfig->setValueInt(
                Application::APP_ID, Application::SETTING_REMEMBER_DAYS,
                in_array($rememberDays, RememberLogin::ALLOWED_DAYS, true) ? $rememberDays : 0
            );
        }
        if ($sharedLabel !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_SHARED_LABEL,
                self::shorten(trim(strip_tags($sharedLabel)), 60)
            );
        }
        if ($transcode !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_TRANSCODE, $transcode);
        }
        if ($featureFolderDownload !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_FEATURE_FOLDER_DOWNLOAD, $featureFolderDownload);
        }
        if ($featureFavorites !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_FEATURE_FAVORITES, $featureFavorites);
        }
        if ($sortDefault !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_SORT_DEFAULT, $sortDefault === 'newest' ? 'newest' : 'name'
            );
        }
        if ($showFolderCount !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_SHOW_FOLDER_COUNT, $showFolderCount);
        }
        // ---------- Anzeige in der Liste, Namen, Sternfarbe (ab 0.33.0) ----------
        foreach ([
            Application::SETTING_SHOW_FOLDER_DATE => $showFolderDate,
            Application::SETTING_SHOW_TRACK_DURATION => $showTrackDuration,
            Application::SETTING_SHOW_TRACK_DATE => $showTrackDate,
            Application::SETTING_PRETTY_FOLDER_NAMES => $prettyFolderNames,
            Application::SETTING_TITLE_FROM_TAGS => $titleFromTags,
        ] as $key => $value) {
            if ($value !== null) {
                $this->appConfig->setValueBool(Application::APP_ID, $key, $value);
            }
        }
        if ($starColor !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_STAR_COLOR,
                in_array($starColor, Application::STAR_COLORS, true) ? $starColor : 'accent'
            );
        }
        // ---------- Kommentare (ab 0.29.0) ----------
        if ($featureComments !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_FEATURE_COMMENTS, $featureComments);
        }
        if ($featureRating !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_FEATURE_RATING, $featureRating);
        }
        if ($publicComments !== null) {
            $this->appConfig->setValueBool(Application::APP_ID, Application::SETTING_PUBLIC_COMMENTS, $publicComments);
        }
        if ($commentNotifyGroup !== null) {
            $gid = trim($commentNotifyGroup);
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_COMMENT_NOTIFY_GROUP,
                ($gid !== '' && \OCP\Server::get(\OCP\IGroupManager::class)->groupExists($gid)) ? $gid : ''
            );
        }
        if ($searchScope !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_SEARCH_SCOPE, $searchScope === 'all' ? 'all' : 'folder'
            );
        }
        if ($repeatDefault !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_REPEAT_DEFAULT,
                in_array($repeatDefault, Application::REPEAT_MODES, true) ? $repeatDefault : 'next'
            );
        }
        if ($featureDownload !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, $featureDownload
            );
        }

        // ---------- BETA-Schild und Text ueber den Aufnahmen ----------
        if ($betaEnabled !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_BETA_ENABLED, $betaEnabled
            );
        }
        if ($noticeEnabled !== null) {
            $this->appConfig->setValueBool(
                Application::APP_ID, Application::SETTING_NOTICE_ENABLED, $noticeEnabled
            );
        }

        if ($betaText !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_BETA_TEXT, trim($betaText)
            );
        }

        if ($betaLinkUrl !== null) {
            $url = trim($betaLinkUrl);

            /*
             * Nur http und https zulassen. Ohne diese Pruefung liesse sich
             * hier "javascript:..." hinterlegen - und der Hinweis wird allen
             * Nutzern angezeigt, auch denen der oeffentlichen Seite.
             */
            if ($url !== '') {
                $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    return new DataResponse(
                        ['error' => 'Die Adresse muss mit http:// oder https:// beginnen.'],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_URL, $url
            );
        }

        if ($betaLinkLabel !== null) {
            $this->appConfig->setValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_LABEL, trim($betaLinkLabel)
            );
        }

        $token = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, '');
        return new DataResponse([
            'publicUrl' => $this->publicUrl(),
            'publicSlug' => preg_match(ShareService::SLUG_PATTERN, $token) ? $token : '',
            // Mit vergebenen Kennungen, damit erneutes Speichern keine Doppel anlegt (ab 0.32.0)
            'extraSources' => \OCP\Server::get(\OCA\AudioArchive\Service\AudioFolder::class)->extraSources(),
        ]);
    }

    /**
     * Nimmt ein Hintergrundbild entgegen.
     *
     * Gesendet wird als klassischer Datei-Upload, nicht als JSON - deshalb
     * kommt der Inhalt ueber $_FILES und nicht ueber die Parameter.
     */
    public function uploadBackground(): DataResponse {
        $response = $this->storeUpload(BackgroundImage::ADMIN);
        return $response ?? new DataResponse(['hasBackground' => true]);
    }

    /**
     * Eigenes Cover-Ersatzbild des Administrator-Links (ab 0.21.1). Gilt
     * erst mit der Auswahl "Eigenes Bild" (publicCoverIcon = 'custom').
     */
    public function uploadCover(): DataResponse {
        $response = $this->storeUpload(BackgroundImage::ADMIN_COVER);
        if ($response !== null) {
            return $response;
        }
        return new DataResponse([
            'hasCoverImage' => true,
            'coverImageUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.adminCover')
                . '?v=' . $this->backgroundImage->version(BackgroundImage::ADMIN_COVER),
        ]);
    }

    public function removeCover(): DataResponse {
        $this->backgroundImage->remove(BackgroundImage::ADMIN_COVER);
        return new DataResponse(['hasCoverImage' => false]);
    }

    /** Gemeinsamer Teil der Bild-Uploads: null = gespeichert, sonst Fehlerantwort. */
    private function storeUpload(string $key): ?DataResponse {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'Es wurde keine Datei empfangen.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $error = $this->backgroundImage->store((string)$file['tmp_name'], (int)$file['size'], $key);
        } catch (\Throwable $e) {
            /*
             * Die Meldung wird mitgegeben, weil diesen Endpunkt nur
             * Administratoren erreichen. Ohne sie erscheint in der
             * Oberflaeche nur ein nichtssagendes "Hochladen fehlgeschlagen",
             * und die Ursache bleibt im Verborgenen.
             */
            return new DataResponse(
                ['error' => 'Unerwarteter Fehler: ' . $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        if ($error !== '') {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        return null;
    }

    public function removeBackground(): DataResponse {
        $this->backgroundImage->remove();
        return new DataResponse(['hasBackground' => false]);
    }

    /** Vollstaendiger Link der oeffentlichen Seite, leer wenn nicht freigegeben. */
    private function publicUrl(): string {
        $enabled = $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_PUBLIC_ENABLED, false
        );
        $token = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
        );

        if (!$enabled || $token === '') {
            return '';
        }

        return $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.publicPlayer.index',
            ['token' => $token]
        );
    }

    /** Kuerzt auf hoechstens $max Zeichen, ohne ein UTF-8-Zeichen zu zerschneiden. */
    private static function shorten(string $s, int $max): string {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : substr($s, 0, $max);
    }
}
