<?php
/**
 * Anleitung fuer Hoerer und Nutzer (ab 1.0.2). Eingebunden von manual.php,
 * dort stehen $escape und $figure bereit.
 *
 * Beim Aendern: Texte der Oberflaeche woertlich uebernehmen (in „…“), und
 * Funktionen, die der Administrator abschalten kann, mit $opt('<schalter>')
 * kennzeichnen (Schalter aus ManualContent::features()).
 *
 * Passt sich selbst an (ab 1.0.3): Auf der Seite steht bei solchen
 * Funktionen, ob sie bei dieser Nextcloud gerade an oder aus sind, und
 * einige Saetze folgen den Einstellungen ($on). Die mitgelieferte PDF ist die
 * allgemeine Fassung ($_['features'] === null, „je nach Einrichtung“).
 */
$features = $_['features'];
/** Kennzeichen hinter einer abschaltbaren Funktion */
$opt = static function (string $key) use ($features): string {
    if ($features === null || !array_key_exists($key, $features)) {
        return '<span class="m-tag" title="Hängt von den Einstellungen des Administrators ab">je nach Einrichtung</span>';
    }
    return $features[$key]
        ? '<span class="m-on" title="So hat es der Administrator eingestellt">bei euch eingeschaltet</span>'
        : '<span class="m-off" title="So hat es der Administrator eingestellt">bei euch ausgeschaltet</span>';
};
/** Wert eines Schalters; null in der allgemeinen Fassung */
$on = static fn (string $key) => $features === null ? null : ($features[$key] ?? null);
?>
<nav class="m-toc" aria-label="Inhalt">
  <h2>Inhalt</h2>
  <ol>
    <li><a href="#ueberblick">Was ist Audio Archive?</a></li>
    <li><a href="#oeffnen">Öffnen und anmelden</a></li>
    <li><a href="#installieren">Als App installieren</a></li>
    <li><a href="#finden">Aufnahmen finden</a></li>
    <li><a href="#abspielen">Abspielen</a></li>
    <li><a href="#favoriten">Favoriten</a></li>
    <li><a href="#offline">Offline hören</a></li>
    <li><a href="#herunterladen">Herunterladen</a></li>
    <li><a href="#kommentare">Kommentare und Bewertungen</a></li>
    <li><a href="#teilen">Ordner teilen</a></li>
    <li><a href="#darstellung">Eigene Darstellung</a></li>
    <li><a href="#hilfe">Hilfe und Kontakt</a></li>
    <li><a href="#fragen">Häufige Fragen</a></li>
  </ol>
</nav>

<section id="ueberblick" class="m-section">
  <h2><span class="m-num">1</span>Was ist Audio Archive?</h2>
  <p>Audio Archive ist ein Player für Aufnahmen – zum Beispiel Predigten, Vorträge,
  Konzerte oder Podcasts –, die in einer Nextcloud liegen. Du siehst die Ordner so,
  wie sie angelegt sind, und kannst jede Aufnahme direkt abspielen: im Browser
  oder als App auf Telefon, Tablet und Rechner.</p>
  <ul>
    <li>Die Wiedergabe läuft im Hintergrund weiter, auch bei gesperrtem Bildschirm.</li>
    <li>Die App merkt sich, wo du aufgehört hast.</li>
    <li>Ganze Ordner lassen sich für unterwegs offline speichern.</li>
  </ul>
  <?php if ($features === null) { ?>
  <p class="m-note">Einige Funktionen schaltet der Administrator ein oder aus. Sie sind
  in dieser Anleitung mit <?php echo $opt(''); ?> gekennzeichnet. Fehlt bei dir ein Knopf,
  ist die Funktion bei euch nicht eingeschaltet.</p>
  <?php } else { ?>
  <p class="m-note">Diese Anleitung passt sich an eure Einrichtung an: Bei Funktionen, die der
  Administrator ein- oder ausschalten kann, steht dabei, wie es bei euch gerade ist –
  <?php echo $opt('offline'); ?> bzw. <span class="m-off">bei euch ausgeschaltet</span>.
  Bei Links, die jemand selbst angelegt hat, kann es abweichen.</p>
  <?php } ?>
  <?php echo $figure('library.jpg', 'Ordner und Aufnahmen, unten die Player-Leiste'); ?>
