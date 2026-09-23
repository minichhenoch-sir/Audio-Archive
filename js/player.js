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
    scrim: document.getElementById('player-scrim'),
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
  // Laufschrift (ab 0.15.4)
  //
  // Passen Titel oder Zusatzzeile in der Leiste nicht in eine Zeile,
  // wandert der Text langsam hin und her, statt abgeschnitten zu werden.
  // Bewegt wird ein inneres <span>; die Strecke (Ueberstand) und die Dauer
  // setzt das Skript als CSS-Variablen. Im Vollbild-Player bricht der Text
  // stattdessen um. Bei "Bewegung reduzieren" bleibt es beim Abschneiden
  // mit "…" (nur CSS, siehe style.css).
  // ------------------------------------------------------------------
  const MARQUEE_SPEED = 30; // Punkte pro Sekunde - gut mitlesbar

  function setMarqueeText(el, text) {
    const inner = document.createElement('span');
    inner.className = 'marquee-inner';
    inner.textContent = text;
    el.replaceChildren(inner);
    updateMarquee(el);
  }

  function updateMarquee(el) {
    el.classList.remove('is-marquee');
    if (els.bar.hidden || els.bar.classList.contains('is-expanded')) return;
    const overflow = el.scrollWidth - el.clientWidth;
    if (overflow <= 2) return;
    // 70 % der Zeit in Bewegung, je 15 % Pause an Anfang und Ende
    const travel = Math.max(4, overflow / MARQUEE_SPEED);
    el.style.setProperty('--aa-marquee-shift', (-overflow) + 'px');
    el.style.setProperty('--aa-marquee-duration', (travel / 0.7).toFixed(2) + 's');
    el.classList.add('is-marquee');
  }

  function updateMarquees() {
    updateMarquee(els.title);
    updateMarquee(els.context);
  }

  if ('ResizeObserver' in window) {
    const marqueeObserver = new ResizeObserver(updateMarquees);
    marqueeObserver.observe(els.title);
    marqueeObserver.observe(els.context);
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(updateMarquees);

  // ------------------------------------------------------------------
  // Cover (ab 0.14)
  //
  // Quelle: eingebettetes Bild der mp3, sonst cover.jpg o. ae. im Ordner -
  // das entscheidet der Server, die Ordnerliste meldet nur, OB es eins gibt
  // (track.cover = Versionskennung). Ohne Cover, oder wenn es nicht laedt
  // (etwa offline und nicht gespeichert), erscheint das App-Symbol.
  // ------------------------------------------------------------------
  // Ab 0.16 in der Leistenfarbe - deshalb eine Funktion statt fester Adresse
  function fallbackCover() {
    return AudioArchive.iconUrl('any-512');
  }

  /** Adresse des Covers eines Titels, oder null ohne Cover. */
  function coverUrlFor(track) {
    return track && track.cover ? AudioArchive.coverUrl(track.path, track.source, track.cover) : null;
  }

  function showCover(track) {
    const url = coverUrlFor(track);
    els.bar.classList.toggle('has-cover', !!url);
    // Kein Cover -> farbiges Symbol -> (falls auch das nicht laedt, etwa
    // offline oder gedrosselt) das mitgelieferte blaue Symbol
    const colored = fallbackCover();
    const plain = AudioArchive.asset('img/icon-512.png');
    els.cover.onerror = () => {
      els.bar.classList.remove('has-cover');
      els.bar.style.removeProperty('--aa-cover');
      const next = els.cover.src === colored ? plain : colored;
      if (next === plain) els.cover.onerror = null;
      els.cover.src = next;
    };
    els.cover.src = url || colored;
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
        { src: AudioArchive.iconUrl('any-192'), sizes: '192x192', type: 'image/png' },
        { src: AudioArchive.iconUrl('any-512'), sizes: '512x512', type: 'image/png' },
      ];

    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: trackTitle(track),
        artist: (track.artist && track.artist.trim()) || '',
        album: (track.album && track.album.trim()) || '',
        artwork,
      });
    } catch (e) {
      // Sehr alte Browser ohne MediaMetadata: Ton laeuft trotzdem
    }

  }

  /*
   * Bedienung auf dem Sperrbildschirm und in der Benachrichtigung.
   *
   * Einmal beim Start registriert (nicht bei jedem Titel neu). Jede Aktion
   * einzeln abgesichert: Aeltere Browser (etwa Safari vor iOS 15) kennen
   * 'seekto' nicht und WERFEN beim Registrieren. Ohne Absicherung brach das
   * frueher das Setzen aller folgenden Aktionen ab - und, weil es beim
   * Titelwechsel passierte, gleich den ganzen Titelwechsel.
   *
   * Spulen:
   *   - seekto:        Fortschrittsbalken ziehen (Android-Benachrichtigung,
   *                    iOS-Sperrbildschirm, Desktop). Braucht zusaetzlich
   *                    setPositionState() - siehe updatePositionState().
   *   - seekbackward / seekforward: 15-Sekunden-Knoepfe. Android zeigt sie in
   *                    der aufgeklappten Benachrichtigung neben Vor/Zurueck.
   *                    iOS zeigt nur EIN Paar Knoepfe und bevorzugt dabei
   *                    Titel vor/zurueck - dort wird ueber den Balken gespult.
   */
  function seekBy(seconds) {
    const duration = isFinite(audio.duration) ? audio.duration : Infinity;
    audio.currentTime = Math.min(Math.max(0, audio.currentTime + seconds), Math.max(0, duration - 0.25));
    updatePositionState();
  }

  function registerMediaActions() {
    if (!('mediaSession' in navigator)) return;
    const actions = {
      play: () => Player.resume(),
      pause: () => Player.pause(),
      previoustrack: () => Player.prev(),
      nexttrack: () => Player.next(),
      seekbackward: (details) => seekBy(-((details && details.seekOffset) || SEEK_STEP)),
      seekforward: (details) => seekBy((details && details.seekOffset) || SEEK_STEP),
      seekto: (details) => {
        if (!details || !isFinite(details.seekTime)) return;
        const duration = isFinite(audio.duration) ? audio.duration : Infinity;
        const target = Math.min(Math.max(0, details.seekTime), Math.max(0, duration - 0.25));
        if (details.fastSeek && 'fastSeek' in audio) {
          audio.fastSeek(target);
        } else {
          audio.currentTime = target;
        }
        updatePositionState();
      },
      stop: () => {
        audio.pause();
        audio.currentTime = 0;
        updatePositionState();
      },
    };
    Object.keys(actions).forEach((action) => {
      try {
        navigator.mediaSession.setActionHandler(action, actions[action]);
      } catch (e) {
        // Aktion wird von diesem Browser nicht unterstuetzt - die uebrigen trotzdem
      }
    });
  }

  function setMediaSessionPlaybackState(state) {
    if ('mediaSession' in navigator) {
      navigator.mediaSession.playbackState = state; // 'playing' | 'paused'
    }
  }

  /*
   * Zuletzt gemeldete Position (ab 0.15.6). Das System zaehlt nach einer
   * Meldung selbst weiter (position + playbackRate). Neu gemeldet wird nur
   * noch, wenn die tatsaechliche Position davon um mehr als 2 s abweicht -
   * etwa nach einer Nachladepause. Vorher wurde alle 5 s gemeldet; jede
   * Meldung laesst Android (sichtbar auf Android 9 / Chrome 114) die
   * Benachrichtigung samt Titelbild neu aufbauen - das Symbol blinkte.
   */
  let reportedPosition = 0;
  let reportedAt = 0;
  let reportedRate = 1;

  function positionDrifted() {
    if (audio.paused || audio.seeking || !reportedAt) return false;
    const expected = reportedPosition + ((Date.now() - reportedAt) / 1000) * reportedRate;
    return Math.abs(expected - audio.currentTime) > 2;
  }

  function updatePositionState() {
    if ('mediaSession' in navigator && 'setPositionState' in navigator.mediaSession) {
      if (isFinite(audio.duration) && audio.duration > 0) {
        /*
         * Die Position darf die Dauer nicht uebersteigen und nicht negativ
         * sein, sonst wirft der Browser (am Titelende kommt currentTime
         * gelegentlich einen Hauch ueber duration). Ohne gueltige Angabe
         * zeigt die Benachrichtigung keinen Fortschritt und kein Spulen.
         */
        try {
          const rate = audio.playbackRate > 0 ? audio.playbackRate : 1;
          const position = Math.min(Math.max(0, audio.currentTime || 0), audio.duration);
          navigator.mediaSession.setPositionState({
            duration: audio.duration,
            playbackRate: rate,
            position,
          });
          reportedPosition = position;
          reportedAt = Date.now();
          reportedRate = rate;
        } catch (e) {
          // Nicht kritisch - dann ohne Fortschritt
        }
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
    pendingRecovery = null;

    audio.src = streamUrlFor(track);
    setMarqueeText(els.title, trackTitle(track));
    setMarqueeText(els.context, trackContext(track));
    showCover(track);
    els.bar.hidden = false;
    updateMarquees();
    updatePlayerBarSpace();

    updateMediaSession(track);
    startPrefetch(index);
    if (!els.details.hidden) loadDetails();
    // Letzter Titel und "danach naechster Ordner": schon mal vorbereiten
    if (repeatMode === 'next' && index === playlist.length - 1) prepareNextFolder();

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
    if (positionDrifted()) {
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
      let next = null;
      try {
        next = await prepareNextFolder();
      } catch (err) {
        next = null;
      }
      if (next && Array.isArray(next.tracks) && next.tracks.length > 0) {
        playlist = next.tracks;
        preparedNext = null;
        showToast('Weiter mit: ' + (next.label || 'nächster Ordner'));
        loadTrack(0, true);
        if (typeof next.onStart === 'function') next.onStart();
        return;
      }
      showToast('Kein weiterer Ordner – Wiedergabe beendet');
    }
    if (automatic) setMediaSessionPlaybackState('paused');
  }

  /*
   * Naechsten Ordner schon WAEHREND des letzten Titels ermitteln.
   *
   * Grund: Auf dem Sperrbildschirm laeuft die Seite im Hintergrund. Muss
   * erst nach dem Titelende gesucht und geladen werden, entsteht eine
   * Pause ohne Ton - manche Systeme (iOS, stromsparende Android-Geraete)
   * frieren die Seite dann ein, oder das Weiterspielen wird ohne neue
   * Beruehrung nicht mehr erlaubt. Liegt die neue Liste schon bereit, geht
   * es ohne Luecke weiter.
   */
  let preparedNext = null; // { key, promise }

  function queueKey() {
    const last = playlist[playlist.length - 1];
    return last ? (last.source || 'shared') + '|' + last.path : '';
  }

  function prepareNextFolder() {
    const key = queueKey();
    if (preparedNext && preparedNext.key === key) return preparedNext.promise;
    const last = playlist[playlist.length - 1];
    if (!last || typeof onQueueEnd !== 'function') return Promise.resolve(null);
    const folder = last.path.includes('/') ? last.path.slice(0, last.path.lastIndexOf('/')) : '';
    const promise = Promise.resolve(onQueueEnd({ source: last.source, folder })).catch(() => null);
    preparedNext = { key, promise };
    // Fehlschlag nicht festhalten - beim naechsten Mal neu versuchen
    promise.then((result) => {
      if (!result && preparedNext && preparedNext.key === key) preparedNext = null;
    });
    return promise;
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
    if (next === 'next' && currentIndex === playlist.length - 1) prepareNextFolder();
  });
  applyRepeatMode();
  registerMediaActions();

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

  // ------------------------------------------------------------------
  // Wiederaufnahme nach Abbruechen (ab 0.15.1)
  //
  // Bricht die Datenquelle ab - Funkloch, Wechsel WLAN/Mobilfunk, oder beim
  // Spulen in einer vom Service Worker gelieferten Aufnahme (in Chromium
  // reproduzierbar: "PIPELINE_ERROR_READ") -, bleibt ein <audio>-Element
  // sonst einfach stehen. Gerade auf dem Sperrbildschirm faellt das nicht
  // auf. Deshalb: Quelle neu setzen, an dieselbe Stelle springen und
  // weiterspielen - hoechstens dreimal je Titel.
  //
  // Die neue Adresse traegt 'retry=N', damit der Browser wirklich neu
  // anfragt statt den kaputten Zwischenstand weiterzuverwenden. Der Service
  // Worker ignoriert den Zusatz beim Nachschlagen im Offline-Speicher.
  // ------------------------------------------------------------------
  let recoveries = 0;
  let recoveryKey = '';
  let resumeAt = null;      // Position nach dem Neuladen
  let resumePlay = false;
  let pendingRecovery = null; // im Pausenzustand aufgeschobenes Neuladen (ab 0.15.6)
  let lastGoodTime = 0;     // letzte sicher erreichte Position
  let intendedTime = null;  // Ziel eines laufenden Sprungs

  audio.addEventListener('seeking', () => { intendedTime = audio.currentTime; });
  audio.addEventListener('seeked', () => { intendedTime = null; lastGoodTime = audio.currentTime; });
  audio.addEventListener('timeupdate', () => { if (!audio.seeking) lastGoodTime = audio.currentTime; });

  audio.addEventListener('error', () => {
    const track = playlist[currentIndex];
    const code = audio.error ? audio.error.code : 0;
    const key = track ? (track.source || 'shared') + '|' + track.path : '';
    if (key !== recoveryKey) {
      recoveryKey = key;
      recoveries = 0;
    }
    // 2 = Netzwerk, 3 = Dekodierung (oft Folge eines abgerissenen Stroms)
    if (track && (code === 2 || code === 3) && recoveries < 3) {
      recoveries++;
      resumeAt = intendedTime !== null ? intendedTime : lastGoodTime;
      const base = streamUrlFor(track);
      const attempt = recoveries;
      const reload = () => {
        audio.src = base + (base.includes('?') ? '&' : '?') + 'retry=' + attempt;
        audio.load();
      };
      /*
       * Ab 0.15.6: Reisst die Verbindung WAEHREND einer Pause ab, wird nicht
       * sofort neu geladen. Ein Quellwechsel beendet die Medien-Sitzung -
       * Android nimmt dann die Benachrichtigung weg, und Kopfhoerer-Tasten
       * erreichen die Seite nicht mehr. Geladen wird erst beim naechsten
       * Abspielen; bis dahin bleibt die Sitzung (pausiert) bestehen.
       * Vorher wurde ausserdem immer weitergespielt, auch aus der Pause.
       */
      if (audio.paused) {
        resumePlay = false;
        pendingRecovery = reload;
        return;
      }
      resumePlay = true;
      setTimeout(reload, recoveries === 1 ? 0 : 1000 * recoveries);
      return;
    }
    setMarqueeText(els.title, 'Wiedergabe fehlgeschlagen');
  });

  audio.addEventListener('loadedmetadata', () => {
    if (resumeAt === null) return;
    const target = Math.min(resumeAt, Math.max(0, (audio.duration || resumeAt) - 0.25));
    resumeAt = null;
    audio.currentTime = target;
    if (resumePlay) {
      resumePlay = false;
      audio.play().catch(() => {});
    }
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

  els.btnSeekBack.addEventListener('click', () => seekBy(-SEEK_STEP));
  els.btnSeekForward.addEventListener('click', () => seekBy(SEEK_STEP));

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
    // Reste einer Wisch-Geste entfernen (ab 0.18)
    els.bar.style.transform = '';
    els.bar.style.opacity = '';
    els.bar.style.transition = '';
    els.bar.classList.remove('is-dragging');
    if (els.scrim) {
      els.scrim.style.transition = '';
      els.scrim.style.opacity = '';
      els.scrim.hidden = !open;
    }
    els.bar.classList.toggle('is-expanded', open);
    document.getElementById('audioarchive').classList.toggle('aa-player-expanded', open);
    els.btnExpand.setAttribute('aria-expanded', open ? 'true' : 'false');
    updateMarquees();
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

  /*
   * Nach unten wischen schliesst den Vollbild-Player (ab 0.18).
   *
   * Vom Nutzer gewuenscht: "wenn runtergescrollt wird, soll das aus dem
   * Fenster verschwinden". Die Leiste folgt dabei dem Finger und rutscht
   * nach unten aus dem Bild; wird zu wenig gezogen, federt sie zurueck.
   *
   * Nur wenn oben nichts mehr zu scrollen ist: Bei aufgeklappten Angaben
   * scrollt die Seite zuerst, erst ganz oben beginnt die Geste. Auf
   * Schiebereglern und Knoepfen startet sie gar nicht, sonst liesse sich
   * der Fortschritt nicht mehr ziehen.
   */
  const CLOSE_DISTANCE = 120;      // ab hier schliesst es
  const CLOSE_VELOCITY = 0.55;     // px/ms - schneller Schubs genuegt auch kurz
  let dragStartY = null;
  let dragStartTime = 0;
  let dragDelta = 0;
  let dragging = false;

  function isExpanded() {
    return els.bar.classList.contains('is-expanded');
  }

  function setDragOffset(px) {
    if (px <= 0) {
      els.bar.style.transform = '';
      els.bar.style.opacity = '';
      if (els.scrim) els.scrim.style.opacity = '';
      return;
    }
    // Die Karte folgt dem Finger, die abgedunkelte Flaeche dahinter wird
    // dabei heller - so sieht man, dass die Liste zurueckkommt
    els.bar.style.transform = 'translate3d(0, ' + px + 'px, 0)';
    const share = Math.min(1, px / (els.bar.clientHeight || 600));
    els.bar.style.opacity = String(Math.max(0.5, 1 - share * 0.5));
    if (els.scrim) els.scrim.style.opacity = String(Math.max(0, 1 - share * 1.2));
  }

  function endDrag(close) {
    els.bar.classList.remove('is-dragging');
    dragStartY = null;
    dragging = false;

    if (!close) {
      setDragOffset(0);
      return;
    }

    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) {
      setDragOffset(0);
      collapse();
      return;
    }

    // Nach unten hinausschieben, danach erst wirklich schliessen
    els.bar.style.transition = 'transform 0.2s ease-out, opacity 0.2s ease-out';
    els.bar.style.transform = 'translate3d(0, 100%, 0)';
    els.bar.style.opacity = '';
    if (els.scrim) {
      els.scrim.style.transition = 'opacity 0.2s ease-out';
      els.scrim.style.opacity = '0';
    }
    window.setTimeout(() => {
      els.bar.style.transition = '';
      setDragOffset(0);
      collapse();
    }, 200);
  }

  els.bar.addEventListener('touchstart', (event) => {
    if (!isExpanded() || event.touches.length !== 1) return;
    if (event.target.closest('input, button, a')) return;
    // Erst ganz oben: sonst scrollen die Angaben
    if (els.bar.scrollTop > 0) return;
    dragStartY = event.touches[0].clientY;
    dragStartTime = Date.now();
    dragDelta = 0;
    dragging = false;
  }, { passive: true });

  els.bar.addEventListener('touchmove', (event) => {
    if (dragStartY === null || event.touches.length !== 1) return;
    const delta = event.touches[0].clientY - dragStartY;

    if (!dragging) {
      // Nach oben gewischt: normales Scrollen, Geste verwerfen
      if (delta < -4) {
        dragStartY = null;
        return;
      }
      if (delta < 10) return;
      dragging = true;
      els.bar.classList.add('is-dragging');
    }

    dragDelta = Math.max(0, delta);
    // Ohne preventDefault wuerde der Browser gleichzeitig scrollen
    if (event.cancelable) event.preventDefault();
    setDragOffset(dragDelta);
  }, { passive: false });

  function finishTouch() {
    if (dragStartY === null) return;
    if (!dragging) {
      dragStartY = null;
      return;
    }
    const speed = dragDelta / Math.max(1, Date.now() - dragStartTime);
    endDrag(dragDelta > CLOSE_DISTANCE || speed > CLOSE_VELOCITY);
  }

  els.bar.addEventListener('touchend', finishTouch);
  els.bar.addEventListener('touchcancel', () => endDrag(false));

  /*
   * Am Rechner dasselbe mit dem Mausrad: nach unten scrollen schliesst,
   * aber nur, wenn es nichts zu scrollen gibt (also ohne aufgeklappte
   * Angaben) - sonst wuerde das Lesen der Angaben den Player schliessen.
   */
  let wheelSum = 0;
  let wheelAt = 0;
  els.bar.addEventListener('wheel', (event) => {
    if (!isExpanded() || event.deltaY <= 0) return;
    // Nur (fast) ganz oben: mit aufgeklappten Angaben scrollt erst der
    // Inhalt. Die 4 Punkte Spielraum fangen Rundungen ab, die sonst schon
    // beim ersten Rad-Schritt greifen.
    if (els.bar.scrollTop > 4) return;
    const now = Date.now();
    if (now - wheelAt > 600) wheelSum = 0;
    wheelAt = now;
    wheelSum += event.deltaY;
    if (wheelSum > 120) {
      wheelSum = 0;
      collapse();
    }
  }, { passive: true });

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
      loadTrack(startIndex, true);
    },

    resume() {
      if (pendingRecovery) {
        const reload = pendingRecovery;
        pendingRecovery = null;
        resumePlay = true;
        reload();
        return;
      }
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

    /**
     * Symbolfarbe hat sich geaendert (Aussehen einer Freigabe, ab 0.16):
     * Titel ohne eigenes Cover zeigen das Symbol in der neuen Farbe.
     */
    refreshIcon() {
      const track = playlist[currentIndex];
      if (!track || els.bar.hidden || coverUrlFor(track)) return;
      els.cover.src = fallbackCover();
      updateMediaSession(track);
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
