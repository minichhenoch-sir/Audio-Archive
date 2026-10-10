<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AppIcon;
use OCA\AudioArchive\Service\Appearance;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Anleitung in der App (ab 1.0.2).
 *
 * Zwei Anleitungen, jeweils als Seite und als PDF:
 *  - fuer Hoerer und Nutzer: oeffentlich, damit auch Hoerer ueber einen
 *    Link ohne Konto sie lesen koennen (enthaelt nichts Vertrauliches)
 *  - fuer Administratoren: nur fuer Administratoren
 *
 * Die Seiten kommen ohne Skripte aus (nur HTML und ein Stylesheet) - so
 * gibt es keine Reibung mit Nextclouds Sicherheitsrichtlinie, und der
 * Service Worker kann sie fuer offline speichern. Die PDFs liegen fertig
 * im Ordner manual/ und werden aus denselben Seiten erzeugt (siehe
 * docs/ENTWICKLUNG.md, Abschnitt 1.0.2).
 */
class ManualController extends Controller {

    public const PDF_USER = 'Audio-Archive-Anleitung.pdf';
    public const PDF_ADMIN = 'Audio-Archive-Anleitung-Administration.pdf';

    public function __construct(
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private Appearance $appearance,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function user(string $back = ''): TemplateResponse {
        return $this->page('user', $back);
    }

    /** Nur Administratoren (kein NoAdminRequired). */
    #[NoCSRFRequired]
    public function admin(string $back = ''): TemplateResponse {
        return $this->page('admin', $back);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function userPdf(): Response {
        return $this->pdf(self::PDF_USER);
    }

    #[NoCSRFRequired]
    public function adminPdf(): Response {
        return $this->pdf(self::PDF_ADMIN);
    }

    private function page(string $kind, string $back): TemplateResponse {
        $values = $this->appearance->adminValues();
        $accent = AppIcon::normalizeColor($values['themeAccent']) ?? 'b9793f';
        $bar = AppIcon::normalizeColor($values['themeBar']) ?? '291c12';
        $version = (string)$this->appManager->getAppVersion(Application::APP_ID);
        $isAdmin = $kind === 'admin';
        $loggedIn = $this->userSession->getUser() !== null;

        $route = static fn (string $name): string => Application::APP_ID . '.manual.' . $name;
        $backUrl = $this->safeBack($back);
        if ($backUrl === '' && $loggedIn) {
            $backUrl = $isAdmin
                ? $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
                : $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index');
        }

        $response = new TemplateResponse(Application::APP_ID, 'manual', [
            'kind' => $kind,
            'title' => $isAdmin ? 'Audio Archive – Anleitung für Administratoren' : 'Audio Archive – Anleitung',
            'version' => $version,
            'accent' => '#' . $accent,
            'bar' => '#' . $bar,
            'backUrl' => $backUrl,
            'backLabel' => $isAdmin && $back === '' ? 'Zu den Einstellungen' : 'Zurück zur App',
            'pdfUrl' => $this->urlGenerator->linkToRoute($route($isAdmin ? 'adminPdf' : 'userPdf')),
            // Querverweise zwischen den beiden Anleitungen
            'userUrl' => $this->urlGenerator->linkToRoute($route('user')),
            'adminUrl' => $this->urlGenerator->linkToRoute($route('admin')),
            'showAdminLink' => !$isAdmin && $loggedIn && $this->isAdmin(),
            'cssUrl' => $this->urlGenerator->linkTo(Application::APP_ID, 'css/manual.css') . '?v=' . rawurlencode($version . '-' . $this->cssVersion()),
            'imgBase' => $this->urlGenerator->linkTo(Application::APP_ID, 'img/manual/'),
            'faviconUrl' => $this->urlGenerator->linkTo(Application::APP_ID, 'img/app.svg'),
            ...$this->ncRange(),
        ], TemplateResponse::RENDER_AS_BLANK);
        return $response;
    }

    /**
     * Unterstuetzte Nextcloud-Versionen aus appinfo/info.xml - so bleibt die
     * Anleitung bei jeder neuen Version von selbst richtig.
     *
     * @return array{ncMin: string, ncMax: string}
     */
    private function ncRange(): array {
        $min = '';
        $max = '';
        try {
            $info = $this->appManager->getAppInfo(Application::APP_ID) ?? [];
            $attrs = $info['dependencies']['nextcloud']['@attributes'] ?? [];
            $min = (string)($attrs['min-version'] ?? '');
            $max = (string)($attrs['max-version'] ?? '');
        } catch (\Throwable $e) {
            // ohne Angabe bleibt der Satz allgemein
        }
        return ['ncMin' => $min, 'ncMax' => $max];
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && \OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($user->getUID());
    }

    /**
     * Ruecksprung nur innerhalb dieser App (Player, oeffentlicher Link,
     * installierte App). Alles andere wird verworfen - sonst liesse sich
     * ueber die Adresse eine fremde Seite als "Zurueck" unterschieben.
     */
    private function safeBack(string $back): string {
        if ($back === '' || strlen($back) > 500 || preg_match('/[\x00-\x1f\\\\]/', $back)) {
            return '';
        }
        $prefix = rtrim($this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'), '/') . '/';
        $path = (string)parse_url($back, PHP_URL_PATH);
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')
            || !(str_starts_with($path . '/', $prefix)) || str_contains($path, '..')) {
            return '';
        }
        return $back;
    }

    private function cssVersion(): string {
        $mtime = @filemtime(__DIR__ . '/../../css/manual.css');
        return $mtime !== false ? (string)$mtime : '';
    }

    private function pdf(string $file): Response {
        $path = __DIR__ . '/../../manual/' . $file;
        $data = @file_get_contents($path);
        if ($data === false) {
            return new Response(Http::STATUS_NOT_FOUND);
        }
        $response = new DataDownloadResponse($data, $file, 'application/pdf');
        $response->cacheFor(3600);
        return $response;
    }
}
