<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Settings;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Eigener Abschnitt unter Einstellungen -> Verwaltung.
 *
 * Ersetzt das bisherige selbstgebaute Admin-Portal samt Trigger-Wort und
 * zweitem Passwort: Wer Administrator ist, weiss Nextcloud selbst.
 */
class AdminSection implements IIconSection {

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('Audio Archive');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'app.png');
    }
}
