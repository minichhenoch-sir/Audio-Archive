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
  };

  const SEEK_STEP = 15; // Sekunden

  let onTrackChange = null;     // Callback: Titel gewechselt (Liste aktualisieren)
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
    if (els.bar.hidden) {
      root.style.setProperty('--player-bar-space', '0px');
      return;
    }
    const rect = els.bar.getBoundingClientRect();
    const space = Math.max(0, window.innerHeight - rect.top);
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

  function updateMediaSession(track) {
    if (!('mediaSession' in navigator)) return;

    navigator.mediaSession.metadata = new MediaMetadata({
      title: trackTitle(track),
      artist: (track.artist && track.artist.trim()) || '',
      album: (track.album && track.album.trim()) || '',
      // Titelbild fuer den Sperrbildschirm. Ueber AudioArchive.asset(),
      // damit die Adresse absolut ist - relativ wuerde sie auf der
      // oeffentlichen Seite gegen /s/<token>/ aufgeloest und ins Leere zeigen.
      artwork: [
        { src: AudioArchive.asset('img/icon-192.png'), sizes: '192x192', type: 'image/png' },
        { src: AudioArchive.asset('img/icon-512.png'), sizes: '512x512', type: 'image/png' },
      ],
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

  /*
   * Vorausladen vorerst ABGESCHALTET.
   *
   * Es laedt den laufenden Titel im Hintergrund vollstaendig herunter, damit
   * ein Verbindungsabbruch die Wiedergabe nicht unterbricht. Nutzen bringt
   * das aber nur, wenn der Service Worker die gepufferte Datei anschliessend
   * auch ausliefert - und der ist seit dem CSP-Zwischenfall bewusst passiv.
   * Bis dahin wuerde der Hintergrund-Download nur Bandbreite kosten und sich
   * mit der laufenden Wiedergabe darum streiten: genau das fuehrte zu
   * staendigem Nachladen (im Netzwerk-Mitschnitt gut sichtbar als
   * zusaetzlicher Abruf der vollstaendigen Datei parallel zum Stream).
   * Wird in Schritt 4 zusammen mit dem Offline-Betrieb wieder eingeschaltet.
   */
  const PREFETCH_ENABLED = false;

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
  const OFFLINE_AUDIO_CACHE = 'gemeinde-offline-audio';
  const prefetchSupported = 'caches' in window;
  let prefetchController = null;

  function streamUrlFor(path) {
    return new URL(AudioArchive.api('stream') + '?path=' + encodeURIComponent(path), location.href).href;
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
    const url = streamUrlFor(track.path);

    try {
      // Schon dauerhaft offline gespeichert? Dann ist nichts zu tun.
      const offline = await caches.open(OFFLINE_AUDIO_CACHE);
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
    if (!PREFETCH_ENABLED || !prefetchSupported) return;

    if (prefetchController) prefetchController.abort();
    prefetchController = new AbortController();
    const { signal } = prefetchController;

    const planned = planPrefetch(index);
    trimPrefetchCache(planned.map((t) => streamUrlFor(t.path)));

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

  function loadTrack(index, autoplay = true) {
    if (index < 0 || index >= playlist.length) return;
    currentIndex = index;
    const track = playlist[index];

    audio.src = AudioArchive.api('stream') + '?path=' + encodeURIComponent(track.path);
    els.title.textContent = trackTitle(track);
    els.context.textContent = trackContext(track);
    els.bar.hidden = false;
    updatePlayerBarSpace();

    updateMediaSession(track);
    startPrefetch(index);

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
  });

  /*
   * 'playing' feuert, sobald wirklich Ton kommt - erst dann steht die
   * Laufzeit fest. Beim Wechsel zum naechsten Titel wird die Quelle
   * getauscht; in dieser Luecke verwirft Android die Benachrichtigung
   * mitunter. Deshalb werden Titelangaben und Zustand hier noch einmal
   * gesetzt, statt sich auf das Setzen vor dem Laden zu verlassen.
   */
  audio.addEventListener('playing', () => {
    const track = playlist[currentIndex];
    if (track) {
      updateMediaSession(track);
    }
    setMediaSessionPlaybackState('playing');
    updatePositionState();
  });

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
    } else {
      setMediaSessionPlaybackState('paused');
    }
  });

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
      }
    },

    getCurrentPath() {
      return currentIndex >= 0 ? playlist[currentIndex].path : null;
    },

    onTrackChange(callback) {
      onTrackChange = callback;
    },

    /** Meldet Play/Pause-Wechsel, damit die Liste die Animation anhalten kann. */
    onPlayStateChange(callback) {
      onPlayStateChange = callback;
    },

    /** true, solange tatsächlich Ton läuft (nicht pausiert / nicht beendet). */
    isPlaying() {
      return !audio.paused && !audio.ended;
    },
  };
})();
