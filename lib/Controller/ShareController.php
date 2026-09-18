<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\BackgroundImage;
use OCA\AudioArchive\Service\ShareService;
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
 * Jeder angemeldete Nutzer darf Ordner teilen, auf die er in der App
 * Zugriff hat: aus den eigenen Dateien und aus dem gemeinsamen Ordner.
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
     * @param array $settings siehe ShareService::SETTING_DEFAULTS
     * @param string $password leer = ohne Passwort
     * @param string $expires  Datum JJJJ-MM-TT oder leer
     */
    #[NoAdminRequired]
    public function create(string $source = '', string $path = '', array $settings = [],
        string $password = '', string $expires = ''): DataResponse {
        $uid = $this->uid();
        if ($uid === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->shares->sharingAllowed()) {
            return new DataResponse(['error' => 'Freigaben sind vom Administrator abgeschaltet.'], Http::STATUS_FORBIDDEN);
        }

        $source = $source === AudioFolder::SOURCE_HOME ? AudioFolder::SOURCE_HOME : AudioFolder::SOURCE_SHARED;
        $path = trim($path, '/');

        if ($source === AudioFolder::SOURCE_HOME && $path === '') {
            return new DataResponse(
                ['error' => 'Die gesamten eigenen Dateien lassen sich nicht teilen – bitte einen Ordner wählen.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $folder = $this->folderFor($source, $path);
        if ($folder === null) {
            return new DataResponse(['error' => 'Ordner nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }

        $expiresAt = $this->parseExpires($expires);
        if ($expiresAt === false) {
            return new DataResponse(['error' => 'Das Ablaufdatum muss in der Zukunft liegen.'], Http::STATUS_BAD_REQUEST);
        }

        $owner = $source === AudioFolder::SOURCE_HOME ? $uid : $this->audioFolder->sharedOwner();
        $share = $this->shares->create($uid, $owner, $folder, $source, $path, $settings, $password, $expiresAt);

        return new DataResponse(['share' => $this->present($share)]);
    }

    /**
     * @param string|null $password null = unveraendert, sonst neues Passwort
     * @param bool $removePassword Passwort entfernen
     */
    #[NoAdminRequired]
    public function update(int $id, array $settings = [], ?string $password = null,
        bool $removePassword = false, string $expires = ''): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }

        $expiresAt = $this->parseExpires($expires);
        if ($expiresAt === false) {
            return new DataResponse(['error' => 'Das Ablaufdatum muss in der Zukunft liegen.'], Http::STATUS_BAD_REQUEST);
        }

        $newPassword = $removePassword ? '' : (($password === null || $password === '') ? null : $password);
        $share = $this->shares->update($share, $settings, $newPassword, $expiresAt);

        return new DataResponse(['share' => $this->present($share)]);
    }

    #[NoAdminRequired]
    public function delete(int $id): DataResponse {
        $share = $this->editable($id);
        if (!is_array($share)) {
            return $share;
        }
        $this->backgroundImage->remove(BackgroundImage::shareKey($share['id']));
        $this->shares->delete($share);
        return new DataResponse(['deleted' => true]);
    }

    #[NoAdminRequired]
    public function uploadBackground(int $id): DataResponse {
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
            BackgroundImage::shareKey($share['id'])
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

    /** Darstellung fuer die Oberflaeche - ohne Passwort-Pruefwert. */
    private function present(array $share): array {
        $folder = $this->shares->rootFolder($share);
        $expired = $this->shares->isExpired($share);

        return [
            'id' => $share['id'],
            'token' => $share['token'],
            'url' => $this->urlGenerator->linkToRouteAbsolute(
                Application::APP_ID . '.publicPlayer.index', ['token' => $share['token']]
            ),
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
            'created' => $share['created'],
        ];
    }
}
