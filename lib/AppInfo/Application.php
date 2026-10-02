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
    /** Anzahl der Aufnahmen neben Ordnern anzeigen (ab 0.30.0, Vikunja #42; Vorgabe: nein) */
    public const SETTING_SHOW_FOLDER_COUNT = 'show_folder_count';
    /** Suchbereich: 'folder' = geoeffneter Ordner samt Unterordnern, 'all' = ganze Quelle (ab 0.28.0, Vikunja #32) */
    public const SETTING_SEARCH_SCOPE = 'search_scope';
    /** Vorgabe fuer "Wiederholen": 'off', 'next', 'folder' oder 'one' (ab 0.28.0, Vikunja #2) */
    public const SETTING_REPEAT_DEFAULT = 'repeat_default';
    public const REPEAT_MODES = ['off', 'next', 'folder', 'one'];
    /** Persoenliche Vorgabe fuer "Wiederholen", leer = Vorgabe der Verwaltung (ab 0.28.0) */
    public const USER_REPEAT_DEFAULT = 'repeat_default';

    /*
     * Kommentare zu Aufnahmen (ab 0.29.0, Vikunja #5). Es sind echte
     * Nextcloud-Dateikommentare (auch in "Dateien" -> Seitenleiste ->
     * Kommentare). In der App sieht jeder nur seine eigenen.
     */
    public const SETTING_FEATURE_COMMENTS = 'feature_comments';
    /** Bewertung mit 1-5 Sternen zusaetzlich zum Text */
    public const SETTING_FEATURE_RATING = 'feature_rating';
    /** Kommentare auch ueber den oeffentlichen Link des Administrators */
    public const SETTING_PUBLIC_COMMENTS = 'public_comments';
    /** Nextcloud-Gruppe, die bei neuen Kommentaren benachrichtigt wird ('' = niemand) */
    public const SETTING_COMMENT_NOTIFY_GROUP = 'comment_notify_group';
    /** Persoenlich: Kommentare bzw. Bewertung in der eigenen Ansicht ('0' = aus) */
    public const USER_COMMENTS = 'comments';
    public const USER_RATING = 'rating';
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
    // Nicht abspielbare Formate beim Abspielen in MP3 umwandeln (ab 0.27.0, braucht ffmpeg)
    public const SETTING_TRANSCODE = 'transcode';
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
    /** Nextcloud-Gruppen, die teilen duerfen; leer = alle angemeldeten Nutzer (ab 0.32.0, Vikunja #8) */
    public const SETTING_SHARE_GROUPS = 'share_groups';
    /**
     * Weitere Quellordner neben dem gemeinsamen Ordner (ab 0.32.0, Vikunja #8):
     * JSON-Liste aus {id, name, owner, path}. In der App heisst die Quelle
     * 'src:<id>'; ihre Links sind gewoehnliche Freigaben.
     */
    public const SETTING_EXTRA_SOURCES = 'extra_sources';

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

    // "BETA"-Schild neben dem Titel. Bis 0.30 schaltete es auch den
    // Textstreifen; seit 0.31.0 (Vikunja #39) nur noch das Schild.
    public const SETTING_BETA_ENABLED = 'beta_enabled';
    /*
     * Text ueber den Aufnahmen (bis 0.30 "Beta-Hinweis"): Vorgabe des
     * Administrators. Die Schluessel heissen aus Vertraeglichkeit weiter
     * beta_*. Angemeldete Nutzer und Freigaben koennen eigenen Text setzen.
     */
    public const SETTING_BETA_TEXT = 'beta_text';
    public const SETTING_BETA_LINK_URL = 'beta_link_url';
    public const SETTING_BETA_LINK_LABEL = 'beta_link_label';
    /** Text des Administrators zeigen; ungesetzt = wie beta_enabled bis 0.30 (ab 0.31.0) */
    public const SETTING_NOTICE_ENABLED = 'notice_enabled';
    /** Persoenlich: eigener Text ueber den Aufnahmen, leer = Vorgabe (ab 0.31.0) */
    public const USER_NOTICE = 'notice';

    /** Zeigt der Administrator seinen Text? Ungesetzt: wie das BETA-Schild bis 0.30. */
    public static function noticeEnabled(\OCP\IAppConfig $appConfig): bool {
        return $appConfig->getValueBool(self::APP_ID, self::SETTING_NOTICE_ENABLED,
            $appConfig->getValueBool(self::APP_ID, self::SETTING_BETA_ENABLED, false));
    }

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Benachrichtigung bei neuen Kommentaren (ab 0.29.0)
        $context->registerNotifierService(\OCA\AudioArchive\Notification\Notifier::class);
    }

    public function boot(IBootContext $context): void {
    }
}
