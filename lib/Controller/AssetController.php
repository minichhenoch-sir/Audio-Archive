<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AppIcon;
use OCA\AudioArchive\Service\BackgroundImage;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use OCP\IURLGenerator;
use OCP\IUserSession;

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
        private BackgroundImage $backgroundImage,
        private Appearance $appearance,
        private IUserSession $userSession,
        private ShareService $shares,
        private AppIcon $appIcon,
        private IAppManager $appManager,
        private ILimiter $limiter,
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
    /**
     * Gibt das Hintergrundbild aus.
     *
     * Oeffentlich erreichbar, weil es auch auf dem Anmelde-Bildschirm der
     * Freigabe-Seite gezeigt wird - also bevor jemand angemeldet ist. Es
     * enthaelt keine schutzwuerdigen Angaben.
     */
    /**
     * App-Symbol in einer Farbe (ab 0.16). Oeffentlich, weil es auch auf
     * dem Startbildschirm, im Browser-Tab und auf dem Anmelde-Bildschirm
     * erscheint; es enthaelt nichts Schutzwuerdiges. Die Farbe steht in
     * der Adresse, deshalb darf der Browser das Bild ein Jahr behalten.
     * Das Erzeugen einer NEUEN Farbe kostet etwas Rechenzeit (~0,1 s).
     * Ohne Anmeldung ist das deshalb begrenzt - aber nur, wenn wirklich neu
     * gerechnet werden muss. Schon erzeugte Symbole kommen unbegrenzt aus
     * dem Zwischenspeicher (viele Geraete hinter einem Gemeinde-WLAN).
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function icon(string $color, string $name): Response {
        if (!$this->appIcon->isCached($color, $name) && $this->userSession->getUser() === null) {
            try {
                $this->limiter->registerAnonRequest(
                    'audioarchive-icon', 30, 60, $this->request->getRemoteAddress()
                );
            } catch (IRateLimitExceededException $e) {
                return new DataDisplayResponse('', Http::STATUS_TOO_MANY_REQUESTS);
            }
        }
        $png = $this->appIcon->render($color, $name);
        if ($png === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }
        $response = new DataDisplayResponse($png, Http::STATUS_OK, ['Content-Type' => 'image/png']);
        $response->cacheFor(365 * 24 * 3600, true, true);
        return $response;
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function background(): Response {
        return $this->imageResponse(BackgroundImage::ADMIN, true);
    }

    /**
     * Gibt ein gespeichertes Bild aus.
     *
     * @param bool $public darf von Zwischenspeichern (Proxy, CDN) gehalten
     *                     werden; persoenliche Bilder nur im Browser
     */
    private function imageResponse(string $key, bool $public): Response {
        $file = $this->backgroundImage->get($key);

        if ($file === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }

        $content = $file->getContent();

        /*
         * Typ am Inhalt bestimmen: Die Datei liegt ohne Endung im
         * AppData-Bereich, Nextcloud meldet dafuer nur
         * application/octet-stream. Beim Hochladen ist bereits geprueft,
         * dass es PNG, JPEG oder WebP ist.
         */
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->buffer($content);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $mime = 'application/octet-stream';
        }

        $response = new DataDisplayResponse($content, Http::STATUS_OK, ['Content-Type' => $mime]);

        // Einen Tag zwischenspeichern; bei Aenderung sorgt die
        // Versionskennung in der Adresse fuer ein Neuladen.
        $response->cacheFor(60 * 60 * 24, $public);
        return $response;
    }


    /**
     * Persoenliches Hintergrundbild des angemeldeten Nutzers. Nur fuer ihn
     * selbst abrufbar - andere Nutzer oder Gaeste erreichen es nicht.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function userBackground(): Response {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }
        return $this->imageResponse(BackgroundImage::userKey($user->getUID()), false);
    }

    /**
     * Hintergrundbild einer Freigabe. Oeffentlich, weil es schon auf dem
     * Anmelde-Bildschirm des Links erscheint - aber nur, solange die
     * Freigabe gueltig ist.
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function shareBackground(string $token): Response {
        $share = $this->shares->findActive($token);
        if ($share === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }
        return $this->imageResponse(BackgroundImage::shareKey($share['id']), false);
    }

    /**
     * Hintergrundbild einer internen Freigabe (ab 0.13). Nur fuer
     * angemeldete Empfaenger und den Ersteller - es gibt keinen Link.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function incomingBackground(int $id): Response {
        $uid = $this->userSession->getUser()?->getUID();
        $share = $uid !== null ? $this->shares->findIncoming($id, $uid) : null;
        if ($share === null) {
            return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
        }
        return $this->imageResponse(BackgroundImage::shareKey($share['id']), false);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function manifest(string $s = ''): DataDisplayResponse {
        $base = $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index');

        /*
         * Angemeldet startet die installierte App in der Fassung OHNE
         * Nextcloud-Leiste (/app). Die App-Wurzel zeigt seit 0.8 die
         * eingebettete Fassung mit Leiste.
         */
        $start = $s !== ''
            ? $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.publicPlayer.index', ['token' => $s])
            : $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.standalone');

        /*
         * Kennung der installierten App. Ohne ausdrueckliche Angabe nimmt
         * der Browser dafuer die Startadresse. Bis 0.7 war das die
         * App-Wurzel - mit der neuen Startadresse wuerden bereits
         * installierte Apps sonst als fremde App gelten. Deshalb wird die
         * alte Adresse hier als feste Kennung weitergefuehrt.
         */
        $id = $s !== ''
            ? $start
            : $this->urlGenerator->getAbsoluteURL($base);

        $title = $this->appConfig->getValueString(
            Application::APP_ID,
            Application::SETTING_HEADER_TITLE,
            'Recordings'
        );

        // Bei Nextcloud-Gestaltung Nextclouds Hauptfarbe, sonst die eigene
        // Leistenfarbe. Angemeldet zaehlt die persoenliche Wahl, bei einer
        // Freigabe deren Einstellung.
        $share = $s !== '' ? $this->shares->findActive($s) : null;
        if ($share !== null) {
            $folder = $this->shares->rootFolder($share);
            $title = $share['settings']['title'] !== ''
                ? $share['settings']['title']
                : ($folder !== null ? $folder->getName() : $title);
            $resolved = $this->appearance->resolveShare($share);
            $barColor = $this->appearance->barColor(
                $resolved['design'],
                $share['settings']['themeBar'],
                $resolved['style']
            );
        } else {
            // Angemeldet: persoenlicher Titel und persoenliche Leistenfarbe
            $uid = $s === '' ? $this->userSession->getUser()?->getUID() : null;
            $look = $this->appearance->effectiveLook($uid);
            $title = $look['title'] !== '' ? $look['title'] : $title;
            $barColor = $this->appearance->barColor($look['design'], $look['themeBar'], $look['style']);
        }

        /*
         * Bewusst absolute Adressen: Das Manifest wird auch von der
         * oeffentlichen Seite unter /s/<token> geladen. Eine relative
         * Angabe wuerde der Browser dann gegen diesen Pfad aufloesen und
         * die Icons nicht finden.
         */
        // Ab 0.16 in der Leistenfarbe dieser Ansicht (siehe AppIcon)
        $version = (string)$this->appManager->getAppVersion(Application::APP_ID);
        $icon = fn (string $variant) => $this->appIcon->url($barColor, $variant, true, $version);

        $manifest = [
            'id' => $id,
            'name' => $title,
            'short_name' => $title,
            'start_url' => $start,
            // Der Geltungsbereich umfasst beide Eingaenge der App und deckt
            // sich mit dem Bereich des Service Workers.
            'scope' => $this->urlGenerator->getAbsoluteURL($base),
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => $barColor,
            'theme_color' => $barColor,
            'icons' => [
                ['src' => $icon('any-192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('any-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        return new DataDisplayResponse(
            (string)json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            Http::STATUS_OK,
            ['Content-Type' => 'application/manifest+json; charset=utf-8']
        );
    }
}
