<?php
declare(strict_types=1);

namespace OCA\AudioArchive\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'audioarchive';

    /** Schluessel der Einstellungen in der App-Konfiguration. */
    public const SETTING_FOLDER = 'source_folder';
    /** Besitzer des Quellordners - beim oeffentlichen Zugang gibt es keinen
     *  angemeldeten Nutzer, ueber den sich der Ordner sonst aufloesen liesse. */
    public const SETTING_FOLDER_OWNER = 'source_folder_owner';
    public const SETTING_PUBLIC_ENABLED = 'public_enabled';
    public const SETTING_PUBLIC_TOKEN = 'public_token';
    public const SETTING_PUBLIC_PASSWORD = 'public_password_hash';
    public const SETTING_HEADER_TITLE = 'header_title';
    public const SETTING_HEADER_SUBTITLE = 'header_subtitle';
    public const SETTING_THEME_ACCENT = 'theme_accent';
    public const SETTING_THEME_BAR = 'theme_bar';
    public const SETTING_THEME_BASE = 'theme_base';
    public const SETTING_FEATURE_OFFLINE = 'feature_offline';
    public const SETTING_FEATURE_DOWNLOAD = 'feature_download';

    // Beta-Hinweis: Kennzeichnung in der Kopfzeile plus ein frei
    // formulierbarer Streifen ueber dem Pfad.
    public const SETTING_BETA_ENABLED = 'beta_enabled';
    public const SETTING_BETA_TEXT = 'beta_text';
    public const SETTING_BETA_LINK_URL = 'beta_link_url';
    public const SETTING_BETA_LINK_LABEL = 'beta_link_label';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
    }

    public function boot(IBootContext $context): void {
    }
}
