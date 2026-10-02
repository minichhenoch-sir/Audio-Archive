<?php
/**
 * Verwaltungs-Einstellungen (Einstellungen -> Verwaltung -> Audio Archive).
 *
 * Laeuft im normalen Nextcloud-Seitengeruest; die Werte kommen ueber den
 * Initial-State-Mechanismus, das Verhalten steckt in js/settings.js.
 */
?>
<div id="audioarchive-settings" class="section">
    <h2>Audio Archive
        <span class="aa-version">Version <?php p($_['version'] ?? ''); ?></span>
    </h2>

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
        <input type="text" id="aa-public-slug" autocomplete="off" placeholder="z. B. vortraege">
        <p class="settings-hint" id="aa-public-slug-preview">
            Leer = zufällige Adresse. Wird der Name geändert, funktioniert der
            bisherige Link nicht mehr – auch nicht in bereits installierten Apps.
        </p>
    </div>
    <div class="aa-field">
        <label for="aa-remember-days">Angemeldet bleiben</label>
        <select id="aa-remember-days">
            <option value="0">Aus – Passwort bei jedem Öffnen</option>
            <option value="7">7 Tage</option>
            <option value="15">15 Tage</option>
            <option value="30">30 Tage</option>
            <option value="90">90 Tage</option>
        </select>
        <p class="settings-hint">
            Wie lange ein Gerät nach richtiger Passworteingabe angemeldet bleibt –
            für diesen Link und alle Freigaben mit Passwort. Wird das Passwort
            geändert, muss es überall neu eingegeben werden.
        </p>
    </div>
    <div class="aa-row" id="aa-public-url-row" hidden>
        <input type="text" id="aa-public-url" readonly>
        <button type="button" id="aa-public-copy">Link kopieren</button>
    </div>
    <div class="aa-field">
        <label>Bild bei Aufnahmen ohne Cover</label>
        <p class="settings-hint">
            Gilt für diesen Link. Die eigenen Ansichten der Nutzer zeigen
            weiterhin das Standard-Zeichen; Freigaben wählen ihr Bild selbst.
        </p>
        <div class="aa-cover-choices" id="aa-cover-choices" role="radiogroup" aria-label="Bild bei Aufnahmen ohne Cover"></div>
        <div class="aa-row" id="aa-cover-upload-row" hidden>
            <input type="file" id="aa-cover-file" accept="image/png,image/jpeg,image/webp">
            <button type="button" id="aa-cover-remove" hidden>Bild entfernen</button>
        </div>
        <p class="settings-hint" id="aa-cover-state"></p>
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
        geteilt“ erscheinen. Jede Freigabe hat eigene Einstellungen (Ablauf,
        Aussehen, Funktionen). Abschalten sperrt nur das Anlegen neuer
        Freigaben. Bestehende bleiben gültig, bis sie hier gelöscht werden.
    </p>
    <table class="aa-shares" id="aa-shares" hidden>
        <thead>
            <tr><th>Ordner</th><th>Angelegt von</th><th>Geteilt mit</th><th>Ablauf</th><th></th></tr>
        </thead>
        <tbody></tbody>
    </table>
    <p class="settings-hint" id="aa-shares-state">Freigaben werden geladen …</p>

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
        <label for="aa-shared-label">Name des gemeinsamen Ordners</label>
        <input type="text" id="aa-shared-label" maxlength="60" placeholder="Gemeinsame Aufnahmen">
        <p class="settings-hint">
            So heißt der Quellordner oben im Pfad und in der Seitenleiste – für
            angemeldete Nutzer und auf dem öffentlichen Link. Leer = „Gemeinsame
            Aufnahmen“ bzw. auf dem Link „Aufnahmen“.
        </p>
    </div>
    <div class="aa-field">
        <label>Gestaltung (Vorgabe für alle)</label>
        <!-- Karten mit Vorschau, aufgebaut von js/settings.js -->
        <div id="aa-design-cards"></div>
        <p class="settings-hint">
            <strong>Klassisch</strong> übernimmt Farben, Hintergrund und Schrift
            von Nextcloud (Einstellungen → Verwaltung → Design) und wechselt mit
            dem Hell-/Dunkelmodus. <strong>Modern</strong> ist rund mit
            Glaseffekt in wählbaren Farben. <strong>Vom Administrator</strong>
            ist die frei einstellbare Gestaltung weiter unten – wählbar, sobald
            sie angeboten wird. Gilt auch für den öffentlichen Link, dort ohne
            Nextcloud-Kopfleiste. Nutzer können im Player über das Zahnrad eine
            andere Gestaltung wählen (sofern unten erlaubt), auch
            „Benutzerdefiniert“.
        </p>
    </div>

    <!-- Farben fuer "Modern"; bei anderer Vorgabe abgeblendet -->
    <div id="aa-custom-design">
    <h4>Farben für „Modern“</h4>
    <div id="aa-palettes"></div>
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

    <h4>Vom Administrator bereitgestellte Gestaltung</h4>
    <p>
        <input type="checkbox" id="aa-admin-style-enabled" class="checkbox">
        <label for="aa-admin-style-enabled">Anbieten – als Vorgabe und zur Auswahl für alle Nutzer und Freigaben</label>
    </p>
    <p class="settings-hint">
        Farben, Ecken, Glaseffekt, Schrift und Hintergrund frei festlegen.
        Wer sie gewählt hat, sieht Änderungen hier nach dem Speichern
        automatisch. Wird sie nicht mehr angeboten, gilt dort wieder die
        Vorgabe.
    </p>
    <div id="aa-admin-style"></div>

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
        <input type="checkbox" id="aa-feature-favorites" class="checkbox">
        <label for="aa-feature-favorites">Favoriten (Stern) anbieten</label>
    </p>
    <p class="settings-hint">
        Angemeldete Nutzer markieren mit echten Nextcloud-Favoriten (der Stern
        erscheint auch in „Dateien“, soweit die Datei in ihren eigenen Dateien
        liegt). Hörer über einen Link speichern Favoriten auf ihrem Gerät. Jeder
        angemeldete Nutzer kann den Stern für sich unter „Darstellung“ abschalten.
    </p>
    <div class="aa-field">
        <label for="aa-sort-default">Sortierung der Liste (Vorgabe)</label>
        <select id="aa-sort-default">
            <option value="name">Name (A–Z)</option>
            <option value="newest">Neueste zuerst</option>
        </select>
        <p class="settings-hint">
            Gilt für Ordner und Aufnahmen. Jeder Hörer kann in der App
            umschalten; seine Wahl merkt sich sein Gerät.
        </p>
    </div>
    <!-- Anzahl der Aufnahmen (ab 0.30.0, Vikunja #42) -->
    <p>
        <input type="checkbox" id="aa-show-folder-count" class="checkbox">
        <label for="aa-show-folder-count">Anzahl der Aufnahmen bei Ordnern anzeigen</label>
    </p>
    <p class="settings-hint">
        Zeigt neben jedem Ordner, wie viele Aufnahmen er enthält (z.&nbsp;B.
        „12 Aufnahmen“). Gilt für alle, auch für geteilte Links.
    </p>
    <!-- Kommentare (ab 0.29.0, Vikunja #5) -->
    <p>
        <input type="checkbox" id="aa-feature-comments" class="checkbox">
        <label for="aa-feature-comments">Kommentare zu Aufnahmen erlauben</label>
    </p>
    <p class="settings-hint">
        Im großen Player gibt es dann den Knopf „Kommentare“: Hörer können
        Anmerkungen, Änderungswünsche oder Fehler zu einer Aufnahme schreiben.
        Es sind echte Nextcloud-Kommentare – wer die Datei in „Dateien“ sieht,
        findet sie in der Seitenleiste unter „Kommentare“ und kann dort
        antworten. In der App sieht jeder nur seine eigenen. Bei geteilten Links
        entscheidet zusätzlich der Link, angemeldete Nutzer können es unter
        „Darstellung“ für sich abschalten.
    </p>
    <div id="aa-comments-options">
        <p>
            <input type="checkbox" id="aa-feature-rating" class="checkbox">
            <label for="aa-feature-rating">Bewertung mit 1–5 Sternen</label>
        </p>
        <p>
            <input type="checkbox" id="aa-public-comments" class="checkbox">
            <label for="aa-public-comments">Auch Hörer über den öffentlichen Link (oben) dürfen kommentieren</label>
        </p>
        <div class="aa-field">
            <label for="aa-comment-group">Bei neuen Kommentaren benachrichtigen</label>
            <select id="aa-comment-group"></select>
            <p class="settings-hint">
                Alle Mitglieder dieser Nextcloud-Gruppe (z. B. Tontechnik) bekommen
                eine Benachrichtigung mit dem Text des Kommentars.
            </p>
        </div>
    </div>
    <div class="aa-field">
        <label for="aa-search-scope">Suchbereich</label>
        <select id="aa-search-scope">
            <option value="folder">Geöffneter Ordner mit Unterordnern</option>
            <option value="all">Alles (ganzer Bereich)</option>
        </select>
        <p class="settings-hint">
            „Geöffneter Ordner“: Die Suche findet nur, was im gerade geöffneten
            Ordner und seinen Unterordnern liegt. Ganz oben wird alles durchsucht.
        </p>
    </div>
    <div class="aa-field">
        <label for="aa-repeat-default">Wiederholen (Vorgabe)</label>
        <select id="aa-repeat-default">
            <option value="next">Danach nächster Ordner</option>
            <option value="off">Aus</option>
            <option value="folder">Ordner wiederholen</option>
            <option value="one">Titel wiederholen</option>
        </select>
        <p class="settings-hint">
            So steht der Wiederholen-Knopf im Player am Anfang. Für einen
            geteilten Link lässt sich das beim Link anders einstellen, angemeldete
            Nutzer können es unter „Darstellung“ für sich ändern. Tippt jemand
            selbst auf den Knopf, merkt sich das sein Gerät.
        </p>
    </div>
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
    <p>
        <input type="checkbox" id="aa-feature-folder-download" class="checkbox">
        <label for="aa-feature-folder-download">Unterordner als ZIP herunterladen erlauben</label>
    </p>
    <p class="settings-hint">
        Zeigt in jedem Unterordner „Ordner herunterladen (ZIP)“ – mit allen
        Aufnahmen, Unterordnern und Ordnerbildern. Die oberste Ebene lässt sich
        nie als Ganzes herunterladen. Freigaben haben dafür einen eigenen
        Schalter; die eigenen Dateien eines Nutzers gehen immer.
    </p>
    <p>
        <input type="checkbox" id="aa-transcode" class="checkbox">
        <label for="aa-transcode">Nicht abspielbare Formate beim Abspielen in MP3 umwandeln</label>
    </p>
    <p class="settings-hint">
        Manche Formate kann nicht jedes Gerät abspielen – AIFF zum Beispiel nur
        Safari (iPhone, iPad, Mac). Ist dieser Schalter an, wandelt der Server
        solche Aufnahmen beim ersten Abspielen in MP3 um. Die Originaldatei
        bleibt unverändert; die umgewandelte Fassung wird zwischengespeichert
        (höchstens 2 GB, älteste werden entfernt). Beim ersten Abspielen dauert
        es je nach Länge etwas. Braucht das Programm ffmpeg auf dem Server.
    </p>
    <p class="settings-hint" id="aa-transcode-state"></p>

    <!-- ============ Beta-Hinweis ============ -->
    <h3>Beta-Hinweis</h3>
    <p class="settings-hint">
        Kennzeichnet die App als in Entwicklung: ein „Beta“-Zeichen neben dem
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
