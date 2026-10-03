<?php
declare(strict_types=1);

namespace OCA\AudioArchive\Notification;

use OCA\AudioArchive\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Zeigt die Benachrichtigung "Neuer Kommentar zu …" (ab 0.29.0, Vikunja #5)
 * und "Hilfe-Anfrage von …" (ab 0.37.0, Vikunja #27).
 * Nur Deutsch, wie die uebrige Oberflaeche.
 */
class Notifier implements INotifier {

    public function __construct(
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Audio Archive';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'help_request') {
            return $this->prepareHelp($notification);
        }
        if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'new_comment') {
            // Ab Nextcloud 30 gibt es dafuer eine eigene Ausnahme, davor die allgemeine
            if (class_exists(\OCP\Notification\UnknownNotificationException::class)) {
                throw new \OCP\Notification\UnknownNotificationException();
            }
            throw new \InvalidArgumentException();
        }
        $p = $notification->getSubjectParameters();
        $rating = (int)($p['rating'] ?? 0);
        $stars = $rating > 0 ? str_repeat('★', $rating) . str_repeat('☆', 5 - $rating) : '';

        $notification->setParsedSubject(sprintf('%s hat „%s“ kommentiert', (string)($p['author'] ?? ''), (string)($p['file'] ?? '')));
        $message = trim($stars . ' ' . (string)($p['text'] ?? ''));
        if ($message !== '') {
            $notification->setParsedMessage($message);
        }
        $fileId = (int)($p['fileId'] ?? 0);
        if ($fileId > 0) {
            $notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]));
        }
        $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/comment.svg')));
        return $notification;
    }

    /** Hilfe-Anfrage aus dem Hilfe-Fenster (ab 0.37.0, Vikunja #27) */
    private function prepareHelp(INotification $notification): INotification {
        $p = $notification->getSubjectParameters();
        $author = (string)($p['author'] ?? '');
        $email = (string)($p['email'] ?? '');
        $where = (string)($p['where'] ?? '');
        $guest = ($p['guest'] ?? false) === true;
        $notification->setParsedSubject(sprintf('Audio Archive – Hilfe-Anfrage von %s%s', $author, $guest ? ' (über einen Link)' : ''));
        $lines = [(string)($p['text'] ?? '')];
        if ($email !== '') {
            $lines[] = 'Antwort an: ' . $email;
        }
        if ($where !== '') {
            $lines[] = 'Wo: ' . $where;
        }
        $notification->setParsedMessage(implode("\n\n", $lines));
        // Kein Link: Nextcloud nimmt dort nur Web-Adressen an, kein mailto:
        // (InvalidValueException) - die Antwort-Adresse steht im Text.
        $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/info.svg')));
        return $notification;
    }
}
