<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Settings;

use OCA\AudioArchive\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\AppIcon;
use OCA\AudioArchive\Service\BackgroundImage;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {

    public function __construct(
        private IAppConfig $appConfig,
        private IInitialState $initialState,
        private IURLGenerator $urlGenerator,
        private BackgroundImage $backgroundImage,
        private IAppManager $appManager,
        private Appearance $appearance,
        private \OCA\AudioArchive\Service\Transcoder $transcoder,
        private \OCP\IGroupManager $groupManager,
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
            // Weitere Quellen und Gruppen, die teilen duerfen (ab 0.32.0, Vikunja #8)
            'extraSources' => array_map(
                static fn ($s) => $s + ['found' => \OCP\Server::get(\OCA\AudioArchive\Service\AudioFolder::class)->folderOf($s['owner'], $s['path']) !== null],
                \OCP\Server::get(\OCA\AudioArchive\Service\AudioFolder::class)->extraSources()
            ),
            'shareGroups' => \OCP\Server::get(\OCA\AudioArchive\Service\ShareService::class)->shareGroups(),
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
            // Wunschname, sofern der Token einer ist (ab 0.13)
            'publicSlug' => preg_match(\OCA\AudioArchive\Service\ShareService::SLUG_PATTERN, $token) ? $token : '',
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
            // Cover-Ersatz des Administrator-Links (ab 0.21.1)
            'publicCoverIcon' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_PUBLIC_COVER_ICON, ''
            ),
            'hasCoverImage' => $this->backgroundImage->exists(BackgroundImage::ADMIN_COVER),
            'coverImageVersion' => $this->backgroundImage->version(BackgroundImage::ADMIN_COVER),
            'coverIcons' => AppIcon::COVER_ICONS,
            'rememberDays' => $this->appConfig->getValueInt(
                Application::APP_ID, Application::SETTING_REMEMBER_DAYS, 0
            ),
            'sharedLabel' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_SHARED_LABEL, ''
            ),
            // Umwandlung in MP3 (ab 0.27.0): nur moeglich, wenn ffmpeg da ist
            'transcode' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_TRANSCODE, false
            ),
            'ffmpegPath' => $this->transcoder->ffmpegPath() ?? '',
            'featureFolderDownload' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_FOLDER_DOWNLOAD, false
            ),
            'featureFavorites' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_FAVORITES, true
            ),
            'sortDefault' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_SORT_DEFAULT, 'name'
            ),
            // Anzahl der Aufnahmen bei Ordnern (ab 0.30.0, Vikunja #42)
            'showFolderCount' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_SHOW_FOLDER_COUNT, false
            ),
            // Anzeige in der Liste und Namen (ab 0.33.0, Vikunja #50, #44, #3)
            'showFolderDate' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_SHOW_FOLDER_DATE, true
            ),
            'showTrackDuration' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_SHOW_TRACK_DURATION, true
            ),
            'showTrackDate' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_SHOW_TRACK_DATE, true
            ),
            'prettyFolderNames' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_PRETTY_FOLDER_NAMES, false
            ),
            'titleFromTags' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_TITLE_FROM_TAGS, false
            ),
            'starColor' => Application::starColor($this->appConfig),
            // Kommentare (ab 0.29.0, Vikunja #5)
            'featureComments' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_COMMENTS, false
            ),
            'featureRating' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_FEATURE_RATING, true
            ),
            'publicComments' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_PUBLIC_COMMENTS, false
            ),
            'commentNotifyGroup' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_COMMENT_NOTIFY_GROUP, ''
            ),
            'groups' => array_map(
                static fn ($g) => ['id' => $g->getGID(), 'name' => $g->getDisplayName()],
                $this->groupManager->search('', 200)
            ),
            'searchScope' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_SEARCH_SCOPE, 'folder'
            ),
            'repeatDefault' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_REPEAT_DEFAULT, 'next'
            ),
            'betaEnabled' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_BETA_ENABLED, false
            ),
            'noticeEnabled' => Application::noticeEnabled($this->appConfig),
            'betaText' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_TEXT, ''
            ),
            'betaLinkUrl' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_URL, ''
            ),
            'betaLinkLabel' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_BETA_LINK_LABEL, ''
            ),
            'design' => $this->appConfig->getValueString(
                Application::APP_ID, Application::SETTING_DESIGN, Application::DESIGN_CUSTOM
            ),
            // Ab 0.17: eigene Gestaltung des Administrators
            'adminStyle' => $this->appearance->adminStyle(),
            'adminStyleEnabled' => $this->appearance->adminStyleOffered(),
            'backgroundNextcloud' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_BACKGROUND_NEXTCLOUD, false
            ),
            'userShares' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_USER_SHARES, true
            ),
            'userCustomization' => $this->appConfig->getValueBool(
                Application::APP_ID, Application::SETTING_USER_CUSTOMIZATION, true
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
        // style-tokens zuerst: settings.js nutzt dessen Editor (window.AAStyle)
        Util::addScript(Application::APP_ID, 'style-tokens');
        Util::addScript(Application::APP_ID, 'settings');
        Util::addScript(Application::APP_ID, 'comments-overview'); // ab 0.35.0 (Vikunja #5)
        Util::addStyle(Application::APP_ID, 'settings');
        Util::addStyle(Application::APP_ID, 'style-editor');

        return new TemplateResponse(Application::APP_ID, 'settings-admin', [
            'version' => $this->appManager->getAppVersion(Application::APP_ID),
        ]);
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
