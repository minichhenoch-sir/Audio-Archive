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
    /** Bild bei Aufnahmen ohne Cover fuer den Administrator-Link (ab 0.21.1) */
    public const SETTING_PUBLIC_COVER_ICON = 'public_cover_icon';
    /** Vorgabe fuer die Sortierung der Liste: 'name' oder 'newest' (ab 0.22.0) */
    public const SETTING_SORT_DEFAULT = 'sort_default';
    /** "Angemeldet bleiben" fuer Links mit Passwort, in Tagen; 0 = aus (ab 0.23.0) */
    public const SETTING_REMEMBER_DAYS = 'remember_days';
    /** Geheimer Schluessel fuer die Signatur der gemerkten Zugaenge (ab 0.23.0) */
    public const SETTING_REMEMBER_SECRET = 'remember_secret';
    /** Anzeigename des gemeinsamen Ordners; leer = Vorgabe (ab 0.23.0) */
    public const SETTING_SHARED_LABEL = 'shared_label';
    /** Favoriten anbieten (ab 0.24.0, Vorgabe: ja) */
    public const SETTING_FEATURE_FAVORITES = 'feature_favorites';
    /** Persoenlich: Favoriten ausblenden ('0'), Nutzer-Einstellung (ab 0.24.0) */
    public const USER_FAVORITES = 'favorites';
    /** Ordner als ZIP herunterladen erlauben (ab 0.25.0, Vorgabe: nein) */
    public const SETTING_FEATURE_FOLDER_DOWNLOAD = 'feature_folder_download';
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
    /**
     * Die vier Gestaltungen (ab 0.17). Die gespeicherten Werte der beiden
     * alten bleiben, damit bestehende Einstellungen gueltig sind:
     *   'nextcloud' = Klassisch (Nextclouds Farben, Hell/Dunkel automatisch)
     *   'custom'    = Modern (rund, Glas; Farben waehlbar, mit Vorlagen)
     *   'admin'     = vom Administrator bereitgestellt (nur wenn aktiviert)
     *   'defined'   = benutzerdefiniert (alles einstellbar, siehe StyleTokens)
     */
    public const DESIGN_CUSTOM = 'custom';
    public const DESIGN_NEXTCLOUD = 'nextcloud';
    public const DESIGN_ADMIN = 'admin';
    public const DESIGN_DEFINED = 'defined';
    public const DESIGNS = [self::DESIGN_NEXTCLOUD, self::DESIGN_CUSTOM, self::DESIGN_ADMIN, self::DESIGN_DEFINED];

    /** Gestaltung des Administrators (JSON, siehe StyleTokens) und ob sie angeboten wird. */
    public const SETTING_ADMIN_STYLE = 'admin_style';
    public const SETTING_ADMIN_STYLE_ENABLED = 'admin_style_enabled';

    /** Administrator-Bild auch bei Nextcloud-Gestaltung zeigen (Vorgabe: nein). */
    public const SETTING_BACKGROUND_NEXTCLOUD = 'background_nextcloud';

    /** Duerfen Nutzer in der App eigene Gestaltung und eigenes Bild waehlen? (Vorgabe: ja) */
    public const SETTING_USER_CUSTOMIZATION = 'user_customization';

    /** Duerfen angemeldete Nutzer eigene Freigaben anlegen? (Vorgabe: ja) */
    public const SETTING_USER_SHARES = 'user_shares';

    /** Schluessel der persoenlichen Einstellungen je Nutzer (IConfig-Nutzerwerte). */
    public const USER_DESIGN = 'design';
    /** Benutzerdefinierte Gestaltung (JSON, siehe StyleTokens), ab 0.17 */
    public const USER_STYLE = 'style';
    // Ab 0.13: alle Oberflaechen-Einstellungen auch persoenlich ('' = Vorgabe)
    public const USER_TITLE = 'header_title';
    public const USER_SUBTITLE = 'header_subtitle';
    public const USER_THEME_ACCENT = 'theme_accent';
    public const USER_THEME_BAR = 'theme_bar';
    public const USER_THEME_BASE = 'theme_base';
    /** JSON-Liste der Nutzer-Freigaben, bei denen der Empfaenger sein
     *  EIGENES Design statt dem der Freigabe sehen will. */
    public const USER_INCOMING_OWN_DESIGN = 'incoming_own_design';

    /**
     * Arten von Freigaben (Spalte 'kind', ab 0.13):
     *  - link:     oeffentlicher Link /s/<token>, auch ohne Nextcloud-Konto
     *  - internal: nur fuer ausgewaehlte Nextcloud-Nutzer und -Gruppen,
     *              sichtbar ausschliesslich innerhalb der App
     */
    public const SHARE_KIND_LINK = 'link';
    public const SHARE_KIND_INTERNAL = 'internal';
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
