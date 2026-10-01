<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\ISecureRandom;

/**
 * "Angemeldet bleiben" fuer Links mit Passwort (ab 0.23.0, Vikunja #36).
 *
 * Nach richtiger Passworteingabe merkt sich der Browser den Zugang fuer die
 * vom Administrator eingestellte Zahl von Tagen (0 = aus, wie bisher nur fuer
 * die Sitzung). Gespeichert wird ein signierter Eintrag je Link im Cookie
 * "aa_remember": Art, Token, Ablauf und eine Pruefsumme. In die Pruefsumme
 * gehen ein geheimer Schluessel der App und ein Fingerabdruck des
 * Passwort-Hashes ein - aendert der Administrator bzw. Ersteller das
 * Passwort oder den Link, werden alle gemerkten Zugaenge sofort ungueltig.
 * Das Passwort selbst steht nirgends im Cookie.
 */
class RememberLogin {

    public const COOKIE = 'aa_remember';
    public const ALLOWED_DAYS = [0, 7, 15, 30, 90];
    private const MAX_ENTRIES = 10;

    public function __construct(
        private IAppConfig $appConfig,
        private IRequest $request,
        private ISecureRandom $random,
    ) {
    }

    /** Eingestellte Dauer in Tagen (0 = aus). */
    public function days(): int {
        $days = $this->appConfig->getValueInt(Application::APP_ID, Application::SETTING_REMEMBER_DAYS, 0);
        return in_array($days, self::ALLOWED_DAYS, true) ? $days : 0;
    }

    /**
     * Gilt ein gemerkter Zugang fuer diesen Link?
     *
     * @param string $kind 'admin' (Link der Verwaltung) oder 'share'
     */
    public function isRemembered(string $kind, string $token, string $passwordHash): bool {
        if ($this->days() === 0 || $token === '' || $passwordHash === '') {
            return false;
        }
        foreach ($this->entries() as $entry) {
            [$k, $t, $until, $sig] = $entry;
            if ($k !== $kind || !hash_equals($t, $token) || (int)$until < time()) {
                continue;
            }
            if (hash_equals($this->sign($k, $t, (int)$until, $passwordHash), $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Zugang merken. Liefert den Ablauf (Unix-Zeit) oder 0, wenn die
     * Funktion abgeschaltet ist.
     */
    public function remember(string $kind, string $token, string $passwordHash): int {
        $days = $this->days();
        if ($days === 0 || $token === '' || $passwordHash === '') {
            return 0;
        }
        $until = time() + $days * 86400;
        $entries = array_values(array_filter(
            $this->entries(),
            static fn ($e) => !($e[0] === $kind && $e[1] === $token) && (int)$e[2] >= time()
        ));
        $entries[] = [$kind, $token, (string)$until, $this->sign($kind, $token, $until, $passwordHash)];
        $this->write(array_slice($entries, -self::MAX_ENTRIES), $until);
        return $until;
    }

    /** Beim Abmelden: den Eintrag dieses Links entfernen. */
    public function forget(string $kind, string $token): void {
        $entries = $this->entries();
        if ($entries === []) {
            return;
        }
        $left = array_values(array_filter($entries, static fn ($e) => !($e[0] === $kind && $e[1] === $token)));
        $until = 0;
        foreach ($left as $e) {
            $until = max($until, (int)$e[2]);
        }
        $this->write($left, $until);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> */
    private function entries(): array {
        $raw = $this->request->getCookie(self::COOKIE);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 4000) {
            return [];
        }
        $out = [];
        foreach (explode('~', $raw) as $part) {
            $fields = explode('.', $part);
            if (count($fields) === 4 && in_array($fields[0], ['admin', 'share'], true)
                && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fields[1]) && ctype_digit($fields[2])
                && preg_match('/^[a-f0-9]{64}$/', $fields[3])) {
                $out[] = $fields;
            }
        }
        return $out;
    }

    private function write(array $entries, int $until): void {
        $value = implode('~', array_map(static fn ($e) => implode('.', $e), $entries));
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $value === '' ? time() - 3600 : $until,
            'path' => '/',
            'secure' => $this->request->getServerProtocol() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function sign(string $kind, string $token, int $until, string $passwordHash): string {
        $data = $kind . '|' . $token . '|' . $until . '|' . hash('sha256', $passwordHash);
        return hash_hmac('sha256', $data, $this->secret());
    }

    /** Geheimer Schluessel der App, beim ersten Gebrauch erzeugt. */
    private function secret(): string {
        $secret = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_REMEMBER_SECRET, '');
        if ($secret === '') {
            $secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
            $this->appConfig->setValueString(Application::APP_ID, Application::SETTING_REMEMBER_SECRET, $secret, false, true);
        }
        return $secret;
    }
}
