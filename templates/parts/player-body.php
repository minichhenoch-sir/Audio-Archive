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
<div id="audioarchive" class="<?php echo $_['embedded'] === '1' ? 'aa-embedded' : 'aa-standalone'; ?>">

<!--
  Startwerte fuer die Skripte. Bewusst als data-Attribute statt als
  Inline-Skript: Nextclouds Sicherheitsrichtlinie erlaubt keine
  Inline-Skripte ohne Nonce.
-->
<div id="app-config"
     data-public-token="<?php echo $escape($_['publicToken']); ?>"
     data-embedded="<?php echo $escape($_['embedded']); ?>"
     data-standalone-url="<?php echo $escape($_['standaloneUrl']); ?>"
     data-header-title="<?php echo $escape($_['headerTitle']); ?>"
     data-header-subtitle="<?php echo $escape($_['headerSubtitle']); ?>"
     data-service-worker="<?php echo $escape($_['serviceWorkerUrl']); ?>"
     data-scope="<?php echo $escape($_['scopeUrl']); ?>"
     data-asset-base="<?php echo $escape($_['assetBase']); ?>"
     data-theme-accent="<?php echo $escape($_['themeAccent']); ?>"
     data-theme-bar="<?php echo $escape($_['themeBar']); ?>"
     data-theme-base="<?php echo $escape($_['themeBase']); ?>"
     data-background="<?php echo $escape($_['backgroundUrl']); ?>"
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
    <div class="topbar-titles glass-pill">
      <h1 id="topbar-title"></h1>
      <p id="topbar-subtitle" class="topbar-subtitle" hidden></p>
    </div>
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

  <div id="library" class="library">
    <p id="offline-banner" class="offline-banner" hidden>
      Keine Internetverbindung &ndash; es werden nur gespeicherte Aufnahmen angezeigt.
    </p>

    <!-- Hinweisstreifen, vom Administrator gefuellt (siehe Einstellungen) -->
    <div id="beta-notice" class="beta-notice" hidden></div>

    <nav id="breadcrumb" class="breadcrumb"></nav>

    <!-- Offline-Leiste: erscheint nur in Ordnern, die Aufnahmen enthalten -->
    <div id="offline-bar" class="offline-bar" hidden>
      <button type="button" id="offline-btn" class="offline-btn"></button>
      <span id="offline-info" class="offline-info"></span>
    </div>

    <p id="library-status" class="status-text">Lade Aufnahmen …</p>
    <div id="list-container" class="explorer-list"></div>
  </div>

  <!-- ===================== PERSISTENTER PLAYER ===================== -->
  <footer id="player-bar" class="player-bar" hidden>
    <div class="player-info">
      <p id="player-track-title" class="player-track-title">–</p>
      <p id="player-track-context" class="player-track-context">–</p>
    </div>

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
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/config.js'); ?>"></script>
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/player.js'); ?>"></script>
<script nonce="<?php echo $nonce; ?>" src="<?php echo $asset('js/app.js'); ?>"></script>
</div>
