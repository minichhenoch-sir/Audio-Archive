<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Uebersicht der Kommentare zum Einsehen und Exportieren (ab 0.35.0,
 * Vikunja #5; Rechte ab 0.36.0 nach Hans' Klarstellung).
 *
 *  - Jeder angemeldete Nutzer sieht seine EIGENEN Kommentare und die, die
 *    ueber SEINE Freigaben (Links, Freigaben an Personen/Gruppen) geschrieben
 *    wurden - solange die Freigabe besteht. Ueber welche Freigabe ein
 *    Kommentar kam, steht ab 0.36.0 in seinen Metadaten ('share'); aeltere
 *    Kommentare anderer sieht nur der Administrator.
 *  - Der Administrator sieht alle Kommentare zu Aufnahmen: gemeinsamer
 *    Ordner, weitere Quellen und alle Freigaben.
 *
 * Grundlage sind die echten Nextcloud-Dateikommentare (neueste zuerst);
 * aufgenommen werden nur Kommentare zu Aufnahmen (erlaubte Audioformate).
 * Zu jeder Zeile gibt es - wenn der Betrachter die Datei erreicht - die
 * Stelle in der App ('open') bzw. in "Dateien" ('filesUrl').
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
        private IRootFolder $rootFolder,
        private IURLGenerator $urlGenerator,
    ) {
    }

    /** Alle Kommentare sieht nur der Administrator (ab 0.36.0, Vikunja #5). */
    public function maySeeAll(string $uid): bool {
        return $this->groupManager->isAdmin($uid);
    }

    /**
     * @param bool $all true = alle (nur Administrator), sonst die eigenen
     * @param string $q Filter (alle Woerter muessen vorkommen), leer = alles
     * @return array{comments: list<array>, truncated: bool}
     */
    public function collect(string $uid, bool $all, string $q = ''): array {
        $myShares = [];
        foreach ($this->shares->listByCreator($uid) as $share) {
            if (!$this->shares->isExpired($share)) {
                $folder = $this->shares->rootFolder($share);
                if ($folder !== null) {
                    $myShares[(int)$share['id']] = ['label' => $this->shareLabel($share, $folder), 'folder' => $folder];
                }
            }
        }
        $roots = $all ? $this->allRoots() : $this->viewerRoots($uid, $myShares);
        $openRoots = $this->openRoots($uid);

        $list = $this->comments->search('', 'files', '', 'comment', 0, self::MAX_COMMENTS);
        $located = [];
        $rows = [];
        $shareCache = [];
        foreach ($list as $comment) {
            $meta = $comment->getMetaData()[CommentService::META_KEY] ?? null;
            $meta = is_array($meta) ? $meta : null;
            $shareId = isset($meta['share']) ? (int)$meta['share'] : 0;

            if (!$all) {
                $own = $comment->getActorType() === 'users' && $comment->getActorId() === $uid;
                $viaMine = $shareId > 0 && isset($myShares[$shareId]);
                if (!$own && !$viaMine) {
                    continue;
                }
            }

            $fileId = (int)$comment->getObjectId();
            if (!array_key_exists($fileId, $located)) {
                $located[$fileId] = $this->locate($fileId, $roots);
            }
            $where = $located[$fileId];
            if ($where === null) {
                continue;
            }
            // Bereich: die Freigabe, ueber die geschrieben wurde, sonst der Fundort
            $source = $where['label'];
            if ($shareId > 0) {
                if (isset($myShares[$shareId])) {
                    $source = $myShares[$shareId]['label'];
                } elseif ($all) {
                    $shareCache[$shareId] ??= $this->shareLabelById($shareId);
                    $source = $shareCache[$shareId] ?? $source;
                }
            }
            $row = $this->row($comment, $meta, $where, $source);
            $row += $this->openTarget($fileId, $openRoots, $uid);
            if ($q !== '' && !self::matches($q, $row)) {
                continue;
            }
            $rows[] = $row;
        }
        return ['comments' => $rows, 'truncated' => count($list) >= self::MAX_COMMENTS];
    }

    /** Filter wie in der Oberflaeche: alle Woerter in Aufnahme, Ordner, Bereich, Name oder Text. */
    private static function matches(string $q, array $row): bool {
        $hay = mb_strtolower(implode(' ', [$row['file'], $row['folder'], $row['source'], $row['author'], $row['text']]));
        foreach (preg_split('/\s+/u', mb_strtolower(trim($q))) ?: [] as $term) {
            if ($term !== '' && !str_contains($hay, $term)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Wo ein Nutzer seine Kommentare finden kann: eigene Freigaben, gemeinsamer
     * Ordner, weitere Quellen, eigene Dateien.
     *
     * @param array<int, array{label: string, folder: Folder}> $myShares
     * @return list<array{label: string, folder: Folder}>
     */
    private function viewerRoots(string $uid, array $myShares): array {
        $roots = array_values($myShares);
        foreach ($this->sourceRoots() as $root) {
            $roots[] = $root;
        }
        try {
            $roots[] = ['label' => 'Meine Dateien', 'folder' => $this->rootFolder->getUserFolder($uid)];
        } catch (\Throwable $e) {
            // ohne eigenen Ordner
        }
        return $roots;
    }

    /** @return list<array{label: string, folder: Folder}> */
    private function allRoots(): array {
        $roots = $this->sourceRoots();
        foreach ($this->shares->listAll() as $share) {
            $folder = $this->shares->rootFolder($share);
            if ($folder !== null) {
                $roots[] = ['label' => $this->shareLabel($share, $folder) . ' (von ' . $this->displayName($share['creator']) . ')', 'folder' => $folder];
            }
        }
        return $roots;
    }

    /** Gemeinsamer Ordner und weitere Quellen. @return list<array{label: string, folder: Folder, source?: string}> */
    private function sourceRoots(): array {
        $roots = [];
        $shared = $this->audioFolder->getRoot();
        if ($shared !== null) {
            $label = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_SHARED_LABEL, '');
            $roots[] = ['label' => $label !== '' ? $label : 'Gemeinsame Aufnahmen', 'folder' => $shared, 'source' => AudioFolder::SOURCE_SHARED];
        }
        foreach ($this->audioFolder->extraSources() as $extra) {
            $folder = $this->audioFolder->folderOf($extra['owner'], $extra['path']);
            if ($folder !== null) {
                $roots[] = ['label' => $extra['name'], 'folder' => $folder, 'source' => AudioFolder::SOURCE_EXTRA_PREFIX . $extra['id']];
            }
        }
        return $roots;
    }

    /**
     * Wo der Betrachter die Datei in der App oeffnen kann: Quellen, die alle
     * Angemeldeten sehen, dann seine eigenen Dateien.
     *
     * @return list<array{folder: Folder, source: string}>
     */
    private function openRoots(string $uid): array {
        $roots = [];
        foreach ($this->sourceRoots() as $root) {
            $roots[] = ['folder' => $root['folder'], 'source' => $root['source']];
        }
        try {
            $roots[] = ['folder' => $this->rootFolder->getUserFolder($uid), 'source' => AudioFolder::SOURCE_HOME];
        } catch (\Throwable $e) {
            // ohne eigenen Ordner
        }
        return $roots;
    }

    /**
     * @param list<array{folder: Folder, source: string}> $roots
     * @return array{open: ?array{source: string, path: string}, filesUrl: ?string}
     */
    private function openTarget(int $fileId, array $roots, string $uid): array {
        $open = null;
        $filesUrl = null;
        foreach ($roots as $root) {
            $node = $this->nodeIn($root['folder'], $fileId);
            if ($node === null) {
                continue;
            }
            $open ??= ['source' => $root['source'], 'path' => $this->audioFolder->relativePath($root['folder'], $node)];
            if ($root['source'] === AudioFolder::SOURCE_HOME) {
                try {
                    $filesUrl = $this->urlGenerator->linkToRoute('files.view.showFile', ['fileid' => $fileId]);
                } catch (\Throwable $e) {
                    $filesUrl = null;
                }
            }
        }
        return ['open' => $open, 'filesUrl' => $filesUrl];
    }

    private function nodeIn(Folder $folder, int $fileId): ?File {
        try {
            $node = method_exists($folder, 'getFirstNodeById')
                ? $folder->getFirstNodeById($fileId)
                : ($folder->getById($fileId)[0] ?? null);
        } catch (\Throwable $e) {
            return null;
        }
        return $node instanceof File && $this->audioFolder->isAllowedFile($node) ? $node : null;
    }

    private function shareLabel(array $share, Folder $folder): string {
        $title = trim((string)($share['settings']['title'] ?? ''));
        $kind = $share['kind'] === Application::SHARE_KIND_LINK ? '🔗 ' : '👥 ';
        return $kind . ($title !== '' ? $title : $folder->getName());
    }

    private function shareLabelById(int $id): ?string {
        $share = $this->shares->findById($id);
        $folder = $share !== null ? $this->shares->rootFolder($share) : null;
        return $folder !== null ? $this->shareLabel($share, $folder) . ' (von ' . $this->displayName($share['creator']) . ')' : null;
    }

    /**
     * Liegt die Datei als Aufnahme in einer der Wurzeln? Die erste passende zaehlt.
     *
     * @param list<array{label: string, folder: Folder}> $roots
     * @return array{label: string, folder: string, file: string}|null
     */
    private function locate(int $fileId, array $roots): ?array {
        foreach ($roots as $root) {
            $node = $this->nodeIn($root['folder'], $fileId);
            if ($node === null) {
                continue;
            }
            $relative = $this->audioFolder->relativePath($root['folder'], $node);
            $pos = strrpos($relative, '/');
            return ['label' => $root['label'], 'folder' => $pos === false ? '' : substr($relative, 0, $pos), 'file' => $node->getName()];
        }
        return null;
    }

    /** @param array{label: string, folder: string, file: string} $where */
    private function row(IComment $comment, ?array $meta, array $where, string $source): array {
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
            'source' => $source,
            'folder' => $where['folder'],
            'file' => $where['file'],
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
