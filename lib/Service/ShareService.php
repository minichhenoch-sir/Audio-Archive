<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;

/**
 * Freigaben, die Nutzer fuer einzelne Ordner anlegen (ab 0.12).
 *
 * Zwei Arten (Spalte 'kind', ab 0.13):
 *
 *  - link:     ein eigener oeffentlicher Link /s/<token> auf einen Ordner
 *              samt Unterordnern, mit Passwort (optional), Ablaufdatum,
 *              Offline/Download und eigenem Aussehen. Der Token ist
 *              entweder zufaellig oder ein selbst gewaehlter Wunschname.
 *
 *  - internal: nur fuer ausgewaehlte Nextcloud-Nutzer und -Gruppen. Sie
 *              sehen den Ordner in der App unter "Mit mir geteilt" - nicht
 *              in Nextclouds Dateien-App. Kein Link, kein Passwort; der
 *              Token existiert nur, weil die Spalte ihn verlangt, und wird
 *              nie herausgegeben.
 *
 * Der Administrator-Link (Einstellungen -> Verwaltung) bleibt davon
 * unberuehrt und laeuft weiter ueber AccessGuard.
 *
 * Darstellung einer Freigabe im Code: ein Array (siehe hydrate()).
 */
class ShareService {

    private const TABLE = 'audioarchive_shares';
    private const MEMBERS = 'audioarchive_share_members';
    private const SESSION_KEY = 'audioarchive_share_tokens';