</section>

<section id="oeffnen" class="m-section">
  <h2><span class="m-num">2</span>Öffnen und anmelden</h2>
  <h3>Mit einem Link (ohne Nextcloud-Konto)</h3>
  <ol>
    <li>Öffne den Link, den du bekommen hast.</li>
    <li>Ist der Link mit einem Passwort geschützt, erscheint „Bitte das Zugangspasswort
    eingeben.“ Passwort eingeben und auf „Anmelden“ tippen.</li>
  </ol>
  <p>Dein Gerät bleibt eine Zeit lang angemeldet – wie lange, legt der Administrator fest.
  Wird das Passwort geändert, musst du es neu eingeben. Mit dem Knopf „Abmelden“ oben
  rechts meldest du dich ab; die Wiedergabe stoppt dann.</p>
  <h3>Mit einem Nextcloud-Konto</h3>
  <p>Melde dich wie gewohnt in Nextcloud an und wähle im App-Menü oben
  <strong>Audio Archive</strong>. Ein eigenes Passwort für die App gibt es nicht.</p>
</section>

<section id="installieren" class="m-section">
  <h2><span class="m-num">3</span>Als App installieren</h2>
  <p>Installiert startet der Player wie eine App vom Startbildschirm – ohne Browserleiste
  und mit eigenem Symbol. Wenn dein Browser es anbietet, erscheint oben der Knopf
  <strong>„App installieren“</strong>; ein Tipp darauf öffnet den Installations-Dialog.
  Sonst geht es so:</p>
  <table class="m-table">
    <thead><tr><th>Gerät</th><th>So geht’s</th></tr></thead>
    <tbody>
      <?php /* dieselbe Liste wie im Fenster „App installieren“ des Players */
      foreach (include __DIR__ . '/../parts/install-browsers.php' as [$device, $howto]) { ?>
      <tr><td><?php echo $escape($device); ?></td><td><?php echo $howto; ?></td></tr>
      <?php } ?>
    </tbody>
  </table>
  <p>In der installierten App fehlt die Browserleiste. Zum Neuladen gibt es deshalb oben
  den Knopf <strong>„Seite neu laden“</strong> (kreisförmiger Pfeil).</p>
</section>

<section id="finden" class="m-section">
  <h2><span class="m-num">4</span>Aufnahmen finden</h2>
  <h3>Ordner öffnen</h3>
  <p>Tippe auf einen Ordner, um ihn zu öffnen. Oben siehst du den Pfad; ein Tipp auf einen
  Teil davon bringt dich dorthin zurück. Mit Nextcloud-Konto gibt es links zusätzlich den
  <strong>Ordnerbaum</strong> (auf schmalen Bildschirmen über das Symbol ☰ oben links). Dort findest du:</p>
  <ul>
    <li><strong>„Meine Dateien“</strong> – Aufnahmen aus deinen eigenen Nextcloud-Dateien,</li>
    <li><strong>„Meine Freigaben“</strong> – Ordner, die du selbst geteilt hast,</li>
    <li><strong>„Mit mir geteilt“</strong> – Ordner, die andere in dieser App mit dir geteilt haben. Dazu gehören
      auch die Aufnahmen, die für alle angemeldeten Nutzer freigegeben sind (🌐, z.&nbsp;B. das gemeinsame Archiv) –
      mit ihnen startet die App,</li>
    <li><strong>„Favoriten“</strong> <?php echo $opt('favoriten'); ?>,</li>
    <li>ganz unten <strong>„Zu Nextcloud“</strong> – zurück zur Nextcloud-Oberfläche.</li>
  </ul>
  <h3>Suchen</h3>
  <p>Ins <strong>Suchfeld</strong> oben tippen und Titel, Künstler oder Ordnernamen eingeben. <?php
  if ($on('suche') === 'all') { ?>Bei euch wird
  immer überall gesucht.<?php } elseif ($on('suche') === 'folder') { ?>Bei euch wird im geöffneten Ordner
  mit allen Unterordnern gesucht; ganz oben wird alles durchsucht.<?php } else { ?>Gesucht wird – je nach
  Einrichtung – im geöffneten Ordner mit allen Unterordnern oder überall. Ganz oben wird immer
  alles durchsucht.<?php } ?> Das ✕ löscht die Suche.</p>
  <h3>Text über den Aufnahmen</h3>
  <p>Steht über der Liste ein Text mit <strong>✕</strong>, kannst du ihn damit auf deinem Gerät ausblenden.
  Ändert sich der Text, erscheint er wieder. Ohne ✕ soll er stehen bleiben – das legt fest, wer den Ordner
  geteilt hat (sonst gilt die Vorgabe der Verwaltung).</p>
  <h3>Sortieren</h3>
  <p>Neben dem Pfad steht die Sortierung: <strong>Name</strong>, <strong>Neueste</strong> oder
  <strong>Zufällig</strong>. Der Pfeil daneben dreht die Reihenfolge um. Dein Gerät merkt sich die Wahl.</p>
  <h3>Weiterhören</h3>
  <p>Hast du eine Aufnahme nicht zu Ende gehört, erscheint beim nächsten Öffnen über der Liste
  die Karte <strong>„Weiterhören“</strong> mit der Stelle, an der du aufgehört hast.</p>
