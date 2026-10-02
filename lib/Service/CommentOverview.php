<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Uebersicht der Kommentare zum Einsehen und Exportieren (ab 0.35.0,
 * Vikunja #5).
 *
 *  - Jeder angemeldete Nutzer sieht in seinen persoenlichen Einstellungen die
 *    Kommentare zu Aufnahmen in den Ordnern, die er selbst geteilt hat (Links
 *    und Freigaben an Personen/Gruppen) - solange die Freigabe besteht und
 *    der Ordner noch erreichbar ist. Dazu gehoeren auch Kommentare, die
 *    jemand ueber seinen Link geschrieben hat.
 *  - Der Administrator und die Mitglieder der Gruppe, die bei neuen
 *    Kommentaren benachrichtigt wird, sehen alle Kommentare: gemeinsamer
 *    Ordner, weitere Quellen und alle Freigaben.
 *
 * Grundlage sind die echten Nextcloud-Dateikommentare; aufgenommen werden nur
 * Kommentare zu Aufnahmen (erlaubte Audioformate) innerhalb dieser Ordner -
 * also auch Antworten, die in "Dateien" geschrieben wurden.
 */
class CommentOverview {

    /** Hoechstens so viele Kommentare werden betrachtet (neueste zuerst). */
    private const MAX_COMMENTS = 3000;

    public function __construct(
        private ICommentsManager $comments,
        private AudioFolder $audioFolder,
        private ShareService $shares,
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {
    }

    /** Darf dieser Nutzer alle Kommentare sehen? Administrator oder Mitglied der Benachrichtigungs-Gruppe. */
    public function maySeeAll(string $uid): bool {
        if ($this->groupManager->isAdmin($uid)) {
            return true;
        }
        $gid = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_COMMENT_NOTIFY_GROUP, '');
        return $gid !== '' && $this->groupManager->isInGroup($uid, $gid);
    }

    /**
     * @param bool $all true = alle (nur mit maySeeAll), sonst die eigenen Freigaben
     * @return array{comments: list<array>, truncated: bool}
     */
    public function collect(string $uid, bool $all): array {
        $roots = $all ? $this->allRoots() : $this->ownRoots($uid);
        if ($roots === []) {
            return ['comments' => [], 'truncated' => false];
        }

        $list = $this->comments->search('', 'files', '', 'comment', 0, self::MAX_COMMENTS);
        $located = []; // Dateikennung -> [Wurzel-Bezeichnung, Ordnerpfad, Dateiname] oder null
        $rows = [];
        foreach ($list as $comment) {
            $fileId = (int)$comment->getObjectId();
            if (!array_key_exists($fileId, $located)) {
                $located[$fileId] = $this->locate($fileId, $roots);
            }
            $where = $located[$fileId];
            if ($where === null) {
                continue;
            }
            $rows[] = $this->row($comment, $where);
        }
        return ['comments' => $rows, 'truncated' => count($list) >= self::MAX_COMMENTS];
    }

    /**
     * Ordner, in denen dieser Nutzer etwas geteilt hat.
     *
     * @return list<array{label: string, folder: Folder}>
     */
    private function ownRoots(string $uid): array {
        $roots = [];
        foreach ($this->shares->listByCreator($uid) as $share) {
            if ($this->shares->isExpired($share)) {
                continue;
            }
            $folder = $this->shares->rootFolder($share);
            if ($folder !== null) {
                $roots[] = ['label' => $this->shareLabel($share, $folder), 'folder' => $folder];
            }
        }
        return $roots;
    }

    /** @return list<array{label: string, folder: Folder}> */
    private function allRoots(): array {
        $roots = [];
        $shared = $this->audioFolder->getRoot();
        if ($shared !== null) {
            $label = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_SHARED_LABEL, '');
            $roots[] = ['label' => $label !== '' ? $label : 'Gemeinsame Aufnahmen', 'folder' => $shared];
        }
        foreach ($this->audioFolder->extraSources() as $extra) {
            $folder = $this->audioFolder->folderOf($extra['owner'], $extra['path']);
            if ($folder !== null) {
                $roots[] = ['label' => $extra['name'], 'folder' => $folder];
            }
        }
        foreach ($this->shares->listAll() as $share) {
            $folder = $this->shares->rootFolder($share);
            if ($folder !== null) {
                $roots[] = ['label' => $this->shareLabel($share, $folder) . ' (von ' . $this->displayName($share['creator']) . ')', 'folder' => $folder];
            }
        }
        return $roots;
    }

    private function shareLabel(array $share, Folder $folder): string {
        $title = trim((string)($share['settings']['title'] ?? ''));
        $kind = $share['kind'] === Application::SHARE_KIND_LINK ? '🔗 ' : '👥 ';
        return $kind . ($title !== '' ? $title : $folder->getName());
    }

    /**
     * Liegt die Datei als Aufnahme in einer der Wurzeln? Dann Bezeichnung
     * der Wurzel, Ordner (relativ) und Dateiname. Die erste passende zaehlt.
     *
     * @param list<array{label: string, folder: Folder}> $roots
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function locate(int $fileId, array $roots): ?array {
        foreach ($roots as $root) {
            try {
                $folder = $root['folder'];
                $node = method_exists($folder, 'getFirstNodeById')
                    ? $folder->getFirstNodeById($fileId)
                    : ($folder->getById($fileId)[0] ?? null);
            } catch (\Throwable $e) {
                $node = null;
            }
            if (!$node instanceof File || !$this->audioFolder->isAllowedFile($node)) {
                continue;
            }
            $relative = $this->audioFolder->relativePath($root['folder'], $node);
            $pos = strrpos($relative, '/');
            return [$root['label'], $pos === false ? '' : substr($relative, 0, $pos), $node->getName()];
        }
        return null;
    }

    /** @param array{0: string, 1: string, 2: string} $where */
    private function row(IComment $comment, array $where): array {
        $meta = $comment->getMetaData()[CommentService::META_KEY] ?? null;
        $meta = is_array($meta) ? $meta : null;
        if ($comment->getActorType() === 'users') {
            $author = $this->displayName($comment->getActorId());
        } elseif ($comment->getActorType() === CommentService::ACTOR_GUEST) {
            $name = trim((string)($meta['name'] ?? ''));
            $author = ($name !== '' ? $name : 'Gast') . ' (über Link)';
        } else {
            $author = $comment->getActorId();
        }
        return [
            'id' => (string)$comment->getId(),
            'created' => $comment->getCreationDateTime()->getTimestamp(),
            'source' => $where[0],
            'folder' => $where[1],
            'file' => $where[2],
            'author' => $author,
            'rating' => $meta !== null ? (int)($meta['rating'] ?? 0) : 0,
            // Ohne eigene Angaben (in "Dateien" geschrieben): der ganze Text
            'text' => $meta !== null ? (string)($meta['text'] ?? '') : $comment->getMessage(),
            'reply' => $comment->getParentId() !== '0' && $comment->getParentId() !== '',
        ];
    }

    private function displayName(string $uid): string {
        $name = $this->userManager->getDisplayName($uid);
        return $name !== null && $name !== '' ? $name : $uid;
    }
}