    /** Wunschname: Kleinbuchstaben, Ziffern, Bindestrich; 3 bis 64 Zeichen. */
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,62})[a-z0-9]$/';

    /**
     * Einstellungen einer Freigabe mit ihren Standardwerten.
     *
     * Der Beta-Hinweis gehoert seit 0.13 NICHT mehr dazu: Ihn schaltet
     * ausschliesslich der Administrator, und er gilt dann ueberall. Alte
     * Werte in bestehenden Freigaben werden beim Lesen verworfen.
     */
    public const SETTING_DEFAULTS = [
        'title' => '',
        'subtitle' => '',
        'design' => '',            // '' = Vorgabe (Administrator bzw. Empfaenger)
        'themeAccent' => '',       // '' = Farben der Vorgabe
        'themeBar' => '',
        'themeBase' => '',
        'style' => null,           // Werte fuer 'defined' (ab 0.17), sonst null
        'featureOffline' => true,
        'featureDownload' => false,
        // Unterordner als ZIP herunterladen (ab 0.25.0)
        'featureFolderDownload' => false,
        // Bild im Player fuer Aufnahmen ohne Cover (ab 0.20): '' = Vorgabe
        // (Archiv-Liste mit Lautsprecher), sonst ein Schluessel aus
        // AppIcon::COVER_ICONS oder 'custom' (eigenes hochgeladenes Bild)
        'coverIcon' => '',
    ];

    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private ISession $session,
        private IHasher $hasher,
        private ISecureRandom $secureRandom,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private RememberLogin $remember,
    ) {
    }

    /** Ablauf des zuletzt gemerkten Zugangs (Unix-Zeit), 0 = nicht gemerkt (ab 0.23.0). */
    private int $rememberedUntil = 0;

    public function rememberedUntil(): int {
        return $this->rememberedUntil;
    }

    /** Duerfen angemeldete Nutzer Freigaben anlegen? (Vorgabe: ja) */
    public function sharingAllowed(): bool {
        return $this->appConfig->getValueBool(
            Application::APP_ID, Application::SETTING_USER_SHARES, true
        );
    }

    // ------------------------------------------------------------------
    // Wunschnamen
    // ------------------------------------------------------------------

    /**
     * Macht aus einer Eingabe einen Wunschnamen: Kleinbuchstaben, Umlaute
     * ausgeschrieben, Leerzeichen und Sonderzeichen als Bindestrich.
     * "Gottesdienst Sonntag" -> "gottesdienst-sonntag".
     */
    public static function normalizeSlug(string $input): string {
        $s = mb_strtolower(trim($input), 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    /**
     * Prueft einen (bereits normalisierten) Wunschnamen.
     *
     * @param int|null $exceptId Freigabe, die den Namen behalten darf
     * @return string Fehlermeldung, leer wenn in Ordnung
     */
    public function validateSlug(string $slug, ?int $exceptId = null): string {
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            return 'Der Wunschname braucht 3 bis 64 Zeichen: Buchstaben a–z, Ziffern und Bindestriche.';
        }
        if (!$this->isTokenAvailable($slug, $exceptId)) {
            return 'Dieser Name ist bereits vergeben.';
        }
        return '';
    }

    /**
     * Ist ein Token noch frei? Geprueft gegen alle Freigaben (auch
     * abgelaufene - deren Link soll nicht unbemerkt auf fremde Inhalte
     * zeigen) und gegen den Administrator-Link. Gross-/Kleinschreibung
     * zaehlt dabei nicht, weil Wunschnamen auch so gefunden werden.
     */
    public function isTokenAvailable(string $token, ?int $exceptId = null): bool {
        $adminToken = $this->appConfig->getValueString(
            Application::APP_ID, Application::SETTING_PUBLIC_TOKEN, ''
        );
        if ($adminToken !== '' && strtolower($adminToken) === strtolower($token)) {
            return false;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::TABLE)
            ->where($qb->expr()->eq(
                $qb->func()->lower('token'),
                $qb->createNamedParameter(strtolower($token))
            ));
        $result = $qb->executeQuery();
        while (($row = $result->fetch()) !== false) {
            if ($exceptId === null || (int)$row['id'] !== $exceptId) {
                $result->closeCursor();
                return false;
            }
        }
        $result->closeCursor();
        return true;
    }

    private function randomToken(): string {
        return $this->secureRandom->generate(
            20,
            ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS
        );
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Freigabe zu einem Token. Erst exakt (zufaellige Tokens unterscheiden
     * Gross-/Kleinschreibung), dann als Wunschname in Kleinschreibung - wer
     * /s/Gottesdienst tippt, landet trotzdem bei /s/gottesdienst.
     */
    public function findByToken(string $token): ?array {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));
        $share = $this->fetchOne($qb);
        if ($share !== null) {
            return $share;
        }

        $lower = strtolower($token);
        if ($lower !== $token && preg_match(self::SLUG_PATTERN, $lower)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')->from(self::TABLE)
                ->where($qb->expr()->eq('token', $qb->createNamedParameter($lower)));
            return $this->fetchOne($qb);
        }
        return null;
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
     * Gueltiger LINK zu einem Token: vorhanden, nicht abgelaufen, und der
     * Ordner existiert noch. Sonst null.
     *
     * Interne Freigaben werden hier bewusst nie gefunden - sie duerfen
     * ueber keinen oeffentlichen Weg erreichbar sein, auch nicht, wenn
     * jemand ihren (nie herausgegebenen) Token errate.
     */
    public function findActive(string $token): ?array {
        $share = $this->findByToken($token);
        if ($share === null || $share['kind'] !== Application::SHARE_KIND_LINK
            || $this->isExpired($share) || $this->rootFolder($share) === null) {
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
    // Interne Freigaben: Empfaenger
    // ------------------------------------------------------------------

    /** @return list<array{type: string, id: string}> */
    public function getMembers(int $shareId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('member_type', 'member')->from(self::MEMBERS)
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->orderBy('member_type')->addOrderBy('member');
        $result = $qb->executeQuery();
        $members = [];
        while (($row = $result->fetch()) !== false) {
            $members[] = ['type' => (string)$row['member_type'], 'id' => (string)$row['member']];
        }
        $result->closeCursor();
        return $members;
    }

    /**
     * Prueft und bereinigt eine Empfaengerliste aus der Oberflaeche. Nur
     * existierende Nutzer und Gruppen, keine Doppelten, nicht man selbst.
     *
     * @param mixed $input Liste aus {type, id}
     * @return list<array{type: string, id: string}>
     */
    public function normalizeMembers(mixed $input, string $creator): array {
        if (!is_array($input)) {
            return [];
        }
        $out = [];
        foreach ($input as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string)($entry['type'] ?? '');
            $id = trim((string)($entry['id'] ?? ''));
            if ($id === '' || strlen($id) > 64) {
                continue;
            }
            if ($type === 'user') {
                if ($id === $creator || !$this->userManager->userExists($id)) {
                    continue;
                }
            } elseif ($type === 'group') {
                if (!$this->groupManager->groupExists($id)) {
                    continue;
                }
            } else {
                continue;
            }
            $out[$type . '|' . $id] = ['type' => $type, 'id' => $id];
        }
        return array_values($out);
    }

    /** Ersetzt die Empfaenger einer Freigabe. */
    public function setMembers(int $shareId, array $members): void {
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::MEMBERS)
                ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();

            foreach ($members as $member) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::MEMBERS)->values([
                    'share_id' => $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT),
                    'member_type' => $qb->createNamedParameter($member['type']),
                    'member' => $qb->createNamedParameter($member['id']),
                ]);
                $qb->executeStatement();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Ist dieser Nutzer Empfaenger der Freigabe - direkt oder ueber eine
     * seiner Gruppen?
     */
    public function isMember(array $share, string $uid): bool {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return false;
        }
        foreach ($this->getMembers($share['id']) as $member) {
            if ($member['type'] === 'user' && $member['id'] === $uid) {
                return true;
            }
            if ($member['type'] === 'group' && $this->groupManager->isInGroup($uid, $member['id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Interne Freigabe, die dieser Nutzer oeffnen darf: vorhanden, nicht
     * abgelaufen, Ordner existiert, und er ist Empfaenger (oder hat sie
     * selbst angelegt). Sonst null.
     */
    public function findIncoming(int $id, string $uid): ?array {
        $share = $this->findById($id);
        if ($share === null || $share['kind'] !== Application::SHARE_KIND_INTERNAL
            || $this->isExpired($share) || $this->rootFolder($share) === null) {
            return null;
        }
        if ($share['creator'] !== $uid && !$this->isMember($share, $uid)) {
            return null;
        }
        return $share;
    }

    /**
     * Alle gueltigen internen Freigaben, die mit diesem Nutzer geteilt sind
     * (ohne die eigenen).
     *
     * @return list<array>
     */
    public function listIncoming(string $uid): array {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return [];
        }
        $groups = $this->groupManager->getUserGroupIds($user);

        $qb = $this->db->getQueryBuilder();
        $conditions = [
            $qb->expr()->andX(
                $qb->expr()->eq('m.member_type', $qb->createNamedParameter('user')),
                $qb->expr()->eq('m.member', $qb->createNamedParameter($uid))
            ),
        ];
        if ($groups !== []) {
            $conditions[] = $qb->expr()->andX(
                $qb->expr()->eq('m.member_type', $qb->createNamedParameter('group')),
                $qb->expr()->in('m.member', $qb->createNamedParameter($groups, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }
        $who = $qb->expr()->orX(...$conditions);

        $qb->selectDistinct('m.share_id')->from(self::MEMBERS, 'm')->where($who);
        $result = $qb->executeQuery();
        $ids = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (int)$row['share_id'];
        }
        $result->closeCursor();

        $shares = [];
        foreach ($ids as $id) {
            $share = $this->findById($id);
            if ($share === null || $share['kind'] !== Application::SHARE_KIND_INTERNAL
                || $share['creator'] === $uid || $this->isExpired($share)
                || $this->rootFolder($share) === null) {
                continue;
            }
            $shares[] = $share;
        }
        usort($shares, static fn ($a, $b) => $b['created'] <=> $a['created']);
        return $shares;
    }

    // ------------------------------------------------------------------
    // Zugang (nur Links)
    // ------------------------------------------------------------------

    /**
     * Darf der Aufrufer diesen Link nutzen?
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
        if (is_array($tokens) && in_array($share['token'], $tokens, true)) {
            return true;
        }
        // Gemerkter Zugang aus einer frueheren Sitzung (ab 0.23.0)
        if ($this->remember->isRemembered('share', $share['token'], (string)$share['passwordHash'])) {
            $tokens = is_array($tokens) ? $tokens : [];
            $tokens[] = $share['token'];
            $this->session->set(self::SESSION_KEY, $tokens);
            return true;
        }
        return false;
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
        $this->rememberedUntil = $this->remember->remember('share', $share['token'], (string)$share['passwordHash']);
        return true;
    }

    public function logout(array $share): void {
        $this->remember->forget('share', $share['token']);
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
     * @param string $kind   Application::SHARE_KIND_LINK oder _INTERNAL
     * @param string $owner  Ueber wessen Dateien der Ordner aufgeloest wird
     * @param array $settings siehe SETTING_DEFAULTS
     * @param string $password leer = ohne Passwort (nur Links)
     * @param int|null $expires Unix-Zeitstempel oder null
     * @param string $slug   Wunschname (bereits geprueft), leer = zufaellig
     */
    public function create(string $kind, string $creator, string $owner, Folder $folder, string $source,
        string $path, array $settings, string $password, ?int $expires, string $slug = ''): array {
        $token = $slug !== '' ? $slug : $this->randomToken();
        $isLink = $kind === Application::SHARE_KIND_LINK;

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'token' => $qb->createNamedParameter($token),
            'kind' => $qb->createNamedParameter($isLink ? Application::SHARE_KIND_LINK : Application::SHARE_KIND_INTERNAL),
            'creator' => $qb->createNamedParameter($creator),
            'owner' => $qb->createNamedParameter($owner),
            'file_id' => $qb->createNamedParameter($folder->getId(), IQueryBuilder::PARAM_INT),
            'source' => $qb->createNamedParameter($source),
            'path' => $qb->createNamedParameter($path),
            'password_hash' => $qb->createNamedParameter(
                ($password === '' || !$isLink) ? null : $this->hasher->hash($password)
            ),
            'expires' => $qb->createNamedParameter($expires, $expires === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
            'settings' => $qb->createNamedParameter(json_encode($this->normalizeSettings($settings))),
            'created' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ]);
        $qb->executeStatement();

        return $this->findById($qb->getLastInsertId()) ?? throw new \RuntimeException('Freigabe nicht gespeichert');
    }

    /**
     * Aendert eine Freigabe.
     *
     * @param string|null $password null = unveraendert, '' = entfernen
     * @param int|null|false $expires false = unveraendert
     * @param string|null $token neuer Token (geprueft), null = unveraendert
     */
    public function update(array $share, array $settings, ?string $password, int|null|false $expires,
        ?string $token = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('settings', $qb->createNamedParameter(json_encode($this->normalizeSettings($settings))))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($share['id'], IQueryBuilder::PARAM_INT)));

        if ($password !== null && $share['kind'] === Application::SHARE_KIND_LINK) {
            $qb->set('password_hash', $qb->createNamedParameter($password === '' ? null : $this->hasher->hash($password)));
        }
        if ($expires !== false) {
            $qb->set('expires', $qb->createNamedParameter($expires, $expires === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT));
        }
        if ($token !== null && $token !== '' && $token !== $share['token']) {
            $qb->set('token', $qb->createNamedParameter($token));
        }
        $qb->executeStatement();

        return $this->findById($share['id']) ?? $share;
    }

    /** Neuer zufaelliger Token (Wunschname wieder entfernen). */
    public function newRandomToken(): string {
        do {
            $token = $this->randomToken();
        } while (!$this->isTokenAvailable($token));
        return $token;
    }

    public function delete(array $share): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::MEMBERS)
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($share['id'], IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($share['id'], IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Uebernimmt nur bekannte Schluessel mit passendem Typ. Farben muessen
     * gueltige Hex-Werte sein.
     */
    public function normalizeSettings(array $input): array {
        $out = self::SETTING_DEFAULTS;
        foreach (self::SETTING_DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ($key === 'style') {
                $out[$key] = is_array($value) ? StyleTokens::normalize($value) : null;
            } elseif (is_bool($default)) {
                $out[$key] = (bool)$value;
            } else {
                $out[$key] = trim((string)$value);
            }
        }

        if ($out['design'] !== '' && !in_array($out['design'], Application::DESIGNS, true)) {
            $out['design'] = '';
        }
        // Benutzerdefiniert ohne Werte: mit der Vorgabe beginnen
        if ($out['design'] === Application::DESIGN_DEFINED && $out['style'] === null) {
            $out['style'] = StyleTokens::normalize([]);
        }
        foreach (['themeAccent', 'themeBar', 'themeBase'] as $color) {
            $out[$color] = self::normalizeColor($out[$color]);
        }
        if ($out['coverIcon'] !== AppIcon::COVER_CUSTOM && !in_array($out['coverIcon'], AppIcon::COVER_ICONS, true)) {
            $out['coverIcon'] = '';
        }
        $out['title'] = mb_substr($out['title'], 0, 200);
        $out['subtitle'] = mb_substr($out['subtitle'], 0, 500);

        return $out;
    }

    /** Hex-Farbe in Kleinschreibung, oder '' wenn ungueltig/leer. */
    public static function normalizeColor(string $value): string {
        $value = trim($value);
        if ($value === '' || !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
            return '';
        }
        return strtolower($value);
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
        $kind = (string)($row['kind'] ?? Application::SHARE_KIND_LINK);
        return [
            'id' => (int)$row['id'],
            'token' => (string)$row['token'],
            'kind' => $kind === Application::SHARE_KIND_INTERNAL
                ? Application::SHARE_KIND_INTERNAL
                : Application::SHARE_KIND_LINK,
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
