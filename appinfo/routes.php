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

        // Anmeldung an der oeffentlichen Seite
        ['name' => 'publicAuth#login', 'url' => '/api/public/login', 'verb' => 'POST'],
        ['name' => 'publicAuth#logout', 'url' => '/api/public/logout', 'verb' => 'POST'],
        ['name' => 'publicAuth#status', 'url' => '/api/public/status', 'verb' => 'GET'],

        // Verwaltungs-Einstellungen speichern (nur fuer Administratoren,
        // siehe Hinweis im SettingsController)
        ['name' => 'settings#setAdmin', 'url' => '/settings/admin', 'verb' => 'POST'],
        ['name' => 'settings#uploadBackground', 'url' => '/settings/background', 'verb' => 'POST'],
        ['name' => 'settings#removeBackground', 'url' => '/settings/background/remove', 'verb' => 'POST'],

        // Ausgabe des Hintergrundbilds - auch fuer die oeffentliche Seite
        ['name' => 'asset#background', 'url' => '/background', 'verb' => 'GET'],

        // PWA-Bausteine. Beide MUESSEN unterhalb von /apps/audioarchive/
        // ausgeliefert werden, damit der Service Worker genau diesen Bereich
        // abdecken darf - ein Worker kann nie oberhalb seines eigenen Pfades
        // gelten.
        ['name' => 'asset#serviceWorker', 'url' => '/service-worker.js', 'verb' => 'GET'],
        ['name' => 'asset#manifest', 'url' => '/manifest.webmanifest', 'verb' => 'GET'],
    ],
];
