<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Settings;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCA\AudioArchive\Service\BackgroundImage;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {

    public function __construct(
        private IAppConfig $appConfig,
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
        private BackgroundImage $backgroundImage,
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
            'sourceFolderOwner' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_FOLDER_OWNER, ''
            ),
            'hasPublicPassword' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_PUBLIC_PASSWORD, ''
            ) !== '',
            'themeAccent' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_THEME_ACCENT, '#b9793f'
            ),
            'themeBar' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_THEME_BAR, '#291c12'
            ),
            'themeBase' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_THEME_BASE, '#a86a3d'
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
            'hasBackground' => $this->backgroundImage->exists(),
            'betaEnabled' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_BETA_ENABLED, false
            ),
            'betaText' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_TEXT, ''
            ),
            'betaLinkUrl' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_URL, ''
            ),
            'betaLinkLabel' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_LABEL, ''
            ),
            'featureOffline' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_OFFLINE, true
            ),
            'featureDownload' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_DOWNLOAD, false
            ),
        ]);

        // Ueber addScript/addStyle eingebunden, nicht als eigenes <script>-Tag:
        // Auf dieser Seite laeuft Nextclouds normales Seitengeruest, und dabei
        // vergibt Nextcloud das CSP-Nonce von sich aus.
        Util::addScript(Application::APP_ID, 'settings');
        Util::addStyle(Application::APP_ID, 'settings');

        return new TemplateResponse(Application::APP_ID, 'settings-admin');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
