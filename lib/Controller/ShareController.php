<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\Appearance;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\BackgroundImage;
use OCA\AudioArchive\Service\ShareService;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Share\IShare;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Verwaltung der Freigaben durch die Nutzer selbst (ab 0.12).
 *
 * Zwei Arten (ab 0.13): oeffentliche Links (optional mit Wunschnamen) und
 * interne Freigaben an Nextcloud-Nutzer und -Gruppen, die nur innerhalb
 * der App unter "Mit mir geteilt" erscheinen.
 *
 * Jeder angemeldete Nutzer darf Ordner teilen, auf die er in der App
 * Zugriff hat: aus den eigenen Dateien und aus dem gemeinsamen Ordner.
 * Mit ihm geteilte Ordner darf er NICHT weiterteilen - das bleibt dem
 * vorbehalten, der sie freigegeben hat.
 * Bearbeiten und Loeschen darf nur, wer die Freigabe angelegt hat - und
 * Administratoren (Uebersicht in den Einstellungen).
 *
 * Schreibende Methoden verlangen Nextclouds Anfrage-Token (kein
 * NoCSRFRequired).
 */
class ShareController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private ShareService $shares,
        private AudioFolder $audioFolder,
        private BackgroundImage $backgroundImage,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IConfig $config,
        private Appearance $appearance,
        private ISearch $collaboratorSearch,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Eigene Freigaben - alle, oder nur die fuer einen bestimmten Ordner.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $source = '', string $path = ''): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $filterId = null;
        if ($source !== '') {
            $folder = $this->folderFor($source, $path);
            if ($folder === null) {
                return new DataResponse(['shares' => [], 'allowed' => $this->shares->sharingAllowed()]);
            }
            $filterId = $folder->getId();
        }

        $list = [];
        foreach ($this->shares->listByCreator($uid) as $share) {
            if ($filterId !== null && $share['fileId'] !== $filterId) {
                continue;
            }
            $list[] = $this->present($share);
        }

        return new DataResponse(['shares' => $list, 'allowed' => $this->shares->sharingAllowed()]);
    }

    /** Alle Freigaben - nur fuer Administratoren (ohne NoAdminRequired). */
    #[NoCSRFRequired]
    public function adminIndex(): DataResponse {
        return new DataResponse([
            'shares' => array_map(fn ($s) => $this->present($s), $this->shares->listAll()),
        ]);
    }

    /**
     * @param string $kind     'link' (Vorgabe) oder 'internal'
     * @param array $settings  siehe ShareService::SETTING_DEFAULTS
     * @param string $password leer = ohne Passwort (nur Links)
     * @param string $expires  Datum JJJJ-MM-TT oder leer
     * @param string $slug     Wunschname fuer den Link, leer = zufaellig
     * @param array $members   interne Freigabe: Liste aus {type, id}
     */
    #[NoAdminRequired]
    public function create(string $source = '', string $path = '', array $settings = [],
        string $password = '', string $expires = '', string $kind = Application::SHARE_KIND_LINK,
        string $slug = '', array $members = []): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->shares->sharingAllowed()) {
            return new DataResponse(['error' => 'Freigaben sind vom Administrator abgeschaltet.'], Http::STATUS_FORBIDDEN);
        }

        $kind = $kind === Application::SHARE_KIND_INTERNAL ? Application::SHARE_KIND_INTERNAL : Application::SHARE_KIND_LINK;
        $path = trim($path, '/');

        // Weiterteilen eines mit mir geteilten Ordners (ab 0.26.0): nur, wenn
        // die Freigabe es erlaubt. Die neue Freigabe zeigt auf denselben
        // Ordner beim Besitzer und merkt sich ihren Ursprung.
        $parent = null;
        $incomingId = \OCA\AudioArchive\Service\ContentScope::incomingId($source);
        if ($incomingId !== null) {
            $parent = $this->shares->findResharable($incomingId, $uid);
            if ($parent === null) {
                return new DataResponse(
                    ['error' => 'Diesen Ordner darfst du nicht weiterteilen – das hat die Person, die ihn mit dir geteilt hat, nicht erlaubt.'],
                    Http::STATUS_FORBIDDEN
                );
            }
            $folder = $this->folderFor($source, $path);
            $source = $parent['source'];
            $path = trim($parent['path'] . '/' . $path, '/');
            $owner = $parent['owner'];
            $settings['viaShare'] = $parent['id'];
        } else {
            $source = $source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED;
            if ($source === AudioFolder::SOURCE_HOME && $path === '') {
                return new DataResponse(
                    ['error' => 'Die gesamten eigenen Dateien lassen sich nicht teilen – bitte einen Ordner wählen.'],
                    Http::STATUS_BAD_REQUEST
                );
            }
            $folder = $this->folderFor($source, $path);
            $owner = $source === AudioFolder::SOURCE_HOME ? $uid : $this->audioFolder->sharedOwner();
            $settings['viaShare'] = 0;
        }
        if ($folder === null) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }
        if ($kind === Application::SHARE_KIND_LINK) {
            $settings['allowReshare'] = false;
        }

        $expiresAt = $this->parseExpires($expires);
        if ($expiresAt === false) {
            return new DataResponse(['error' => 'Das Ablaufdatum muss in der Zukunft liegen.'], Http::STATUS_BAD_REQUEST);
        }

        $token = '';
        $memberList = [];
        if ($kind === Application::SHARE_KIND_LINK) {
            $token = ShareService::normalizeSlug($slug);
            if ($token !== '') {
                $error = $this->shares->validateSlug($token);
                if ($error !== '') {
                    return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
                }
            }
        } else {
            $memberList = $this->shares->normalizeMembers($members, $uid);
            if ($memberList === []) {
                return new DataResponse(['error' => 'Bitte mindestens eine Person oder Gruppe auswählen.'], Http::STATUS_BAD_REQUEST);
            }
        }

        $share = $this->shares->create($kind, $uid, $owner, $folder, $source, $path, $settings,
            $kind === Application::SHARE_KIND_LINK ? $password : '', $expiresAt, $token);

        if ($kind === Application::SHARE_KIND_INTERNAL) {
            $this->shares->setMembers($share['id'], $memberList);
        }

        return new DataResponse(['share' => $this->present($share)]);
    }

    /**
     * @param string|null $password null = unveraendert, sonst neues Passwort
     * @param bool $removePassword Passwort entfernen
     * @param string|null $slug null = unveraendert, '' = wieder zufaelliger
     *                          Token, sonst neuer Wunschname
     * @param array|null $members interne Freigabe: neue Empfaengerliste
     */
    #[NoAdminRequired]
    public function update(int $id, array $settings = [], ?string $password = null,
        bool $removePassword = false, string $expires = '', ?string $slug = null,
        ?array $members = null): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }

        $expiresAt = $this->parseExpires($expires);
        if ($expiresAt === false) {
            return new DataResponse(['error' => 'Das Ablaufdatum muss in der Zukunft liegen.'], Http::STATUS_BAD_REQUEST);
        }

        $newToken = null;
        if ($share['kind'] === Application::SHARE_KIND_LINK && $slug !== null) {
            $normalized = ShareService::normalizeSlug($slug);
            if ($normalized === '') {
                // Wunschname entfernen: nur dann einen neuen Zufallstoken,
                // wenn bisher einer gesetzt war
                if (preg_match(ShareService::SLUG_PATTERN, $share['token'])) {
                    $newToken = $this->shares->newRandomToken();
                }
            } elseif ($normalized !== $share['token']) {
                $error = $this->shares->validateSlug($normalized, $share['id']);
                if ($error !== '') {
                    return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
                }
                $newToken = $normalized;
            }
        }

        if ($share['kind'] === Application::SHARE_KIND_INTERNAL && $members !== null) {
            $memberList = $this->shares->normalizeMembers($members, $share['creator']);
            if ($memberList === []) {
                return new DataResponse(['error' => 'Bitte mindestens eine Person oder Gruppe auswählen.'], Http::STATUS_BAD_REQUEST);
            }
            $this->shares->setMembers($share['id'], $memberList);
        }

        // Herkunft bleibt, wie sie ist; Links koennen nie weitergeteilt werden
        $settings['viaShare'] = $share['settings']['viaShare'];
        if ($share['kind'] === Application::SHARE_KIND_LINK) {
            $settings['allowReshare'] = false;
        }

        $newPassword = $removePassword ? '' : (($password === null || $password === '') ? null : $password);
        $share = $this->shares->update($share, $settings, $newPassword, $expiresAt, $newToken);

        return new DataResponse(['share' => $this->present($share)]);
    }

    #[NoAdminRequired]
    public function delete(int $id): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }
        $this->backgroundImage->remove(BackgroundImage::shareKey($share['id']));
        $this->backgroundImage->remove(BackgroundImage::shareCoverKey($share['id']));
        $this->shares->delete($share);
        return new DataResponse(['deleted' => true]);
    }

    #[NoAdminRequired]
    public function uploadBackground(int $id): DataResponse {
        return $this->storeImage($id, BackgroundImage::shareKey(...));
    }

    /**
     * Eigenes Bild fuer Aufnahmen ohne Cover (ab 0.20). Gewaehlt wird es
     * ueber die Einstellung coverIcon = 'custom'; das Hochladen allein
     * aendert die Auswahl nicht.
     */
    #[NoAdminRequired]
    public function uploadCover(int $id): DataResponse {
        return $this->storeImage($id, BackgroundImage::shareCoverKey(...));
    }

    #[NoAdminRequired]
    public function removeCover(int $id): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }
        $this->backgroundImage->remove(BackgroundImage::shareCoverKey($share['id']));
        return new DataResponse(['share' => $this->present($share)]);
    }

    /** Hochgeladenes Bild einer Freigabe ablegen (Hintergrund oder Cover-Ersatz). */
    private function storeImage(int $id, \Closure $keyFor): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }

        $file = $this->request->getUploadedFile('file');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'Es wurde keine Datei empfangen.'], Http::STATUS_BAD_REQUEST);
        }

        $error = $this->backgroundImage->store(
            (string)$file['tmp_name'],
            (int)$file['size'],
            $keyFor($share['id'])
        );
        if ($error !== '') {
            return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse(['share' => $this->present($share)]);
    }

    #[NoAdminRequired]
    public function removeBackground(int $id): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }
        $this->backgroundImage->remove(BackgroundImage::shareKey($share['id']));
        return new DataResponse(['share' => $this->present($share)]);
    }

    // ------------------------------------------------------------------
    // Empfaengerseite: "Mit mir geteilt"
    // ------------------------------------------------------------------

    /**
     * Interne Freigaben, die mit dem angemeldeten Nutzer geteilt sind -
     * jeweils mit dem Aussehen, das der Teilende festgelegt hat, und der
     * Wahl des Empfaengers, ob er es sehen will.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function incoming(): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $own = $this->ownDesignIds($uid);
        $list = [];
        foreach ($this->shares->listIncoming($uid) as $share) {
            $folder = $this->shares->rootFolder($share);
            $settings = $share['settings'];
            $hasBackground = $this->backgroundImage->exists(BackgroundImage::shareKey($share['id']));
            $list[] = [
                'id' => $share['id'],
                'source' => 'in:' . $share['id'],
                'label' => $settings['title'] !== '' ? $settings['title'] : ($folder !== null ? $folder->getName() : 'Freigabe'),
                'folderName' => $folder !== null ? $folder->getName() : '',
                'creator' => $share['creator'],
                'creatorName' => $this->userManager->getDisplayName($share['creator']) ?? $share['creator'],
                'expires' => $share['expires'] === null ? '' : date('Y-m-d', $share['expires']),
                // Hat der Teilende ueberhaupt ein eigenes Aussehen gewaehlt?
                'hasLook' => $settings['design'] !== '' || $settings['themeAccent'] !== ''
                    || $settings['themeBar'] !== '' || $settings['themeBase'] !== ''
                    || $settings['title'] !== '' || $settings['subtitle'] !== '' || $hasBackground
                    || $settings['coverIcon'] !== '',
                'useShareDesign' => !in_array($share['id'], $own, true),
                'look' => $this->appearance->incomingLook($share, $uid),
                // Darf der Empfaenger weiterteilen? (ab 0.26.0)
                'allowReshare' => $settings['allowReshare'] && $this->shares->sharingAllowed(),
            ];
        }

        return new DataResponse(['shares' => $list]);
    }

    /**
     * Empfaenger waehlt: Design der Freigabe verwenden oder das eigene.
     */
    #[NoAdminRequired]
    public function setIncomingDesign(int $id, bool $useShareDesign = true): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($this->shares->findIncoming($id, $uid) === null) {
            return new DataResponse(['error' => 'Freigabe nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $own = array_values(array_filter($this->ownDesignIds($uid), static fn ($x) => $x !== $id));
        if (!$useShareDesign) {
            $own[] = $id;
        }
        if ($own === []) {
            $this->config->deleteUserValue($uid, Application::APP_ID, Application::USER_INCOMING_OWN_DESIGN);
        } else {
            $this->config->setUserValue($uid, Application::APP_ID, Application::USER_INCOMING_OWN_DESIGN, (string)json_encode($own));
        }
        return new DataResponse(['useShareDesign' => $useShareDesign]);
    }

    /**
     * Personen und Gruppen fuer eine interne Freigabe suchen.
     *
     * Ueber Nextclouds eigene Suche fuer Freigaben: Sie beachtet die
     * Einstellungen der Verwaltung, etwa "nur mit Mitgliedern der eigenen
     * Gruppen teilen" oder eine abgeschaltete Namensvervollstaendigung.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function searchMembers(string $search = ''): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $search = trim($search);
        if ($search === '') {
            return new DataResponse(['results' => []]);
        }

        try {
            [$result] = $this->collaboratorSearch->search(
                $search, [IShare::TYPE_USER, IShare::TYPE_GROUP], false, 15, 0
            );
        } catch (\Throwable $e) {
            return new DataResponse(['results' => []]);
        }

        $out = [];
        $add = function (array $entries, string $type) use (&$out, $uid): void {
            foreach ($entries as $entry) {
                $id = (string)($entry['value']['shareWith'] ?? '');
                if ($id === '' || ($type === 'user' && $id === $uid)) {
                    continue;
                }
                $out[$type . '|' . $id] = [
                    'type' => $type,
                    'id' => $id,
                    'label' => (string)($entry['label'] ?? $id),
                ];
            }
        };
        $add($result['exact']['users'] ?? [], 'user');
        $add($result['exact']['groups'] ?? [], 'group');
        $add($result['users'] ?? [], 'user');
        $add($result['groups'] ?? [], 'group');

        return new DataResponse(['results' => array_values($out)]);
    }

    /** @return list<int> Freigaben, bei denen der Nutzer sein eigenes Design will */
    private function ownDesignIds(string $uid): array {
        $raw = json_decode($this->config->getUserValue($uid, Application::APP_ID, Application::USER_INCOMING_OWN_DESIGN, '[]'), true);
        return is_array($raw) ? array_values(array_map('intval', $raw)) : [];
    }

    // ------------------------------------------------------------------

    private function uid(): ?string {
        return $this->userSession->getUser()?->getUID();
    }

    /**
     * Freigabe laden und pruefen, ob der Aufrufer sie aendern darf.
     *
     * @return array|DataResponse
     */
    private function editable(int $id): array|DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $share = $this->shares->findById($id);
        if ($share === null) {
            return new DataResponse(['error' => 'Freigabe nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }
        if ($share['creator'] !== $uid && !$this->groupManager->isAdmin($uid)) {
            // Fremde Freigaben: so tun, als gaebe es sie nicht
            return new DataResponse(['error' => 'Freigabe nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }
        return $share;
    }

    /** Ordner einer Quelle, den der Aufrufer in der App sehen darf. */
    private function folderFor(string $source, string $path): ?Folder {
        $incomingId = \OCA\AudioArchive\Service\ContentScope::incomingId($source);
        if ($incomingId !== null) {
            $uid = $this->uid();
            $share = $uid !== null ? $this->shares->findIncoming($incomingId, $uid) : null;
            $root = $share !== null ? $this->shares->rootFolder($share) : null;
            if ($root === null) {
                return null;
            }
            $node = $this->audioFolder->resolveIn($root, trim($path, '/'));
            return $node instanceof Folder ? $node : null;
        }
        $source = $source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED;
        $root = $this->audioFolder->rootFor($source);
        if ($root === null) {
            return null;
        }
        $node = $this->audioFolder->resolveIn($root, $path);
        return $node instanceof Folder ? $node : null;
    }

    /**
     * Datum JJJJ-MM-TT -> Ende dieses Tages als Zeitstempel.
     *
     * @return int|null|false null = kein Ablauf, false = ungueltig/vergangen
     */
    private function parseExpires(string $value): int|null|false {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $timestamp = strtotime($value . ' 23:59:59');
        if ($timestamp === false || $timestamp < time()) {
            return false;
        }
        return $timestamp;
    }

    /** Adresse des eigenen Cover-Ersatzbildes, oder '' ohne Bild. */
    private function coverImageUrl(array $share, bool $isLink): string {
        $key = BackgroundImage::shareCoverKey($share['id']);
        if (!$this->backgroundImage->exists($key)) {
            return '';
        }
        $url = $isLink
            ? $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.shareCover', ['token' => $share['token']])
            : $this->urlGenerator->linkToRoute(Application::APP_ID . '.asset.incomingCover', ['id' => $share['id']]);
        return $url . '?v=' . $this->backgroundImage->version($key);
    }

    /** Darstellung fuer die Oberflaeche - ohne Passwort-Pruefwert. */
    private function present(array $share): array {
        $folder = $this->shares->rootFolder($share);
        $expired = $this->shares->isExpired($share);
        $isLink = $share['kind'] === Application::SHARE_KIND_LINK;

        $members = [];
        if (!$isLink) {
            foreach ($this->shares->getMembers($share['id']) as $member) {
                if ($member['type'] === 'user') {
                    $label = $this->userManager->getDisplayName($member['id']) ?? $member['id'];
                } else {
                    $label = $this->groupManager->get($member['id'])?->getDisplayName() ?? $member['id'];
                }
                $members[] = $member + ['label' => $label];
            }
        }

        // Weitergeteilt (ab 0.26.0): Ursprung und Pfad darin, damit die App
        // den Ordner unter "Mit mir geteilt" oeffnen kann
        $via = null;
        $viaId = (int)$share['settings']['viaShare'];
        if ($viaId > 0) {
            $parent = $this->shares->findById($viaId);
            $relative = '';
            if ($parent !== null) {
                $base = trim($parent['path'], '/');
                $own = trim($share['path'], '/');
                $relative = $base === '' ? $own : (str_starts_with($own . '/', $base . '/') ? ltrim(substr($own, strlen($base)), '/') : '');
            }
            $via = [
                'id' => $viaId,
                'path' => $relative,
                'creator' => $parent['creator'] ?? '',
                'creatorName' => $parent !== null ? ($this->userManager->getDisplayName($parent['creator']) ?? $parent['creator']) : '',
            ];
        }

        return [
            'id' => $share['id'],
            'kind' => $share['kind'],
            'via' => $via,
            'creatorName' => $this->userManager->getDisplayName($share['creator']) ?? $share['creator'],
            // Interne Freigaben haben keinen Link - der Token bleibt geheim
            'token' => $isLink ? $share['token'] : '',
            'slug' => ($isLink && preg_match(ShareService::SLUG_PATTERN, $share['token'])) ? $share['token'] : '',
            'url' => $isLink ? $this->urlGenerator->linkToRouteAbsolute(
                Application::APP_ID . '.publicPlayer.index', ['token' => $share['token']]
            ) : '',
            'members' => $members,
            'creator' => $share['creator'],
            'source' => $share['source'],
            'path' => $share['path'],
            'folderName' => $folder !== null ? $folder->getName() : '',
            'hasPassword' => $share['hasPassword'],
            'expires' => $share['expires'] === null ? '' : date('Y-m-d', $share['expires']),
            'expired' => $expired,
            'missing' => $folder === null,
            'settings' => $share['settings'],
            'hasBackground' => $this->backgroundImage->exists(BackgroundImage::shareKey($share['id'])),
            'hasCoverImage' => $this->backgroundImage->exists(BackgroundImage::shareCoverKey($share['id'])),
            // Vorschau des eigenen Cover-Ersatzbildes im Formular (ab 0.20)
            'coverImageUrl' => $this->coverImageUrl($share, $isLink),
            'created' => $share['created'],
        ];
    }
}
