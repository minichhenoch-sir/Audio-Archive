<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\IHasher;

/**
 * Entscheidet, wer die Aufnahmen abrufen darf.
 *
 * Zwei Wege fuehren hinein:
 *   1. ein angemeldeter Nextcloud-Nutzer, oder
 *   2. die oeffentliche Seite, nachdem dort das gemeinsame Passwort
 *      eingegeben wurde.
 *
 * Der zweite Weg merkt sich den Erfolg in der Sitzung. Bewusst wird dort der
 * TOKEN abgelegt und nicht nur ein Ja/Nein: Wird der Token spaeter gewechselt
 * oder der oeffentliche Zugang abgeschaltet, verlieren alte Sitzungen damit
 * sofort ihre Gueltigkeit.
 */
class AccessGuard {

    private const SESSION_KEY = 'audioarchive_public_token';

    public function __construct(
        private IAppConfig $appConfig,
        private ISession $session,
        private IUserSession $userSession,
        private IHasher $hasher,
    ) {
    }

    public function isPublicEnabled(): bool {
        return $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_PUBLIC_ENABLED, false
        );
    }

    public function publicToken(): string {
        return $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
        );
    }

    /** Ist fuer die oeffentliche Seite ueberhaupt ein Passwort hinterlegt? */
    public function hasPublicPassword(): bool {
        return $this->publicPasswordHash() !== '';
    }

    private function publicPasswordHash(): string {
        return $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_PUBLIC_PASSWORD, ''
        );
    }

    /** Prueft das eingegebene Passwort und merkt den Erfolg in der Sitzung. */
    public function tryPublicLogin(string $token, string $password): bool {
        if (!$this->isPublicEnabled()) {
            return false;
        }

        $expected = $this->publicToken();
        if ($expected === '' || !hash_equals($expected, $token)) {
            return false;
        }

        $hash = $this->publicPasswordHash();

        /*
         * Ohne hinterlegtes Passwort wird der Zugang NICHT freigegeben.
         * Andernfalls waere ein oeffentlicher Zugang ohne gesetztes Passwort
         * fuer jeden offen, der den Link kennt.
         */
        if ($hash === '') {
            return false;
        }

        if (!$this->hasher->verify($password, $hash)) {
            return false;
        }

        $this->session->set(self::SESSION_KEY, $token);
        return true;
    }

    public function publicLogout(): void {
        $this->session->remove(self::SESSION_KEY);
    }

    /** Darf der aktuelle Aufrufer die Aufnahmen sehen bzw. hoeren? */
    public function hasAccess(): bool {
        if ($this->userSession->isLoggedIn()) {
            return true;
        }

        if (!$this->isPublicEnabled()) {
            return false;
        }

        $token = $this->publicToken();
        if ($token === '') {
            return false;
        }

        $fromSession = (string)$this->session->get(self::SESSION_KEY);
        return $fromSession !== '' && hash_equals($token, $fromSession);
    }

    /**
     * Darf der Aufrufer diese Quelle lesen? Die eigenen Dateien ('home')
     * nur angemeldet, den gemeinsamen Ordner wie bisher.
     */
    public function canUseSource(string $source): bool {
        if ($source === AudioFolder::SOURCE_HOME) {
            return $this->userSession->isLoggedIn();
        }
        return $this->hasAccess();
    }

    /** Nur fuer die Anzeige: Ist der Aufrufer ein angemeldeter Nutzer? */
    public function isLoggedInUser(): bool {
        return $this->userSession->isLoggedIn();
    }
}
