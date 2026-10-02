<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\CommentOverview;
use OCA\AudioArchive\Service\CommentService;
use OCA\AudioArchive\Service\ContentScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Kommentare zu einer Aufnahme (ab 0.29.0, Vikunja #5). Gleiche
 * Zugangspruefung wie Liste und Aufnahme (ContentScope); erlaubt nur, wenn
 * Verwaltung - und je nach Zugang Freigabe bzw. oeffentlicher Link - es
 * einschalten.
 *
 * Schreibzugriffe brauchen die Kopfzeile "X-AudioArchive: 1": Fremde
 * Webseiten koennen sie nicht setzen (der Browser fragt dann vorher nach,
 * und Nextcloud erlaubt das nicht). Gaeste haben kein Anfrage-Token.
 */
class CommentController extends Controller {

    public function __construct(
        IRequest $request,
        private ContentScope $scope,
        private AudioFolder $audioFolder,
        private CommentService $comments,
        private IUserSession $userSession,
        private CommentOverview $overview,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Uebersicht zum Einsehen und Exportieren (ab 0.35.0, Vikunja #5):
     * scope 'mine' = Kommentare in den eigenen Freigaben, 'all' = alle
     * (Administrator und Benachrichtigungs-Gruppe). Export (CSV, Drucken)
     * macht die Oberflaeche aus dieser Antwort.
     */
    #[NoAdminRequired]
    public function overview(string $scope = 'mine'): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $uid = $user->getUID();
        $mayAll = $this->overview->maySeeAll($uid);
        $all = $scope === 'all';
        if ($all && !$mayAll) {
            return new DataResponse(['error' => 'Nicht erlaubt.'], Http::STATUS_FORBIDDEN);
        }
        return new DataResponse(['scope' => $all ? 'all' : 'mine', 'mayAll' => $mayAll] + $this->overview->collect($uid, $all));
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function index(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = '', string $guest = ''): DataResponse {
        $ctx = $this->context($path, $source, $s, $guest);
        if ($ctx instanceof DataResponse) {
            return $ctx;
        }
        return new DataResponse([
            'enabled' => true,
            'rating' => $ctx['rating'],
            'guest' => $ctx['actorType'] === CommentService::ACTOR_GUEST,
            'comments' => $this->comments->listOwn($ctx['file'], $ctx['actorType'], $ctx['actorId']),
        ]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 20, period: 600)]
    #[UserRateLimit(limit: 60, period: 600)]
    public function create(string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = '', string $guest = '',
        string $text = '', int $rating = 0, string $name = ''): DataResponse {
        if ($this->request->getHeader('X-AudioArchive') !== '1') {
            return new DataResponse(['error' => 'Ungültige Anfrage.'], Http::STATUS_BAD_REQUEST);
        }
        $ctx = $this->context($path, $source, $s, $guest);
        if ($ctx instanceof DataResponse) {
            return $ctx;
        }
        if (!$ctx['rating']) {
            $rating = 0;
        }
        if (trim($text) === '' && $rating <= 0) {
            return new DataResponse(['error' => 'Bitte etwas schreiben oder Sterne vergeben.'], Http::STATUS_BAD_REQUEST);
        }
        $user = $this->userSession->getUser();
        $comment = $this->comments->add($ctx['file'], $ctx['actorType'], $ctx['actorId'], $text, $rating, $name,
            $user !== null ? $user->getDisplayName() : '');
        return new DataResponse(['comment' => $comment]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function delete(string $id, string $path = '', string $source = AudioFolder::SOURCE_SHARED, string $s = '', string $guest = ''): DataResponse {
        if ($this->request->getHeader('X-AudioArchive') !== '1') {
            return new DataResponse(['error' => 'Ungültige Anfrage.'], Http::STATUS_BAD_REQUEST);
        }
        $ctx = $this->context($path, $source, $s, $guest);
        if ($ctx instanceof DataResponse) {
            return $ctx;
        }
        if (!$this->comments->deleteOwn($ctx['file'], $id, $ctx['actorType'], $ctx['actorId'])) {
            return new DataResponse(['error' => 'Kommentar nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse(['deleted' => true]);
    }

    /**
     * Datei, Kommentierender und Bewertung - oder die Fehlerantwort.
     *
     * @return array{file: File, actorType: string, actorId: string, rating: bool}|DataResponse
     */
    private function context(string $path, string $source, string $s, string $guest): array|DataResponse {
        $scope = $this->scope->resolve($source, $s);
        if (is_int($scope)) {
            return new DataResponse(['error' => 'Aufnahme nicht gefunden.'], $scope);
        }
        if (($scope['comments'] ?? false) !== true) {
            return new DataResponse(['enabled' => false, 'error' => 'Kommentare sind hier nicht eingeschaltet.'], Http::STATUS_FORBIDDEN);
        }
        $node = $this->audioFolder->resolveIn($scope['root'], $path);
        if (!$node instanceof File || !$this->audioFolder->isAllowedFile($node)) {
            return new DataResponse(['error' => 'Aufnahme nicht gefunden.'], Http::STATUS_NOT_FOUND);
        }
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return ['file' => $node, 'actorType' => 'users', 'actorId' => $user->getUID(), 'rating' => $scope['rating']];
        }
        $actorId = CommentService::guestActorId($guest);
        if ($actorId === null) {
            return new DataResponse(['error' => 'Geräte-Kennung fehlt.'], Http::STATUS_BAD_REQUEST);
        }
        return ['file' => $node, 'actorType' => CommentService::ACTOR_GUEST, 'actorId' => $actorId, 'rating' => $scope['rating']];
    }
}
