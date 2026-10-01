<?php
declare(strict_types=1);

return [
    'routes' => [
        // Oberflaeche fuer angemeldete Nextcloud-Nutzer, eingebettet in
        // Nextclouds Seitengeruest (mit Kopfleiste). Ziel des Menue-Eintrags.
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Dieselbe Oberflaeche OHNE Nextcloud-Rahmen. Startadresse der
        // installierten App - nur hier kann das eigene Manifest greifen.
        ['name' => 'page#standalone', 'url' => '/app', 'verb' => 'GET'],

        // Oeffentlicher Zugang ohne Konto. Der Token stammt aus den
        // Admin-Einstellungen und wirkt wie der Link einer Dateifreigabe.
        ['name' => 'publicPlayer#index', 'url' => '/s/{token}', 'verb' => 'GET'],

        // Aufnahmen: Ordnerliste und Ausgabe der Audiodateien.
        // Beide sind oeffentlich erreichbar, pruefen den Zugang aber selbst
        // (angemeldeter Nutzer ODER freigeschaltete oeffentliche Sitzung).
        ['name' => 'list#index', 'url' => '/api/list', 'verb' => 'GET'],
        ['name' => 'stream#index', 'url' => '/api/stream', 'verb' => 'GET'],
        // Cover einer Aufnahme (ab 0.14): eingebettet oder cover.jpg im Ordner
        ['name' => 'cover#index', 'url' => '/api/cover', 'verb' => 'GET'],
        // Ausfuehrliche Angaben zu einer Aufnahme (Info-Ansicht, ab 0.15)
        ['name' => 'list#info', 'url' => '/api/info', 'verb' => 'GET'],
        // Naechster Ordner mit Aufnahmen in Baum-Reihenfolge (ab 0.15)
        ['name' => 'list#next', 'url' => '/api/next', 'verb' => 'GET'],
        // Nur Unterordner, fuer den Ordnerbaum (angemeldet)
        ['name' => 'list#tree', 'url' => '/api/tree', 'verb' => 'GET'],
        // Suche in der ganzen Quelle (ab 0.22.0)
        ['name' => 'list#search', 'url' => '/api/search', 'verb' => 'GET'],

        // Anmeldung an der oeffentlichen Seite
        ['name' => 'publicAuth#login', 'url' => '/api/public/login', 'verb' => 'POST'],
        ['name' => 'publicAuth#logout', 'url' => '/api/public/logout', 'verb' => 'POST'],
        ['name' => 'publicAuth#status', 'url' => '/api/public/status', 'verb' => 'GET'],

        // Verwaltungs-Einstellungen speichern (nur fuer Administratoren,
        // siehe Hinweis im SettingsController)
        ['name' => 'settings#setAdmin', 'url' => '/settings/admin', 'verb' => 'POST'],
        ['name' => 'settings#uploadBackground', 'url' => '/settings/background', 'verb' => 'POST'],
        ['name' => 'settings#removeBackground', 'url' => '/settings/background/remove', 'verb' => 'POST'],
        ['name' => 'settings#uploadCover', 'url' => '/settings/coverimage', 'verb' => 'POST'],
        ['name' => 'settings#removeCover', 'url' => '/settings/coverimage/remove', 'verb' => 'POST'],

        // Freigaben durch Nutzer (ab 0.12)
        ['name' => 'share#index', 'url' => '/api/shares', 'verb' => 'GET'],
        ['name' => 'share#create', 'url' => '/api/shares', 'verb' => 'POST'],
        ['name' => 'share#adminIndex', 'url' => '/api/admin/shares', 'verb' => 'GET'],
        ['name' => 'share#update', 'url' => '/api/shares/{id}', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'share#delete', 'url' => '/api/shares/{id}/delete', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'share#uploadBackground', 'url' => '/api/shares/{id}/background', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'share#removeBackground', 'url' => '/api/shares/{id}/background/remove', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        // Eigenes Bild fuer Aufnahmen ohne Cover (ab 0.20)
        ['name' => 'share#uploadCover', 'url' => '/api/shares/{id}/coverimage', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'share#removeCover', 'url' => '/api/shares/{id}/coverimage/remove', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        // Mit mir geteilte Ordner (interne Freigaben, ab 0.13)
        ['name' => 'share#incoming', 'url' => '/api/incoming', 'verb' => 'GET'],
        ['name' => 'share#setIncomingDesign', 'url' => '/api/incoming/{id}/design', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        // Personen und Gruppen fuer eine interne Freigabe suchen
        ['name' => 'share#searchMembers', 'url' => '/api/members/search', 'verb' => 'GET'],
        // Bild einer internen Freigabe (nur fuer Empfaenger und Ersteller)
        ['name' => 'asset#incomingBackground', 'url' => '/background/incoming/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
        // Bild einer Freigabe (oeffentlich, solange die Freigabe gilt)
        ['name' => 'asset#shareBackground', 'url' => '/background/share/{token}', 'verb' => 'GET'],
        // Eigenes Cover-Ersatzbild einer Freigabe (ab 0.20), ebenso
        ['name' => 'asset#shareCover', 'url' => '/coverimage/share/{token}', 'verb' => 'GET'],
        ['name' => 'asset#incomingCover', 'url' => '/coverimage/incoming/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],

        // Persoenliche Einstellungen angemeldeter Nutzer
        ['name' => 'userSettings#get', 'url' => '/api/user/settings', 'verb' => 'GET'],
        ['name' => 'userSettings#set', 'url' => '/api/user/settings', 'verb' => 'POST'],
        ['name' => 'userSettings#uploadBackground', 'url' => '/api/user/background', 'verb' => 'POST'],
        ['name' => 'userSettings#removeBackground', 'url' => '/api/user/background/remove', 'verb' => 'POST'],

        // Ausgabe des Hintergrundbilds - auch fuer die oeffentliche Seite
        ['name' => 'asset#background', 'url' => '/background', 'verb' => 'GET'],
        // Cover-Ersatzbild des Administrator-Links (ab 0.21.1)
        ['name' => 'asset#adminCover', 'url' => '/coverimage/admin', 'verb' => 'GET'],
        // Persoenliches Bild, nur fuer den jeweiligen Nutzer
        ['name' => 'asset#userBackground', 'url' => '/background/user', 'verb' => 'GET'],

        // PWA-Bausteine. Beide MUESSEN unterhalb von /apps/audioarchive/
        // ausgeliefert werden, damit der Service Worker genau diesen Bereich
        // abdecken darf - ein Worker kann nie oberhalb seines eigenen Pfades
        // gelten.
        ['name' => 'asset#serviceWorker', 'url' => '/service-worker.js', 'verb' => 'GET'],
        ['name' => 'asset#manifest', 'url' => '/manifest.webmanifest', 'verb' => 'GET'],

        // App-Symbol in der Leistenfarbe (ab 0.16). Bewusst ohne ".png" am
        // Ende: Nextclouds .htaccess reicht Adressen mit Bild-Endung nicht
        // an index.php weiter, sie kaemen nie beim Controller an.
        ['name' => 'asset#icon', 'url' => '/icon/{color}/{name}', 'verb' => 'GET',
            'requirements' => ['color' => '[0-9a-f]{6}', 'name' => '[a-z]+-[0-9]+']],
    ],
];
