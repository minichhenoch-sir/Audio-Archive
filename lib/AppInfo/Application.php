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

    /**
     * Gestaltung: 'custom' = eigene Farben und eigenes Hintergrundbild,
     * 'nextcloud' = Nextclouds Farben, Hintergrund, Schrift und Hell/Dunkel.
     * Gilt fuer alle Zugaenge, auch den geteilten Link.
     */
    public const SETTING_DESIGN = 'design';
    public const DESIGN_CUSTOM = 'custom';
    public const DESIGN_NEXTCLOUD = 'nextcloud';

    /** Administrator-Bild auch bei Nextcloud-Gestaltung zeigen (Vorgabe: nein). */
    public const SETTING_BACKGROUND_NEXTCLOUD = 'background_nextcloud';

    /** Duerfen Nutzer in der App eigene Gestaltung und eigenes Bild waehlen? (Vorgabe: ja) */
    public const SETTING_USER_CUSTOMIZATION = 'user_customization';

    /** Duerfen angemeldete Nutzer eigene Freigaben anlegen? (Vorgabe: ja) */
    public const SETTING_USER_SHARES = 'user_shares';

    /** Schluessel der persoenlichen Einstellungen je Nutzer (IConfig-Nutzerwerte). */
    public const USER_DESIGN = 'design';
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
