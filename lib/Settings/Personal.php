<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Settings;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\ShareService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Einstellungen -> Persoenlich -> Audio Archive (ab 0.26.0, Vikunja #8).
 *
 * Zeigt die eigenen Freigaben (Links und Freigaben an Personen/Gruppen)
 * mit Empfaengern, Ablauf und Weiterteilen. Bearbeiten und Anlegen fuehren
 * in die App, die das vollstaendige Formular samt Ordnerauswahl hat;
 * Loeschen und Link kopieren gehen direkt hier.
 */
class Personal implements ISettings {

    public function __construct(
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
        private ShareService $shares,
    ) {
    }

    public function getForm(): TemplateResponse {
        $this->initialState->provideInitialState('personal', [
            'sharingAllowed' => $this->shares->sharingAllowed(),
            'appUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'),
        ]);
        Util::addScript(Application::APP_ID, 'settings-personal');
        Util::addScript(Application::APP_ID, 'comments-overview'); // ab 0.35.0 (Vikunja #5)
        Util::addStyle(Application::APP_ID, 'settings');
        return new TemplateResponse(Application::APP_ID, 'settings-personal', []);
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
