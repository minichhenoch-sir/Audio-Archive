<?php
/**
 * Verwaltungs-Einstellungen (Einstellungen -> Verwaltung -> Audio Archive).
 *
 * Laeuft im normalen Nextcloud-Seitengeruest; die Werte kommen ueber den
 * Initial-State-Mechanismus, das Verhalten steckt in js/settings.js.
 */

// "?" neben einer Ueberschrift: fuehrt zum passenden Abschnitt der
// Anleitung fuer Administratoren (ab 1.0.2, Sprungmarken in
// templates/manual/admin.php)
$help = static function (string $anchor) use ($_): void {
    ?><a class="aa-help-link" href="<?php p(($_['manualUrl'] ?? '') . '#' . $anchor); ?>" target="_blank" rel="noopener"
         title="Erklärung in der Anleitung" aria-label="Erklärung in der Anleitung">?</a><?php
};
?>
<div id="audioarchive-settings" class="section">
    <h2>Audio Archive
        <span class="aa-version">Version <?php p($_['version'] ?? ''); ?></span>
    </h2>
    <!-- Anleitung (ab 1.0.2) -->
    <p class="aa-manual-links">
        <a class="button" href="<?php p($_['manualUrl'] ?? ''); ?>" target="_blank" rel="noopener">📖 Anleitung für Administratoren</a>
        <a class="button" href="<?php p($_['manualPdf']['url'] ?? ''); ?>"<?php if (!empty($_['manualPdf']['file'])) { ?> download<?php } else { ?> target="_blank" rel="noopener"<?php } ?>>⬇ Als PDF</a>
        <a class="aa-manual-user" href="<?php p($_['userManualUrl'] ?? ''); ?>" target="_blank" rel="noopener">Anleitung für Hörer und Nutzer</a>
    </p>

    <!-- ============ Freigaben durch Nutzer ============ -->
    <h3>Freigaben durch Nutzer <?php $help('freigaben'); ?></h3>
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
    <p class="settings-hint">
        Links für Hörer ohne Nextcloud-Konto legt man in der App an: Ordner
        öffnen → „Diesen Ordner teilen“ → „Neuer Link“ – dort auch mit
        Passwort, Wunschnamen und eigener Gestaltung. Der frühere Link aus
        „Öffentlicher Zugang“ (bis Version 1.1) steht seit 1.2.0 als ganz
        normaler Link in der Liste unten – gleiche Adresse, gleiches Passwort.
        Administratoren können alle Links in der App bearbeiten.
    </p>
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
            für alle Links mit Passwort. Wird das Passwort eines Links geändert,
            muss es dort neu eingegeben werden.
        </p>
    </div>
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

    <!-- ============ Darstellung ============
         Ab 1.3.0 legt jede Freigabe ihr Aussehen selbst fest; hier steht
         nur noch, ob Nutzer ihre eigene Ansicht gestalten duerfen. -->
    <h3>Darstellung <?php $help('darstellung'); ?></h3>
    <p class="settings-hint">
        Titel, Gestaltung, Farben, Hintergrundbild und Text über den Aufnahmen
        legt jede Freigabe selbst fest (in der App: Ordner → „Diesen Ordner
        teilen“). Neue Freigaben beginnen mit dem Standard-Aussehen.
    </p>
    <p>
        <input type="checkbox" id="aa-user-customization" class="checkbox">
        <label for="aa-user-customization">Nutzer dürfen Gestaltung und Hintergrundbild selbst wählen</label>
    </p>
    <p class="settings-hint">
        Angemeldete Nutzer finden das in der App über das Zahnrad. Es gilt nur
        für ihre eigene Ansicht, nicht für Links.
    </p>
    <p>
        <input type="checkbox" id="aa-notice-closable" class="checkbox">
        <label for="aa-notice-closable">Text über den Aufnahmen lässt sich mit ✕ ausblenden</label>
    </p>
    <p class="settings-hint">
        Vorgabe für alle Texte über den Aufnahmen – der eigenen Ansicht der
        Nutzer und der Freigaben. Eingeschaltet kann jeder den Text auf seinem
        Gerät mit ✕ schließen; er erscheint wieder, sobald der Text geändert
        wird. Jede Freigabe kann das auch selbst festlegen.
    </p>

    <!-- ============ Funktionen ============ -->
    <h3>Funktionen <?php $help('funktionen'); ?></h3>
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
    <h3>Hilfe und Kontakt <?php $help('hilfe-kontakt'); ?></h3>
    <p class="settings-hint">
        Oben in der App und auf allen Links steht ein kleiner Knopf (i)
        „Hilfe“. Er führt immer zur Anleitung für Hörer und Nutzer. Ist hier
        eine Gruppe oder Adresse eingetragen, heißt er „Hilfe und Kontakt“:
        Hörer und Nutzer können darüber dann auch eine E-Mail schreiben oder
        direkt eine Nachricht senden – auch aus der Anleitung „App installieren“.
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

    <p class="aa-actions">
        <button type="button" id="aa-save" class="primary">Speichern</button>
        <span id="aa-status" class="aa-status"></span>
    </p>
</div>

<!-- Kommentare einsehen und exportieren (ab 0.35.0, Vikunja #5) -->
<div class="section aa-comments-section">
    <h2>Audio Archive – Kommentare <?php $help('kommentare'); ?></h2>
    <p class="settings-hint">
        Alle Kommentare und Bewertungen zu Aufnahmen in allen Freigaben (nur für
        Administratoren; Nutzer sehen unter „Persönlich“ ihre eigenen und die
        über ihre Freigaben). ▶ bzw. ein Klick auf die Zeile öffnet die
        Aufnahme in Audio Archive. Als Excel-Datei herunterladen, in den
        eigenen Dateien ablegen und mit Office öffnen, als CSV speichern oder
        drucken (im Druckfenster „Als PDF sichern“ ergibt eine PDF-Datei).
    </p>
    <div class="aa-comments-overview" data-scope="all"></div>
</div>
