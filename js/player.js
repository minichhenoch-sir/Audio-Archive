/**
 * player.js
 * Kapselt den HTML5-<audio>-Player, die Media Session API (Sperrbildschirm-Steuerung)
 * und die "Playlist im aktuellen Ordner"-Logik (vor/zurück + Autoplay).
 *
 * WICHTIG für Hintergrund-Wiedergabe:
 * - Es wird bewusst EIN einziges, dauerhaftes <audio>-Element verwendet (kein
 *   Neuerzeugen pro Titel), das im DOM bleibt.
 * - play() wird ausschließlich aus einer echten Nutzer-Interaktion heraus
 *   aufgerufen (Klick auf einen Titel bzw. auf Play), das ist Voraussetzung
 *   dafür, dass iOS/Android die Wiedergabe bei gesperrtem Bildschirm fortsetzen.
 * - Die Media Session API sorgt dafür, dass Titel + Steuerung auf dem
 *   Sperrbildschirm erscheinen.
 */

const Player = (() => {
  const audio = document.getElementById('audio-element');

  let playlist = [];        // Liste der Tracks im aktuellen Ordner (Kategorie)
  let currentIndex = -1;    // Index des aktuell gespielten Titels in "playlist"
  let contextLabel = '';    // z.B. "Januar 2024 – Gottesdienst"

  const els = {
    bar: document.getElementById('player-bar'),
    title: document.getElementById('player-track-title'),
    context: document.getElementById('player-track-context'),
    seek: document.getElementById('player-seek'),
    timeCurrent: document.getElementById('player-time-current'),
    timeDuration: document.getElementById('player-time-duration'),
    btnPlayPause: document.getElementById('btn-playpause'),
    iconPlay: document.getElementById('icon-play'),
    iconPause: document.getElementById('icon-pause'),
    btnPrev: document.getElementById('btn-prev'),
    btnNext: document.getElementById('btn-next'),
    btnSeekBack: document.getElementById('btn-seek-back'),
    btnSeekForward: document.getElementById('btn-seek-forward'),
    cover: document.getElementById('player-cover'),
    coverBtn: document.getElementById('player-cover-btn'),
    btnExpand: document.getElementById('btn-expand'),
    btnCollapse: document.getElementById('btn-collapse'),
    btnRepeat: document.getElementById('btn-repeat'),
    repeatLabel: document.getElementById('repeat-label'),
    btnInfo: document.getElementById('btn-info'),
    details: document.getElementById('player-details'),
    toast: document.getElementById('player-toast'),
  };

  const SEEK_STEP = 15; // Sekunden

  let onTrackChange = null;     // Callback: Titel gewechselt (Liste aktualisieren)
  let onQueueEnd = null;        // Callback: Ordner fertig -> naechster Ordner (app.js)
  let onPlayStateChange = null; // Callback: Play/Pause gewechselt (Animation in der Liste)

  function formatTime(seconds) {
    if (!isFinite(seconds) || seconds < 0) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
  }

  /**
   * WICHTIG (Ursache eines langwierigen Bugs):
   * <svg>-Elemente sind KEINE HTMLElements, sondern SVGElements - und
   * SVGElement besitzt keine reflektierte "hidden"-Eigenschaft. Ein
   *   svgEl.hidden = true
   * legt deshalb nur eine gewöhnliche JS-Eigenschaft am Objekt an und setzt
   * NICHT das HTML-Attribut. Folge: Die CSS-Regel svg[hidden] konnte nie
   * greifen, obwohl ein Auslesen von .hidden in der Konsole korrekt
   * true/false lieferte.
   * Deshalb hier bewusst setAttribute/removeAttribute (setzt das echte
   * Attribut -> svg[hidden] greift) UND zusätzlich eine CSS-Klasse, damit
   * die Umschaltung unabhängig von beiden CSS-Regeln zuverlässig bleibt.
   */
  function setIconHidden(el, shouldHide) {
    if (shouldHide) {
      el.setAttribute('hidden', '');
      el.classList.add('icon-hidden');
    } else {
      el.removeAttribute('hidden');
      el.classList.remove('icon-hidden');
    }
  }

  function updatePlayPauseIcon() {
    const isPlaying = !audio.paused && !audio.ended;
    setIconHidden(els.iconPlay, isPlaying);
    setIconHidden(els.iconPause, !isPlaying);
  }

  /**
   * Setzt die CSS-Variable --player-bar-space auf die tatsächliche Höhe
   * (inkl. Abstand zum Rand) der Player-Leiste, damit die Explorer-Liste
   * exakt so viel Platz am Ende freihält, dass ihr letzter Eintrag nicht
   * von der schwebenden Player-Leiste verdeckt wird - unabhängig davon,
   * ob der Track-Titel ein- oder zweizeilig ist oder sich die
   * Bildschirmgröße ändert (Rotation, Tastatur etc.).
   */
  function updatePlayerBarSpace() {
    const root = document.documentElement;
    // Vollbild: Die Leiste bedeckt alles, der Platz darunter bleibt wie er war
    if (els.bar.classList.contains('is-expanded')) return;
    if (els.bar.hidden) {
      root.style.setProperty('--player-bar-space', '0px');
      return;
    }
    const rect = els.bar.getBoundingClientRect();
    // Unterkante des Players statt des Fensters: Innerhalb von Nextcloud
    // endet der Inhaltsbereich mit etwas Abstand vor dem Fensterrand.
    // (Eigenstaendig hat #audioarchive keine Hoehe - alle Kinder sind fest
    // positioniert -, dort zaehlt deshalb weiter das Fenster.)
    const container = document.querySelector('#audioarchive.aa-embedded');
    const bottom = container ? container.getBoundingClientRect().bottom : window.innerHeight;
    const space = Math.max(0, bottom - rect.top);
    root.style.setProperty('--player-bar-space', space + 'px');
  }

  if ('ResizeObserver' in window) {
    new ResizeObserver(updatePlayerBarSpace).observe(els.bar);
  }
  window.addEventListener('resize', updatePlayerBarSpace);
  window.addEventListener('orientationchange', updatePlayerBarSpace);

  /**
   * Angezeigter Titel: bevorzugt der Titel aus den ID3-Tags der Datei,
   * sonst der Dateiname ohne Endung.
   */
  function trackTitle(track) {
    return (track.title && track.title.trim()) || track.name;
  }

  /**
   * Zweite Zeile unter dem Titel: Kuenstler und Album aus den ID3-Tags.
   * Fehlen beide, bleibt die Zeile leer - der Ordnerpfad wird hier bewusst
   * NICHT mehr angezeigt.
   */
  function trackContext(track) {
    return [track.artist, track.album]
      .filter((v) => v && v.trim())
      .join(' \u00b7 ');
  }

  // ------------------------------------------------------------------
  // Cover (ab 0.14)
  //
  // Quelle: eingebettetes Bild der mp3, sonst cover.jpg o. ae. im Ordner -
  // das entscheidet der Server, die Ordnerliste meldet nur, OB es eins gibt
  // (track.cover = Versionskennung). Ohne Cover, oder wenn es nicht laedt
  // (etwa offline und nicht gespeichert), erscheint das App-Symbol.
  // ------------------------------------------------------------------
  const FALLBACK_COVER = AudioArchive.asset('img/icon-512.png');

  /** Adresse des Covers eines Titels, oder null ohne Cover. */
  function coverUrlFor(track) {
    return track && track.cover ? AudioArchive.coverUrl(track.path, track.source, track.cover) : null;
  }

  function showCover(track) {
    const url = coverUrlFor(track);
    els.bar.classList.toggle('has-cover', !!url);
    els.cover.onerror = () => {
      els.cover.onerror = null;
      els.cover.src = FALLBACK_COVER;
      els.bar.classList.remove('has-cover');
      els.bar.style.removeProperty('--aa-cover');
    };
    els.cover.src = url || FALLBACK_COVER;
    // Fuer den unscharfen Hintergrund des Vollbild-Players
    if (url) {
      els.bar.style.setProperty('--aa-cover', `url("${url.replace(/["\\\n]/g, '')}")`);
    } else {
      els.bar.style.removeProperty('--aa-cover');
    }
  }

  function updateMediaSession(track) {
    if (!('mediaSession' in navigator)) return;

    // Titelbild fuer den Sperrbildschirm: das Cover, sonst das App-Symbol.
    // Immer absolute Adressen - relativ wuerden sie auf der oeffentlichen
    // Seite gegen /s/<token>/ aufgeloest und ins Leere zeigen.
    const cover = coverUrlFor(track);
    const artwork = cover
      ? [{ src: cover, sizes: '512x512' }]
      : [
        { src: AudioArchive.asset('img/icon-192.png'), sizes: '192x192', type: 'image/png' },
        { src: AudioArchive.asset('img/icon-512.png'), sizes: '512x512', type: 'image/png' },
      ];

    navigator.mediaSession.metadata = new MediaMetadata({
      title: trackTitle(track),
      artist: (track.artist && track.artist.trim()) || '',
      album: (track.album && track.album.trim()) || '',
      artwork,
    });

    navigator.mediaSession.setActionHandler('play', () => Player.resume());
    navigator.mediaSession.setActionHandler('pause', () => Player.pause());
    navigator.mediaSession.setActionHandler('previoustrack', () => Player.prev());
    navigator.mediaSession.setActionHandler('nexttrack', () => Player.next());
    navigator.mediaSession.setActionHandler('seekto', (details) => {
      if (details.fastSeek && 'fastSeek' in audio) {
        audio.fastSeek(details.seekTime);
        return;
      }
      audio.currentTime = details.seekTime;
    });
    navigator.mediaSession.setActionHandler('seekbackward', (details) => {
      const skip = details.seekOffset || 15;
      audio.currentTime = Math.max(0, audio.currentTime - skip);
    });
    navigator.mediaSession.setActionHandler('seekforward', (details) => {
      const skip = details.seekOffset || 15;
      audio.currentTime = Math.min(audio.duration || Infinity, audio.currentTime + skip);
    });
  }

  function setMediaSessionPlaybackState(state) {
    if ('mediaSession' in navigator) {
      navigator.mediaSession.playbackState = state; // 'playing' | 'paused'
    }
  }

  // Zeitpunkt der letzten Positionsmeldung, fuer die Drosselung unten
  let lastPositionReport = 0;

  function updatePositionState() {
    lastPositionReport = Date.now();
    if ('mediaSession' in navigator && 'setPositionState' in navigator.mediaSession) {
      if (isFinite(audio.duration) && audio.duration > 0) {
        navigator.mediaSession.setPositionState({
          duration: audio.duration,
          playbackRate: audio.playbackRate,
          position: audio.currentTime,
        });
      }
    }
  }

  // ------------------------------------------------------------------
  // Vorausladen (Puffer gegen Verbindungsabbrueche)
  //
  // Hintergrund: Wie weit ein <audio>-Element vorauslaedt, entscheidet der
  // Browser selbst - erzwingen laesst sich das nicht. Bricht die Verbindung
  // ab, bevor genug gepuffert ist, stockt die Wiedergabe.
  // Deshalb laedt die App den laufenden Titel zusaetzlich im Hintergrund
  // vollstaendig in einen eigenen Cache. Sobald das durch ist, spielt ein
  // Verbindungsabbruch keine Rolle mehr: Der Service Worker beantwortet alle
  // weiteren Anfragen (auch beim Spulen) aus diesem Cache statt aus dem Netz.
  // Vorgeladen wird so weit, dass rund 90 Minuten Wiedergabe abgesichert sind:
  // bei langen Aufnahmen ist das der laufende Titel selbst, bei vielen kurzen
  // Dateien entsprechend mehrere Titel im Voraus. Nach oben begrenzt ein
  // Datenlimit, damit auf Mobilfunk nicht unbemerkt sehr viel geladen wird.
  // ------------------------------------------------------------------
  const PREFETCH_CACHE = 'audioarchive-prefetch-audio';
  const OFFLINE_AUDIO_CACHE_PLAYER = 'audioarchive-offline-audio';

  /*
   * Vorausladen gegen Verbindungsabbrueche.
   *
   * WICHTIG - der Hintergrund-Download teilt sich die Bandbreite mit der
   * laufenden Wiedergabe. Solange der Service Worker die gepufferte Datei
   * nicht ausliefert, kostet er also nur Bandbreite und laesst die
   * Wiedergabe stocken (genau das war zwischenzeitlich der Fall). Er wird
   * deshalb erst gestartet, wenn ein Service Worker die Seite auch wirklich
   * steuert.
   */
  const PREFETCH_ENABLED = true;

  /** Steuert ein Service Worker diese Seite? Nur dann nuetzt das Vorausladen. */
  function serviceWorkerActive() {
    return 'serviceWorker' in navigator && !!navigator.serviceWorker.controller;
  }

  /** Zielgroesse des Puffers in Sekunden Wiedergabe (90 Minuten). */
  const PREFETCH_TARGET_SECONDS = 90 * 60;

  /** Harte Obergrenze, damit der Puffer nicht unbegrenzt Daten zieht. */
  const PREFETCH_MAX_BYTES = 250 * 1024 * 1024;

  /**
   * Schaetzt die Laufzeit einer Datei aus ihrer Groesse. Die echte Dauer
   * kennt nur der Player, und zwar erst nach dem Laden - fuer die Planung
   * des Puffers genuegt die Abschaetzung ueber eine uebliche mp3-Bitrate.
   */
  function estimateSeconds(track) {
    const ASSUMED_BITRATE_BYTES_PER_SEC = 128000 / 8; // 128 kbit/s
    if (!track || !track.size) return 0;
    return track.size / ASSUMED_BITRATE_BYTES_PER_SEC;
  }

  /**
   * Bestimmt, welche Titel ab dem aktuellen Index vorgeladen werden sollen,
   * bis die Ziel-Pufferzeit bzw. das Datenlimit erreicht ist. Der laufende
   * Titel ist immer dabei - er hat Vorrang vor allem anderen.
   */
  function planPrefetch(startIndex) {
    const planned = [];
    let seconds = 0;
    let bytes = 0;

    for (let i = startIndex; i < playlist.length; i++) {
      const track = playlist[i];
      if (!track) break;

      const trackBytes = track.size || 0;
      // Ab dem zweiten Titel greifen die Grenzen - der laufende immer zuerst.
      if (i > startIndex) {
        if (seconds >= PREFETCH_TARGET_SECONDS) break;
        if (bytes + trackBytes > PREFETCH_MAX_BYTES) break;
      }

      planned.push(track);
      bytes += trackBytes;

      // Fuer den laufenden Titel zaehlt nur die verbleibende Restzeit
      const full = estimateSeconds(track);
      seconds += (i === startIndex && isFinite(audio.duration) && audio.duration > 0)
        ? Math.max(0, audio.duration - audio.currentTime)
        : full;
    }

    return planned;
  }

  const prefetchSupported = 'caches' in window;
  let prefetchController = null;

  /** Adresse einer Aufnahme - die Quelle (gemeinsam/eigene Dateien) reist mit dem Titel. */
  function streamUrlFor(track) {
    return AudioArchive.streamUrl(track.path, track.source);
  }

  /** Haelt den Vorauslade-Cache klein: nur die uebergebenen URLs bleiben drin. */
  async function trimPrefetchCache(keepUrls) {
    try {
      const cache = await caches.open(PREFETCH_CACHE);
      const keys = await cache.keys();
      await Promise.all(
        keys
          .filter((req) => !keepUrls.includes(req.url))
          .map((req) => cache.delete(req))
      );
    } catch (err) {
      // Nicht kritisch - im Zweifel bleibt etwas mehr im Cache liegen.
    }
  }

  async function prefetchTrack(track, signal) {
    if (!track) return;
    const url = streamUrlFor(track);

    try {
      // Schon dauerhaft offline gespeichert? Dann ist nichts zu tun.
      const offline = await caches.open(OFFLINE_AUDIO_CACHE_PLAYER);
      if (await offline.match(url)) return;

      const cache = await caches.open(PREFETCH_CACHE);
      if (await cache.match(url)) return;

      // Ohne Range-Header anfordern, damit die vollstaendige Datei als
      // 200-Antwort im Cache landet (206-Teilantworten sind nicht speicherbar).
      const res = await fetch(url, { credentials: 'same-origin', signal });
      if (!res.ok) return;
      await cache.put(url, res);
    } catch (err) {
      // Abbruch beim Titelwechsel oder fehlende Verbindung - beides harmlos,
      // die Wiedergabe laeuft normal ueber das Netzwerk weiter.
    }
  }

  function startPrefetch(index) {
    if (!PREFETCH_ENABLED || !prefetchSupported || !serviceWorkerActive()) return;

    if (prefetchController) prefetchController.abort();
    prefetchController = new AbortController();
    const { signal } = prefetchController;

    const planned = planPrefetch(index);
    trimPrefetchCache(planned.map((t) => streamUrlFor(t)));

    // Streng der Reihe nach: Der laufende Titel wird zuerst komplett
    // gesichert, erst danach die folgenden. So ist das, was gerade gehoert
    // wird, am schnellsten gegen Verbindungsabbrueche geschuetzt - und die
    // Wiedergabe muss sich die Bandbreite nicht mit mehreren Downloads teilen.
    (async () => {
      for (const track of planned) {
        if (signal.aborted) return;
        await prefetchTrack(track, signal);
      }
    })();
  }

  /*
   * Wird beim Titelwechsel gesetzt und erst geloescht, wenn die Angaben
   * danach wieder sicher beim System angekommen sind.
   *
   * Hintergrund: Beim Zuweisen einer neuen Quelle setzt der Browser das
   * Audio-Element zurueck. Android raeumt in dieser Luecke die
   * Medien-Benachrichtigung ab - und zwar teils NACH unserem Setzen der
   * Angaben, sodass ein einmaliges Setzen vor dem Laden verpufft. Deshalb
   * werden sie bei den naechsten Ereignissen erneut gesetzt, bis es sitzt.
   */
  let mediaSessionNeedsRefresh = false;

  function refreshMediaSession() {
    if (!mediaSessionNeedsRefresh) return;

    const track = playlist[currentIndex];
    if (!track) return;

    updateMediaSession(track);
    setMediaSessionPlaybackState(audio.paused ? 'paused' : 'playing');
    updatePositionState();

    // Erst wenn die Laufzeit feststeht, ist die Sitzung wirklich vollstaendig
    if (isFinite(audio.duration) && audio.duration > 0) {
      mediaSessionNeedsRefresh = false;
    }
  }

  function loadTrack(index, autoplay = true) {
    if (index < 0 || index >= playlist.length) return;
    currentIndex = index;
    const track = playlist[index];

    mediaSessionNeedsRefresh = true;

    audio.src = streamUrlFor(track);
    els.title.textContent = trackTitle(track);
    els.context.textContent = trackContext(track);
    showCover(track);
    els.bar.hidden = false;
    updatePlayerBarSpace();

    updateMediaSession(track);
    startPrefetch(index);
    if (!els.details.hidden) loadDetails();

    if (autoplay) {
      audio.play().catch(() => {
        // Autoplay ohne Geste (z.B. beim Start der App) kann blockiert werden -
        // das ist normal, der Nutzer muss dann selbst auf Play tippen.
      });
    }

    if (typeof onTrackChange === 'function') {
      onTrackChange(track, currentIndex);
    }
  }

  audio.addEventListener('seeked', updatePositionState);

  audio.addEventListener('play', () => {
    updatePlayPauseIcon();
    setMediaSessionPlaybackState('playing');
    updatePositionState();
    if (typeof onPlayStateChange === 'function') onPlayStateChange(true);
  });

  audio.addEventListener('pause', () => {
    updatePlayPauseIcon();
    setMediaSessionPlaybackState('paused');
    updatePositionState();
    if (typeof onPlayStateChange === 'function') onPlayStateChange(false);
  });

  audio.addEventListener('timeupdate', () => {
    if (!isFinite(audio.duration)) return;
    els.timeCurrent.textContent = formatTime(audio.currentTime);
    els.timeDuration.textContent = formatTime(audio.duration);
    if (!els.seek.dragging) {
      els.seek.value = (audio.currentTime / audio.duration) * 100 || 0;
    }

    /*
     * Position gedrosselt an das System melden - hoechstens alle fuenf
     * Sekunden. Bei jedem timeupdate (rund viermal je Sekunde) baut Android
     * die Medien-Benachrichtigung samt Titelbild neu auf, was auf aelteren
     * Geraeten sichtbar flackert. Ganz ohne Meldung laeuft der Fortschritt
     * in der Benachrichtigung dagegen aus dem Ruder, sobald gesprungen oder
     * zwischen Titeln gewechselt wurde - deshalb dieser Mittelweg.
     */
    if (Date.now() - lastPositionReport > 5000) {
      updatePositionState();
    }

    // Letzte Sicherung: Sollten die Angaben nach einem Wechsel noch nicht
    // sitzen, werden sie hier nachgereicht.
    refreshMediaSession();
    /*
     * WICHTIG: Hier bewusst KEIN updatePositionState()!
     * 'timeupdate' feuert rund 4x pro Sekunde. Jeder setPositionState()-Aufruf
     * laesst Android die Medien-Benachrichtigung neu aufbauen - inklusive
     * Titelbild. Auf aelteren Geraeten (z.B. Android 9) fuehrt das zu
     * sichtbarem Flackern des Covers mehrmals pro Sekunde.
     * Das System zaehlt die Position anhand von position + playbackRate
     * selbst weiter; gemeldet werden muss sie nur, wenn sie SPRINGT
     * (Laden, Play, Pause, Spulen) - genau das passiert unten.
     */
  });

  audio.addEventListener('loadedmetadata', () => {
    els.timeDuration.textContent = formatTime(audio.duration);
    updatePositionState();
    refreshMediaSession();
  });

  /*
   * 'playing' feuert, sobald wirklich Ton kommt - erst dann steht die
   * Laufzeit fest. Beim Wechsel zum naechsten Titel wird die Quelle
   * getauscht; in dieser Luecke verwirft Android die Benachrichtigung
   * mitunter. Deshalb werden Titelangaben und Zustand hier noch einmal
   * gesetzt, statt sich auf das Setzen vor dem Laden zu verlassen.
   */
  audio.addEventListener('playing', refreshMediaSession);
  audio.addEventListener('loadeddata', refreshMediaSession);
  audio.addEventListener('durationchange', refreshMediaSession);

  // Kritisch: Beim Ende automatisch den nächsten Titel im selben Ordner starten
  audio.addEventListener('ended', () => {
    if (currentIndex + 1 < playlist.length) {
      /*
       * Zustand bewusst auf 'playing' belassen: Beim Wechsel zum naechsten
       * Titel entsteht eine kurze Luecke, in der keine Quelle geladen ist.
       * Wird hier auf 'paused' gestellt, raeumt Android die
       * Medien-Benachrichtigung in dieser Luecke weg - der Ton laeuft dann
       * zwar weiter, aber ohne Steuerung am Sperrbildschirm.
       */
      loadTrack(currentIndex + 1, true);
      return;
    }
    // Ende des Ordners: je nach Wiederholen-Stufe
    finishQueue(true);
  });

  /**
   * Der letzte Titel des Ordners ist vorbei (oder "Naechster" wurde dort
   * gedrueckt). automatic = durch das Ende der Wiedergabe ausgeloest.
   */
  async function finishQueue(automatic) {
    if (repeatMode === 'folder' && playlist.length > 0) {
      loadTrack(0, true);
      return;
    }
    if (repeatMode === 'next' && typeof onQueueEnd === 'function' && playlist.length > 0) {
      const last = playlist[playlist.length - 1];
      const folder = last.path.includes('/') ? last.path.slice(0, last.path.lastIndexOf('/')) : '';
      let next = null;
      try {
        next = await onQueueEnd({ source: last.source, folder });
      } catch (err) {
        next = null;
      }
      if (next && Array.isArray(next.tracks) && next.tracks.length > 0) {
        playlist = next.tracks;
        contextLabel = next.label || '';
        showToast('Weiter mit: ' + (next.label || 'nächster Ordner'));
        loadTrack(0, true);
        return;
      }
      showToast('Kein weiterer Ordner – Wiedergabe beendet');
    }
    if (automatic) setMediaSessionPlaybackState('paused');
  }

  // ------------------------------------------------------------------
  // Wiederholen (ab 0.15)
  //
  // Ein Knopf mit vier Stufen:
  //   off    - am Ende des Ordners anhalten (bisheriges Verhalten)
  //   next   - danach mit dem naechsten Ordner weiter (Baum-Reihenfolge,
  //            die Suche uebernimmt app.js ueber onQueueEnd)
  //   folder - den Ordner von vorn
  //   one    - den Titel endlos (audio.loop, dadurch ohne Luecke)
  // Die Wahl wird je Geraet gemerkt.
  // ------------------------------------------------------------------
  const REPEAT_MODES = ['off', 'next', 'folder', 'one'];
  const REPEAT_LABELS = {
    off: 'Wiederholen aus',
    next: 'Danach nächster Ordner',
    folder: 'Ordner wiederholen',
    one: 'Titel wiederholen',
  };
  const REPEAT_KEY = 'audioarchive_repeat';

  let repeatMode = 'off';
  try {
    const stored = localStorage.getItem(REPEAT_KEY);
    if (REPEAT_MODES.includes(stored)) repeatMode = stored;
  } catch (e) { /* ohne Speicher: Vorgabe */ }

  function applyRepeatMode() {
    audio.loop = repeatMode === 'one';
    els.btnRepeat.dataset.mode = repeatMode;
    els.btnRepeat.classList.toggle('is-on', repeatMode !== 'off');
    const label = REPEAT_LABELS[repeatMode];
    els.btnRepeat.setAttribute('aria-label', label);
    els.btnRepeat.title = label;
    els.repeatLabel.textContent = label;
  }

  function setRepeatMode(mode) {
    repeatMode = REPEAT_MODES.includes(mode) ? mode : 'off';
    try { localStorage.setItem(REPEAT_KEY, repeatMode); } catch (e) { /* egal */ }
    applyRepeatMode();
  }

  els.btnRepeat.addEventListener('click', () => {
    const next = REPEAT_MODES[(REPEAT_MODES.indexOf(repeatMode) + 1) % REPEAT_MODES.length];
    setRepeatMode(next);
    showToast(REPEAT_LABELS[next]);
  });
  applyRepeatMode();

  let toastTimer = null;
  function showToast(text) {
    els.toast.textContent = text;
    els.toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { els.toast.hidden = true; }, 2200);
  }

  // ------------------------------------------------------------------
  // Angaben zur Aufnahme (ab 0.15)
  //
  // Sofort aus dem, was die Ordnerliste schon liefert; die ausfuehrlichen
  // Angaben (Jahr, Genre, Bitrate, ...) kommen per api/info nach. Ohne
  // Verbindung bleibt es bei den Grundangaben.
  // ------------------------------------------------------------------
  let detailsRequest = 0;

  function formatSize(bytes) {
    if (!bytes && bytes !== 0) return '';
    const mb = bytes / (1024 * 1024);
    return mb >= 1
      ? mb.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' MB'
      : Math.round(bytes / 1024).toLocaleString('de-DE') + ' KB';
  }

  function formatDuration(seconds) {
    if (!isFinite(seconds) || seconds <= 0) return '';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return h > 0 ? `${h}:${m.toString().padStart(2, '0')}:${s}` : `${m}:${s}`;
  }

  function renderDetails(track, info, note) {
    const t = (info && info.tags) || {};
    const f = (info && info.file) || {};
    const val = (v) => (v === null || v === undefined ? '' : String(v).trim());
    const pick = (...values) => values.map(val).find((v) => v !== '') || '';

    const count = (v) => {
      const m = /^(\d+)\s*\/\s*(\d+)$/.exec(val(v));
      return m ? `${m[1]} von ${m[2]}` : val(v);
    };

    const technical = [];
    if (t.bitrate) technical.push(`${t.bitrate} kbit/s${t.vbr ? ' (variabel)' : ''}`);
    if (t.sampleRate) technical.push((t.sampleRate / 1000).toLocaleString('de-DE') + ' kHz');
    if (t.channels) technical.push(t.channels === 1 ? 'Mono' : 'Stereo');

    const folder = f.folder !== undefined ? f.folder
      : (track.path.includes('/') ? track.path.slice(0, track.path.lastIndexOf('/')) : '');

    const groups = [
      ['Aufnahme', [
        ['Titel', pick(t.title, track.title, track.name)],
        ['Künstler', pick(t.artist, track.artist)],
        ['Album', pick(t.album, track.album)],
        ['Albumkünstler', val(t.albumArtist)],
        ['Jahr', val(t.year)],
        ['Genre', val(t.genre)],
        ['Titelnummer', count(t.track)],
        ['CD', count(t.disc)],
        ['Komponist', val(t.composer)],
        ['Kommentar', val(t.comment)],
      ]],
      ['Wiedergabe', [
        ['Dauer', formatDuration(t.duration || track.duration || audio.duration)],
        ['Qualität', technical.join(' · ')],
        ['Format', pick(t.format, 'MP3')],
      ]],
      ['Datei', [
        ['Name', pick(f.name, track.file, track.name)],
        ['Ordner', folder === '' ? '(oberste Ebene)' : folder.split('/').join(' / ')],
        ['Größe', formatSize(f.size !== undefined ? f.size : track.size)],
        ['Geändert', f.mtime ? new Date(f.mtime * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : ''],
      ]],
    ];

    els.details.textContent = '';
    groups.forEach(([heading, rows]) => {
      const filled = rows.filter(([, v]) => v !== '');
      if (filled.length === 0) return;
      const h = document.createElement('h3');
      h.className = 'player-details-heading';
      h.textContent = heading;
      const dl = document.createElement('dl');
      dl.className = 'player-details-list';
      filled.forEach(([label, value]) => {
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.textContent = value; // reiner Text - Tags stammen aus fremden Dateien
        dl.append(dt, dd);
      });
      els.details.append(h, dl);
    });
    if (note) {
      const p = document.createElement('p');
      p.className = 'player-details-note';
      p.textContent = note;
      els.details.appendChild(p);
    }
  }

  async function loadDetails() {
    const track = playlist[currentIndex];
    if (!track || els.details.hidden) return;
    const request = ++detailsRequest;
    renderDetails(track, null, 'Lade weitere Angaben …');
    try {
      const res = await fetch(AudioArchive.infoUrl(track.path, track.source), { credentials: 'same-origin' });
      if (!res.ok) throw new Error('info');
      const info = await res.json();
      if (request === detailsRequest) renderDetails(track, info, '');
    } catch (err) {
      if (request === detailsRequest) renderDetails(track, null, 'Weitere Angaben nur mit Verbindung.');
    }
  }

  function setDetailsOpen(open) {
    els.details.hidden = !open;
    els.bar.classList.toggle('show-details', open);
    els.btnInfo.setAttribute('aria-expanded', open ? 'true' : 'false');
    els.btnInfo.classList.toggle('is-on', open);
    if (open) {
      loadDetails();
      // Die Angaben stehen unter dem Titel - dorthin scrollen
      requestAnimationFrame(() => els.details.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    }
  }

  els.btnInfo.addEventListener('click', () => setDetailsOpen(els.details.hidden));

  audio.addEventListener('error', () => {
    els.title.textContent = 'Wiedergabe fehlgeschlagen';
  });

  // --- Bedienelemente in der Player-Leiste ---
  els.btnPlayPause.addEventListener('click', () => {
    if (audio.paused) {
      Player.resume();
    } else {
      Player.pause();
    }
  });

  els.btnPrev.addEventListener('click', () => Player.prev());
  els.btnNext.addEventListener('click', () => Player.next());

  els.btnSeekBack.addEventListener('click', () => {
    audio.currentTime = Math.max(0, audio.currentTime - SEEK_STEP);
  });
  els.btnSeekForward.addEventListener('click', () => {
    const dur = isFinite(audio.duration) ? audio.duration : Infinity;
    audio.currentTime = Math.min(dur, audio.currentTime + SEEK_STEP);
  });

  els.seek.addEventListener('input', () => {
    els.seek.dragging = true;
    if (isFinite(audio.duration)) {
      els.timeCurrent.textContent = formatTime((els.seek.value / 100) * audio.duration);
    }
  });

  els.seek.addEventListener('change', () => {
    if (isFinite(audio.duration)) {
      audio.currentTime = (els.seek.value / 100) * audio.duration;
    }
    els.seek.dragging = false;
  });

  // ------------------------------------------------------------------
  // Vollbild-Player (ab 0.14)
  //
  // Dieselbe Leiste, nur gross: Cover oben, darunter Titel, Fortschritt und
  // Steuerung. Beim Oeffnen entsteht ein Verlaufseintrag, damit die
  // Zurueck-Geste (Android) bzw. die Zurueck-Taste den Player schliesst,
  // statt die Ordneransicht zu wechseln oder die App zu verlassen.
  // ------------------------------------------------------------------
  let expandedByHistory = false;

  function setExpanded(open) {
    els.bar.classList.toggle('is-expanded', open);
    document.getElementById('audioarchive').classList.toggle('aa-player-expanded', open);
    els.btnExpand.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      els.btnCollapse.focus({ preventScroll: true });
    } else {
      // Die Angaben gehoeren zum Vollbild - beim Verkleinern zuklappen
      if (!els.details.hidden) setDetailsOpen(false);
      updatePlayerBarSpace();
    }
  }

  function expand() {
    if (els.bar.hidden || els.bar.classList.contains('is-expanded')) return;
    setExpanded(true);
    try {
      history.pushState({ ...(history.state || {}), aaPlayerExpanded: true }, '');
      expandedByHistory = true;
    } catch (e) {
      expandedByHistory = false;
    }
  }

  function collapse() {
    if (!els.bar.classList.contains('is-expanded')) return;
    if (expandedByHistory) {
      // Der popstate-Handler unten schliesst dann
      history.back();
      return;
    }
    setExpanded(false);
  }

  els.btnExpand.addEventListener('click', expand);
  els.coverBtn.addEventListener('click', () => {
    if (els.bar.classList.contains('is-expanded')) return;
    expand();
  });
  els.btnCollapse.addEventListener('click', collapse);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && els.bar.classList.contains('is-expanded')) collapse();
  });

  /*
   * Laeuft VOR dem Handler in app.js (player.js wird zuerst geladen). Ist
   * der Player offen, schliesst Zurueck nur ihn - die Ordneransicht soll
   * davon nichts merken, deshalb stopImmediatePropagation.
   */
  window.addEventListener('popstate', (event) => {
    if (!els.bar.classList.contains('is-expanded')) return;
    expandedByHistory = false;
    setExpanded(false);
    event.stopImmediatePropagation();
  });

  return {
    /**
     * Startet eine neue "Playlist" (= Inhalt eines Kategorie-Ordners) ab einem
     * bestimmten Titel-Index. tracks: [{name, path}], label: Anzeigekontext.
     */
    playFolder(tracks, startIndex, label) {
      playlist = tracks;
      contextLabel = label;
      loadTrack(startIndex, true);
    },

    resume() {
      audio.play().catch(() => {});
    },

    pause() {
      audio.pause();
    },

    prev() {
      if (currentIndex > 0) {
        loadTrack(currentIndex - 1, true);
      } else {
        audio.currentTime = 0;
      }
    },

    next() {
      if (currentIndex + 1 < playlist.length) {
        loadTrack(currentIndex + 1, true);
        return;
      }
      // Am Ende des Ordners wie beim natuerlichen Ende - ausser bei
      // "Titel wiederholen": Dort soll "Naechster" nicht haengen bleiben
      if (repeatMode === 'folder' || repeatMode === 'one') {
        loadTrack(0, true);
      } else if (repeatMode === 'next') {
        finishQueue(false);
      }
    },

    getCurrentPath() {
      return currentIndex >= 0 ? playlist[currentIndex].path : null;
    },

    /**
     * Eindeutige Kennung des laufenden Titels: Quelle und Pfad. Der Pfad
     * allein reicht nicht - dieselbe Datei kann im gemeinsamen Ordner und in
     * den eigenen Dateien gleich heissen.
     */
    getCurrentKey() {
      if (currentIndex < 0) return null;
      const track = playlist[currentIndex];
      return (track.source || 'shared') + '|' + track.path;
    },

    onTrackChange(callback) {
      onTrackChange = callback;
    },

    /**
     * Callback fuer "danach naechster Ordner": bekommt {source, folder} des
     * fertigen Ordners und liefert (Promise) {tracks, label} oder null.
     */
    onQueueEnd(callback) {
      onQueueEnd = callback;
    },

    getRepeatMode() {
      return repeatMode;
    },

    /** Meldet Play/Pause-Wechsel, damit die Liste die Animation anhalten kann. */
    onPlayStateChange(callback) {
      onPlayStateChange = callback;
    },

    /** Vollbild-Player oeffnen bzw. schliessen. */
    expand,
    collapse,

    /** true, solange tatsächlich Ton läuft (nicht pausiert / nicht beendet). */
    isPlaying() {
      return !audio.paused && !audio.ended;
    },
  };
})();
