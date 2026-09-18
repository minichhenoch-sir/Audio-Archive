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
