<?php
/**
 * Inhalt des Players - eingebunden von player.php (eigenstaendig) und
 * player-embedded.php (innerhalb von Nextcloud).
 *
 * Alles steckt in #audioarchive. Die Stylesheets der App gelten nur
 * innerhalb dieses Elements; so kommen sie Nextclouds Kopfleiste nicht in
 * die Quere, und Nextclouds Vorgaben fuer Knoepfe und Eingabefelder werden
 * dort drinnen zurueckgesetzt (siehe Anfang von css/style.css).
 *
 * Erwartet $_, $escape und $asset aus der einbindenden Vorlage.
 */
?>
<?php
$rootClasses = [$_['embedded'] === '1' ? 'aa-embedded' : 'aa-standalone'];
if (($_['flat'] ?? '') === '1') {
    $rootClasses[] = 'aa-design-nextcloud';
}
// Angemeldet: Ordnerbaum in der Seitenleiste
if ($_['loggedIn'] === '1') {
    $rootClasses[] = 'aa-with-tree';
}
/*
 * Hintergrundbild fuer die Nextcloud-Gestaltung als CSS-Variable. (Bei
 * eigener Gestaltung setzt app.js das Bild, zusammen mit dem Farbverlauf.)
 * Die Adresse stammt aus dem eigenen Router und wird trotzdem maskiert.
 */
$rootStyle = '';
if ($_['backgroundUrl'] !== '') {
    $rootClasses[] = 'aa-has-image';
    $rootStyle = '--aa-image: url("' . str_replace(['"', '\\', "\n"], '', $_['backgroundUrl']) . '")';
}
?>
<div id="audioarchive" class="<?php echo $escape(implode(' ', $rootClasses)); ?>"<?php if ($rootStyle !== '') { ?> style="<?php echo $escape($rootStyle); ?>"<?php } ?>>

<!--
  Startwerte fuer die Skripte. Bewusst als data-Attribute statt als
  Inline-Skript: Nextclouds Sicherheitsrichtlinie erlaubt keine
  Inline-Skripte ohne Nonce.
-->
<div id="app-config"
     data-public-token="<?php echo $escape($_['publicToken']); ?>"
     data-design="<?php echo $escape($_['design']); ?>"
     data-style="<?php echo $escape($_['styleJson'] ?? ''); ?>"
     data-admin-style-offered="<?php echo $escape($_['adminStyleOffered'] ?? ''); ?>"
     data-user-settings="<?php echo $escape($_['userSettings']); ?>"
     data-logged-in="<?php echo $escape($_['loggedIn']); ?>"
     data-has-shared="<?php echo $escape($_['hasShared']); ?>"
     data-can-share="<?php echo $escape($_['canShare']); ?>"
     data-api-token="<?php echo $escape($_['apiToken']); ?>"
     data-open-access="<?php echo $escape($_['openAccess']); ?>"
     data-requesttoken="<?php echo $escape($_['requestToken']); ?>"
     data-embedded="<?php echo $escape($_['embedded']); ?>"
     data-standalone-url="<?php echo $escape($_['standaloneUrl']); ?>"
     data-nextcloud-url="<?php echo $escape($_['nextcloudUrl'] ?? ''); ?>"
     data-sort-default="<?php echo $escape($_['sortDefault'] ?? 'name'); ?>"
     data-shared-label="<?php echo $escape($_['sharedLabel'] ?? ''); ?>"
     data-header-title="<?php echo $escape($_['headerTitle']); ?>"
     data-header-subtitle="<?php echo $escape($_['headerSubtitle']); ?>"
     data-service-worker="<?php echo $escape($_['serviceWorkerUrl']); ?>"
     data-scope="<?php echo $escape($_['scopeUrl']); ?>"
     data-asset-base="<?php echo $escape($_['assetBase']); ?>"
     data-theme-accent="<?php echo $escape($_['themeAccent']); ?>"
     data-theme-bar="<?php echo $escape($_['themeBar']); ?>"
     data-theme-base="<?php echo $escape($_['themeBase']); ?>"
     data-background="<?php echo $escape($_['backgroundUrl']); ?>"
     data-app-version="<?php echo $escape($_['appVersion'] ?? ''); ?>"
     data-icon-color="<?php echo $escape($_['iconColor'] ?? ''); ?>"
     data-nc-primary="<?php echo $escape($_['ncPrimary'] ?? ''); ?>"
     data-cover-icon="<?php echo $escape($_['coverIcon'] ?? ''); ?>"
     data-cover-url="<?php echo $escape($_['coverUrl'] ?? ''); ?>"
     data-theme-stylesheets="<?php echo $escape($_['themeStylesheetsJson'] ?? '[]'); ?>"
     data-beta="<?php echo $escape($_['betaEnabled']); ?>"
     data-beta-text="<?php echo $escape($_['betaText']); ?>"
     data-beta-link-url="<?php echo $escape($_['betaLinkUrl']); ?>"
     data-beta-link-label="<?php echo $escape($_['betaLinkLabel']); ?>"
     hidden></div>

