<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\App\IAppManager;
use OCP\IUserSession;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Baut die Player-Seite - fuer alle Eingaenge mit demselben Inhalt.
 *
 * Zwei Darstellungen:
 *
 * - eingebettet (RENDER_AS_USER): innerhalb von Nextcloud mit dessen
 *   Kopfleiste. Nur fuer angemeldete Nutzer ueber den Menue-Eintrag.
 *
 * - eigenstaendig (RENDER_AS_BLANK): OHNE Nextclouds Seitengeruest. Fuer
 *   die oeffentliche Seite und die installierte App. Grund: Nextcloud
 *   bindet auf seinen eigenen Seiten ein Manifest ein, und der Browser
 *   wertet nur das erste aus. Nur mit eigenem Markup greift unser Manifest,
 *   und die App laesst sich als eigene Kachel installieren.
 *
 * Der eigentliche Inhalt steckt in templates/parts/player-body.php und wird
 * von beiden Seitenvorlagen eingebunden.
 */
class PlayerPage {

    public function __construct(
        private IAppConfig $appConfig,
        private IURLGenerator $urlGenerator,
        private Appearance $appearance,
        private IUserSession $userSession,
        private AudioFolder $audioFolder,
        private ShareService $shares,
        private IAppManager $appManager,
        private AppIcon $appIcon,
        private \OCP\IConfig $config,
    ) {
    }

    /** Favoriten fuer diese Seite? Verwaltung ein und - angemeldet - nicht selbst abgeschaltet. */
    private function favoritesOn(?string $uid): bool {
        if (!$this->appConfig->getValueBool(Application::APP_ID, Application::SETTING_FEATURE_FAVORITES, true)) {
            return false;
        }
        return $uid === null
            || $this->config->getUserValue($uid, Application::APP_ID, Application::USER_FAVORITES, '1') !== '0';
    }

