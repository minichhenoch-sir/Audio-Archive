<?php
/**
 * Verwaltungs-Einstellungen (Einstellungen -> Verwaltung -> Audio Archive).
 *
 * Laeuft im normalen Nextcloud-Seitengeruest; die Werte kommen ueber den
 * Initial-State-Mechanismus, das Verhalten steckt in js/settings.js.
 */
?>
<div id="audioarchive-settings" class="section">
    <h2>Audio Archive</h2>

    <!-- ============ Quellordner ============ -->
    <h3>Quellordner</h3>
    <p class="settings-hint">
        Ordner mit den Aufnahmen. Unterordner werden so angezeigt, wie sie
        angelegt sind.
    </p>
    <div class="aa-row">
        <input type="text" id="aa-folder" placeholder="/Aufnahmen" readonly>
        <button type="button" id="aa-folder-pick">Auswählen …</button>
    </div>
    <p class="settings-hint" id="aa-folder-owner"></p>

    <!-- ============ Oeffentlicher Zugang ============ -->
    <h3>Öffentlicher Zugang</h3>
    <p class="settings-hint">
        Erlaubt das Zuhören ohne Nextcloud-Konto über einen Link mit
        gemeinsamem Passwort.
    </p>
    <p>
        <input type="checkbox" id="aa-public-enabled" class="checkbox">
        <label for="aa-public-enabled">Öffentlichen Zugang aktivieren</label>
    </p>
    <div class="aa-row">
        <input type="password" id="aa-public-password" autocomplete="new-password"
               placeholder="Passwort setzen oder ändern">
    </div>
    <p class="settings-hint" id="aa-public-password-state"></p>
    <div class="aa-field">
        <label for="aa-public-slug">Wunschname im Link (optional)</label>
        <input type="text" id="aa-public-slug" autocomplete="off" placeholder="z. B. gottesdienste">
        <p class="settings-hint" id="aa-public-slug-preview">
            Leer = zufällige Adresse. Wird der Name geändert, funktioniert der
            bisherige Link nicht mehr – auch nicht in bereits installierten Apps.
        </p>
    </div>
    <div class="aa-row" id="aa-public-url-row" hidden>
        <input type="text" id="aa-public-url" readonly>
        <button type="button" id="aa-public-copy">Link kopieren</button>
    </div>

    <!-- ============ Freigaben durch Nutzer ============ -->
    <h3>Freigaben durch Nutzer</h3>
    <p>
        <input type="checkbox" id="aa-user-shares" class="checkbox">
        <label for="aa-user-shares">Angemeldete Nutzer dürfen Ordner über die App teilen</label>
    </p>
    <p class="settings-hint">
        Zwei Arten: öffentliche Links (auch mit Wunschnamen) und Freigaben an
        Nextcloud-Nutzer und -Gruppen, die nur in dieser App unter „Mit mir
        geteilt" erscheinen. Jede Freigabe hat eigene Einstellungen (Ablauf,
        Aussehen, Funktionen). Abschalten sperrt nur das Anlegen neuer
        Freigaben. Bestehende bleiben gültig, bis sie hier gelöscht werden.
    </p>
    <table class="aa-shares" id="aa-shares" hidden>
        <thead>
            <tr><th>Ordner</th><th>Angelegt von</th><th>Art / Zugang</th><th>Ablauf</th><th></th></tr>
        </thead>
        <tbody></tbody>
    </table>
    <p class="settings-hint" id="aa-shares-state">Lade Freigaben …</p>

    <!-- ============ Darstellung ============ -->
    <h3>Darstellung</h3>
    <div class="aa-field">
        <label for="aa-title">Titel</label>
        <input type="text" id="aa-title" placeholder="Audio Archive">
    </div>
    <div class="aa-field">
        <label for="aa-subtitle">Zusatzzeile (optional)</label>
        <input type="text" id="aa-subtitle">
    </div>
    <div class="aa-field">
        <label>Gestaltung</label>
        <p>
            <input type="radio" name="aa-design" id="aa-design-custom" value="custom" class="radio">
            <label for="aa-design-custom">Eigene Gestaltung</label>
        </p>
        <p class="settings-hint">
            Eigene Farben und eigenes Hintergrundbild (unten).
        </p>
        <p>
            <input type="radio" name="aa-design" id="aa-design-nextcloud" value="nextcloud" class="radio">
            <label for="aa-design-nextcloud">Nextcloud-Gestaltung</label>
        </p>
        <p class="settings-hint">
            Übernimmt Farben, Hintergrund und Schrift von Nextcloud
            (Einstellungen → Verwaltung → Design) und wechselt mit dem
            Hell-/Dunkelmodus. Gilt auch für den öffentlichen Link, dort
            ohne Nextcloud-Kopfleiste.
        </p>
    </div>

    <!-- Farben nur fuer die eigene Gestaltung; bei Nextcloud-Gestaltung abgeblendet -->
    <div id="aa-custom-design">
    <div class="aa-colors">
        <div class="aa-field">
            <label for="aa-accent">Akzentfarbe</label>
            <input type="color" id="aa-accent" value="#b9793f">
        </div>
        <div class="aa-field">
            <label for="aa-bar">Leisten</label>
            <input type="color" id="aa-bar" value="#291c12">
        </div>
        <div class="aa-field">
            <label for="aa-base">Grundton</label>
            <input type="color" id="aa-base" value="#a86a3d">
        </div>
    </div>
    </div>

    <div class="aa-field">
        <label>Hintergrundbild</label>
        <p class="settings-hint">
            Optional. Wird hinter der Oberfläche durchscheinend gezeigt.
            PNG, JPEG oder WebP, höchstens 8 MB. Ohne Bild erscheint ein
            Verlauf aus dem Grundton.
        </p>
        <div class="aa-row">
            <input type="file" id="aa-background-file" accept="image/png,image/jpeg,image/webp">
            <button type="button" id="aa-background-remove" hidden>Entfernen</button>
        </div>
        <p class="settings-hint" id="aa-background-state"></p>
    </div>

    <p>
        <input type="checkbox" id="aa-background-nextcloud" class="checkbox">
        <label for="aa-background-nextcloud">Hintergrundbild auch bei Nextcloud-Gestaltung verwenden</label>
    </p>
    <p class="settings-hint">
        Sonst zeigt die Nextcloud-Gestaltung Nextclouds eigenen Hintergrund.
    </p>

    <p>
        <input type="checkbox" id="aa-user-customization" class="checkbox">
        <label for="aa-user-customization">Nutzer dürfen Gestaltung und Hintergrundbild selbst wählen</label>
    </p>
    <p class="settings-hint">
        Angemeldete Nutzer finden das in der App über das Zahnrad. Es gilt nur
        für ihre eigene Ansicht, nicht für den öffentlichen Link.
    </p>

    <!-- ============ Funktionen ============ -->
    <h3>Funktionen</h3>
    <p>
        <input type="checkbox" id="aa-feature-offline" class="checkbox">
        <label for="aa-feature-offline">Offline verfügbar machen erlauben</label>
    </p>
    <p class="settings-hint">
        Aufnahmen werden in der App gespeichert und bleiben ohne Verbindung
        hörbar. Sie landen nicht im Download-Ordner des Geräts.
    </p>
    <p>
        <input type="checkbox" id="aa-feature-download" class="checkbox">
        <label for="aa-feature-download">Herunterladen als Datei erlauben</label>
    </p>
    <p class="settings-hint">
        Blendet je Aufnahme einen Knopf zum Speichern der Datei ein. Die Datei
        lässt sich danach frei weitergeben.
    </p>

    <!-- ============ Beta-Hinweis ============ -->
    <h3>Beta-Hinweis</h3>
    <p class="settings-hint">
        Kennzeichnet die App als in Entwicklung: ein „Beta"-Zeichen neben dem
        Titel und ein Hinweisstreifen über dem Pfad. Nur hier ein- und
        ausschaltbar – eingeschaltet erscheint er überall: in der App, auf dem
        öffentlichen Link und auf allen Links der Nutzer.
    </p>
    <p>
        <input type="checkbox" id="aa-beta-enabled" class="checkbox">
        <label for="aa-beta-enabled">Beta-Hinweis anzeigen</label>
    </p>
    <div class="aa-field">
        <label for="aa-beta-text">Text im Hinweisstreifen</label>
        <input type="text" id="aa-beta-text"
               placeholder="Diese App wird noch entwickelt. Rückmeldungen sind willkommen.">
    </div>
    <div class="aa-field">
        <label for="aa-beta-link-url">Link-Adresse (optional)</label>
        <input type="text" id="aa-beta-link-url" placeholder="https://…">
    </div>
    <div class="aa-field">
        <label for="aa-beta-link-label">Link-Beschriftung</label>
        <input type="text" id="aa-beta-link-label" placeholder="Rückmeldung geben">
        <p class="settings-hint">
            Wird anstelle der Adresse angezeigt – so bleibt der Hinweis auch
            auf dem Telefon kurz.
        </p>
    </div>

    <p class="aa-actions">
        <button type="button" id="aa-save" class="primary">Speichern</button>
        <span id="aa-status" class="aa-status"></span>
    </p>
</div>
