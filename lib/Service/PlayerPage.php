<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\BackgroundImage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Baut die Player-Seite - fuer beide Eingaenge identisch.
 *
 * Wichtig: Die Seite wird mit RENDER_AS_BLANK ausgeliefert, also OHNE
 * Nextclouds Seitengeruest. Grund ist die Installierbarkeit als eigene App:
 * Nextcloud bindet auf seinen eigenen Seiten ein Manifest ein, und der
 * Browser wertet nur das erste aus. Nur mit eigenem Markup koennen wir
 * unser Manifest setzen und die App als eigene Kachel installierbar machen.
 */
class PlayerPage {

    public function __construct(
        private IAppConfig $appConfig,
        private IURLGenerator $urlGenerator,
        private BackgroundImage $backgroundImage,
    ) {
    }

    public function build(string $publicToken): TemplateResponse {
        $params = [
            'publicToken' => $publicToken,
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
            'player',
            $params,
            TemplateResponse::RENDER_AS_BLANK
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
