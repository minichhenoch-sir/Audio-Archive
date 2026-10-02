<?php
/**
 * Persoenliche Einstellungen (Einstellungen -> Persoenlich -> Audio Archive),
 * ab 0.26.0. Verhalten in js/settings-personal.js.
 */
?>
<div id="audioarchive-settings" class="section aa-personal">
    <h2>Audio Archive – Meine Freigaben</h2>
    <p class="settings-hint">
        Hier stehen alle Ordner, die du über die App geteilt hast: an Personen
        und Gruppen aus Nextcloud (👤 Person, 👥 Gruppe) oder über einen
        öffentlichen Link (🔗). Zum Anlegen und Bearbeiten öffnet sich die App
        – dort gibt es alle Einstellungen.
    </p>
    <p id="aa-personal-disabled" class="settings-hint" hidden>
        Neue Freigaben anzulegen hat der Administrator abgeschaltet. Bestehende
        Freigaben gelten weiter, bis du sie löschst.
    </p>
    <p>
        <a class="button" id="aa-personal-new" href="#">+ Neue Freigabe in der App</a>
    </p>
    <p class="settings-hint" id="aa-personal-new-hint" hidden>
        In der App den gewünschten Ordner öffnen und auf „Teilen“ tippen.
    </p>
    <table class="aa-shares" id="aa-shares" hidden>
        <thead>
            <tr><th>Ordner</th><th>Geteilt mit</th><th>Ablauf</th><th></th></tr>
        </thead>
        <tbody></tbody>
    </table>
    <p class="settings-hint" id="aa-shares-state">Freigaben werden geladen …</p>
</div>

<!-- Kommentare einsehen und exportieren (ab 0.35.0, Vikunja #5) -->
<div class="section aa-comments-section">
    <h2>Audio Archive – Kommentare</h2>
    <p class="settings-hint">
        Kommentare und Bewertungen zu Aufnahmen in den Ordnern, die du geteilt
        hast – auch die, die jemand über deinen Link geschrieben hat. Wer in
        der Gruppe ist, die bei neuen Kommentaren benachrichtigt wird, kann
        hier auch alle Kommentare ansehen. Zum Weiterverarbeiten als CSV-Datei
        herunterladen (öffnet sich in Excel oder LibreOffice) oder drucken – im
        Druckfenster „Als PDF sichern“ wählen, um eine PDF-Datei zu erhalten.
    </p>
    <div class="aa-comments-overview" data-scope="mine" data-switch="1"></div>
</div>
