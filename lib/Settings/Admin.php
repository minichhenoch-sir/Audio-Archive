<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Settings;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class Admin implements ISettings {

    public function __construct(
        private IAppConfig $appConfig,
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm(): TemplateResponse {
        $token = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
        );

        $this->initialState->provideInitialState('settings', [
            'sourceFolder' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_FOLDER, ''
            ),
            'publicEnabled' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_PUBLIC_ENABLED, false
            ),
            'publicToken' => $token,
            'publicUrl' => $token === '' ? '' : $this->urlGenerator->linkToRouteAbsolute(
                Application::APP_ID . '.publicPlayer.index', ['token' => $token]
            ),
            'headerTitle' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_HEADER_TITLE, 'Recordings'
            ),
            'headerSubtitle' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_HEADER_SUBTITLE, ''
            ),
            'featureOffline' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, true
            ),
            'featureDownload' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, false
            ),
        ]);

        return new TemplateResponse(Application::APP_ID, 'settings-admin');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