</section>

<section id="abspielen" class="m-section">
  <h2><span class="m-num">5</span>Abspielen</h2>
  <p>Ein Tipp auf eine Aufnahme startet sie. Unten erscheint die <strong>Player-Leiste</strong>.
  Ein Tipp auf das Cover oder auf das Vergrößern-Symbol öffnet den <strong>großen Player</strong>;
  schließen mit dem Pfeil oben oder durch Wischen nach unten.</p>
  <p>Unter dem Titel steht mit Ordner-Symbol der <strong>Ordner</strong>, in dem die Aufnahme liegt – auch wenn
  du sie über die Suche, die Favoriten oder „Danach nächster Ordner“ gestartet hast. Ein Tipp auf den Ordnernamen
  öffnet ihn in der Liste. Beim Durchblättern ist der Ordner, in dem die laufende Aufnahme liegt, <strong>farbig
  markiert</strong> – mit allen Ordnern darüber, in der Liste und in der Seitenleiste.</p>
  <?php echo $figure('player-phone.jpg', 'Links die Liste mit Player-Leiste, rechts der große Player', 'm-figure--phone'); ?>
  <table class="m-table">
    <thead><tr><th>Knopf</th><th>Wirkung</th></tr></thead>
    <tbody>
      <tr><td>⏮ / ⏭</td><td>Vorherige / nächste Aufnahme im Ordner</td></tr>
      <tr><td>15 zurück / 15 vor</td><td>15 Sekunden springen; zum Spulen die Leiste ziehen</td></tr>
      <tr><td>▶ / ⏸</td><td>Abspielen und Pause – am Rechner auch mit der <strong>Leertaste</strong></td></tr>
      <tr><td>Wiederholen</td><td>Jeder Tipp schaltet weiter: „Wiederholen aus“ → „Danach nächster Ordner“ →
        „Ordner wiederholen“ → „Titel wiederholen“</td></tr>
      <tr><td>Angaben</td><td>Titel, Künstler, Album, Länge und weitere Angaben zur Aufnahme; darunter ein
        Protokoll der letzten zwei Stunden (Befehle und Wiedergabe) – hilfreich, wenn du einen Fehler meldest</td></tr>
      <tr><td>Kommentare</td><td>Siehe <a href="#kommentare">Kommentare</a> <?php echo $opt('kommentare'); ?></td></tr>
    </tbody>
  </table>
  <p>Die Wiedergabe läuft weiter, wenn du den Bildschirm sperrst oder in eine andere App wechselst.
  Steuern kannst du sie dann über den Sperrbildschirm, Kopfhörer oder das Auto (Bluetooth, CarPlay, Android Auto).
  Hält eine Navigationsansage oder ein Anruf die Wiedergabe an, geht es danach von selbst weiter, wo dein Gerät
  das Ende der Unterbrechung meldet (Safari auf iPhone/iPad mit aktuellem iOS).
  Am Ende eines Ordners geht es – je nach Wiederholen-Einstellung – mit dem nächsten Ordner weiter.</p>
  <p>Kann dein Gerät ein Format nicht abspielen (z.&nbsp;B. AIFF außerhalb von Safari), steht die
  Aufnahme blass in der Liste. Ist die Umwandlung eingeschaltet <?php echo $opt('umwandeln'); ?>, wird sie
  beim Abspielen für dein Gerät in MP3 umgewandelt – das dauert beim ersten Mal etwas.</p>
