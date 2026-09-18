<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

/**
 * Freigaben, die Nutzer fuer einzelne Ordner anlegen (ab 0.12).
 *
 * Eine Freigabe ist ein eigener oeffentlicher Link auf einen Ordner samt
 * Unterordnern, mit eigenen Einstellungen: Passwort (optional),
 * Ablaufdatum, Offline/Download, Aussehen und Beta-Hinweis.
 *
 * Der bisherige Administrator-Link (Einstellungen -> Verwaltung) bleibt
 * davon unberuehrt und laeuft weiter ueber AccessGuard.
 *
 * Darstellung einer Freigabe im Code: ein Array (siehe hydrate()).
 */
class ShareService {

    private const TABLE = 'audioarchive_shares';
    private const SESSION_KEY = 'audioarchive_share_tokens';

    /** Einstellungen einer Freigabe mit ihren Standardwerten. */
    public const SETTING_DEFAULTS = [
        'title' => '',
        'subtitle' => '',
        'design' => '',            // '' = Vorgabe des Administrators
        'themeAccent' => '',       // '' = Farben des Administrators
        'themeBar' => '',
        'themeBase' => '',
        'featureOffline' => true,
        'featureDownload' => false,
        'betaEnabled' => false,
        'betaText' => '',
        'betaLinkUrl' => '',
        'betaLinkLabel' => '',
    ];

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private ISession $session,
        private IHasher $hasher,
        private ISecureRandom $secureRandom,
    ) {
    }

    /** Duerfen angemeldete Nutzer Freigaben anlegen? (Vorgabe: ja) */
    public function sharingAllowed(): bool {
        return $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_USER_SHARES, true
        );
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    public function findByToken(string $token): ?array {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
        return $this->fetchOne($qb);
    }

    public function findById(int $id): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->fetchOne($qb);
    }

    /** @return list<array> */
    public function listByCreator(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('creator', $qb->createNamedParameter($uid)))
            ->orderBy('created', 'DESC');
        return $this->fetchAll($qb);
    }

    /** @return list<array> Alle Freigaben - nur fuer die Verwaltung */
    public function listAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)->orderBy('created', 'DESC');
        return $this->fetchAll($qb);
    }

    /**
     * Gueltige Freigabe zu einem Token: vorhanden, nicht abgelaufen, und
     * der Ordner existiert noch. Sonst null.
     */
    public function findActive(string $token): ?array {
        $share = $this->findByToken($token);
        if ($share === null || $this->isExpired($share) || $this->rootFolder($share) === null) {
            return null;
        }
        return $share;
    }

    public function isExpired(array $share): bool {
        return $share['expires'] !== null && $share['expires'] < time();
    }

    /** Der freigegebene Ordner, oder null wenn er nicht mehr existiert. */
    public function rootFolder(array $share): ?Folder {
        try {
            $userFolder = $this->rootFolder->getUserFolder($share['owner']);
            $nodes = $userFolder->getById($share['fileId']);
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($nodes as $node) {
            if ($node instanceof Folder) {
                return $node;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Zugang
    // ------------------------------------------------------------------

    /**
     * Darf der Aufrufer diese Freigabe nutzen?
     *   - ohne Passwort: jeder mit dem Link
     *   - der Ersteller selbst, wenn angemeldet
     *   - sonst nach richtiger Passworteingabe in dieser Sitzung
     */
    public function hasAccess(array $share): bool {
        if (!$share['hasPassword']) {
            return true;
        }
        $user = $this->userSession->getUser();
        if ($user !== null && $user->getUID() === $share['creator']) {
            return true;
        }
        $tokens = $this->session->get(self::SESSION_KEY);
        return is_array($tokens) && in_array($share['token'], $tokens, true);
    }

    public function tryLogin(array $share, string $password): bool {
        if (!$share['hasPassword']) {
            return true;
        }
        if (!$this->hasher->verify($password, (string)$share['passwordHash'])) {
            return false;
        }
        $tokens = $this->session->get(self::SESSION_KEY);
        $tokens = is_array($tokens) ? $tokens : [];
        if (!in_array($share['token'], $tokens, true)) {
            $tokens[] = $share['token'];
        }
        $this->session->set(self::SESSION_KEY, $tokens);
        return true;
    }

    public function logout(array $share): void {
        $tokens = $this->session->get(self::SESSION_KEY);
        if (!is_array($tokens)) {
            return;
        }
        $this->session->set(self::SESSION_KEY, array_values(array_filter(
            $tokens,
            static fn ($t) => $t !== $share['token']
        )));
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * Legt eine Freigabe an.
     *
     * @param string $owner  Ueber wessen Dateien der Ordner aufgeloest wird
     * @param array $settings siehe SETTING_DEFAULTS
     * @param string $password leer = ohne Passwort
     * @param int|null $expires Unix-Zeitstempel oder null
     */
    public function create(string $creator, string $owner, Folder $folder, string $source, string $path,
        array $settings, string $password, ?int $expires): array {
        $token = $this->secureRandom->generate(
            20,
            ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
        );

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'token' => $qb->createNamedParameter($token),
            'creator' => $qb->createNamedParameter($creator),
            'owner' => $qb->createNamedParameter($owner),
            'file_id' => $qb->createNamedParameter($folder->getId(), IQueryBuilder::PARAM_INT),
            'source' => $qb->createNamedParameter($source),
            'path' => $qb->createNamedParameter($path),
            'password_hash' => $qb->createNamedParameter($password === '' ? null : $this->hasher->hash($password)),
            'expires' => $qb->createNamedParameter($expires, $expires === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'settings' => $qb->createNamedParameter(json_encode($this->normalizeSettings($settings))),
            'created' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();

        return $this->findByToken($token) ?? throw new \RuntimeException('Freigabe nicht gespeichert');
    }

    /**
     * Aendert eine Freigabe.
     *
     * @param string|null $password null = unveraendert, '' = entfernen
     * @param int|null|false $expires false = unveraendert
     */
    public function update(array $share, array $settings, ?string $password, int|null|false $expires): array {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('settings', $qb->createNamedParameter(json_encode($this->normalizeSettings($settings))))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($share['id'], IQueryBuilder::PARAM_INT)));

        if ($password !== null) {
            $qb->set('password_hash', $qb->createNamedParameter($password === '' ? null : $this->hasher->hash($password)));
        }
        if ($expires !== false) {
            $qb->set('expires', $qb->createNamedParameter($expires, $expires === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT));
        }
        $qb->executeStatement();

        return $this->findById($share['id']) ?? $share;
    }

    public function delete(array $share): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($share['id'], IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Uebernimmt nur bekannte Schluessel mit passendem Typ. Farben muessen
     * gueltige Hex-Werte sein, Links http(s) - der Hinweis wird allen
     * Besuchern des Links angezeigt.
     */
    public function normalizeSettings(array $input): array {
        $out = self::SETTING_DEFAULTS;
        foreach (self::SETTING_DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_bool($default)) {
                $out[$key] = (bool)$value;
            } else {
                $out[$key] = trim((string)$value);
            }
        }

        if (!in_array($out['design'], ['', Application::DESIGN_CUSTOM, Application::DESIGN_NEXTCLOUD], true)) {
            $out['design'] = '';
        }
        foreach (['themeAccent', 'themeBar', 'themeBase'] as $color) {
            if ($out[$color] !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $out[$color])) {
                $out[$color] = '';
            }
            $out[$color] = strtolower($out[$color]);
        }
        if ($out['betaLinkUrl'] !== '' && !preg_match('#^https?://#i', $out['betaLinkUrl'])) {
            $out['betaLinkUrl'] = '';
        }
        $out['title'] = mb_substr($out['title'], 0, 200);
        $out['subtitle'] = mb_substr($out['subtitle'], 0, 500);
        $out['betaText'] = mb_substr($out['betaText'], 0, 500);
        $out['betaLinkLabel'] = mb_substr($out['betaLinkLabel'], 0, 100);

        return $out;
    }

    // ------------------------------------------------------------------

    private function fetchOne(IQueryBuilder $qb): ?array {
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<array> */
    private function fetchAll(IQueryBuilder $qb): array {
        $result = $qb->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            $rows[] = $this->hydrate($row);
        }
        $result->closeCursor();
        return $rows;
    }

    private function hydrate(array $row): array {
        $settings = json_decode((string)($row['settings'] ?? ''), true);
        $hash = (string)($row['password_hash'] ?? '');
        return [
            'id' => (int)$row['id'],
            'token' => (string)$row['token'],
            'creator' => (string)$row['creator'],
            'owner' => (string)$row['owner'],
            'fileId' => (int)$row['file_id'],
            'source' => (string)$row['source'],
            'path' => (string)($row['path'] ?? ''),
            'passwordHash' => $hash,
            'hasPassword' => $hash !== '',
            'expires' => $row['expires'] === null ? null : (int)$row['expires'],
            'settings' => $this->normalizeSettings(is_array($settings) ? $settings : []),
            'created' => (int)$row['created'],
        ];
    }
}
