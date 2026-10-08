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

    <!-- ============ Quellen (ab 0.32.0 mehrere, Vikunja #8) ============ -->
    <h3>Quellen</h3>
    <p class="settings-hint">
        Ordner mit den Aufnahmen. Unterordner werden so angezeigt, wie sie
        angelegt sind. Der erste ist der gemeinsame Ordner mit dem öffentlichen
        Zugang weiter unten.
    </p>
    <div class="aa-row">
        <input type="text" id="aa-folder" placeholder="/Aufnahmen" readonly>
        <button type="button" id="aa-folder-pick">Auswählen …</button>
    </div>
    <p class="settings-hint" id="aa-folder-owner"></p>

    <h4 class="aa-subheading">Weitere Quellen</h4>
    <p class="settings-hint">
        Jede weitere Quelle erscheint in der App für alle angemeldeten Nutzer
        als eigener Eintrag oben in der Seitenleiste (z.&nbsp;B.
        „Hörbücher“). <strong>Links</strong> dafür – mit eigenem Passwort,
        Wunschnamen, „Angemeldet bleiben“ und eigener Gestaltung – legt man in
        der App an: Quelle öffnen → „Diesen Ordner teilen“ → „Neuer Link“. Alle
        Links stehen unten unter „Freigaben durch Nutzer“.
    </p>
    <div id="aa-extra-sources" class="aa-extra-sources"></div>
    <p>
        <button type="button" id="aa-extra-add">+ Weitere Quelle</button>
    </p>
    <p class="settings-hint">
        Entfernen nimmt die Quelle aus der Seitenleiste. Bereits angelegte
        Links bleiben gültig, bis sie unten gelöscht werden.
    </p>

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
    <!-- Wer darf teilen (ab 0.32.0, Vikunja #8) -->
    <div class="aa-field">
        <label>Wer darf teilen?</label>
        <div id="aa-share-groups" class="aa-share-groups"></div>
        <p class="settings-hint">
            Nur Mitglieder der angehakten Gruppen sehen den Knopf „Teilen“ und
            dürfen Links anlegen oder weiterteilen. Keine Gruppe angehakt =
            alle angemeldeten Nutzer. Wer über einen Link ohne Nextcloud-Konto
            zuhört, kann nie teilen.
        </p>
    </div>
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
            <option value="random">Zufällig (Aufnahmen gemischt)</option>
        </select>
        <p class="settings-hint">
            Gilt für Ordner und Aufnahmen. Jeder Hörer kann in der App
            umschalten und mit dem Pfeil daneben die Richtung wechseln; seine
            Wahl merkt sich sein Gerät.
        </p>
    </div>
    <!-- Anzeige in der Liste (ab 0.33.0, Vikunja #50; Anzahl ab 0.30.0, #42) -->
    <h4 class="aa-subheading">Anzeige in der Liste</h4>
    <p class="settings-hint">
        Was klein neben den Namen steht. Gilt für alle, auch für geteilte Links.
    </p>
    <p><strong>Bei Ordnern:</strong></p>
    <p>
        <input type="checkbox" id="aa-show-folder-date" class="checkbox">
        <label for="aa-show-folder-date">Datum (wann der Ordner hinzugekommen ist)</label>
    </p>
    <p>
        <input type="checkbox" id="aa-show-folder-count" class="checkbox">
        <label for="aa-show-folder-count">Anzahl der Aufnahmen (z.&nbsp;B. „12 Aufnahmen“)</label>
    </p>
    <p><strong>Bei Aufnahmen:</strong></p>
    <p>
        <input type="checkbox" id="aa-show-track-duration" class="checkbox">
        <label for="aa-show-track-duration">Länge (z.&nbsp;B. „48:12“)</label>
    </p>
    <p>
        <input type="checkbox" id="aa-show-track-date" class="checkbox">
        <label for="aa-show-track-date">Datum (wann die Datei zuletzt geändert wurde)</label>
    </p>

    <!-- Namen (ab 0.33.0, Vikunja #44) -->
    <h4 class="aa-subheading">Namen</h4>
    <p class="settings-hint">
        In der Liste und im Ordnerbaum heißen Ordner und Aufnahmen genau so
        wie im Ordner.
    </p>
    <p>
        <input type="checkbox" id="aa-pretty-folder-names" class="checkbox">
        <label for="aa-pretty-folder-names">Ordnernamen lesbar umschreiben</label>
    </p>
    <p class="settings-hint">
        Unterstriche werden zu Leerzeichen, Datumsangaben werden ausgeschrieben:
        „2026_08“ wird „August 2026“, „2026-09-21 Konzert“ wird „Konzert,
        21. September 2026“. Die Ordner selbst bleiben unverändert.
    </p>
    <p>
        <input type="checkbox" id="aa-title-from-tags" class="checkbox">
        <label for="aa-title-from-tags">Im Player den Titel aus der Datei zeigen</label>
    </p>
    <p class="settings-hint">
        Vorgabe: Der Player zeigt den Titel, der in der Audiodatei selbst
        gespeichert ist – hat sie keinen, den Dateinamen. Ohne Haken immer den
        Dateinamen. Gilt auch für Sperrbildschirm und Auto.
    </p>

    <!-- Sternfarbe (ab 0.33.0, Vikunja #3) -->
    <div class="aa-field">
        <label for="aa-star-color">Farbe des Favoriten-Sterns</label>
        <select id="aa-star-color">
            <option value="accent">Wie die Gestaltung (Akzentfarbe)</option>
            <option value="yellow">Gelb</option>
            <option value="text">Wie die Schrift</option>
        </select>
        <p class="settings-hint">
            Ein Favorit hat einen ausgefüllten Stern, sonst ist nur der Umriss
            zu sehen – das bleibt bei jeder Farbe erkennbar.
        </p>
    </div>
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
    <!-- ab 0.39.0, Vikunja #53 -->
    <p>
        <input type="checkbox" id="aa-offline-open" class="checkbox">
        <label for="aa-offline-open">Offline ohne Passwort öffnen</label>
    </p>
    <p class="settings-hint">
        Gespeicherte Aufnahmen öffnen sich ohne Verbindung direkt – ohne
        Passwort und ohne eigene Offline-PIN. Wer sich in der App ausdrücklich
        abmeldet, braucht danach wieder das Passwort. Ausgeschaltet: offline wie
        bisher mit dem Passwort des Links bzw. einer PIN für angemeldete Nutzer.
        Hinweis: Wer das Gerät in der Hand hat, kann die gespeicherten Aufnahmen
        dann ohne Passwort anhören.
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

    <!-- ============ Hilfe und Kontakt (ab 0.37.0, Vikunja #27) ============ -->
    <h3>Hilfe und Kontakt</h3>
    <p class="settings-hint">
        Zeigt oben in der App und auf allen Links einen kleinen Knopf (i)
        „Hilfe und Kontakt“ – auch in der Anleitung „App installieren“.
        Hörer und Nutzer können darüber eine E-Mail schreiben oder direkt eine
        Nachricht senden. Ohne Gruppe und ohne Adresse erscheint kein Knopf.
    </p>
    <div class="aa-field">
        <label for="aa-help-group">Nachrichten aus dem Hilfe-Fenster an</label>
        <select id="aa-help-group"></select>
        <p class="settings-hint">
            Alle Mitglieder dieser Nextcloud-Gruppe bekommen die Nachricht als
            Nextcloud-Benachrichtigung – mit Name, Antwort-Adresse und wo der
            Absender gerade war (Seite, Ordner, Aufnahme, Gerät). Per E-Mail kommt
            sie, wenn das in den persönlichen Einstellungen unter
            „Benachrichtigungen“ eingeschaltet ist.
        </p>
    </div>
    <div class="aa-field">
        <label for="aa-help-email">E-Mail-Adresse für „Per E-Mail schreiben“ (optional)</label>
        <input type="text" id="aa-help-email" placeholder="z. B. technik@example.org" autocomplete="off">
        <p class="settings-hint">
            Öffnet beim Hörer das Mailprogramm mit dieser Adresse. Die Adresse ist
            damit für alle sichtbar, die die App oder einen Link öffnen. Leer =
            nur das Textfenster.
        </p>
    </div>

    <!-- ============ Text ueber den Aufnahmen ============ -->
    <h3>Text über den Aufnahmen</h3>
    <p class="settings-hint">
        Ein frei formulierbarer Text über der Liste, z.&nbsp;B. ein Gruß, ein
        Zitat oder ein Hinweis. Er erscheint ohne Überschrift. Das hier ist
        die Vorgabe: Angemeldete Nutzer können für ihre eigene Ansicht (Zahnrad
        → Texte) und beim Teilen für jeden Link einen eigenen Text setzen.
    </p>
    <p>
        <input type="checkbox" id="aa-notice-enabled" class="checkbox">
        <label for="aa-notice-enabled">Diesen Text anzeigen</label>
    </p>
    <div class="aa-field">
        <span class="aa-field-label">Text</span>
        <textarea id="aa-beta-text" aria-label="Text über den Aufnahmen"
                  placeholder="z. B. Herzlich willkommen!"></textarea>
        <p class="settings-hint">
            Mit der Leiste lassen sich Schriftart, Größe, Farbe, Ausrichtung,
            Listen und Links festlegen – erst Text markieren, dann wählen.
        </p>
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

<!-- Kommentare einsehen und exportieren (ab 0.35.0, Vikunja #5) -->
<div class="section aa-comments-section">
    <h2>Audio Archive – Kommentare</h2>
    <p class="settings-hint">
        Alle Kommentare und Bewertungen zu Aufnahmen: im gemeinsamen Ordner,
        in den weiteren Quellen und in allen Freigaben der Nutzer (nur für
        Administratoren; Nutzer sehen unter „Persönlich“ ihre eigenen und die
        über ihre Freigaben). ▶ bzw. ein Klick auf die Zeile öffnet die
        Aufnahme in Audio Archive. Als Excel-Datei herunterladen, in den
        eigenen Dateien ablegen und mit Office öffnen, als CSV speichern oder
        drucken (im Druckfenster „Als PDF sichern“ ergibt eine PDF-Datei).
    </p>
    <div class="aa-comments-overview" data-scope="all"></div>
</div>