<div id="bg-layer" aria-hidden="true"></div>

<!-- ===================== LOGIN-ANSICHT ===================== -->
<section id="login-screen" class="screen" hidden>
  <div class="login-card">
    <h1 class="login-title" id="login-title"></h1>
    <p class="login-subtitle" id="login-subtitle" hidden></p>
    <p class="login-hint">Bitte das Zugangspasswort eingeben.</p>
    <form id="login-form" autocomplete="off">
      <input
        type="password"
        id="login-password"
        name="password"
        placeholder="Passwort"
        autocomplete="current-password"
        required
      >
      <button type="submit" id="login-submit">Anmelden</button>
      <p class="login-error" id="login-error" hidden></p>
    </form>
  </div>
</section>

<!-- ===================== HAUPTANSICHT ===================== -->
<section id="main-screen" class="screen" hidden>
  <header class="topbar">
    <!-- Ordnerbaum ein-/ausblenden (nur schmale Bildschirme, nur angemeldet) -->
    <button id="sidebar-toggle" class="icon-btn glass-pill sidebar-toggle" type="button"
            title="Ordner" aria-label="Ordner" aria-controls="sidebar" aria-expanded="false" hidden>
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>
      </svg>
    </button>
    <div class="topbar-titles glass-pill">
      <h1 id="topbar-title"></h1>
      <p id="topbar-subtitle" class="topbar-subtitle" hidden></p>
    </div>
    <!-- Persoenliche Einstellungen (nur angemeldet, sofern erlaubt) -->
    <button id="user-settings-btn" class="icon-btn glass-pill" type="button"
            title="Darstellung" aria-label="Darstellung" hidden>
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="12" cy="12" r="3"/>
        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
      </svg>
    </button>

    <!--
      Eingebettet: fuehrt zur Fassung ohne Nextcloud-Leiste, wo der Browser
      die Installation anbietet. Eigenstaendig: erscheint nur, wenn der
      Browser die Installation direkt anbietet (beforeinstallprompt).
    -->
    <a id="install-btn" class="icon-btn glass-pill install-btn" href="#" title="Als App installieren" hidden>
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M12 3v12"/>
        <polyline points="7 10 12 15 17 10"/>
        <path d="M5 21h14"/>
      </svg>
      <span class="install-btn-label">App installieren</span>
    </a>
    <button id="logout-btn" class="icon-btn glass-pill" title="Abmelden" aria-label="Abmelden">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </button>
  </header>

  <div class="main-body">
  <!-- Ordnerbaum (nur angemeldet): gemeinsamer Ordner und eigene Dateien -->
  <nav id="sidebar" class="sidebar" aria-label="Ordner" hidden>
    <ul id="tree" class="tree" role="tree"></ul>
    <!-- Zurueck zur Nextcloud-Oberflaeche (ab 0.21.1, Vikunja #26) -->
    <a id="nextcloud-link" class="sidebar-nextcloud" href="#" hidden>
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M15 18l-6-6 6-6"/>
      </svg>
      <span>Zu Nextcloud</span>
    </a>
  </nav>
  <div id="sidebar-backdrop" class="sidebar-backdrop" hidden></div>

  <div class="library-scroll">
  <div id="library" class="library">
    <p id="offline-banner" class="offline-banner" hidden>
      Keine Internetverbindung &ndash; es werden nur gespeicherte Aufnahmen angezeigt.
    </p>

    <!-- Persoenliche Darstellung, geoeffnet ueber das Zahnrad -->
    <section id="user-settings" class="panel" hidden>
      <h2 class="panel-title">Darstellung</h2>
      <p class="panel-hint">Gilt nur für deine eigene Ansicht – andere Nutzer und geteilte Links bleiben unverändert.</p>

      <fieldset class="panel-group">
        <legend>Gestaltung</legend>
        <!-- Karten mit Vorschau, aufgebaut von app.js (AAStyle.createDesignCards) -->
        <div id="us-design-cards"></div>
        <p class="panel-hint" id="us-design-hint" hidden></p>
      </fieldset>

      <fieldset class="panel-group" id="us-modern" hidden>
        <legend>Farben für „Modern“</legend>
        <label class="panel-choice"><input type="checkbox" id="us-own-colors"> Eigene Farben</label>
        <div id="us-colors" hidden>
          <div id="us-palettes"></div>
          <div class="panel-row share-colors">
            <label class="panel-color-field"><input type="color" id="us-accent" class="panel-color"><span class="panel-hint">Akzent</span></label>
            <label class="panel-color-field"><input type="color" id="us-bar" class="panel-color"><span class="panel-hint">Leisten</span></label>
            <label class="panel-color-field"><input type="color" id="us-base" class="panel-color"><span class="panel-hint">Grundton</span></label>
          </div>
        </div>
      </fieldset>

      <fieldset class="panel-group" id="us-defined" hidden>
        <legend>Benutzerdefiniert</legend>
        <p class="panel-hint">Farben, Ecken, Glas, Schrift und Hintergrund frei einstellen. Die Seite zeigt Änderungen sofort, gespeichert wird mit „Übernehmen“.</p>
        <div id="us-editor"></div>
      </fieldset>

      <fieldset class="panel-group">
        <legend>Texte</legend>
        <label class="panel-field">
          <span class="panel-field-label">Titel</span>
          <input type="text" id="us-title" class="panel-input" maxlength="200">
        </label>
        <label class="panel-field">
          <span class="panel-field-label">Zusatzzeile</span>
          <input type="text" id="us-subtitle" class="panel-input" maxlength="500">
          <span class="panel-hint">Leere Felder übernehmen die Vorgabe des Administrators.</span>
        </label>
      </fieldset>

      <fieldset class="panel-group">
        <legend>Hintergrundbild</legend>
        <p class="panel-hint" id="us-background-state"></p>
        <div class="panel-row">
          <label class="panel-button">
            Bild wählen …
            <input type="file" id="us-background-file" accept="image/png,image/jpeg,image/webp" hidden>
          </label>
          <button type="button" class="panel-button" id="us-background-remove" hidden>Entfernen</button>
        </div>
      </fieldset>

      <p class="panel-hint app-version-panel" id="us-version"></p>
      <p class="panel-error" id="us-error" hidden></p>
      <div class="panel-row panel-actions">
        <button type="button" class="panel-button panel-button--primary" id="us-save">Übernehmen</button>
        <button type="button" class="panel-button" id="us-cancel">Schließen</button>
      </div>
    </section>

    <!-- Kurzanleitung "App installieren" fuer geteilte Links (ab 0.21.1, Vikunja #27) -->
    <section id="install-help" class="panel install-help" hidden>
      <h2 class="panel-title">Als App installieren</h2>
      <ol id="install-help-steps" class="install-help-steps"></ol>
      <p class="panel-hint">Danach startet der Player wie eine App vom Startbildschirm – auch ohne Browserleiste.</p>
      <div class="panel-row panel-actions">
        <button type="button" class="panel-button" id="install-help-close">Schließen</button>
      </div>
    </section>

    <!-- Hinweisstreifen, vom Administrator gefuellt (siehe Einstellungen) -->
    <div id="beta-notice" class="beta-notice" hidden></div>

    <nav id="breadcrumb" class="breadcrumb"></nav>

    <!-- Ordner teilen (angemeldet, sofern erlaubt) -->
    <div id="folder-actions" class="folder-actions" hidden>
      <button type="button" id="share-btn" class="share-btn">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
          <line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/><line x1="15.4" y1="6.5" x2="8.6" y2="10.5"/>
        </svg>
        <span>Diesen Ordner teilen</span>
      </button>
    </div>

    <!-- Mit mir geteilter Ordner: von wem, und welches Aussehen gelten soll -->
    <div id="incoming-bar" class="incoming-bar" hidden>
      <span id="incoming-info" class="incoming-info"></span>
      <label id="incoming-design-wrap" class="incoming-design" hidden>
        <input type="checkbox" id="incoming-design"> Aussehen der Freigabe verwenden
      </label>
    </div>

    <!-- Freigaben dieses Ordners: Liste und Formular, von app.js gefuellt -->
    <section id="share-panel" class="panel share-panel" hidden></section>

    <!-- Offline-Leiste: erscheint nur in Ordnern, die Aufnahmen enthalten -->
    <div id="offline-bar" class="offline-bar" hidden>
      <button type="button" id="offline-btn" class="offline-btn"></button>
      <span id="offline-info" class="offline-info"></span>
    </div>

    <p id="library-status" class="status-text">Lade Aufnahmen …</p>
    <div id="list-container" class="explorer-list"></div>

    <!-- Versionsanzeige am Ende der Liste (ab 0.15.2) -->
    <p id="app-version" class="app-version" hidden></p>
  </div>
  </div>
  </div>

  <!-- ===================== PERSISTENTER PLAYER ===================== -->
  <!--
    Player-Leiste. Ueber das Vergroessern-Symbol (oder einen Tipp aufs Cover)
    wird DIESELBE Leiste zum Vollbild-Player (Klasse is-expanded) - so gibt
    es die Bedienelemente nur einmal, und nichts muss abgeglichen werden.
  -->
  <!-- Abgedunkelte Flaeche hinter dem Vollbild-Player (ab 0.18.1): Die
       Liste bleibt dahinter sichtbar, der Player wirkt darueber gelegt. -->
  <div id="player-scrim" class="player-scrim" hidden aria-hidden="true"></div>

  <footer id="player-bar" class="player-bar" hidden aria-label="Player">
    <div class="player-expanded-head">
      <button type="button" id="btn-collapse" class="player-icon-btn player-grabber" aria-label="Player schließen" title="Schließen">
        <!-- Glas-Gestaltung: ein Pfeil im Griff; flache Gestaltung: doppelter Pfeil -->
        <svg class="collapse-icon collapse-icon--single" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        <svg class="collapse-icon collapse-icon--double" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 6 12 12 18 6"/><polyline points="6 13 12 19 18 13"/></svg>
      </button>
      <span class="player-expanded-label">Wiedergabe</span>
    </div>
    <div class="player-main">
      <button type="button" id="player-cover-btn" class="player-cover" aria-label="Player vergrößern">
        <img id="player-cover" alt="" decoding="async">
      </button>
      <div class="player-info">
        <p id="player-track-title" class="player-track-title">–</p>
        <p id="player-track-context" class="player-track-context">–</p>
      </div>
      <div class="player-actions">
        <!-- Angaben zur Aufnahme (nur im Vollbild sichtbar, ab 0.15) -->
        <button type="button" id="btn-info" class="player-icon-btn player-info-btn" aria-label="Angaben zur Aufnahme"
                aria-expanded="false" aria-controls="player-details" title="Angaben">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="9.5"/><line x1="12" y1="11" x2="12" y2="17"/><circle cx="12" cy="7.5" r="0.6" fill="currentColor"/>
          </svg>
          <span class="player-action-label">Angaben</span>
        </button>
        <!-- Wiederholen: Aus -> naechster Ordner -> Ordner -> Titel (ab 0.15) -->
        <button type="button" id="btn-repeat" class="player-icon-btn player-repeat-btn" data-mode="off"
                aria-label="Wiederholen: aus" title="Wiederholen: aus">
          <svg class="repeat-icon repeat-icon--loop" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>
          </svg>
          <svg class="repeat-icon repeat-icon--next" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v2"/><path d="M3 7v10a2 2 0 0 0 2 2h7"/><line x1="15" y1="17" x2="22" y2="17"/><polyline points="19 14 22 17 19 20"/>
          </svg>
          <span class="repeat-one-badge" aria-hidden="true">1</span>
          <span class="player-action-label" id="repeat-label">Wiederholen aus</span>
        </button>
        <button type="button" id="btn-expand" class="player-icon-btn player-expand-btn" aria-label="Player vergrößern" title="Vergrößern">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Kurze Rueckmeldung, z. B. nach dem Umschalten von Wiederholen -->
    <p id="player-toast" class="player-toast" role="status" aria-live="polite" hidden></p>

    <!-- Angaben zur Aufnahme, aufklappbar ueber den Info-Knopf -->
    <section id="player-details" class="player-details" hidden aria-label="Angaben zur Aufnahme"></section>

    <div class="player-progress">
      <span id="player-time-current" class="player-time">0:00</span>
      <input type="range" id="player-seek" class="player-seek" min="0" max="100" value="0" step="0.1">
      <span id="player-time-duration" class="player-time">0:00</span>
    </div>

    <div class="player-controls">
      <button id="btn-prev" class="control-btn" aria-label="Vorheriger Titel">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M6 6h2v12H6zM10 12l10-6v12z"/></svg>
      </button>
      <button id="btn-seek-back" class="control-btn" aria-label="15 Sekunden zurück">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>
        <span class="control-btn-label">15</span>
      </button>
      <button id="btn-playpause" class="control-btn control-btn--main" aria-label="Abspielen/Pause">
        <svg id="icon-play" viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
        <svg id="icon-pause" viewBox="0 0 24 24" width="30" height="30" fill="currentColor" hidden class="icon-hidden"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
      </button>
      <button id="btn-seek-forward" class="control-btn" aria-label="15 Sekunden vor">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 5V1l5 5-5 5V7c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6h2c0 4.42-3.58 8-8 8s-8-3.58-8-8 3.58-8 8-8z"/></svg>
        <span class="control-btn-label">15</span>
      </button>
      <button id="btn-next" class="control-btn" aria-label="Nächster Titel">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M16 6h2v12h-2zM4 6l10 6-10 6z"/></svg>
      </button>
    </div>
  </footer>
</section>

<audio id="audio-element" preload="auto" playsinline></audio>

<?php $nonce = $escape($_['cspNonce'] ?? ''); ?>
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/style-tokens.js'); ?>"></script>
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/config.js'); ?>"></script>
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/player.js'); ?>"></script>
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/app.js'); ?>"></script>
</div>