</section>

<section id="favoriten" class="m-section">
  <h2><span class="m-num">6</span>Favoriten <?php echo $opt('favoriten'); ?></h2>
  <p>Tippe auf den Stern ☆ neben einem Ordner oder einer Aufnahme. Alle Favoriten findest du
  unter <strong>„Favoriten“</strong> – mit Nextcloud-Konto in der Seitenleiste, über einen Link
  als letzten Eintrag der obersten Ebene.</p>
  <ul>
    <li>Mit Nextcloud-Konto sind es echte Nextcloud-Favoriten; bei Aufnahmen aus deinen eigenen Dateien erscheint der Stern auch in „Dateien“.</li>
    <li>Über einen Link speichert dein Gerät die Favoriten – sie gelten nur dort.</li>
    <li>Ändern geht nur mit Internetverbindung.</li>
  </ul>
</section>

<section id="offline" class="m-section">
  <h2><span class="m-num">7</span>Offline hören <?php echo $opt('offline'); ?></h2>
  <p>So hörst du Aufnahmen ohne Internet, etwa im Flugzeug oder im Funkloch:</p>
  <ol>
    <li>Ordner mit Verbindung öffnen.</li>
    <li>Auf <strong>„Diesen Ordner offline verfügbar machen“</strong> tippen. Daneben siehst du,
    wie viele Aufnahmen schon gespeichert sind.</li>
    <li>Ohne Verbindung zeigt die App nur die gespeicherten Ordner und den Hinweis
    „Keine Internetverbindung“.</li>
  </ol>
  <p>Die Aufnahmen liegen nur in der App, nicht im Download-Ordner. Wieder löschen mit
  <strong>„Offline-Aufnahmen entfernen“</strong>. Am besten funktioniert das in der installierten App.</p>
  <?php if ($on('offlineOhnePasswort') === true) { ?>
  <p>Bei euch öffnen sich gespeicherte Aufnahmen offline direkt, ohne Passwort oder PIN.
  Wer sich ausdrücklich abmeldet, braucht danach wieder das Passwort.</p>
  <?php } elseif ($on('offlineOhnePasswort') === false) { ?>
  <p>Ohne Verbindung fragt die App bei euch nach dem Passwort des Links, mit Nextcloud-Konto nach
  einer <strong>Offline-PIN</strong>, die du beim ersten Speichern festlegst.</p>
  <?php } else { ?>
  <p>Je nach Einrichtung öffnen sich gespeicherte Aufnahmen offline direkt. Sonst fragt die App
  nach dem Passwort des Links, mit Nextcloud-Konto nach einer <strong>Offline-PIN</strong>, die du
  beim ersten Speichern festlegst. Wer sich ausdrücklich abmeldet, braucht danach wieder das Passwort.</p>
  <?php } ?>
</section>

<section id="herunterladen" class="m-section">
  <h2><span class="m-num">8</span>Herunterladen</h2>
  <ul>
    <li><strong>Einzelne Aufnahme</strong> <?php echo $opt('herunterladen'); ?>: Pfeil-Symbol ⬇ ganz rechts in der Zeile, hinter dem Stern („Aufnahme herunterladen“).</li>
    <li><strong>Ganzer Ordner</strong> <?php echo $opt('zip'); ?>: Pfeil-Symbol ⬇ ganz rechts in der Ordner-Zeile
    („Ordner als ZIP herunterladen“) oder <strong>„Ordner herunterladen (ZIP)“</strong> über der Liste des geöffneten
    Ordners – mit allen Aufnahmen, Unterordnern und Ordnerbildern. Die oberste Ebene lässt sich nicht als Ganzes herunterladen.
    Mit Nextcloud-Konto geht das bei deinen eigenen Dateien immer.</li>
  </ul>
  <p>Heruntergeladene Dateien liegen danach ganz normal auf deinem Gerät.</p>
