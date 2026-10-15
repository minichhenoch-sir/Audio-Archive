<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AppIcon;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\ManualContent;
use OCA\AudioArchive\Service\PlayerPage;
use OCP\AppFramework\Http\RedirectResponse;
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
        private ManualContent $content,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function user(string $back = '', string $fassung = '', string $drucken = ''): TemplateResponse {
        return $this->page('user', $back, $fassung === 'allgemein', $drucken === '1');
    }

    /** Nur Administratoren (kein NoAdminRequired). */
    #[NoCSRFRequired]
    public function admin(string $back = '', string $fassung = '', string $drucken = ''): TemplateResponse {
        return $this->page('admin', $back, $fassung === 'allgemein', $drucken === '1');
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function userPdf(): Response {
        return $this->pdf(self::PDF_USER, 'user');
    }

    #[NoCSRFRequired]
    public function adminPdf(): Response {
        return $this->pdf(self::PDF_ADMIN, 'admin');
    }

    /**
     * @param bool $generic "allgemein": ohne die Einstellungen dieser
     *        Nextcloud (fuer die mitgelieferten PDFs, docs/manual-pdf.py)
     * @param bool $printNow Druckfenster sofort oeffnen (Ersatz, wenn die
     *        mitgelieferte PDF nicht mehr zum Stand passt)
     */
    private function page(string $kind, string $back, bool $generic = false, bool $printNow = false): TemplateResponse {
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
            'pdf' => ManualContent::pdfLink($this->urlGenerator, $kind),
            'printNow' => $printNow,
            'nonce' => $printNow ? PlayerPage::cspNonce() : '',
            // Was bei dieser Nextcloud eingeschaltet ist (null = allgemeine Fassung)
            'features' => $generic ? null : $this->content->features(),
            // Einstellungen der Verwaltung, gelesen aus deren Vorlage (ab 1.0.3)
            'settingsRef' => $isAdmin ? ManualContent::settingsReference(dirname(__DIR__, 2)) : [],
            'styleRef' => ManualContent::styleEditorReference(dirname(__DIR__, 2)),
            'phpMin' => $this->phpMin(),
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

    private function phpMin(): string {
        try {
            $info = $this->appManager->getAppInfo(Application::APP_ID) ?? [];
            return (string)($info['dependencies']['php']['@attributes']['min-version'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
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

    /**
     * Mitgelieferte PDF - aber nur, wenn sie zum Stand der Seiten passt
     * (ManualContent::sourcesHash). Sonst die Seite mit Druckfenster, damit
     * nie eine veraltete Anleitung herauskommt.
     */
    private function pdf(string $file, string $kind): Response {
        if (!ManualContent::pdfsCurrent(dirname(__DIR__, 2))) {
            return new RedirectResponse($this->urlGenerator->linkToRoute(
                Application::APP_ID . '.manual.' . $kind, ['drucken' => '1']
            ));
        }
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
