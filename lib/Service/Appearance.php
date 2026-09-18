<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Defaults;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Entscheidet, wie eine Seite aussieht: Gestaltung und Hintergrundbild.
 *
 * Mehrere Ebenen, die spezifischere gewinnt:
 *   1. Administrator (Vorgabe fuer alle)
 *   2. Nutzer - fuer seine eigene Ansicht, sofern der Administrator das
 *      erlaubt
 *   3. Freigabe - fuer den geteilten Link (ab 0.12)
 *
 * Hintergrundbild:
 *   - Ein Bild auf Nutzer- oder Freigabe-Ebene gilt in JEDER Gestaltung -
 *     wer es ausdruecklich waehlt, will es auch sehen.
 *   - Das Administrator-Bild gilt bei eigener Gestaltung immer, bei
 *     Nextcloud-Gestaltung nur, wenn der Administrator das eingeschaltet hat.
 *     Sonst zeigt die Nextcloud-Gestaltung Nextclouds eigenen Hintergrund.
 */
class Appearance {

    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private BackgroundImage $backgroundImage,
        private Defaults $defaults,
    ) {
    }

    public function adminDesign(): string {
        $design = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_DESIGN, Application::DESIGN_CUSTOM
        );
        return $design === Application::DESIGN_NEXTCLOUD
            ? Application::DESIGN_NEXTCLOUD
            : Application::DESIGN_CUSTOM;
    }

    public function userCustomizationAllowed(): bool {
        return $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_USER_CUSTOMIZATION, true
        );
    }

    public function adminBackgroundInNextcloudDesign(): bool {
        return $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_BACKGROUND_NEXTCLOUD, false
        );
    }

    /** Persoenliche Wahl des Nutzers: '' (Vorgabe), 'custom' oder 'nextcloud'. */
    public function userDesignPreference(string $uid): string {
        $value = $this->config->getUserValue($uid, Application::APP_ID, Application::USER_DESIGN, '');
        return in_array($value, [Application::DESIGN_CUSTOM, Application::DESIGN_NEXTCLOUD], true)
            ? $value
            : '';
    }

    public function setUserDesignPreference(string $uid, string $design): void {
        if ($design === '') {
            $this->config->deleteUserValue($uid, Application::APP_ID, Application::USER_DESIGN);
            return;
        }
        $this->config->setUserValue($uid, Application::APP_ID, Application::USER_DESIGN, $design);
    }

    /**
     * Persoenliche Oberflaechen-Werte eines Nutzers (ab 0.13). Leere Werte
     * bedeuten "Vorgabe des Administrators". Ist die persoenliche
     * Einstellung abgeschaltet, gelten alle Werte als leer.
     *
     * @return array{design: string, title: string, subtitle: string, themeAccent: string, themeBar: string, themeBase: string}
     */
    public function userValues(?string $uid): array {
        $values = ['design' => '', 'title' => '', 'subtitle' => '', 'themeAccent' => '', 'themeBar' => '', 'themeBase' => ''];
        if ($uid === null || !$this->userCustomizationAllowed()) {
            return $values;
        }
        $values['design'] = $this->userDesignPreference($uid);
        $values['title'] = $this->config->getUserValue($uid, Application::APP_ID, Application::USER_TITLE, '');
        $values['subtitle'] = $this->config->getUserValue($uid, Application::APP_ID, Application::USER_SUBTITLE, '');
        foreach ([
            'themeAccent' => Application::USER_THEME_ACCENT,
            'themeBar' => Application::USER_THEME_BAR,
            'themeBase' => Application::USER_THEME_BASE,
        ] as $key => $configKey) {
            $values[$key] = ShareService::normalizeColor(
                $this->config->getUserValue($uid, Application::APP_ID, $configKey, '')
            );
        }
        return $values;
    }

    /** Speichert die persoenlichen Texte und Farben ('' = Vorgabe). */
    public function setUserValues(string $uid, array $values): void {
        $map = [
            'title' => Application::USER_TITLE,
            'subtitle' => Application::USER_SUBTITLE,
            'themeAccent' => Application::USER_THEME_ACCENT,
            'themeBar' => Application::USER_THEME_BAR,
            'themeBase' => Application::USER_THEME_BASE,
        ];
        foreach ($map as $key => $configKey) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = (string)$values[$key];
            if ($value === '') {
                $this->config->deleteUserValue($uid, Application::APP_ID, $configKey);
            } else {
                $this->config->setUserValue($uid, Application::APP_ID, $configKey, $value);
            }
        }
    }

    /** Wert des Administrators fuer Titel, Zusatzzeile und Farben. */
    public function adminValues(): array {
        $get = fn (string $key, string $default) => $this->appConfig->getValueString(Application::APP_ID, $key, $default);
        return [
            'design' => $this->adminDesign(),
            'title' => $get(Application::SETTING_HEADER_TITLE, 'Recordings'),
            'subtitle' => $get(Application::SETTING_HEADER_SUBTITLE, ''),
            'themeAccent' => $get(Application::SETTING_THEME_ACCENT, '#b9793f'),
            'themeBar' => $get(Application::SETTING_THEME_BAR, '#291c12'),
            'themeBase' => $get(Application::SETTING_THEME_BASE, '#a86a3d'),
        ];
    }

    /**
     * Vollstaendiges Aussehen der eigenen Ansicht eines Nutzers: seine
     * Werte, wo gesetzt, sonst die des Administrators. Ohne Nutzer (null)
     * nur die Werte des Administrators.
     *
     * @return array{design: string, title: string, subtitle: string, themeAccent: string, themeBar: string, themeBase: string, backgroundUrl: string}
     */
    public function effectiveLook(?string $uid): array {
        $look = $this->adminValues();
        foreach ($this->userValues($uid) as $key => $value) {
            if ($value !== '') {
                $look[$key] = $value;
            }
        }
        $look['backgroundUrl'] = $this->resolve($uid)['backgroundUrl'];
        return $look;
    }

    /**
     * Aussehen einer internen Freigabe fuer ihren Empfaenger (ab 0.13):
     * Was der Teilende festgelegt hat, sonst die eigene Ansicht des
     * Empfaengers - nicht die des Administrators. So wirkt eine Freigabe,
     * die nur die Farben aendert, beim Empfaenger nicht fremder als noetig.
     *
     * @return array{design: string, title: string, subtitle: string, themeAccent: string, themeBar: string, themeBase: string, backgroundUrl: string}
     */
    public function incomingLook(array $share, string $uid): array {
        $own = $this->effectiveLook($uid);
        $settings = $share['settings'];
        $look = $own;
        foreach (['design', 'title', 'subtitle', 'themeAccent', 'themeBar', 'themeBase'] as $key) {
            if (($settings[$key] ?? '') !== '') {
                $look[$key] = $settings[$key];
            }
        }

        $key = BackgroundImage::shareKey($share['id']);
        if ($this->backgroundImage->exists($key)) {
            $look['backgroundUrl'] = $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.asset.incomingBackground', ['id' => $share['id']]
            ) . '?v=' . $this->backgroundImage->version($key);
        } elseif ($look['design'] !== $own['design']) {
            // Andere Gestaltung als die eigene: das dazu passende Bild
            $look['backgroundUrl'] = $this->backgroundFor($uid, $look['design']);
        }
        return $look;
    }

    /** Hintergrundbild eines Nutzers fuer eine bestimmte Gestaltung. */
    private function backgroundFor(?string $uid, string $design): string {
        if ($uid !== null && $this->userCustomizationAllowed()
            && $this->backgroundImage->exists(BackgroundImage::userKey($uid))) {
            return $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.userBackground')
                . '?v=' . $this->backgroundImage->version(BackgroundImage::userKey($uid));
        }
        return $this->adminBackgroundUrl($design);
    }

    /**
     * Aussehen fuer einen angemeldeten Nutzer (uid) bzw. ohne Nutzer (null,
     * etwa der Administrator-Link).
     *
     * @return array{design: string, backgroundUrl: string}
     */
    public function resolve(?string $uid): array {
        $design = $this->adminDesign();
        $userImage = false;

        if ($uid !== null && $this->userCustomizationAllowed()) {
            $preference = $this->userDesignPreference($uid);
            if ($preference !== '') {
                $design = $preference;
            }
            $userImage = $this->backgroundImage->exists(BackgroundImage::userKey($uid));
        }

        if ($userImage) {
            $url = $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.userBackground')
                . '?v=' . $this->backgroundImage->version(BackgroundImage::userKey($uid));
        } else {
            $url = $this->adminBackgroundUrl($design);
        }

        return ['design' => $design, 'backgroundUrl' => $url];
    }

    /**
     * Aussehen einer Freigabe: ihre eigene Wahl, sonst die Vorgabe des
     * Administrators. Ein Bild der Freigabe gilt in beiden Gestaltungen.
     *
     * @return array{design: string, backgroundUrl: string}
     */
    public function resolveShare(array $share): array {
        $design = $share['settings']['design'] !== '' ? $share['settings']['design'] : $this->adminDesign();
        $key = BackgroundImage::shareKey($share['id']);

        if ($this->backgroundImage->exists($key)) {
            $url = $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.asset.shareBackground', ['token' => $share['token']]
            ) . '?v=' . $this->backgroundImage->version($key);
        } else {
            $url = $this->adminBackgroundUrl($design);
        }

        return ['design' => $design, 'backgroundUrl' => $url];
    }

    /** Adresse des Administrator-Bildes, sofern es in dieser Gestaltung gilt. */
    public function adminBackgroundUrl(string $design): string {
        if (!$this->backgroundImage->exists()) {
            return '';
        }
        if ($design === Application::DESIGN_NEXTCLOUD && !$this->adminBackgroundInNextcloudDesign()) {
            return '';
        }
        return $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.background')
            . '?v=' . $this->backgroundImage->version();
    }

    /**
     * Farbe fuer Statusleiste und Manifest: bei Nextcloud-Gestaltung
     * Nextclouds Hauptfarbe, sonst die eigene Leistenfarbe.
     */
    public function barColor(string $design, string $customBar = ''): string {
        if ($design === Application::DESIGN_NEXTCLOUD) {
            $primary = $this->defaults->getColorPrimary();
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary)) {
                return $primary;
            }
        }
        if ($customBar !== '') {
            return $customBar;
        }
        return $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_THEME_BAR, '#291c12'
        );
    }
}