</section>

<section id="kommentare" class="m-section">
  <h2><span class="m-num">9</span>Kommentare und Bewertungen <?php echo $opt('kommentare'); ?></h2>
  <p>Im großen Player gibt es den Knopf <strong>„Kommentare“</strong>. Dort kannst du zu der
  laufenden Aufnahme eine Anmerkung, einen Änderungswunsch oder einen Fehler melden und mit 1 bis 5 Sternen
  bewerten <?php echo $opt('bewertung'); ?>.</p>
  <ul>
    <li>In der App siehst du nur deine eigenen Kommentare.</li>
    <li>Je nach Einrichtung werden die Verantwortlichen benachrichtigt; sie können in Nextcloud antworten.</li>
    <li>Mit Nextcloud-Konto findest du alle deine Kommentare unter
    <em>Einstellungen → Persönlich → Audio Archive</em>, auch als Excel-Datei.</li>
  </ul>
</section>

<section id="teilen" class="m-section">
  <h2><span class="m-num">10</span>Ordner teilen <?php echo $opt('teilen'); ?></h2>
  <p>Mit Nextcloud-Konto kannst du Ordner weitergeben – sofern der Administrator dir das erlaubt.
  Ordner öffnen und über der Liste auf <strong>„Diesen Ordner teilen“</strong> tippen.
  Eine Freigabe umfasst immer auch alle Unterordner.</p>
  <p>Die Einstellungen einer Freigabe stehen in vier <strong>Tabs</strong>: <em>Zugang</em> (Personen bzw. Link,
  Passwort, Ablaufdatum), <em>Funktionen</em>, <em>Aussehen</em> und <em>Namen</em>. Solange das Formular offen ist,
  kannst du durch die Unterordner des geteilten Ordners klicken – es bleibt offen, und die Liste zeigt die Namen schon
  so, wie die Empfänger sie sehen werden, auch vor dem Speichern. Verlässt du den geteilten Ordner mit ungespeicherten
  Änderungen, fragt die App: <em>Speichern</em>, <em>Verwerfen</em> oder <em>Abbrechen</em>.</p>
  <?php echo $figure('share.jpg', 'Einen Ordner teilen'); ?>
  <h3>Mit Personen und Gruppen</h3>
  <p><strong>„Mit Personen teilen“</strong> wählt Nextcloud-Nutzer oder Gruppen aus. Sie finden den Ordner
  in dieser App unter „Mit mir geteilt“. Auf Wunsch dürfen sie ihn selbst weiterteilen. Administratoren
  können zusätzlich <strong>„Alle angemeldeten Nutzer“</strong> anhaken (🌐) – so wird zum Beispiel das gemeinsame
  Archiv für alle freigegeben. Titel, Gestaltung und Text über den Aufnahmen stellst du je Freigabe ein; neue
  Freigaben beginnen mit dem Standard-Aussehen.</p>
  <h3>Link für Hörer ohne Konto</h3>
  <p><strong>„Neuer Link“</strong> erstellt einen Link, über den man auch ohne Nextcloud-Konto zuhören kann.
  Für jeden Link einstellbar:</p>
  <ul>
    <li><strong>Wunschname im Link</strong> statt einer zufälligen Adresse,</li>
    <li><strong>Passwort</strong> (ohne Passwort kann jeder mit dem Link zuhören),</li>
    <li><strong>Ablaufdatum</strong>,</li>
    <li>ob <strong>Offline speichern</strong> und <strong>Herunterladen</strong> erlaubt sind,</li>
    <li>Wiederholen-Vorgabe, Titel, Text über den Aufnahmen (auf Wunsch mit ✕ ausblendbar) und Gestaltung.</li>
  </ul>
  <h3>Namen anders anzeigen</h3>
  <p>Im Tab <strong>„Namen“</strong> legst du fest, wie Datei- und Ordnernamen für die Empfänger
  erscheinen – die Dateien selbst bleiben unverändert. Dafür gibt es einen <strong>Baukasten</strong>; Klammern
  muss niemand tippen:</p>
  <ol>
    <li><strong>Beispiel wählen:</strong> „+ Regel“ tippen, einen Namen aus dem Ordner wählen (oder eintippen) und
    „Zerlegen“ tippen. Die App teilt ihn an <code>_</code>, <code>-</code>, Punkt und Leerzeichen in Teile und
    erkennt ein Datum von selbst.</li>
    <li><strong>Was bedeuten die Teile?</strong> Für jeden Teil wählen: <em>Text</em>, <em>Datum</em>,
    <em>immer gleich</em> oder <em>weglassen</em> – und auf Wunsch einen Namen vergeben (z. B. „Tag“). Bei einem
    Text-Teil steht direkt darunter <strong>„Anzeigen als“</strong> mit den Werten, die im Ordner vorkommen: neben „So“
    einfach „Sonntag“ eintragen. Mit <strong>„+ Wert“</strong> kommen weitere dazu, die (noch) nicht vorkommen,
    z. B. „Mo“ → „Montag“. Leer = wie im Namen. Ein Tipp auf ein Trennzeichen verbindet zwei Teile zu einem
    (z. B. „Psalm_23“ als ein Titel); ✂ trennt Buchstaben von Ziffern.</li>
    <li><strong>Was soll angezeigt werden?</strong> Teile per „+“ hinzufügen, mit ← → ordnen, mit ✕ entfernen,
    mit „+ Text“ eigenen Text einfügen. Beim Datum das Format wählen – aus der Liste oder als eigenes Format.</li>
    <li><strong>Wo die Regel gilt:</strong> Dateien und/oder Ordner, die ganze Freigabe oder nur ein bestimmter Ordner
    (mit oder ohne Unterordner), alle Ebenen oder nur eine Ebene, dazu Ausnahmen.</li>
  </ol>
  <table class="m-table">
    <tbody>
      <tr><td>Beispielname</td><td><code>GBO_2026-05-17_So_mp3_a</code></td></tr>
      <tr><td>Teile</td><td>GBO <em>weglassen</em> · 2026-05-17 <em>Datum</em> · So <em>Text „Tag“</em> ·
      mp3 <em>immer gleich</em> · a <em>Text „Zeit“</em></td></tr>
      <tr><td>Anzeige</td><td>Datum (Format <code>TT.MM</code>) · Tag · Zeit (<em>nur wenn mehrere</em>)</td></tr>
      <tr><td>Werte</td><td>So → Sonntag · a → Vormittag · b → Abend</td></tr>
      <tr><td>Ergebnis</td><td>„17.05 Sonntag Vormittag“ und „17.05 Sonntag Abend“ – gibt es an einem Tag nur eine
      Aufnahme, nur „10.05 Sonntag“</td></tr>
    </tbody>
  </table>
  <p><strong>Datum:</strong> Zur Auswahl stehen fertige Formate (z. B. „17. Mai 2026“, „17.05.2026“, „Mai 2026“,
  „So, 17.05.2026“, „2026-05-17“, „05/17/2026“) oder ein eigenes Format aus diesen Bausteinen:</p>
  <table class="m-table">
    <thead><tr><th>Baustein</th><th>Bedeutung</th><th>Beispiel</th></tr></thead>
    <tbody>
      <tr><td><code>T</code> / <code>TT</code></td><td>Tag / zweistellig</td><td>7 / 07</td></tr>
      <tr><td><code>M</code> / <code>MM</code></td><td>Monat als Zahl / zweistellig</td><td>5 / 05</td></tr>
      <tr><td><code>MMM</code> / <code>MMMM</code></td><td>Monatsname kurz / lang</td><td>Mai / Mai</td></tr>
      <tr><td><code>JJ</code> / <code>JJJJ</code></td><td>Jahr zweistellig / vierstellig</td><td>26 / 2026</td></tr>
      <tr><td><code>WW</code> / <code>WWWW</code></td><td>Wochentag kurz / lang</td><td>So / Sonntag</td></tr>
      <tr><td><code>'Text'</code></td><td>Text in Hochkommas bleibt, wie er ist</td><td><code>'am' T.M.</code> → am 17.5.</td></tr>
    </tbody>
  </table>
  <ul>
    <li>Auch international: <code>D</code>/<code>DD</code> für den Tag, <code>Y</code>/<code>YY</code>/<code>YYYY</code>
    für das Jahr. Punkt, Komma, Schrägstrich und Leerzeichen erscheinen, wie sie sind. Steht im Namen nur Jahr und
    Monat (<code>2026-05</code>), entfallen Tag und Wochentag.</li>
    <li><strong>Sprache</strong> der Monats- und Tagesnamen: je Regel wählbar – Deutsch, English, Français und viele
    mehr, oder „Sprache des Hörers“ (dann sieht jeder die Namen in seiner Sprache).</li>
    <li>Je Teil in der Anzeige: <strong>Schreibweise</strong> (wie im Namen, GROSS, klein, erster Buchstabe groß) und
    <strong>nur wenn mehrere</strong> – der Teil erscheint nur, wenn es im selben Ordner einen sonst gleichen Namen
    gibt.</li>
    <li><strong>Eigene Namen:</strong> Unter „Eigene Namen für einzelne Ordner oder Aufnahmen“ einen Ordner oder eine
    Aufnahme wählen und den Namen eintragen, der erscheinen soll. Das geht allen Regeln vor.</li>
    <li>Mehrere Regeln sind möglich – die erste passende gilt (mit „↑ Nach oben“ ordnen), passt keine, bleibt der
    Name. Jede Regel zeigt eine <strong>Vorschau</strong> mit Namen aus dem Ordner und allen Unterordnern; im Feld
    „Namen ausprobieren“ lässt sich jeder Name testen. Die Übersicht aller Bausteine steht auch im Formular
    („Übersicht: alle Bausteine“).</li>
    <li>Ordner-Regeln wirken überall, wo Ordner erscheinen: in der Liste, im Pfad, in der Seitenleiste, im Titel
    des Links (ohne eigenen Titel), im Player und bei „Mit mir geteilt“.</li>
    <li>Die <strong>Suche</strong> findet auf solchen Freigaben die <strong>angezeigten</strong> Namen. Titel, Künstler
    und Album aus der Datei werden weiter durchsucht. Sortiert wird nach den echten Namen. Hat eine Aufnahme einen
    Titel in der Datei (Tags), zeigt der Player diesen.</li>
    <li><strong>Für Fortgeschrittene</strong> bleibt die Schreibweise mit Klammern aufklappbar (z. B. Muster
    <code>{Kürzel:3}_{Datum}_{Titel}</code>, Anzeige <code>{Titel} – {Datum:"T. MMMM JJJJ"}</code>). Regeln aus
    früheren Versionen erscheinen dort und gelten unverändert weiter.</li>
  </ul>
  <p>Alle deine Freigaben stehen unter <em>Einstellungen → Persönlich → Audio Archive</em>
  („Meine Freigaben“). Dort kannst du Links kopieren und Freigaben löschen.</p>
  <p>Hat jemand einen Ordner mit dir geteilt, steht oben, von wem er kommt. Mit
  <strong>„Aussehen der Freigabe verwenden“</strong> siehst du ihn in der Gestaltung des Absenders.</p>
