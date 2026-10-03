<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Controller;

use OCA\AudioArchive\AppInfo\Application;
use OCA\AudioArchive\Service\AudioFolder;
use OCA\AudioArchive\Service\ContentScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Hilfe und Kontakt (ab 0.37.0, Vikunja #27): Nachricht aus dem
 * Hilfe-Fenster an die Gruppe, die der Administrator in der Verwaltung
 * gewaehlt hat. Jedes Mitglied bekommt eine Nextcloud-Benachrichtigung
 * (die Nextcloud auf Wunsch auch per E-Mail weiterleitet).
 *
 * Zugang wie bei Liste und Kommentaren: angemeldet, oder ueber einen Link
 * mit gueltiger Anmeldung (ContentScope). Gegen Missbrauch: Kopfzeile
 * "X-AudioArchive: 1" und Begrenzung der Anfragen.
 */
class HelpController extends Controller {

    public function __construct(
        IRequest $request,
        private ContentScope $scope,
        private IUserSession $userSession,
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private INotificationManager $notifications,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 5, period: 600)]
    #[UserRateLimit(limit: 20, period: 600)]
    public function send(string $source = AudioFolder::SOURCE_SHARED, string $s = '', string $name = '',
        string $email = '', string $message = '', string $where = ''): DataResponse {
        if ($this->request->getHeader('X-AudioArchive') !== '1') {
            return new DataResponse(['error' => 'Ungültige Anfrage.'], Http::STATUS_BAD_REQUEST);
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            $scope = $this->scope->resolve($source, $s);
            if (is_int($scope)) {
                return new DataResponse(['error' => 'Bitte zuerst anmelden.'], $scope);
            }
        }

        $gid = $this->appConfig->getValueString(Application::APP_ID, Application::SETTING_HELP_GROUP, '');
        $group = $gid !== '' ? $this->groupManager->get($gid) : null;
        if ($group === null) {
            return new DataResponse(['error' => 'Nachrichten sind hier nicht eingerichtet.'], Http::STATUS_FORBIDDEN);
        }

        $message = trim(mb_substr($message, 0, 2000));
        if ($message === '') {
            return new DataResponse(['error' => 'Bitte eine Nachricht schreiben.'], Http::STATUS_BAD_REQUEST);
        }
        $email = trim(mb_substr($email, 0, 200));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new DataResponse(['error' => 'Die E-Mail-Adresse stimmt nicht – bitte prüfen oder leer lassen.'], Http::STATUS_BAD_REQUEST);
        }
        if ($user !== null) {
            $name = $user->getDisplayName();
            if ($email === '') {
                $email = (string)($user->getEMailAddress() ?? '');
            }
        }
        $name = trim(mb_substr(preg_replace('/\s+/u', ' ', $name) ?? '', 0, 80));
        if ($name === '') {
            $name = 'Jemand';
        }
        $where = trim(mb_substr(preg_replace('/\s+/u', ' ', $where) ?? '', 0, 300));

        $sent = 0;
        try {
            $now = new \DateTime();
            $id = bin2hex(random_bytes(8));
            foreach ($group->getUsers() as $member) {
                $n = $this->notifications->createNotification();
                $n->setApp(Application::APP_ID)
                    ->setUser($member->getUID())
                    ->setDateTime($now)
                    ->setObject('audioarchive_help', $id)
                    ->setSubject('help_request', [
                        'author' => $name,
                        'email' => $email,
                        'text' => $message,
                        'where' => $where,
                        'guest' => $user === null,
                    ]);
                $this->notifications->notify($n);
                $sent++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Audio Archive: Hilfe-Nachricht konnte nicht zugestellt werden', ['exception' => $e]);
            return new DataResponse(['error' => 'Die Nachricht konnte nicht zugestellt werden. Bitte später noch einmal versuchen.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        if ($sent === 0) {
            return new DataResponse(['error' => 'Die Gruppe für Hilfe hat keine Mitglieder.'], Http::STATUS_SERVICE_UNAVAILABLE);
        }
        return new DataResponse(['sent' => true]);
    }
}