    /**
     * Vorgabe fuer "Wiederholen" (ab 0.28.0, Vikunja #2): Link-Seite -> Wert
     * der Freigabe, angemeldet -> persoenlicher Wert, sonst Verwaltung.
     */
    private function repeatDefault(?string $uid, ?array $share): string {
        $mode = '';
        if ($share !== null) {
            $mode = (string)($share['settings']['repeatDefault'] ?? '');
        } elseif ($uid !== null) {
            $mode = $this->config->getUserValue($uid, Application::APP_ID, Application::USER_REPEAT_DEFAULT, '');
        }
        if (!in_array($mode, Application::REPEAT_MODES, true)) {
            $mode = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_REPEAT_DEFAULT, 'next');
        }
        return in_array($mode, Application::REPEAT_MODES, true) ? $mode : 'next';
    }

    /** Name des freigegebenen Ordners - Titel, wenn keiner gesetzt ist. */
    private function shareFolderName(array $share): string {
        $folder = $this->shares->rootFolder($share);
        return $folder !== null && $folder->getName() !== '' ? $folder->getName() : 'Aufnahmen';
    }

    /**
     * Stylesheets mit Nextclouds Gestaltungs-Variablen fuer die Seiten
     * OHNE Nextcloud-Rahmen.
     *
     * Innerhalb von Nextcloud bindet das Seitengeruest diese Variablen
     * selbst ein - und zwar passend zum Design, das der Nutzer gewaehlt hat.
     * Die eigenstaendigen Seiten (geteilter Link, installierte App) liefern
     * ihr Markup selbst und muessen sie deshalb selbst einbinden. Das geht
     * ueber denselben oeffentlichen Endpunkt der Theming-App, den Nextcloud
     * auch auf seinen Anmeldeseiten nutzt:
     *   - 'default' immer (hell),
     *   - 'dark' zusaetzlich, wenn das Geraet auf dunkel steht.
     * Mit plain=1 liefert der Endpunkt die Variablen direkt auf :root.
     *
     * Ist die Theming-App abgeschaltet, gibt es die Route nicht - dann
     * greifen die Ersatzwerte in style.css.
     *
     * @return list<array{href: string, media: string}>
     */
    private function themeStylesheets(): array {
        try {
            $cacheBuster = $this->appConfig->getValueString('theming', 'cachebuster', '0');
        } catch (\Throwable $e) {
            $cacheBuster = '0';
        }

        $links = [];
        foreach (['default' => 'all', 'dark' => '(prefers-color-scheme: dark)'] as $themeId => $media) {
            try {
                $href = $this->urlGenerator->linkToRoute('theming.Theming.getThemeStylesheet', [
                    'themeId' => $themeId,
                    'plain' => 1,
                    'v' => $cacheBuster,
                ]);
            } catch (\Throwable $e) {
                return [];
            }
            if ($href === '') {
                return [];
            }
            $links[] = ['href' => $href, 'media' => $media];
        }
        return $links;
    }

    /**
     * @param string $publicToken Token des Links ('' = angemeldete Ansicht)
     * @param array|null $share   Freigabe eines Nutzers (siehe ShareService),
     *                            null beim Administrator-Link
     */
    public function build(string $publicToken, bool $embedded = false, ?array $share = null): TemplateResponse {
        // Die oeffentliche Seite ist nie eingebettet - Gaeste haben keine
        // Nextcloud-Leiste.
        $embedded = $embedded && $publicToken === '';

        // Angemeldeter Nutzer - nur ausserhalb der oeffentlichen Seite
        // relevant: Dort gilt, was fuer den Link eingestellt ist.
        $user = $publicToken === '' ? $this->userSession->getUser() : null;
        $uid = $user?->getUID();

        $look = $share !== null ? $this->appearance->resolveShare($share) : $this->appearance->resolve($uid);
        $design = $look['design'];
        // Vollstaendige Werte bei 'admin'/'defined' (ab 0.17), sonst null
        $style = $look['style'];
        $shareSettings = $share['settings'] ?? null;

        /*
         * Titel, Zusatzzeile und Farben, jeweils die spezifischste Ebene:
         *   Link-Seite:   Wert der Freigabe, sonst Administrator
         *   angemeldet:   persoenlicher Wert (ab 0.13), sonst Administrator
         *   Admin-Link:   Administrator
         */
        $values = $share !== null ? $this->appearance->adminValues() : $this->appearance->effectiveLook($uid);
        if ($shareSettings !== null) {
            foreach (['subtitle', 'themeAccent', 'themeBar', 'themeBase'] as $key) {
                if ($shareSettings[$key] !== '') {
                    $values[$key] = $shareSettings[$key];
                }
            }
            $values['title'] = $shareSettings['title'] !== '' ? $shareSettings['title'] : $this->shareFolderName($share);
            // Ohne eigene Zusatzzeile keine des Administrators - der Link
            // zeigt einen anderen Ordner als dessen Seite
            if ($shareSettings['subtitle'] === '') {
                $values['subtitle'] = '';
            }
        }

        // Stylesheets mit Nextclouds Variablen - eigenstaendig bei
        // Nextcloud-Gestaltung sofort, angemeldet zusaetzlich zum Nachladen,
        // falls ein mit dem Nutzer geteilter Ordner diese Gestaltung hat
        $themeStylesheets = !$embedded ? $this->themeStylesheets() : [];
        $barColor = $this->appearance->barColor($design, $values['themeBar'], $style);

        $params = [
            'publicToken' => $publicToken,
            'embedded' => $embedded ? '1' : '',
            'design' => $design,
            // Flacher Aufbau wie Nextcloud: "Klassisch" oder Grundstil flach
            'flat' => Appearance::isFlat($design, $style) ? '1' : '',
            'styleJson' => $style !== null ? StyleTokens::toJson($style) : '',
            // Nur eigenstaendig noetig, siehe themeStylesheets()
            'themeStylesheets' => $design === Application::DESIGN_NEXTCLOUD ? $themeStylesheets : [],
            'themeStylesheetsJson' => ($uid !== null && $design !== Application::DESIGN_NEXTCLOUD)
                ? (string)json_encode($themeStylesheets, JSON_UNESCAPED_SLASHES)
                : '[]',
            'themeColor' => $barColor,
            // App-Symbol in der Leistenfarbe (ab 0.16): Farbe fuer die
            // Skripte, dazu Browser-Tab und Apple-Startbildschirm
            'iconColor' => (string)AppIcon::normalizeColor($barColor),
            'ncPrimary' => (string)AppIcon::normalizeColor($this->appearance->barColor(Application::DESIGN_NEXTCLOUD)),
            'faviconUrl' => $this->appIcon->url(
                $barColor, 'any-192', false,
                (string)$this->appManager->getAppVersion(Application::APP_ID)
            ),
            // Bild fuer Aufnahmen ohne Cover (ab 0.20): Auswahl der Freigabe,
            // sonst die Vorgabe (Archiv-Liste mit Lautsprecher)
            // Administrator-Link: eigene Wahl in der Verwaltung (ab 0.21.1)
            ...(($share === null && $publicToken !== '')
                ? $this->appearance->adminCover()
                : $this->appearance->shareCover($share)),
            'appleIconUrl' => $this->appIcon->url(
                $barColor, 'apple-180', false,
                (string)$this->appManager->getAppVersion(Application::APP_ID)
            ),
            // Darf der Nutzer in der App seine Darstellung selbst waehlen?
            'userSettings' => ($uid !== null && $this->appearance->userCustomizationAllowed()) ? '1' : '',
            // "Vom Administrator bereitgestellt" waehlbar? (ab 0.17)
            'adminStyleOffered' => $this->appearance->adminStyleOffered() ? '1' : '',
            // Fuer Schreibzugriffe der App (persoenliche Einstellungen):
            // Nextcloud verlangt dafuer das Anfrage-Token. Die eigenstaendige
            // Seite hat kein OC.requestToken, deshalb steht es im Dokument.
            'requestToken' => $uid !== null ? $this->requestToken() : '',
            // Ordnerbaum: nur angemeldet; der gemeinsame Ordner nur, wenn
            // der Administrator einen eingerichtet hat
            'loggedIn' => $uid !== null ? '1' : '',
            'hasShared' => $this->audioFolder->hasSharedRoot() ? '1' : '',
            // Freigaben anlegen: angemeldet und vom Administrator erlaubt
            'canShare' => ($uid !== null && $this->shares->sharingAllowed()) ? '1' : '',
            // Adresse der eigenstaendigen Fassung, fuer den Knopf
            // "App installieren" auf der eingebetteten Seite
            'standaloneUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.page.standalone'
            ),
            // Startseite der Nextcloud fuer den Knopf "Zu Nextcloud" in der
            // Seitenleiste (ab 0.21.1, Vikunja #26)
            'nextcloudUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
            // Favoriten (ab 0.24.0, Vikunja #3): Verwaltung und persoenlich
            'favorites' => $this->favoritesOn($uid) ? '1' : '',
            // Name des gemeinsamen Ordners (ab 0.23.0, Vikunja #37); leer = Vorgabe
            'sharedLabel' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_SHARED_LABEL, ''
            ),
            // Suchbereich und Wiederholen-Vorgabe (ab 0.28.0, Vikunja #32/#2)
            'searchScope' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_SEARCH_SCOPE, 'folder'
            ) === 'all' ? 'all' : 'folder',
            'repeatDefault' => $this->repeatDefault($uid, $share),
            // Fuer die Auswahl "Vorgabe der Verwaltung (…)" in Link-Formular und Zahnrad
            'adminRepeatDefault' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_REPEAT_DEFAULT, 'next'
            ),
            // Vorgabe fuer die Sortierung der Liste (ab 0.22.0, Vikunja #30)
            'sortDefault' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_SORT_DEFAULT, 'name'
            ) === 'newest' ? 'newest' : 'name',
            'headerTitle' => $values['title'],
            'headerSubtitle' => $values['subtitle'],
            'themeBar' => $values['themeBar'],
            'themeAccent' => $values['themeAccent'],
            'themeBase' => $values['themeBase'],
            // Freigabe eines Nutzers: Die App haengt diesen Token an alle
            // Abrufe (s=...). Beim Administrator-Link bleibt er leer, damit
            // dessen Adressen - und damit offline Gespeichertes - gleich
            // bleiben.
            'apiToken' => $share !== null ? $share['token'] : '',
            // Freigabe ohne Passwort: kein Anmelde-Bildschirm, auch offline
            'openAccess' => ($share !== null && !$share['hasPassword']) ? '1' : '',
            'manifestUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.asset.manifest'
            ) . ($publicToken !== '' ? '?s=' . urlencode($share !== null ? $share['token'] : $publicToken) : ''),
            'serviceWorkerUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.asset.serviceWorker'
            ),
            'scopeUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.page.index'
            ),
            'assetBase' => $this->urlGenerator->linkTo(Application::APP_ID, ''),
            'assetVersion' => $this->assetVersion(),
            'cspNonce' => $this->cspNonce(),
            /*
             * Beta-Hinweis: seit 0.13 ausschliesslich Sache des
             * Administrators. Ist er eingeschaltet, erscheint er ueberall -
             * in der App, auf dem Administrator-Link und auf allen Links der
             * Nutzer. Nutzer koennen ihn weder ein- noch ausschalten.
             */
            'betaEnabled' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_BETA_ENABLED, false
            ) ? '1' : '',
            'betaText' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_TEXT, ''
            ),
            'betaLinkUrl' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_URL, ''
            ),
            'betaLinkLabel' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_LABEL, ''
            ),
            // Leer, wenn kein Bild gilt - dann zeigt die App den Verlauf aus
            // dem Grundton bzw. Nextclouds Hintergrund (siehe Appearance).
            'backgroundUrl' => $look['backgroundUrl'],
            // Installierte Fassung der App (ab 0.15.2), unten in der Liste
            // und in der Darstellung angezeigt
            'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
        ];

        $response = new TemplateResponse(
            Application::APP_ID,
            $embedded ? 'player-embedded' : 'player',
            $params,
            $embedded ? TemplateResponse::RENDER_AS_USER : TemplateResponse::RENDER_AS_BLANK
        );

        // Audio wird ueber einen eigenen Endpunkt derselben Herkunft
        // ausgeliefert; mehr Freiheiten braucht die Seite nicht.
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedMediaDomain("'self'");
        $csp->addAllowedImageDomain("'self'");
        $csp->addAllowedImageDomain('data:');

        /*
         * Service Worker ausdruecklich erlauben. Ohne worker-src greift der
         * Browser auf script-src zurueck - und dort steht seit Nextcloud 34
         * nur noch das Nonce, kein 'self'. Die Registrierung scheiterte dann
         * still: keine Offline-Wiedergabe, kein Vorausladen, und die App war
         * nicht installierbar. (Gefunden 0.10.0 an einer echten
         * Nextcloud 34.0.3.)
         */
        $csp->addAllowedWorkerSrcDomain("'self'");

        /*
         * Hinweis zu 'strict-dynamic':
         * Nextcloud setzt fuer Skripte 'strict-dynamic'. Der Browser ignoriert
         * dann Pfadfreigaben wie 'self' vollstaendig - es zaehlt nur noch ein
         * passendes Nonce. Es waere naheliegend, das ueber
         * useStrictDynamicOnScripts(false) fuer diese Seite abzuschalten; das
         * greift aber nicht, weil beim Zusammenfuehren mit der
         * Standardrichtlinie der jeweils strengere Wert gewinnt. Der einzige
         * gangbare Weg ist deshalb, das Nonce selbst an das <script>-Tag zu
         * schreiben (siehe cspNonce()).
         */
        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    /**
     * Kennung zum Anhaengen an Stylesheet- und Skript-Adressen.
     *
     * Ohne sie behaelt der Browser einmal geladene Dateien beliebig lange -
     * nach einem App-Update laeuft dann weiter die alte Fassung, und zwar
     * ohne jeden Hinweis. Nextcloud haengt bei Util::addScript von sich aus
     * eine Kennung an; weil diese Seite ihr Markup selbst liefert, muss das
     * hier von Hand geschehen.
     *
     * Verwendet wird der juengste Aenderungszeitpunkt der ausgelieferten
     * Dateien - damit aendert sich die Kennung bei jedem Einspielen,
     * unabhaengig davon, ob die Versionsnummer erhoeht wurde.
     */
    private function assetVersion(): string {
        $newest = 0;

        $files = [
            '/../../css/style.css',
            '/../../css/style-editor.css',
            '/../../js/config.js',
            '/../../js/style-tokens.js',
            '/../../js/player.js',
            '/../../js/app.js',
        ];

        foreach ($files as $relative) {
            $mtime = @filemtime(__DIR__ . $relative);
            if ($mtime !== false && $mtime > $newest) {
                $newest = $mtime;
            }
        }

        return (string)$newest;
    }

    /**
     * Liefert das Nonce, mit dem Nextcloud Skripte auf dieser Anfrage erlaubt.
     *
     * Der Anfrage-Token hat die Form "base64(wert XOR geheimnis):base64(geheimnis)".
     * Als Nonce verwendet Nextcloud ausschliesslich den hinteren Teil, also das
     * Geheimnis - der vordere Teil traegt keine zusaetzliche Zufaelligkeit bei.
     * Deshalb wird hier am Doppelpunkt zerlegt und das letzte Stueck genommen.
     */
    private function requestToken(): string {
        try {
            return \OCP\Util::callRegister();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function cspNonce(): string {
        try {
            $token = \OCP\Util::callRegister();
        } catch (\Throwable $e) {
            return '';
        }

        $parts = explode(':', $token);
        return (string)end($parts);
    }
}