</section>

<section id="darstellung" class="m-section">
  <h2><span class="m-num">11</span>Eigene Darstellung <?php echo $opt('darstellung'); ?></h2>
  <p>Mit Nextcloud-Konto öffnet das <strong>Zahnrad</strong> oben die „Darstellung“. Sie gilt nur für
  deine eigene Ansicht – andere Nutzer und geteilte Links bleiben unverändert.</p>
  <ul>
    <li><strong>Gestaltung:</strong> Standard (Aussehen der App), Klassisch (wie Nextcloud), Modern (mit eigenen Farben) oder Benutzerdefiniert<?php
      // Was „Benutzerdefiniert“ bietet, kommt direkt aus dem Editor (js/style-tokens.js, ab 1.0.3)
      $styleLabels = array_values(array_unique(array_map(static fn ($i) => $i['label'], array_filter(
          $_['styleRef'], static fn ($i) => in_array($i['kind'], ['color', 'range', 'choice', 'switch'], true)
      ))));
      if ($styleLabels !== []) { ?> – dort einstellbar: <?php echo $escape(implode(', ', $styleLabels)); ?><?php } ?>,</li>
    <li><strong>Funktionen:</strong> Favoriten, Kommentare und Bewertung ein- oder ausblenden, Wiederholen-Vorgabe,</li>
    <li><strong>Texte für „Meine Dateien“:</strong> eigener Titel, Zusatzzeile und Text über deinen eigenen Dateien
      (ohne Eintrag: Titel „Meine Dateien“, keine Zusatzzeile, kein Text). Über geteilten Ordnern stehen
      die Texte, die beim Teilen festgelegt wurden,</li>
    <li><strong>Hintergrundbild</strong> wählen oder entfernen.</li>
  </ul>
  <p>Mit „Übernehmen“ speichern. Unten im Fenster steht die Version der App.</p>
