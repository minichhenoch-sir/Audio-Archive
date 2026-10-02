<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Service;

use OCA\AudioArchive\AppInfo\Application;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Kommentare zu Aufnahmen (ab 0.29.0, Vikunja #5).
 *
 * Gespeichert werden echte Nextcloud-Dateikommentare (objectType 'files'):
 * Wer die Datei in "Dateien" sieht, findet sie dort in der Seitenleiste
 * unter "Kommentare" und kann antworten. In der App sieht jeder nur seine
 * eigenen (Wunsch von Hans).
 *
 * Wer kommentiert:
 *   - angemeldet: Nextcloud-Nutzer (actorType 'users')
 *   - ueber einen Link: Gast. Kennung je Geraet (Zufallswert aus dem
 *     Browser, hier nur als Hash gespeichert), actorType
 *     'audioarchive_guest'. Den Namen gibt der Gast selbst an; er steht vorn
 *     im Text, weil Nextcloud fuer Gaeste keinen Anzeigenamen kennt.
 *
 * Bewertung (1-5 Sterne) steht als Sterne vorn im Text (sichtbar in der
 * Seitenleiste) und zusaetzlich in den Metadaten des Kommentars.
 */
class CommentService {

    public const ACTOR_GUEST = 'audioarchive_guest';
    public const META_KEY = 'audioarchive';
    /** Laenge des eigentlichen Textes (Nextcloud erlaubt 1000 Zeichen samt Name/Sternen) */
    public const MAX_TEXT = 900;
    private const MAX_NAME = 60;

    public function __construct(
        private ICommentsManager $comments,
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private INotificationManager $notifications,
        private LoggerInterface $logger,
    ) {
    }

    /** Gast-Kennung aus dem Geraete-Zufallswert; null, wenn ungueltig. */
    public static function guestActorId(string $deviceKey): ?string {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $deviceKey)) {
            return null;
        }
        return 'aa-' . substr(hash('sha256', 'audioarchive-guest|' . $deviceKey), 0, 40);
    }

    /**
     * Eigene Kommentare zu einer Datei, aelteste zuerst.
     *
     * @return list<array{id: string, text: string, rating: int, name: string, created: int}>
     */
    public function listOwn(File $file, string $actorType, string $actorId): array {
        $out = [];
        $all = $this->comments->getForObject('files', (string)$file->getId(), 500);
        foreach ($all as $comment) {
            if ($comment->getActorType() !== $actorType || $comment->getActorId() !== $actorId
                || $comment->getVerb() !== 'comment') {
                continue;
            }
            $out[] = $this->toArray($comment);
        }
        usort($out, static fn ($a, $b) => $a['created'] <=> $b['created'] ?: strcmp($a['id'], $b['id']));
        return $out;
    }

    /**
     * Legt einen Kommentar an und benachrichtigt die eingestellte Gruppe.
     *
     * @return array{id: string, text: string, rating: int, name: string, created: int}
     */
    public function add(File $file, string $actorType, string $actorId, string $text, int $rating,
        string $guestName, string $authorLabel): array {
        $text = trim(mb_substr(str_replace("\r", '', $text), 0, self::MAX_TEXT));
        $rating = max(0, min(5, $rating));
        $guestName = trim(mb_substr(preg_replace('/\s+/u', ' ', strip_tags($guestName)) ?? '', 0, self::MAX_NAME));

        $prefix = '';
        if ($actorType === self::ACTOR_GUEST) {
            $prefix .= ($guestName !== '' ? $guestName : 'Gast') . ' (über Link): ';
        }
        if ($rating > 0) {
            $prefix .= str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) . ($text !== '' ? ' – ' : '');
        }

        $comment = $this->comments->create($actorType, $actorId, 'files', (string)$file->getId());
        $comment->setVerb('comment');
        $comment->setMessage($prefix . $text, IComment::MAX_MESSAGE_LENGTH);
        $comment->setMetaData([self::META_KEY => [
            'rating' => $rating,
            'name' => $guestName,
            'text' => $text,
        ]]);
        $this->comments->save($comment);

        $this->notifyGroup($comment, $file, $actorType === 'users' ? $actorId : null,
            $actorType === self::ACTOR_GUEST ? ($guestName !== '' ? $guestName : 'Gast') . ' (über Link)' : $authorLabel,
            $text, $rating);

        return $this->toArray($comment);
    }

    /** Loescht einen eigenen Kommentar zu dieser Datei. */
    public function deleteOwn(File $file, string $id, string $actorType, string $actorId): bool {
        try {
            $comment = $this->comments->get($id);
        } catch (\Throwable $e) {
            return false;
        }
        if ($comment->getObjectType() !== 'files' || $comment->getObjectId() !== (string)$file->getId()
            || $comment->getActorType() !== $actorType || $comment->getActorId() !== $actorId) {
            return false;
        }
        return $this->comments->delete($id);
    }

    /** @return array{id: string, text: string, rating: int, name: string, created: int} */
    private function toArray(IComment $comment): array {
        $meta = $comment->getMetaData()[self::META_KEY] ?? null;
        $created = $comment->getCreationDateTime();
        return [
            'id' => (string)$comment->getId(),
            // Ohne eigene Angaben (in "Dateien" geschrieben): der ganze Text
            'text' => is_array($meta) ? (string)($meta['text'] ?? '') : $comment->getMessage(),
            'rating' => is_array($meta) ? (int)($meta['rating'] ?? 0) : 0,
            'name' => is_array($meta) ? (string)($meta['name'] ?? '') : '',
            'created' => $created->getTimestamp(),
        ];
    }

    private function notifyGroup(IComment $comment, File $file, ?string $authorUid, string $author,
        string $text, int $rating): void {
        $gid = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_COMMENT_NOTIFY_GROUP, '');
        if ($gid === '') {
            return;
        }
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            return;
        }
        try {
            $now = new \DateTime();
            foreach ($group->getUsers() as $user) {
                if ($user->getUID() === $authorUid) {
                    continue; // nicht ueber den eigenen Kommentar
                }
                $n = $this->notifications->createNotification();
                $n->setApp(Application::APP_ID)
                    ->setUser($user->getUID())
                    ->setDateTime($now)
                    ->setObject('audioarchive_comment', (string)$comment->getId())
                    ->setSubject('new_comment', [
                        'author' => $author,
                        'file' => $file->getName(),
                        'fileId' => $file->getId(),
                        'text' => mb_substr($text, 0, 300),
                        'rating' => $rating,
                    ]);
                $this->notifications->notify($n);
            }
        } catch (\Throwable $e) {
            // Ein Fehler beim Benachrichtigen darf den Kommentar nicht verhindern
            $this->logger->warning('Audio Archive: Benachrichtigung zum Kommentar fehlgeschlagen', ['exception' => $e]);
        }
    }
}
