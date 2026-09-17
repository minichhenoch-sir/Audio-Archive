<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
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
            'cspNonce' => $this->cspNonce(),
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
         * Nextcloud setzt fuer Skripte 'strict-dynamic'. Dabei ignoriert der
         * Browser Pfadfreigaben wie 'self' vollstaendig - es zaehlt nur noch
         * ein passendes Nonce. Weil diese Seite ihr Markup selbst liefert
         * (siehe oben) und damit nicht durch Util::addScript laeuft, das das
         * Nonce sonst automatisch vergibt, sind hier zwei Wege noetig:
         *   1. 'strict-dynamic' fuer diese eine Seite abschalten, sofern die
         *      Nextcloud-Fassung das anbietet, und
         *   2. das Nonce zusaetzlich selbst an das <script>-Tag schreiben.
         * Jeder der beiden Wege genuegt fuer sich; zusammen sind sie
         * unabhaengig von der Nextcloud-Fassung verlaesslich.
         */
        if (method_exists($csp, 'useStrictDynamicOnScripts')) {
            $csp->useStrictDynamicOnScripts(false);
        }

        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    /**
     * Liefert das Nonce, mit dem Nextcloud Skripte auf dieser Anfrage erlaubt.
     *
     * Nextcloud bildet es aus dem Anfrage-Token (genau das steckt auch hinter
     * dem bekannten btoa(OC.requestToken) auf der JavaScript-Seite).
     */
    private function cspNonce(): string {
        try {
            return base64_encode(\OCP\Util::callRegister());
        } catch (\Throwable $e) {
            return '';
        }
    }
}