</section>

<section id="hilfe" class="m-section">
  <h2><span class="m-num">12</span>Hilfe und Kontakt</h2>
  <p>Der Knopf <strong>(i)</strong> oben öffnet „Hilfe“. Dort findest du diese Anleitung und –
  falls eingerichtet – zwei Wege, uns zu erreichen:</p>
  <ul>
    <li><strong>„Per E-Mail schreiben“</strong> <?php echo $opt('kontaktMail'); ?> öffnet dein Mailprogramm,</li>
    <li>ein <strong>Nachrichtenfeld</strong> <?php echo $opt('kontaktNachricht'); ?>, das direkt an die Verantwortlichen geht. Gib eine
    E-Mail-Adresse an, wenn du eine Antwort möchtest.</li>
  </ul>
  <?php if ($on('kontaktMail') !== false || $on('kontaktNachricht') !== false) { ?>
  <p>Mitgeschickt wird, wo du gerade warst (Seite, Ordner, Aufnahme und Gerät) – das hilft beim Helfen.</p>
  <?php } ?>
</section>

<section id="fragen" class="m-section">
  <h2><span class="m-num">13</span>Häufige Fragen</h2>
  <dl class="m-faq">
    <dt>Die Wiedergabe stoppt nach einer Weile im Hintergrund.</dt>
    <dd>Manche Android-Geräte beenden Apps im Hintergrund, um Strom zu sparen. In den
    Systemeinstellungen die Akku-Optimierung für den Browser bzw. die installierte App ausschalten.</dd>
    <dt>Eine Aufnahme ist blass und lässt sich nicht abspielen.</dt>
    <dd>Dein Gerät kennt das Format nicht. Ein anderer Browser hilft oft (Safari spielt z.&nbsp;B. AIFF);
    sonst den Administrator bitten, die Umwandlung in MP3 einzuschalten.</dd>
    <dt>Ich werde wieder nach dem Passwort gefragt.</dt>
    <dd>Die Anmeldung ist abgelaufen oder das Passwort wurde geändert. Einfach neu eingeben.</dd>
    <dt>Die installierte App zeigt etwas Altes.</dt>
    <dd>Oben auf „Seite neu laden“ (kreisförmiger Pfeil) tippen.</dd>
    <dt>Der Link funktioniert nicht mehr.</dt>
    <dd>Der Link ist abgelaufen oder wurde geändert. Bitte bei der Person nachfragen, von der du ihn hast.</dd>
    <dt>Ein Knopf aus dieser Anleitung fehlt.</dt>
    <dd>Die Funktion ist bei euch nicht eingeschaltet (<?php echo $opt(''); ?>) oder geht nur mit Nextcloud-Konto.</dd>
  </dl>
</section>
