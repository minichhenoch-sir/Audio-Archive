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
    <div class="aa-row" id="aa-public-url-row" hidden>
        <input type="text" id="aa-public-url" readonly>
        <button type="button" id="aa-public-copy">Link kopieren</button>
    </div>

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

    <p class="aa-actions">
        <button type="button" id="aa-save" class="primary">Speichern</button>
        <span id="aa-status" class="aa-status"></span>
    </p>
</div>
