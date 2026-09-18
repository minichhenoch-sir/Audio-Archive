<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\BackgroundImage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Defaults;
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
        private BackgroundImage $backgroundImage,
        private Defaults $defaults,
    ) {
    }

    /** Ist die Nextcloud-Gestaltung eingestellt? */
    public function usesNextcloudDesign(): bool {
        return $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_DESIGN, Application::DESIGN_CUSTOM
        ) === Application::DESIGN_NEXTCLOUD;
    }

    /**
     * Farbe fuer die Statusleiste der installierten App und das Manifest.
     * Bei Nextcloud-Gestaltung Nextclouds Hauptfarbe, sonst die eigene
     * Leistenfarbe.
     */
    public function barColor(): string {
        if ($this->usesNextcloudDesign()) {
            $primary = $this->defaults->getColorPrimary();
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary)) {
                return $primary;
            }
        }
        return $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_THEME_BAR, '#291c12'
        );
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

    public function build(string $publicToken, bool $embedded = false): TemplateResponse {
        // Die oeffentliche Seite ist nie eingebettet - Gaeste haben keine
        // Nextcloud-Leiste.
        $embedded = $embedded && $publicToken === '';

        $params = [
            'publicToken' => $publicToken,
            'embedded' => $embedded ? '1' : '',
            'design' => $this->usesNextcloudDesign()
                ? Application::DESIGN_NEXTCLOUD
                : Application::DESIGN_CUSTOM,
            // Nur eigenstaendig noetig, siehe themeStylesheets()
            'themeStylesheets' => (!$embedded && $this->usesNextcloudDesign())
                ? $this->themeStylesheets()
                : [],
            'themeColor' => $this->barColor(),
            // Adresse der eigenstaendigen Fassung, fuer den Knopf
            // "App installieren" auf der eingebetteten Seite
            'standaloneUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.page.standalone'
            ),
            'headerTitle' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_HEADER_TITLE, 'Recordings'
            ),
            'headerSubtitle' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_HEADER_SUBTITLE, ''
            ),
            'themeBar' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_THEME_BAR, '#291c12'
            ),
            'themeAccent' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_THEME_ACCENT, '#b9793f'
            ),
            'themeBase' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_THEME_BASE, '#a86a3d'
            ),
            'manifestUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.asset.manifest'
            ) . ($publicToken !== '' ? '?s=' . urlencode($publicToken) : ''),
            'serviceWorkerUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.asset.serviceWorker'
            ),
            'scopeUrl' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.page.index'
            ),
            'assetBase' => $this->urlGenerator->linkTo(Application::APP_ID, ''),
            'assetVersion' => $this->assetVersion(),
            'cspNonce' => $this->cspNonce(),
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
            // Leer, wenn kein Bild gesetzt ist - dann zeigt die App den
            // Verlauf aus dem Grundton.
            'backgroundUrl' => $this->backgroundImage->exists()
                ? $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.background')
                  . '?v=' . $this->assetVersion()
                : '',
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
            '/../../js/config.js',
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
