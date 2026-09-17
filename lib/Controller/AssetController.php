<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Liefert die beiden Dateien aus, die eine PWA braucht: das Manifest und den
 * Service Worker.
 *
 * Warum ueber einen Controller statt einfach als Datei im js-Ordner?
 * Ein Service Worker darf nur den Pfadbereich abdecken, in dem er selbst
 * liegt. Aus "/apps/audioarchive/js/service-worker.js" heraus koennte er
 * die eigentliche Player-Seite unter "/apps/audioarchive/" NICHT
 * steuern. Ueber diese Route liegt er direkt im App-Wurzelpfad und deckt
 * damit sowohl die Seite fuer angemeldete Nutzer als auch die oeffentliche
 * Seite ab.
 */
class AssetController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function serviceWorker(): DataDisplayResponse {
        $path = __DIR__ . '/../../js/service-worker.js';
        $content = is_file($path) ? (string)file_get_contents($path) : '';

        $response = new DataDisplayResponse(
            $content,
            Http::STATUS_OK,
            ['Content-Type' => 'application/javascript; charset=utf-8']
        );

        /*
         * WICHTIG: Ein Service Worker erbt die Sicherheitsrichtlinie der
         * Antwort, mit der er SELBST ausgeliefert wurde - nicht die der
         * Seite, die er steuert. Antworten aus Controllern bekommen in
         * Nextcloud standardmaessig "default-src 'none'". Ohne die folgende
         * Zeile darf der Worker also gar nichts abrufen: Jedes fetch() in
         * ihm scheitert, und weil er Anfragen abfaengt, fallen damit auch
         * Stylesheet, Skript und Bilder der Seite aus.
         */
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedConnectDomain("'self'");
        $response->setContentSecurityPolicy($csp);

        // Der Worker wird bewusst nicht zwischengespeichert, damit ein
        // App-Update auf den Geraeten auch wirklich ankommt.
        $response->cacheFor(0);
        return $response;
    }

    /**
     * Das Manifest wird erzeugt statt fest hinterlegt, weil Name und
     * Startadresse davon abhaengen, ob die App oeffentlich oder angemeldet
     * genutzt wird.
     *
     * @param string $s Token der oeffentlichen Seite (leer = angemeldete Nutzung)
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function manifest(string $s = ''): DataDisplayResponse {
        $base = $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index');

        $start = $s !== ''
            ? $this->urlGenerator->linkToRoute(Application::APP_ID . '.publicPlayer.index', ['token' => $s])
            : $base;

        $title = $this->appConfig->getValueString(
            Application::APP_ID,
            Application::SETTING_HEADER_TITLE,
            'Recordings'
        );

        $barColor = $this->appConfig->getValueString(
            Application::APP_ID,
            Application::SETTING_THEME_BAR,
            '#291c12'
        );

        $icon = fn (string $file) => $this->urlGenerator->imagePath(Application::APP_ID, $file);

        $manifest = [
            'name' => $title,
            'short_name' => $title,
            'start_url' => $start,
            // Der Geltungsbereich umfasst beide Eingaenge der App und deckt
            // sich mit dem Bereich des Service Workers.
            'scope' => $base,
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => $barColor,
            'theme_color' => $barColor,
            'icons' => [
                ['src' => $icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        return new DataDisplayResponse(
            (string)json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            Http::STATUS_OK,
            ['Content-Type' => 'application/manifest+json; charset=utf-8']
        );
    }
}
