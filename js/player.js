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
    btnComments: document.getElementById('btn-comments'),
    comments: document.getElementById('player-comments'),
    details: document.getElementById('player-details'),
    toast: document.getElementById('player-toast'),
  };

  const SEEK_STEP = 15; // Sekunden

  // ------------------------------------------------------------------
  // Weiterhoeren (ab 0.23.0, Vikunja #35)
  //
  // Je Aufnahme merkt sich das Geraet die Stelle, an der zuletzt gehoert
  // wurde (ab 15 s; die letzten 20 s gelten als fertig gehoert). Beim
  // erneuten Abspielen geht es dort weiter; app.js bietet beim Oeffnen die
  // zuletzt gehoerte Aufnahme an. Getrennt je Link, damit gleich benannte
  // Pfade verschiedener Freigaben sich nicht vermischen.
  // ------------------------------------------------------------------
  const POS_KEY = 'audioarchive_positions';
  const POS_MAX = 300;
  const POS_MIN = 15;
  const POS_TAIL = 20;
  const POS_SCOPE = (typeof AudioArchive !== 'undefined' && (AudioArchive.apiToken || AudioArchive.publicToken)) || 'user';
  let positions = {};
  try {
    const stored = JSON.parse(localStorage.getItem(POS_KEY) || '{}');
    if (stored && typeof stored === 'object') positions = stored;
  } catch (e) { /* ohne Speicher: nur fuer diese Sitzung */ }
  let lastPosSave = 0;

  // ------------------------------------------------------------------
  // Audioformate (ab 0.21.0)
  //
  // Neben MP3 zeigt die App alle Formate, die verbreitete Browser selbst
  // abspielen. Umgewandelt wird nichts. Ob DIESER Browser ein Format kann,
  // sagt er selbst (canPlayType) - AIFF etwa nur Safari. Titel, die er
  // nicht kann, werden beim Weiterspielen uebersprungen; tippt man sie an,
  // erscheint ein Hinweis statt endloser Ladeversuche.
  // ------------------------------------------------------------------
  const FORMAT_TYPES = {
    mp3: ['audio/mpeg'],
    m4a: ['audio/mp4', 'audio/x-m4a'],
    m4b: ['audio/mp4', 'audio/x-m4a'],
    aac: ['audio/aac', 'audio/mp4'],
    ogg: ['audio/ogg'],
    oga: ['audio/ogg'],
    opus: ['audio/ogg; codecs=opus', 'audio/opus'],
    webm: ['audio/webm'],
    weba: ['audio/webm'],
    wav: ['audio/wav', 'audio/wave', 'audio/x-wav'],
    flac: ['audio/flac', 'audio/x-flac'],
    aif: ['audio/aiff', 'audio/x-aiff'],
    aiff: ['audio/aiff', 'audio/x-aiff'],
    aifc: ['audio/aiff', 'audio/x-aiff'],
    caf: ['audio/x-caf'],
  };
  /** Formate, die praktisch nur Apple-Geraete (Safari) abspielen. */
  const APPLE_ONLY = ['aif', 'aiff', 'aifc', 'caf'];
  const formatCache = {};
  /** Titel, die trotz "maybe" nicht dekodiert werden konnten (z. B. ALAC in Chrome). */
  const undecodable = new Set();
  /** Zuletzt vom Nutzer angetippter Titel: der wird nie von selbst uebersprungen. */
  let tappedKey = '';

  function extensionOf(track) {
    const name = (track && (track.file || track.path)) || '';
    const m = /\.([a-z0-9]+)$/i.exec(name);
    return m ? m[1].toLowerCase() : '';
  }

  /** Kurzname des Formats fuer Anzeigen ("AIFF", "M4A" ...). */
  function formatLabel(track) {
    return extensionOf(track).toUpperCase();
  }

  /*
   * Umwandlung in MP3 beim Abspielen (ab 0.27.0, Vikunja #34): Kann der
   * Browser ein Format nicht, wandelt der Server es um - sofern der
   * Administrator das eingeschaltet hat und ffmpeg vorhanden ist. Das sagt
   * die Ordnerliste (features.transcode), app.js reicht es durch.
   */
  let transcodeOn = false;
  /** Titel, deren Umwandlung gescheitert ist - die bleiben "nicht abspielbar". */
  const transcodeFailed = new Set();
  /** Titel, der gerade in umgewandelter Fassung geladen ist (bzw. wird). */
  let transcodedKey = '';

  function canTranscode(track) {
    return transcodeOn && !!track && extensionOf(track) !== 'mp3'
      && !transcodeFailed.has(track.path + '|' + (track.source || ''));
  }

  /** Abspielbar - selbst oder ueber die Umwandlung des Servers. */
  function formatPlayable(track) {
    return nativePlayable(track) || canTranscode(track);
  }

  /** Kann dieser Browser den Titel selbst abspielen? Unbekannt = ja. */
  function nativePlayable(track) {
    if (track && undecodable.has(track.path + '|' + (track.source || ''))) return false;
    const ext = extensionOf(track);
    if (ext === '' || ext === 'mp3' || !FORMAT_TYPES[ext]) return true;
    if (!(ext in formatCache)) {
      formatCache[ext] = FORMAT_TYPES[ext].some((type) => {
        try {
          return audio.canPlayType(type) !== '';
        } catch (e) {
          return true;
        }
      });
    }
    return formatCache[ext];
  }

  /** Hinweistext fuer ein Format, das dieser Browser nicht kann. */
  function formatHint(track) {
    const ext = extensionOf(track);
    const label = formatLabel(track);
    if (track && undecodable.has(track.path + '|' + (track.source || ''))) {
      return 'Diese ' + label + '-Datei kann dieser Browser nicht abspielen';
    }
    return APPLE_ONLY.includes(ext)
      ? label + ' spielt nur Safari (iPhone, iPad, Mac) ab'
      : label + ' kann dieser Browser nicht abspielen';
  }

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
    // Ab 0.33.0 (Vikunja #44) zeigt der Player den Dateinamen wie in der
    // Liste; den Titel aus den Tags nur, wenn die Verwaltung es so einstellt.
    if (!AudioArchive.listDisplay.titleFromTags) return track.name;
    return (track.title && track.title.trim()) || track.name;
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

  /**
   * Zusatzzeile mit Kuenstler und Album (ab 0.21.1, Vikunja #31): In der
   * kleinen Leiste wie bisher "Kuenstler · Album" in einer Zeile, im
   * Vollbild per CSS je Angabe eine eigene Zeile (Trenner ausgeblendet).
   * Leere Angaben entfallen.
   */
  function setContextLine(track) {
    const parts = [['artist', track.artist], ['album', track.album]]
      .filter(([, v]) => v && v.trim());
    const inner = document.createElement('span');
    inner.className = 'marquee-inner';
    parts.forEach(([kind, value], i) => {
      if (i > 0) {
        const sep = document.createElement('span');
        sep.className = 'context-sep';
        sep.textContent = ' \u00b7 ';
        inner.appendChild(sep);
      }
      const part = document.createElement('span');
      part.className = 'context-part context-' + kind;
      part.textContent = value.trim();
      inner.appendChild(part);
    });
    els.context.replaceChildren(inner);
    updateMarquee(els.context);
  }

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
  // (etwa offline und nicht gespeichert), erscheint ein Ersatzbild.
  // ------------------------------------------------------------------
  // Ab 0.16 in der Leistenfarbe, ab 0.20 als randlose Flaeche (vorher das
  // abgerundete App-Symbol, dessen Schatten das Cover-Feld abschnitt) und
  // je Freigabe waehlbar - deshalb eine Funktion statt fester Adresse
  function fallbackCover() {
    return AudioArchive.fallbackCoverUrl();
  }

  /** Adresse des Covers eines Titels, oder null ohne Cover. */
  function coverUrlFor(track) {
    return track && track.cover ? AudioArchive.coverUrl(track.path, track.source, track.cover) : null;
  }

  function showCover(track) {
    const url = coverUrlFor(track);
    els.bar.classList.toggle('has-cover', !!url);
    // Mitgeliefertes Zeichen statt Cover: bekommt per CSS etwas Tiefe
    // (leichter Verlauf), ein eigenes Bild der Freigabe nicht (ab 0.20)
    els.bar.classList.toggle('cover-generated', !url && !AudioArchive.customCover());
    // Kein Cover -> Ersatzbild (farbig bzw. eigenes Bild der Freigabe) ->
    // (falls auch das nicht laedt, etwa offline oder gedrosselt) das
    // mitgelieferte blaue Ersatzbild
    const colored = fallbackCover();
    const plain = AudioArchive.plainCoverUrl();
    els.cover.onerror = () => {
      els.bar.classList.remove('has-cover');
      els.bar.style.removeProperty('--aa-cover');
      const next = els.cover.src === colored ? plain : colored;
      if (next === plain) els.cover.onerror = null;
      els.bar.classList.toggle('cover-generated', next === plain || !AudioArchive.customCover());
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

    // Titelbild fuer den Sperrbildschirm: das Cover, sonst dasselbe
    // Ersatzbild wie im Player (ab 0.20; vorher das App-Symbol).
    // Immer absolute Adressen - relativ wuerden sie auf der oeffentlichen
    // Seite gegen /s/<token>/ aufgeloest und ins Leere zeigen.
    const cover = coverUrlFor(track);
    const artwork = cover
      ? [{ src: cover, sizes: '512x512' }]
      : [{ src: fallbackCover(), sizes: '512x512' }];

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

  /*
   * Bremse gegen Dauerspulen (ab 0.19.3).
   *
   * Befund (Nutzer, 2026-09-28): Autoradio VW T5 (Werksradio), Android 9,
   * installierte App offline. Kurz am Radio gespult - danach sprang die
   * Wiedergabe immer weiter um 15 s, bis zum naechsten Titelwechsel.
   * Die App springt je Befehl genau einmal; die Wiederholungen kommen also
   * von aussen (Radio haelt "Spulen" gedrueckt bzw. wiederholt den Befehl).
   * Eine Web-App bekommt nur einzelne 'seekforward'/'seekbackward' - kein
   * "Taste gedrueckt/losgelassen" - und kann das Spulen des Radios deshalb
   * nicht sauber beenden.
   *
   * Deshalb: Befehle gleicher Richtung, zwischen denen weniger als
   * SERIES_GAP liegt, gelten als EINE Serie. Pro Serie hoechstens
   * SERIES_MAX_JUMPS Spruenge, und zwischen zwei Spruengen mindestens
   * SERIES_MIN_INTERVAL. Alles darueber wird ignoriert, solange die
   * Wiederholungen weiterlaufen. Jeder andere Befehl (Play, Pause, Titel,
   * Balken) und jeder Titelwechsel beendet die Serie. Die Knoepfe IN der
   * App sind davon nicht betroffen.
   */
  const SERIES_GAP = 2500;          // ms Pause, ab der ein neuer Druck zaehlt
  const SERIES_MAX_JUMPS = 2;       // hoechstens 2 x 15 s je Serie
  const SERIES_MIN_INTERVAL = 1000; // ms zwischen zwei Spruengen einer Serie

  let seekSeries = null; // { dir, lastAt, jumpAt, jumps }

  function endSeekSeries() {
    seekSeries = null;
  }

  /** Spulbefehl von aussen; liefert true, wenn gesprungen wurde. */
  function externalSeek(dir, seconds) {
    const now = Date.now();
    const s = seekSeries;
    if (!s || s.dir !== dir || now - s.lastAt >= SERIES_GAP) {
      seekSeries = { dir, lastAt: now, jumpAt: now, jumps: 1 };
      seekBy(dir * seconds);
      return true;
    }
    // Serie laeuft: auch ignorierte Wiederholungen halten sie am Leben
    s.lastAt = now;
    if (s.jumps >= SERIES_MAX_JUMPS || now - s.jumpAt < SERIES_MIN_INTERVAL) return false;
    s.jumps += 1;
    s.jumpAt = now;
    seekBy(dir * seconds);
    return true;
  }

  /*
   * Protokoll der Befehle von aussen (ab 0.19.3) - fuer die Fehlersuche im
   * Auto. Sichtbar im Vollbild-Player unter "Angaben" (i), Gruppe "Befehle
   * von aussen". Zeigt Uhrzeit, Abstand zum vorigen Befehl und ob er
   * ausgefuehrt oder gebremst wurde. Bleibt ueber einen Neustart der App
   * erhalten (localStorage, nur die letzten Eintraege).
   */
  const MEDIA_LOG_KEY = 'audioarchive_media_log';
  const MEDIA_LOG_MAX = 40;
  const MEDIA_LOG_SHOW = 15;
  const MEDIA_LOG_AGE = 2 * 60 * 60 * 1000; // aelter als 2 h: nicht mehr zeigen
  const ACTION_LABELS = {
    play: 'Play',
    pause: 'Pause',
    previoustrack: 'Titel zurück',
    nexttrack: 'Titel vor',
    seekbackward: 'Zurückspulen',
    seekforward: 'Vorspulen',
    seekto: 'Balken',
    stop: 'Stopp',
  };

  let mediaLog = [];
  try {
    const stored = JSON.parse(localStorage.getItem(MEDIA_LOG_KEY) || '[]');
    if (Array.isArray(stored)) mediaLog = stored.slice(-MEDIA_LOG_MAX);
  } catch (e) { /* ohne Speicher: nur fuer diese Sitzung */ }

  function logMediaAction(action, executed) {
    mediaLog.push({ t: Date.now(), a: action, ok: executed });
    if (mediaLog.length > MEDIA_LOG_MAX) mediaLog = mediaLog.slice(-MEDIA_LOG_MAX);
    try {
      localStorage.setItem(MEDIA_LOG_KEY, JSON.stringify(mediaLog));
    } catch (e) { /* nicht kritisch */ }
  }

  /** Zeilen fuer die Angaben: neueste zuerst. */
  function mediaLogRows() {
    const since = Date.now() - MEDIA_LOG_AGE;
    const recent = mediaLog.filter((e) => e.t >= since);
    const rows = [];
    for (let i = recent.length - 1; i >= 0 && rows.length < MEDIA_LOG_SHOW; i--) {
      const e = recent[i];
      const d = new Date(e.t);
      const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        + ',' + String(d.getMilliseconds()).padStart(3, '0').slice(0, 1);
      const gap = i > 0 ? ((e.t - recent[i - 1].t) / 1000).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' s danach' : '';
      const label = ACTION_LABELS[e.a] || e.a;
      rows.push([time, [label, e.ok ? '' : 'gebremst', gap].filter(Boolean).join(' · ')]);
    }
    return rows;
  }

  /*
   * Weiter/Zurueck-Tasten am Geraet (ab 0.21.1, Vikunja #22).
   *
   * Autoradio, CarPlay, Kopfhoerer und Sperrbildschirm schicken bei den
   * Pfeiltasten "naechster/voriger Titel". Ob kurz getippt oder gehalten,
   * erfaehrt eine Web-App nicht. Deshalb waehlbar, je Geraet gemerkt:
   *   'track' - Titel wechseln (bisheriges Verhalten, Vorgabe)
   *   'seek'  - 15 s vor- bzw. zurueckspulen (mit derselben Bremse gegen
   *             Dauerspulen wie die Spultasten)
   */
  const HW_KEYS_KEY = 'audioarchive_hw_keys';
  let hwKeys = 'track';
  try {
    if (localStorage.getItem(HW_KEYS_KEY) === 'seek') hwKeys = 'seek';
  } catch (e) { /* ohne Speicher: Vorgabe */ }

  function setHwKeys(mode) {
    hwKeys = mode === 'seek' ? 'seek' : 'track';
    try {
      localStorage.setItem(HW_KEYS_KEY, hwKeys);
    } catch (e) { /* nur fuer diese Sitzung */ }
  }

  function registerMediaActions() {
    if (!('mediaSession' in navigator)) return;
    // Jeder andere Befehl beendet eine laufende Spul-Serie
    const other = (action, fn) => (details) => {
      endSeekSeries();
      logMediaAction(action, true);
      fn(details);
    };
    const seek = (action, dir) => (details) => {
      const executed = externalSeek(dir, (details && details.seekOffset) || SEEK_STEP);
      logMediaAction(action, executed);
    };
    const actions = {
      play: other('play', () => Player.resume()),
      pause: other('pause', () => Player.pause()),
      previoustrack: (details) => {
        if (hwKeys === 'seek') {
          logMediaAction('previoustrack', externalSeek(-1, SEEK_STEP));
          return;
        }
        other('previoustrack', () => Player.prev())(details);
      },
      nexttrack: (details) => {
        if (hwKeys === 'seek') {
          logMediaAction('nexttrack', externalSeek(1, SEEK_STEP));
          return;
        }
        other('nexttrack', () => Player.next())(details);
      },
      seekbackward: seek('seekbackward', -1),
      seekforward: seek('seekforward', 1),
      seekto: other('seekto', (details) => {
        if (!details || !isFinite(details.seekTime)) return;
        const duration = isFinite(audio.duration) ? audio.duration : Infinity;
        const target = Math.min(Math.max(0, details.seekTime), Math.max(0, duration - 0.25));
        if (details.fastSeek && 'fastSeek' in audio) {
          audio.fastSeek(target);
        } else {
          audio.currentTime = target;
        }
        updatePositionState();
      }),
      stop: other('stop', () => {
        audio.pause();
        audio.currentTime = 0;
        updatePositionState();
      }),
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

  /*
   * Laufzeit sofort beim Titelwechsel melden (ab 0.19.3).
   *
   * Befund (Nutzer, 2026-09-28): Das Autoradio (VW T5, Android 9) zeigte
   * die Gesamtlaenge beim naechsten Titel mal an, mal nicht. Die Titel-
   * angaben (MediaMetadata) koennen keine Laenge tragen - Chrome nimmt sie
   * aus setPositionState(). Die kam bisher erst mit 'loadedmetadata', also
   * nach Titel und Cover. Autoradios fragen die Angaben meist nur einmal,
   * direkt nach "Titel gewechselt", ab - je nachdem, was schneller war,
   * fehlte die Laenge. Bis dahin galt ausserdem noch die Laenge des
   * VORIGEN Titels.
   *
   * Die Liste vom Server (und offline die gespeicherte Liste) kennt die
   * Laenge schon (Feld 'duration'). Sie wird hier gemeldet, bevor die
   * Titelangaben gesetzt werden. Sobald die Datei geladen ist, ersetzt
   * updatePositionState() sie durch die Laenge laut Browser. Ist keine
   * bekannt, wird die alte Angabe geloescht statt stehen gelassen.
   */
  function announceTrackDuration(track) {
    if (!('mediaSession' in navigator) || !('setPositionState' in navigator.mediaSession)) return;
    const known = Number(track && track.duration);
    reportedAt = 0; // Abweichungspruefung erst nach der echten Meldung
    try {
      if (isFinite(known) && known > 0) {
        navigator.mediaSession.setPositionState({ duration: known, playbackRate: 1, position: 0 });
      } else {
        navigator.mediaSession.setPositionState();
      }
    } catch (e) {
      // Nicht kritisch - dann wie bisher erst nach dem Laden
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
    if (track && track.duration > 0) return track.duration; // ab 0.21.0: WAV/FLAC sind viel groesser
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

    // Nur, was der Browser selbst abspielen kann (Umgewandeltes nicht, ab 0.27.0)
    const planned = planPrefetch(index).filter((t) => nativePlayable(t));
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
    // Stelle des bisherigen Titels sichern (ab 0.23.0)
    rememberPosition(true);
    currentIndex = index;
    const track = playlist[index];

    mediaSessionNeedsRefresh = true;
    // Geplante Wiederholungen des vorherigen Titels verwerfen (ab 0.18.3)
    resetRecovery();
    shouldPlay = autoplay;
    // Der Quellwechsel kann ein pause-Ereignis ausloesen - das ist keine
    // Pause des Nutzers
    reloading = autoplay;

    if (!formatPlayable(track)) {
      // Nicht abspielbar: anzeigen, aber nichts laden (ab 0.21.0)
      shouldPlay = false;
      reloading = false;
      audio.removeAttribute('src');
      audio.load();
      setMarqueeText(els.title, trackTitle(track));
      setMarqueeText(els.context, formatHint(track));
      els.timeCurrent.textContent = formatTime(0);
      els.timeDuration.textContent = '–:––';
      els.seek.value = 0;
      showCover(track);
      els.bar.hidden = false;
      updateMarquees();
      updatePlayerBarSpace();
      updatePlayPauseIcon();
      setMediaSessionPlaybackState('paused');
      showToast(formatHint(track));
      if (typeof onTrackChange === 'function') onTrackChange(track, currentIndex);
      return;
    }

    // Weiterhoeren (ab 0.23.0): an der gemerkten Stelle beginnen
    const startAt = savedPosition(track);
    if (startAt > 0) {
      resumeAt = startAt;
      resumePlay = false;
      showToast('Weiter bei ' + formatTime(startAt) + ' – zum Anfang: Balken nach links');
    }

    if (nativePlayable(track)) {
      transcodedKey = '';
      audio.src = streamUrlFor(track);
    } else {
      startTranscoded(track);
    }
    setMarqueeText(els.title, trackTitle(track));
    setContextLine(track);
    if (transcodedKey === trackKey(track) && !audio.getAttribute('src')) {
      setMarqueeText(els.context, 'Wird für dieses Gerät in MP3 umgewandelt …');
    }
    showCover(track);
    els.bar.hidden = false;
    updateMarquees();
    updatePlayerBarSpace();

    // Titelwechsel beendet eine laufende Spul-Serie des Autoradios (ab 0.19.3)
    endSeekSeries();
    // Laufzeit VOR den Titelangaben melden (ab 0.19.3) - siehe dort
    announceTrackDuration(track);
    updateMediaSession(track);
    startPrefetch(index);
    if (!els.details.hidden) loadDetails();
    if (!els.comments.hidden) loadComments();
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

  /**
   * Umgewandelte Fassung anfordern und laden, sobald der Server fertig ist
   * (ab 0.27.0). Beim ersten Mal dauert das je nach Laenge der Aufnahme;
   * danach liegt sie beim Server bereit.
   */
  function startTranscoded(track) {
    const key = trackKey(track);
    transcodedKey = key;
    const statusUrl = streamUrlFor(track).replace('/api/stream?', '/api/transcode?');
    let waited = 0;
    let told = false;
    const poll = async () => {
      if (trackKey(playlist[currentIndex]) !== key) return;
      let state = 'failed';
      try {
        const res = await fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store' });
        state = res.ok ? ((await res.json()).state || 'failed') : 'failed';
      } catch (e) {
        state = 'failed';
      }
      if (trackKey(playlist[currentIndex]) !== key) return;
      if (state === 'ready') {
        audio.src = streamUrlFor(track) + '&mp3=1';
        setContextLine(track);
        if (shouldPlay) audio.play().catch(() => {});
        return;
      }
      if (state === 'working' && waited < 30 * 60) {
        if (!told) {
          told = true;
          showToast(formatLabel(track) + ' wird für dieses Gerät in MP3 umgewandelt – einen Moment …');
        }
        waited += 3;
        window.setTimeout(poll, 3000);
        return;
      }
      transcodeFailed.add(track.path + '|' + (track.source || ''));
      transcodedKey = '';
      showToast('Umwandlung nicht möglich – ' + formatHint(track));
      loadTrack(currentIndex, false); // zeigt den Hinweis an
    };
    poll();
  }

  audio.addEventListener('seeked', updatePositionState);

  audio.addEventListener('play', () => {
    updatePlayPauseIcon();
    setMediaSessionPlaybackState('playing');
    updatePositionState();
    if (typeof onPlayStateChange === 'function') onPlayStateChange(true);
  });

  audio.addEventListener('pause', () => {
    rememberPosition(true);
    // Vom System angehalten (Kopfhoerer ab, Anruf, ...): nicht weiterversuchen.
    // Das Neuladen selbst und das Titelende zaehlen nicht als Pause.
    if (!reloading && !audio.ended && !audio.error) shouldPlay = false;
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
    rememberPosition(false);
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
    rememberPosition(true); // zu Ende gehoert: gemerkte Stelle entfaellt
    // Ohne Verbindung nicht gespeicherte Titel ueberspringen (ab 0.18.3)
    const nextIndex = playableIndex(currentIndex + 1, 1);
    if (nextIndex !== -1) {
      /*
       * Zustand bewusst auf 'playing' belassen: Beim Wechsel zum naechsten
       * Titel entsteht eine kurze Luecke, in der keine Quelle geladen ist.
       * Wird hier auf 'paused' gestellt, raeumt Android die
       * Medien-Benachrichtigung in dieser Luecke weg - der Ton laeuft dann
       * zwar weiter, aber ohne Steuerung am Sperrbildschirm.
       */
      loadTrack(nextIndex, true);
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
      const first = playableIndex(0, 1);
      if (first !== -1) {
        loadTrack(first, true);
        return;
      }
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
        const start = playableIndex(0, 1);
        loadTrack(start === -1 ? 0 : start, true);
        if (typeof next.onStart === 'function') next.onStart();
        return;
      }
      showToast('Kein weiterer Ordner – Wiedergabe beendet');
    }
    shouldPlay = false;
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
  // Die Wahl wird je Geraet gemerkt - zusammen mit der Vorgabe, die beim
  // Merken galt (ab 0.28.0, Vikunja #2). Aendert sich die Vorgabe
  // (Verwaltung, Link oder persoenlich), gilt wieder die neue Vorgabe.
  // ------------------------------------------------------------------
  const REPEAT_MODES = ['off', 'next', 'folder', 'one'];
  const REPEAT_LABELS = {
    off: 'Wiederholen aus',
    next: 'Danach nächster Ordner',
    folder: 'Ordner wiederholen',
    one: 'Titel wiederholen',
  };
  const REPEAT_KEY = 'audioarchive_repeat';

  const repeatDefault = REPEAT_MODES.includes(AudioArchive.repeatDefault) ? AudioArchive.repeatDefault : 'next';
  let repeatMode = repeatDefault;
  try {
    // Bis 0.27.0 stand hier nur der Modus - ohne Vorgabe, also verworfen
    const stored = JSON.parse(localStorage.getItem(REPEAT_KEY) || 'null');
    if (stored && stored.base === repeatDefault && REPEAT_MODES.includes(stored.mode)) repeatMode = stored.mode;
  } catch (e) { /* ohne Speicher oder alter Wert: Vorgabe */ }

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
    try {
      localStorage.setItem(REPEAT_KEY, JSON.stringify({ mode: repeatMode, base: repeatDefault }));
    } catch (e) { /* egal */ }
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
        ['Format', pick(t.format, formatLabel(track))],
      ]],
      ['Datei', [
        ['Name', pick(f.name, track.file, track.name)],
        ['Ordner', folder === '' ? '(oberste Ebene)' : folder.split('/').join(' / ')],
        ['Größe', formatSize(f.size !== undefined ? f.size : track.size)],
        ['Geändert', f.mtime ? new Date(f.mtime * 1000).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : ''],
      ]],
      // Nur sichtbar, wenn in den letzten 2 h Befehle von aussen kamen (ab 0.19.3)
      ['Tastenbefehle (Protokoll)', mediaLogRows()],
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
    els.details.appendChild(hwKeysChooser());
  }

  /** Auswahl fuer die Pfeiltasten am Geraet (ab 0.21.1), unter den Angaben. */
  function hwKeysChooser() {
    const box = document.createElement('div');
    box.className = 'player-hwkeys';
    const h = document.createElement('h3');
    h.className = 'player-details-heading';
    h.textContent = 'Tasten an Auto, Kopfhörer und Sperrbildschirm';
    const hint = document.createElement('p');
    hint.className = 'player-hwkeys-hint';
    hint.textContent = 'Was sollen die Weiter- und Zurück-Tasten tun? Gilt nur für dieses Gerät.';
    const row = document.createElement('div');
    row.className = 'player-hwkeys-row';
    row.setAttribute('role', 'radiogroup');
    [['track', 'Titel wechseln'], ['seek', '15 s spulen']].forEach(([mode, label]) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'player-hwkeys-btn' + (hwKeys === mode ? ' is-on' : '');
      btn.setAttribute('role', 'radio');
      btn.setAttribute('aria-checked', hwKeys === mode ? 'true' : 'false');
      btn.textContent = label;
      btn.addEventListener('click', () => {
        setHwKeys(mode);
        row.querySelectorAll('.player-hwkeys-btn').forEach((b) => {
          const on = b === btn;
          b.classList.toggle('is-on', on);
          b.setAttribute('aria-checked', on ? 'true' : 'false');
        });
        showToast(mode === 'seek' ? 'Weiter/Zurück am Gerät: 15 s spulen' : 'Weiter/Zurück am Gerät: Titel wechseln');
      });
      row.appendChild(btn);
    });
    box.append(h, hint, row);
    return box;
  }

  async function loadDetails() {
    const track = playlist[currentIndex];
    if (!track || els.details.hidden) return;
    const request = ++detailsRequest;
    renderDetails(track, null, 'Weitere Angaben werden geladen …');
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
    if (open && !els.comments.hidden) setCommentsOpen(false);
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
  // Kommentare zur Aufnahme (ab 0.29.0, Vikunja #5)
  //
  // Echte Nextcloud-Dateikommentare (siehe CommentService). Hier sieht
  // jeder nur seine eigenen. Gaeste ueber einen Link werden ueber eine
  // zufaellige Geraete-Kennung erkannt und geben ihren Namen selbst an.
  // Ob es Kommentare gibt, meldet die Ordnerliste (features.comments).
  // ------------------------------------------------------------------
  let commentsOn = false;
  let commentsRequest = 0;
  let memoryDeviceKey = '';
  const DEVICE_KEY = 'audioarchive_device';
  const NAME_KEY = 'audioarchive_comment_name';

  function randomKey() {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  }

  /** Zufaellige Kennung dieses Geraets (nur fuer Gaeste von Bedeutung). */
  function deviceKey() {
    try {
      let key = localStorage.getItem(DEVICE_KEY) || '';
      if (!/^[A-Za-z0-9_-]{16,128}$/.test(key)) {
        key = randomKey();
        localStorage.setItem(DEVICE_KEY, key);
      }
      return key;
    } catch (e) {
      if (!memoryDeviceKey) memoryDeviceKey = randomKey();
      return memoryDeviceKey;
    }
  }

  function commentsUrl(track, suffix = '') {
    return new URL(AudioArchive.api('comments' + suffix) + '?' + AudioArchive.sourceQuery(track.source)
      + 'path=' + encodeURIComponent(track.path) + '&guest=' + encodeURIComponent(deviceKey()), location.href).href;
  }

  async function commentsRequestJson(url, body) {
    const headers = { 'X-AudioArchive': '1' };
    if (AudioArchive.requestToken) headers.requesttoken = AudioArchive.requestToken;
    if (body) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
    let data = {};
    try { data = await res.json(); } catch (e) { /* leer */ }
    if (!res.ok) {
      const err = new Error(data.error || ('Fehler ' + res.status));
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function starsText(n) {
    return n > 0 ? '★'.repeat(n) + '☆'.repeat(5 - n) : '';
  }

  function renderComments(track, state) {
    const box = els.comments;
    box.textContent = '';
    const h = document.createElement('h3');
    h.className = 'player-details-heading';
    h.textContent = 'Deine Kommentare zu dieser Aufnahme';
    box.appendChild(h);

    if (state.note) {
      const p = document.createElement('p');
      p.className = 'player-details-note';
      p.textContent = state.note;
      box.appendChild(p);
      if (!state.data) return;
    }
    const data = state.data;

    const list = document.createElement('ul');
    list.className = 'player-comments-list';
    if (data.comments.length === 0) {
      const p = document.createElement('p');
      p.className = 'player-details-note';
      p.textContent = 'Du hast hier noch nichts geschrieben.';
      box.appendChild(p);
    }
    data.comments.forEach((c) => {
      const li = document.createElement('li');
      li.className = 'player-comment';
      const meta = document.createElement('div');
      meta.className = 'player-comment-meta';
      const when = new Date(c.created * 1000).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
      });
      meta.textContent = when + (c.rating > 0 ? ' · ' + starsText(c.rating) : '');
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'player-comment-delete';
      del.textContent = 'Löschen';
      del.addEventListener('click', async () => {
        del.disabled = true;
        try {
          await commentsRequestJson(commentsUrl(track, '/' + encodeURIComponent(c.id) + '/delete'), {});
          loadComments();
        } catch (err) {
          del.disabled = false;
          showToast('Löschen hat nicht geklappt.');
        }
      });
      meta.appendChild(del);
      li.appendChild(meta);
      if (c.text) {
        const text = document.createElement('p');
        text.className = 'player-comment-text';
        text.textContent = c.text; // reiner Text
        li.appendChild(text);
      }
      list.appendChild(li);
    });
    if (data.comments.length > 0) box.appendChild(list);

    // ----- Neuer Kommentar -----
    const form = document.createElement('form');
    form.className = 'player-comment-form';
    let nameInput = null;
    if (data.guest) {
      nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.className = 'player-comment-input';
      nameInput.placeholder = 'Dein Name';
      nameInput.maxLength = 60;
      nameInput.autocomplete = 'name';
      try { nameInput.value = localStorage.getItem(NAME_KEY) || ''; } catch (e) { /* egal */ }
      form.appendChild(nameInput);
    }
    let rating = 0;
    if (data.rating) {
      const row = document.createElement('div');
      row.className = 'player-comment-stars';
      row.setAttribute('role', 'radiogroup');
      row.setAttribute('aria-label', 'Bewertung');
      const paint = () => row.querySelectorAll('button').forEach((b, i) => {
        b.textContent = i < rating ? '★' : '☆';
        b.setAttribute('aria-checked', i + 1 === rating ? 'true' : 'false');
      });
      for (let i = 1; i <= 5; i++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'player-comment-star';
        b.setAttribute('role', 'radio');
        b.setAttribute('aria-label', i + ' von 5 Sternen');
        b.addEventListener('click', () => {
          rating = rating === i ? 0 : i; // nochmal tippen = keine Bewertung
          paint();
        });
        row.appendChild(b);
      }
      paint();
      form.appendChild(row);
    }
    const textarea = document.createElement('textarea');
    textarea.className = 'player-comment-input';
    textarea.rows = 3;
    textarea.maxLength = 900;
    textarea.placeholder = 'Anmerkung, Änderungswunsch oder Fehler …';
    form.appendChild(textarea);
    const error = document.createElement('p');
    error.className = 'player-comment-error';
    error.hidden = true;
    const send = document.createElement('button');
    send.type = 'submit';
    send.className = 'player-hwkeys-btn is-on player-comment-send';
    send.textContent = 'Senden';
    form.append(error, send);
    const hint = document.createElement('p');
    hint.className = 'player-details-note';
    hint.textContent = 'Hier siehst nur du deine Kommentare. Die Verantwortlichen lesen sie in Nextcloud bei der Datei.';
    form.appendChild(hint);
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      error.hidden = true;
      const text = textarea.value.trim();
      if (text === '' && rating === 0) {
        error.textContent = data.rating ? 'Bitte etwas schreiben oder Sterne vergeben.' : 'Bitte etwas schreiben.';
        error.hidden = false;
        return;
      }
      const name = nameInput ? nameInput.value.trim() : '';
      if (nameInput) {
        try { localStorage.setItem(NAME_KEY, name); } catch (e) { /* egal */ }
      }
      send.disabled = true;
      try {
        await commentsRequestJson(commentsUrl(track), { text, rating, name });
        showToast('Danke! Kommentar gespeichert.');
        loadComments();
      } catch (err) {
        send.disabled = false;
        error.textContent = err.status === 429
          ? 'Zu viele Kommentare in kurzer Zeit – bitte später noch einmal.'
          : 'Speichern hat nicht geklappt: ' + err.message;
        error.hidden = false;
      }
    });
    box.appendChild(form);
  }

  async function loadComments() {
    const track = playlist[currentIndex];
    if (!track || els.comments.hidden) return;
    const request = ++commentsRequest;
    renderComments(track, { note: 'Kommentare werden geladen …' });
    if (!navigator.onLine) {
      renderComments(track, { note: 'Kommentare gibt es nur mit Internetverbindung.' });
      return;
    }
    try {
      const data = await commentsRequestJson(commentsUrl(track));
      if (request === commentsRequest) renderComments(track, { data });
    } catch (err) {
      if (request !== commentsRequest) return;
      renderComments(track, {
        note: err.status === 403 ? 'Für diese Aufnahme sind Kommentare nicht eingeschaltet.'
          : 'Kommentare konnten nicht geladen werden.',
      });
    }
  }

  function setCommentsOpen(open) {
    if (open && !els.details.hidden) setDetailsOpen(false);
    els.comments.hidden = !open;
    els.bar.classList.toggle('show-details', open);
    els.btnComments.setAttribute('aria-expanded', open ? 'true' : 'false');
    els.btnComments.classList.toggle('is-on', open);
    if (open) {
      loadComments();
      requestAnimationFrame(() => els.comments.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    }
  }

  els.btnComments.addEventListener('click', () => setCommentsOpen(els.comments.hidden));

  // ------------------------------------------------------------------
  // Wiederaufnahme nach Abbruechen (ab 0.15.1, ueberarbeitet in 0.18.3)
  //
  // Bricht die Datenquelle ab - Funkloch, Wechsel WLAN/Mobilfunk, zu
  // schnelles Spulen, oder beim Spulen in einer vom Service Worker
  // gelieferten Aufnahme (in Chromium reproduzierbar: "PIPELINE_ERROR_READ")
  // -, bleibt ein <audio>-Element sonst einfach stehen. Deshalb: Quelle neu
  // setzen, an dieselbe Stelle springen und weiterspielen.
  //
  // Die neue Adresse traegt 'retry=N', damit der Browser wirklich neu
  // anfragt statt den kaputten Zwischenstand weiterzuverwenden. Der Service
  // Worker ignoriert den Zusatz beim Nachschlagen im Offline-Speicher.
  //
  // Was sich in 0.18.3 geaendert hat (Nutzer: "wenn man zu schnell vorspult
  // oder wechselt und das Geraet nicht schafft nachzuladen, steht
  // 'Wiedergabe fehlgeschlagen'", danach half auch Play nicht mehr):
  //   - Bis zu sechs Versuche mit wachsendem Abstand (zusammen rund eine
  //     halbe Minute) statt drei schneller. Ohne Netz wird auf die
  //     Rueckkehr der Verbindung gewartet.
  //   - Der Zaehler gilt nicht mehr fuer den ganzen Titel: Nach 10 Sekunden
  //     sauberer Wiedergabe beginnt er von vorn. Frueher war ein Titel nach
  //     drei Aussetzern - egal wie weit auseinander - endgueltig verloren.
  //   - Auch "Quelle nicht ladbar" (Code 4) wird wiederholt. Chrome meldet
  //     so einen Abruf, der unterwegs scheitert.
  //   - Waechter gegen stilles Haengenbleiben: Wartet die Wiedergabe ueber
  //     20 Sekunden auf Daten, ohne dass ein Fehler kommt, wird ebenfalls
  //     neu geladen.
  //   - Ein geplanter Versuch wird beim Titelwechsel verworfen. Vorher
  //     konnte er nach schnellem Wechseln den ALTEN Titel zurueckholen.
  //   - Nach dem endgueltigen Scheitern laedt Play neu, an derselben Stelle.
  //   - Ob weitergespielt werden soll, merkt sich ein eigener Zustand
  //     (shouldPlay). audio.paused taugt dafuer nicht: Schon das Neuladen
  //     setzt es auf "pausiert", und der zweite Versuch hielt sich dann
  //     faelschlich fuer eine Pause und blieb stehen.
  // ------------------------------------------------------------------
  const RETRY_DELAYS = [0, 1500, 3000, 5000, 8000, 12000]; // ms, zusammen ~30 s
  // Titel lud noch nie (vielleicht gar nicht abspielbar): etwas kuerzer
  const RETRY_DELAYS_NEVER_LOADED = [0, 2000, 5000, 10000];
  const STALL_TIMEOUT = 20000;   // ms ohne Fortschritt beim Warten auf Daten
  const STABLE_AFTER = 10;       // s saubere Wiedergabe -> Zaehler zuruecksetzen

  let recoveries = 0;
  let recoveryKey = '';
  let resumeAt = null;        // Position nach dem Neuladen
  let resumePlay = false;
  let pendingRecovery = null; // im Pausenzustand aufgeschobenes Neuladen (ab 0.15.6)
  let recoveryTimer = 0;
  let lastGoodTime = 0;       // letzte sicher erreichte Position
  let intendedTime = null;    // Ziel eines laufenden Sprungs
  let shouldPlay = false;     // Will der Nutzer gerade hoeren?
  let reloading = false;      // Pause-Ereignisse beim Neuladen nicht als Nutzer-Pause werten
  let loadedOnce = false;     // Hat dieser Titel schon einmal Metadaten geliefert?
  let stableFrom = null;      // Position, ab der wieder sauber gespielt wird
  let reloadCounter = 0;
  let failed = false;

  function trackKey(track) {
    return track ? (track.source || 'shared') + '|' + track.path : '';
  }

  function posKey(track) {
    return POS_SCOPE + '#' + trackKey(track);
  }

  function savePositions() {
    try {
      localStorage.setItem(POS_KEY, JSON.stringify(positions));
    } catch (e) { /* voll oder gesperrt: nicht kritisch */ }
  }

  function savedPosition(track) {
    const entry = track ? positions[posKey(track)] : null;
    return entry && entry.t > 0 ? entry.t : 0;
  }

  /** Stelle des laufenden Titels merken; force = sofort statt hoechstens alle 5 s. */
  function rememberPosition(force) {
    const track = playlist[currentIndex];
    if (!track || resumeAt !== null || !isFinite(audio.duration) || audio.duration <= 0) return;
    const now = Date.now();
    if (!force && now - lastPosSave < 5000) return;
    lastPosSave = now;
    const t = audio.currentTime;
    const d = audio.duration;
    const key = posKey(track);
    if (t < POS_MIN || t > d - POS_TAIL) {
      if (positions[key]) {
        delete positions[key];
        savePositions();
      }
      return;
    }
    positions[key] = {
      t: Math.round(t), d: Math.round(d), at: now,
      name: track.name || '', title: track.title || '', file: track.file || '',
      path: track.path, source: track.source || 'shared',
    };
    const keys = Object.keys(positions);
    if (keys.length > POS_MAX) {
      keys.sort((a, b) => positions[a].at - positions[b].at)
        .slice(0, keys.length - POS_MAX)
        .forEach((k) => delete positions[k]);
    }
    savePositions();
  }

  window.addEventListener('pagehide', () => rememberPosition(true));
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') rememberPosition(true);
  });

  /** Alles verwerfen, was noch zum vorherigen Titel gehoert. */
  function resetRecovery() {
    window.clearTimeout(recoveryTimer);
    window.removeEventListener('online', onlineAgain);
    recoveryTimer = 0;
    pendingRecovery = null;
    resumeAt = null;
    resumePlay = false;
    reloading = false;
    intendedTime = null;
    lastGoodTime = 0;
    loadedOnce = false;
    stableFrom = null;
    recoveries = 0;
    recoveryKey = '';
    stallSince = 0;
    if (failed) {
      failed = false;
      els.bar.classList.remove('is-failed');
    }
  }

  function reloadAt(position, play) {
    const track = playlist[currentIndex];
    if (!track) return;
    resumeAt = position;
    resumePlay = play;
    reloading = true;
    stallSince = 0;
    // Umgewandelte Fassung bleibt die umgewandelte (ab 0.27.0)
    const base = streamUrlFor(track) + (transcodedKey === trackKey(track) ? '&mp3=1' : '');
    reloadCounter++;
    audio.src = base + (base.includes('?') ? '&' : '?') + 'retry=' + reloadCounter;
    audio.load();
    if (play) {
      // play() gleich mitgeben: Ohne laedt iOS im Hintergrund nicht weiter
      audio.play().catch(() => {});
    }
  }

  let waitingForOnline = null;
  function onlineAgain() {
    window.removeEventListener('online', onlineAgain);
    const run = waitingForOnline;
    waitingForOnline = null;
    window.clearTimeout(recoveryTimer);
    if (run) run();
  }

  function giveUp(position) {
    failed = true;
    shouldPlay = false;
    els.bar.classList.add('is-failed');
    setMarqueeText(els.title, 'Wiedergabe fehlgeschlagen');
    const failedTrack = playlist[currentIndex];
    setMarqueeText(els.context, !loadedOnce && failedTrack && extensionOf(failedTrack) !== 'mp3'
      ? 'Format ' + formatLabel(failedTrack) + ' evtl. nicht unterstützt – Play: neuer Versuch'
      : 'Zum erneuten Versuch auf Play tippen');
    updateMarquees();
    updatePlayPauseIcon();
    setMediaSessionPlaybackState('paused');
    // Play laedt neu - mit frischem Zaehler, an derselben Stelle
    pendingRecovery = () => {
      const track = playlist[currentIndex];
      failed = false;
      els.bar.classList.remove('is-failed');
      if (track) {
        setMarqueeText(els.title, trackTitle(track));
        setContextLine(track);
        updateMarquees();
      }
      recoveries = 0;
      reloadAt(position, true);
    };
  }

  /**
   * Gemeinsamer Weg fuer Fehler-Ereignis und Haenger-Waechter.
   */
  function recover() {
    const track = playlist[currentIndex];
    if (!track) return;
    const key = trackKey(track);
    if (key !== recoveryKey) {
      recoveryKey = key;
      recoveries = 0;
    }
    const position = intendedTime !== null ? intendedTime : lastGoodTime;
    const wantPlay = shouldPlay || resumePlay;

    /*
     * Ab 0.15.6: Reisst die Verbindung WAEHREND einer Pause ab, wird nicht
     * sofort neu geladen. Ein Quellwechsel beendet die Medien-Sitzung -
     * Android nimmt dann die Benachrichtigung weg, und Kopfhoerer-Tasten
     * erreichen die Seite nicht mehr. Geladen wird erst beim naechsten
     * Abspielen; bis dahin bleibt die Sitzung (pausiert) bestehen.
     */
    if (!wantPlay) {
      resumePlay = false;
      pendingRecovery = () => reloadAt(position, true);
      return;
    }

    const delays = loadedOnce ? RETRY_DELAYS : RETRY_DELAYS_NEVER_LOADED;
    if (recoveries >= delays.length) {
      giveUp(position);
      return;
    }
    const delay = delays[recoveries];
    recoveries++;
    if (recoveries === 2) showToast('Verbindung stockt – wird neu geladen …');

    const attempt = () => {
      recoveryTimer = 0;
      if (trackKey(playlist[currentIndex]) !== key) return; // inzwischen gewechselt
      if (!shouldPlay) {
        // Inzwischen pausiert: erst beim naechsten Play neu laden
        pendingRecovery = () => reloadAt(position, true);
        return;
      }
      reloadAt(position, true);
    };

    window.clearTimeout(recoveryTimer);
    if (navigator.onLine === false) {
      // Ohne Netz sinnlos zu klopfen: auf die Verbindung warten, aber nicht
      // laenger als 30 Sekunden je Versuch
      waitingForOnline = attempt;
      window.addEventListener('online', onlineAgain);
      recoveryTimer = window.setTimeout(onlineAgain, 30000);
      return;
    }
    recoveryTimer = window.setTimeout(attempt, delay);
  }

  audio.addEventListener('seeking', () => { intendedTime = audio.currentTime; stableFrom = null; });
  audio.addEventListener('seeked', () => { intendedTime = null; lastGoodTime = audio.currentTime; });
  audio.addEventListener('timeupdate', () => {
    // Waehrend eines Neuladens steht currentTime kurz auf 0 - das ist keine
    // erreichte Position (sonst begann der naechste Versuch von vorn)
    if (audio.seeking || resumeAt !== null || audio.readyState < 1) return;
    lastGoodTime = audio.currentTime;
    if (audio.paused) return;
    if (stableFrom === null) stableFrom = audio.currentTime;
    if (recoveries > 0 && audio.currentTime - stableFrom > STABLE_AFTER) recoveries = 0;
  });
  audio.addEventListener('playing', () => { reloading = false; });

  audio.addEventListener('error', () => {
    const code = audio.error ? audio.error.code : 0;
    // 1 = vom Browser selbst abgebrochen (Quellwechsel) - kein Fehler
    if (code === 1 || !audio.getAttribute('src')) return;
    const track = playlist[currentIndex];
    if (!track) return;

    // Ohne Verbindung und nicht gespeichert: Warten hilft nicht
    if (isOffline()) {
      const key = trackKey(track);
      isStoredOffline(track).then((stored) => {
        if (trackKey(playlist[currentIndex]) !== key) return;
        if (stored) recover();
        else skipUnavailable();
      });
      return;
    }
    // 2 = Netzwerk, 3 = Dekodierung (oft Folge eines abgerissenen Stroms),
    // 4 = Quelle nicht ladbar (so meldet Chrome einen gescheiterten Abruf)
    //
    // Ab 0.21.0: Scheitert ein Nicht-MP3-Titel, bevor er je geladen war,
    // kurz nachsehen, ob der Server die Datei liefert. Wenn ja, liegt es am
    // Format (der Browser sagte nur "vielleicht") - dann hilft kein
    // Nachladen, sondern ein klarer Hinweis bzw. der naechste Titel.
    // Umgewandelte Fassung: das ist MP3 - normal nachladen (ab 0.27.0)
    if (transcodedKey === trackKey(track)) {
      recover();
      return;
    }
    if ((code === 3 || code === 4) && !loadedOnce && extensionOf(track) !== 'mp3' && !undecodable.has(track.path + '|' + (track.source || ''))) {
      const key = trackKey(track);
      const wanted = shouldPlay;
      fetch(streamUrlFor(track), { headers: { Range: 'bytes=0-1' }, credentials: 'same-origin', cache: 'no-store' })
        .then((response) => {
          if (trackKey(playlist[currentIndex]) !== key) return;
          if (!response.ok) {
            recover();
            return;
          }
          undecodable.add(track.path + '|' + (track.source || ''));
          resetRecovery();
          // Kann der Server umwandeln, dann eben die MP3-Fassung (ab 0.27.0)
          if (canTranscode(track)) {
            loadTrack(currentIndex, wanted);
            return;
          }
          const next = wanted && key !== tappedKey ? playableIndex(currentIndex + 1, 1) : -1;
          if (next !== -1) {
            showToast(formatHint(track) + ' – übersprungen');
            loadTrack(next, true);
          } else {
            loadTrack(currentIndex, false); // zeigt den Hinweis an
          }
          if (typeof onTrackChange === 'function') onTrackChange(playlist[currentIndex], currentIndex);
        })
        .catch(() => recover());
      return;
    }
    recover();
  });

  audio.addEventListener('loadedmetadata', () => {
    loadedOnce = true;
    if (resumeAt === null) return;
    const target = Math.min(resumeAt, Math.max(0, (audio.duration || resumeAt) - 0.25));
    resumeAt = null;
    if (target > 0) audio.currentTime = target;
    if (resumePlay) {
      resumePlay = false;
      audio.play().catch(() => {});
    }
  });

  /*
   * Waechter: Wartet die Wiedergabe lange auf Daten, ohne dass der Browser
   * einen Fehler meldet (haengende Verbindung), wird neu geladen. Laeuft
   * nur, solange der Nutzer hoeren will.
   */
  let stallSince = 0;
  let stallAt = 0;
  window.setInterval(() => {
    if (!shouldPlay || failed || recoveryTimer || !audio.getAttribute('src')) {
      stallSince = 0;
      return;
    }
    const starving = audio.readyState < 3 /* HAVE_FUTURE_DATA */ && !audio.ended;
    if (!starving) {
      stallSince = 0;
      return;
    }
    const now = Date.now();
    if (!stallSince || Math.abs(audio.currentTime - stallAt) > 0.5) {
      stallSince = now;
      stallAt = audio.currentTime;
      return;
    }
    if (now - stallSince > STALL_TIMEOUT) {
      stallSince = 0;
      recover();
    }
  }, 2000);

  // ------------------------------------------------------------------
  // Offline: nicht gespeicherte Titel (ab 0.18.3)
  //
  // Wurde ein Ordner nur teilweise gespeichert, stehen ohne Verbindung
  // auch Titel in der Liste, die gar nicht auf dem Geraet liegen. Frueher
  // blieb die Wiedergabe am ersten davon haengen - die gespeicherten
  // dahinter kamen nie dran. Jetzt werden sie uebersprungen.
  // ------------------------------------------------------------------
  let storedUrls = null; // Set der Adressen im Offline-/Vorauslade-Speicher

  function isOffline() {
    return navigator.onLine === false || document.body.classList.contains('is-offline');
  }

  // Wie cacheKeyFor() im Service Worker: per Textersetzung, damit %20 nicht
  // zu "+" wird
  function plainUrl(raw) {
    let url = raw;
    try {
      url = new URL(raw, location.href).href;
    } catch (e) { /* unveraendert */ }
    if (!/[?&]retry=/.test(url)) return url;
    return url
      .replace(/([?&])retry=[^&#]*(&)?/, (match, sep, amp) => (amp ? sep : ''))
      .replace(/[?&]$/, '');
  }

  async function refreshStoredUrls() {
    if (!('caches' in window)) return;
    try {
      const set = new Set();
      for (const name of [OFFLINE_AUDIO_CACHE_PLAYER, PREFETCH_CACHE]) {
        const cache = await caches.open(name);
        (await cache.keys()).forEach((req) => set.add(plainUrl(req.url)));
      }
      storedUrls = set;
    } catch (e) {
      storedUrls = null;
    }
  }

  async function isStoredOffline(track) {
    await refreshStoredUrls();
    return !!storedUrls && storedUrls.has(plainUrl(streamUrlFor(track)));
  }

  /** Ohne Verbindung sicher NICHT abspielbar? (unbekannt = abspielbar) */
  function knownUnavailable(track) {
    return isOffline() && !!storedUrls && !storedUrls.has(plainUrl(streamUrlFor(track)));
  }

  /** Naechster abspielbarer Titel ab from (einschliesslich), -1 wenn keiner. */
  function playableIndex(from, step) {
    for (let i = from; i >= 0 && i < playlist.length; i += step) {
      if (!knownUnavailable(playlist[i]) && formatPlayable(playlist[i])) return i;
    }
    return -1;
  }

  async function skipUnavailable() {
    await refreshStoredUrls();
    const next = playableIndex(currentIndex + 1, 1);
    if (next !== -1) {
      showToast('Nicht offline gespeichert – übersprungen');
      loadTrack(next, true);
      return;
    }
    shouldPlay = false;
    audio.removeAttribute('src');
    audio.load();
    updatePlayPauseIcon();
    setMarqueeText(els.context, 'Nicht offline gespeichert');
    updateMarquees();
    showToast('Keine weitere Aufnahme offline gespeichert');
    setMediaSessionPlaybackState('paused');
  }

  window.addEventListener('offline', refreshStoredUrls);
  refreshStoredUrls();

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

  /*
   * Zustand beim Hinausschieben (ab 0.18.2). "sheetOut" heisst: Die Karte
   * liegt schon unten ausserhalb des Bildes, es fehlt nur noch das
   * eigentliche Schliessen. So springt sie nicht mehr kurz zurueck, waehrend
   * history.back() auf den popstate wartet (das war das Blitzen in 0.18.1).
   */
  let sheetSliding = false;
  let sheetOut = false;
  let slideCallbacks = [];
  let backPending = false;
  let slideTimer = 0;
  let enterTimer = 0;

  function reducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function clearSheetStyles() {
    els.bar.style.transform = '';
    els.bar.style.transition = '';
    els.bar.classList.remove('is-dragging', 'is-entering', 'is-docking');
    if (els.scrim) {
      els.scrim.style.transition = '';
      els.scrim.style.opacity = '';
    }
  }

  function setExpanded(open) {
    const wasOpen = els.bar.classList.contains('is-expanded');
    window.clearTimeout(slideTimer);
    window.clearTimeout(enterTimer);
    sheetSliding = false;
    sheetOut = false;
    slideCallbacks = [];
    backPending = false;
    clearSheetStyles();
    if (els.scrim) els.scrim.hidden = !open;
    els.bar.classList.toggle('is-expanded', open);
    document.getElementById('audioarchive').classList.toggle('aa-player-expanded', open);
    els.btnExpand.setAttribute('aria-expanded', open ? 'true' : 'false');
    updateMarquees();
    if (open) {
      /*
       * Die Einfahr-Animation haengt an einer eigenen Klasse, die nach dem
       * Einfahren wieder verschwindet. Vorher hing sie an .is-expanded und
       * startete jedes Mal neu, wenn die Wisch-Geste .is-dragging (dort
       * "animation: none") wieder abnahm - die Karte fuhr dann mitten im
       * Schliessen noch einmal von unten herein.
       */
      if (!reducedMotion()) {
        els.bar.classList.add('is-entering');
        // Laenger als die Karte selbst: Cover, Titel und Steuerung folgen
        // versetzt (ab 0.19.1) und sollen fertig einblenden
        enterTimer = window.setTimeout(() => els.bar.classList.remove('is-entering'), 850);
      }
      els.btnCollapse.focus({ preventScroll: true });
    } else {
      // Die Angaben gehoeren zum Vollbild - beim Verkleinern zuklappen
      if (!els.details.hidden) setDetailsOpen(false);
      updatePlayerBarSpace();
      if (wasOpen && !reducedMotion()) {
        els.bar.classList.add('is-docking');
        enterTimer = window.setTimeout(() => els.bar.classList.remove('is-docking'), 450);
      }
    }
  }

  els.bar.addEventListener('animationend', (event) => {
    if (event.target !== els.bar) return;
    if (event.animationName === 'aa-bar-dock') els.bar.classList.remove('is-docking');
  });

  /**
   * Schiebt die Karte nach unten aus dem Bild und ruft danach done() auf.
   * velocity (px/ms) kommt von der Wisch-Geste: Ein schneller Schubs
   * faehrt entsprechend schneller hinaus, damit die Bewegung nahtlos
   * weiterlaeuft statt abzubremsen.
   */
  function slideOut(velocity, fromOffset, done) {
    if (sheetOut) { done(); return; }
    // Faehrt sie schon hinaus, nur den Abschluss vormerken
    slideCallbacks.push(done);
    if (sheetSliding) return;
    const runCallbacks = () => {
      const list = slideCallbacks;
      slideCallbacks = [];
      list.forEach((fn) => fn());
    };
    if (reducedMotion()) {
      sheetOut = true;
      runCallbacks();
      return;
    }
    sheetSliding = true;
    els.bar.classList.remove('is-dragging', 'is-entering');

    const height = els.bar.getBoundingClientRect().height || window.innerHeight;
    const rest = Math.max(0, height - fromOffset);
    // Ohne Schwung (Griff, Zurueck-Geste) etwas gemaechlicher als frueher
    // (ab 0.19.1: 340 statt 260 ms), passend zum sanfteren Erscheinen
    let duration = 340;
    if (velocity > 0.2) duration = Math.round(rest / Math.max(velocity, 1.1));
    duration = Math.min(360, Math.max(160, duration));

    // Mit Schwung vom Finger: sofort schnell weiter; sonst weich an- und
    // auslaufen
    const curve = velocity > 0.2 ? 'cubic-bezier(0.3, 0.6, 0.4, 1)' : 'cubic-bezier(0.4, 0, 0.2, 1)';
    els.bar.style.transition = 'transform ' + duration + 'ms ' + curve;
    els.bar.style.transform = 'translate3d(0, ' + Math.ceil(height + 8) + 'px, 0)';
    if (els.scrim) {
      els.scrim.style.transition = 'opacity ' + duration + 'ms ease-out';
      els.scrim.style.opacity = '0';
    }

    let finished = false;
    const finish = () => {
      if (finished) return;
      finished = true;
      els.bar.removeEventListener('transitionend', onEnd);
      window.clearTimeout(slideTimer);
      sheetSliding = false;
      sheetOut = true;
      runCallbacks();
    };
    const onEnd = (event) => {
      if (event.target === els.bar && event.propertyName === 'transform') finish();
    };
    els.bar.addEventListener('transitionend', onEnd);
    // Falls transitionend ausbleibt (Tab im Hintergrund, Unterbrechung)
    slideTimer = window.setTimeout(finish, duration + 80);
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
    // Doppelt getippt, bevor der popstate da ist: nicht zwei Schritte zurueck
    if (backPending) return;
    if (expandedByHistory) {
      // Der popstate-Handler unten schiebt die Karte hinaus und schliesst
      backPending = true;
      history.back();
      // Sicherheitsnetz: Liegt die Karte schon draussen und kommt kein
      // popstate, darf die unsichtbare Karte nicht die Liste verdecken
      if (sheetOut) {
        window.setTimeout(() => {
          if (sheetOut && els.bar.classList.contains('is-expanded')) {
            expandedByHistory = false;
            setExpanded(false);
          }
        }, 500);
      }
      return;
    }
    slideOut(0, 0, () => setExpanded(false));
  }

  els.btnExpand.addEventListener('click', expand);
  els.coverBtn.addEventListener('click', () => {
    if (els.bar.classList.contains('is-expanded')) return;
    expand();
  });
  els.btnCollapse.addEventListener('click', collapse);
  // Tippen auf den abgedunkelten Streifen ueber der Karte schliesst auch
  if (els.scrim) els.scrim.addEventListener('click', collapse);

  /*
   * Nach unten wischen schliesst den Vollbild-Player (ab 0.18, ueberarbeitet
   * in 0.18.2).
   *
   * Die Karte folgt dem Finger. Beim Loslassen entscheidet vor allem die
   * RICHTUNG der letzten Bewegung, nicht nur die Strecke:
   *   - zuletzt nach oben bewegt oder spuerbar zurueckgezogen -> bleibt offen
   *   - schneller Schubs nach unten                          -> schliesst
   *   - sonst: weit genug gezogen                            -> schliesst
   * In 0.18.0/0.18.1 zaehlte nur die aktuelle Strecke (und eine ueber die
   * ganze Geste gemittelte Geschwindigkeit). Wer erst runter und dann
   * wieder etwas hoch zog, bekam trotzdem ein Schliessen.
   *
   * Nur wenn oben nichts mehr zu scrollen ist: Bei aufgeklappten Angaben
   * scrollt der Inhalt zuerst. Auf Reglern und Eingabefeldern startet die
   * Geste nicht, sonst liesse sich der Fortschritt nicht mehr ziehen. Auf
   * Knoepfen (Griff, Cover) darf sie starten - ein Klick nach dem Ziehen
   * wird dann verschluckt.
   */
  const SLOP = 8;                 // px Totzone, bevor die Karte mitgeht
  const FLICK_VELOCITY = 0.45;    // px/ms nach unten: Schubs schliesst
  const BACK_VELOCITY = -0.08;    // px/ms nach oben beim Loslassen: bleibt offen
  const PULLBACK = 24;            // px vom tiefsten Punkt zurueck: bleibt offen
  const VELOCITY_WINDOW = 90;     // ms - nur die letzten Bewegungen zaehlen

  let touchId = null;
  let startY = 0;
  let dragging = false;
  let offset = 0;
  let peak = 0;
  let samples = [];
  let suppressClickUntil = 0;

  function isExpanded() {
    return els.bar.classList.contains('is-expanded');
  }

  function closeDistance() {
    const h = els.bar.clientHeight || window.innerHeight;
    // Rund ein Fuenftel der Karte, aber nie unter 90 oder ueber 140 Punkte
    return Math.min(140, Math.max(90, h * 0.2));
  }

  function setDragOffset(px) {
    if (px <= 0) {
      els.bar.style.transform = '';
      if (els.scrim) els.scrim.style.opacity = '';
      return;
    }
    // Die Karte folgt dem Finger, die abgedunkelte Flaeche dahinter wird
    // heller - so sieht man, dass die Liste zurueckkommt
    els.bar.style.transform = 'translate3d(0, ' + px + 'px, 0)';
    const share = Math.min(1, px / (els.bar.clientHeight || 600));
    if (els.scrim) els.scrim.style.opacity = String(Math.max(0, 1 - share * 1.4));
  }

  function springBack(fromOffset) {
    if (reducedMotion() || fromOffset <= 0) {
      setDragOffset(0);
      return;
    }
    els.bar.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.9, 0.3, 1)';
    if (els.scrim) els.scrim.style.transition = 'opacity 0.3s ease-out';
    setDragOffset(0);
    window.clearTimeout(slideTimer);
    slideTimer = window.setTimeout(() => {
      if (sheetSliding || sheetOut) return;
      els.bar.style.transition = '';
      if (els.scrim) els.scrim.style.transition = '';
    }, 340);
  }

  function releaseVelocity(now) {
    // Geschwindigkeit nur aus den letzten ~90 ms vor der letzten Bewegung.
    // Steht der Finger vor dem Loslassen still, kommen keine touchmove
    // mehr - liegt die letzte Bewegung zu lange zurueck, ist sie 0.
    if (samples.length < 2) return 0;
    const last = samples[samples.length - 1];
    if (now - last.t > 80) return 0;
    let first = last;
    for (let i = samples.length - 2; i >= 0; i--) {
      if (last.t - samples[i].t > VELOCITY_WINDOW) break;
      first = samples[i];
    }
    const dt = last.t - first.t;
    return dt > 0 ? (last.y - first.y) / dt : 0;
  }

  function resetTouch() {
    touchId = null;
    dragging = false;
    offset = 0;
    peak = 0;
    samples = [];
  }

  function onTouchStart(event) {
    if (!isExpanded() || sheetSliding || sheetOut) return;
    if (event.touches.length !== 1) {
      // Zweiter Finger: laufende Geste abbrechen
      if (dragging) {
        const was = offset;
        resetTouch();
        els.bar.classList.remove('is-dragging');
        springBack(was);
        return;
      }
      resetTouch();
      return;
    }
    const fromScrim = event.currentTarget === els.scrim;
    if (!fromScrim) {
      if (event.target.closest('input, select, textarea, [contenteditable="true"]')) return;
      // Erst ganz oben (1 Punkt Spielraum fuer Rundungen)
      if (els.bar.scrollTop > 1) return;
    }
    // Faengt eine noch laufende Einfahr-Animation ab
    els.bar.classList.remove('is-entering');
    window.clearTimeout(slideTimer);
    els.bar.style.transition = '';
    if (els.scrim) els.scrim.style.transition = '';

    const touch = event.touches[0];
    touchId = touch.identifier;
    startY = touch.clientY;
    dragging = false;
    offset = 0;
    peak = 0;
    samples = [{ t: performance.now(), y: touch.clientY }];
  }

  function findTouch(list) {
    for (let i = 0; i < list.length; i++) {
      if (list[i].identifier === touchId) return list[i];
    }
    return null;
  }

  function onTouchMove(event) {
    if (touchId === null) return;
    const touch = findTouch(event.changedTouches);
    if (!touch) return;
    const dy = touch.clientY - startY;

    if (!dragging) {
      // Nach oben: normales Scrollen, Geste verwerfen
      if (dy < -SLOP / 2) {
        resetTouch();
        return;
      }
      // Hat der Browser schon selbst zu scrollen begonnen, laesst sich das
      // nicht mehr verhindern - dann keine Geste, sonst ruckelt beides
      if (!event.cancelable) {
        resetTouch();
        return;
      }
      // Schon die ersten Punkte nach unten abfangen, sonst beginnt der
      // Browser mit Neu-Laden (Android) oder Gummiband (iPhone)
      event.preventDefault();
      if (dy < SLOP) return;
      dragging = true;
      els.bar.classList.add('is-dragging');
    }

    if (event.cancelable) event.preventDefault();
    // Totzone abziehen, damit die Karte beim Start nicht springt
    offset = Math.max(0, dy - SLOP);
    peak = Math.max(peak, offset);
    const now = performance.now();
    samples.push({ t: now, y: touch.clientY });
    // Nur die juengsten Punkte behalten
    while (samples.length > 2 && now - samples[0].t > VELOCITY_WINDOW * 2) samples.shift();
    setDragOffset(offset);
  }

  function onTouchEnd(event) {
    if (touchId === null) return;
    if (event.changedTouches && !findTouch(event.changedTouches)) return;
    if (!dragging) {
      resetTouch();
      return;
    }

    const velocity = releaseVelocity(performance.now());
    const pulledBack = peak - offset > PULLBACK;
    let close;
    if (velocity < BACK_VELOCITY) {
      close = false;                        // zuletzt nach oben bewegt
    } else if (velocity > FLICK_VELOCITY && offset > SLOP) {
      close = true;                         // schneller Schubs nach unten
    } else if (pulledBack) {
      close = false;                        // zurueckgezogen und losgelassen
    } else {
      // Strecke plus etwas Schwung: Wer zuegig zieht, muss nicht ganz so
      // weit ziehen wie jemand, der langsam zieht und stehen bleibt
      close = offset + Math.max(0, velocity) * 150 > closeDistance();
    }

    // Nach dem Ziehen keinen Klick auf Griff, Cover oder Knopf ausloesen
    suppressClickUntil = Date.now() + 400;
    const releasedOffset = offset;
    resetTouch();

    if (close) {
      slideOut(Math.max(0, velocity), releasedOffset, collapse);
    } else {
      els.bar.classList.remove('is-dragging');
      springBack(releasedOffset);
    }
  }

  function onTouchCancel() {
    if (touchId === null) return;
    const wasDragging = dragging;
    const releasedOffset = offset;
    resetTouch();
    if (wasDragging) {
      els.bar.classList.remove('is-dragging');
      springBack(releasedOffset);
    }
  }

  [els.bar, els.scrim].forEach((el) => {
    if (!el) return;
    el.addEventListener('touchstart', onTouchStart, { passive: true });
    el.addEventListener('touchmove', onTouchMove, { passive: false });
    el.addEventListener('touchend', onTouchEnd);
    el.addEventListener('touchcancel', onTouchCancel);
  });

  // Klick nach einer Wisch-Geste verschlucken (Capture-Phase, vor allen
  // anderen Handlern der Leiste)
  document.addEventListener('click', (event) => {
    if (Date.now() < suppressClickUntil
      && (els.bar.contains(event.target) || event.target === els.scrim)) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);

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
   *
   * Ab 0.18.2 faehrt die Karte auch hier nach unten hinaus (Zurueck-Geste,
   * Griff, Escape). Liegt sie nach einer Wisch-Geste schon draussen, wird
   * nur noch geschlossen.
   */
  window.addEventListener('popstate', (event) => {
    if (!els.bar.classList.contains('is-expanded')) return;
    expandedByHistory = false;
    backPending = false;
    event.stopImmediatePropagation();
    slideOut(0, 0, () => setExpanded(false));
  });

  return {
    /** Kann dieser Browser das Format abspielen? Und wenn nicht: warum (ab 0.21.0). */
    /** Umwandlung in MP3 verfuegbar? (aus der Ordnerliste, ab 0.27.0) */
    setTranscode(on) {
      transcodeOn = on === true;
    },

    /** Kommentare verfuegbar? (aus der Ordnerliste, ab 0.29.0; die Bewertung meldet die Kommentar-Abfrage) */
    setComments(on) {
      commentsOn = on === true;
      els.btnComments.hidden = !commentsOn;
      if (!commentsOn && !els.comments.hidden) setCommentsOpen(false);
    },

    formatSupport(track) {
      const playable = formatPlayable(track);
      const short = APPLE_ONLY.includes(extensionOf(track)) ? 'nur Safari' : 'hier nicht abspielbar';
      const ext = extensionOf(track);
      return {
        playable,
        hint: playable ? '' : formatHint(track),
        short: playable ? '' : short,
        // Kurzname fuer die Zeile; bei MP3 (dem Normalfall) leer
        label: ext === 'mp3' ? '' : ext.toUpperCase(),
      };
    },

    /**
     * Startet eine neue "Playlist" (= Inhalt eines Kategorie-Ordners) ab einem
     * bestimmten Titel-Index. tracks: [{name, path}], label: Anzeigekontext.
     */
    playFolder(tracks, startIndex, label) {
      // Ohne Verbindung nicht gespeichert: gar nicht erst versuchen (ab 0.18.3)
      if (tracks[startIndex] && knownUnavailable(tracks[startIndex])) {
        showToast('Diese Aufnahme ist nicht offline gespeichert');
        return;
      }
      // Format, das dieser Browser nicht kann: Hinweis, laufende
      // Wiedergabe bleibt unberuehrt (ab 0.21.0)
      if (tracks[startIndex] && !formatPlayable(tracks[startIndex]) && currentIndex >= 0 && !audio.paused) {
        showToast(formatHint(tracks[startIndex]));
        return;
      }
      playlist = tracks;
      tappedKey = trackKey(tracks[startIndex]);
      loadTrack(startIndex, true);
      refreshStoredUrls();
    },

    resume() {
      // Format, das dieser Browser nicht kann: nur den Hinweis wiederholen
      if (playlist[currentIndex] && !formatPlayable(playlist[currentIndex])) {
        showToast(formatHint(playlist[currentIndex]));
        return;
      }
      shouldPlay = true;
      if (pendingRecovery) {
        const reload = pendingRecovery;
        pendingRecovery = null;
        resumePlay = true;
        reload();
        return;
      }
      // Quelle im Fehlerzustand: play() allein bewirkt dann nichts. Ein
      // geplanter Versuch wird dabei vorgezogen, der Zaehler beginnt neu.
      if (audio.error && playlist[currentIndex]) {
        window.clearTimeout(recoveryTimer);
        recoveryTimer = 0;
        recoveries = 0;
        reloadAt(intendedTime !== null ? intendedTime : lastGoodTime, true);
        return;
      }
      audio.play().catch(() => {});
    },

    pause() {
      shouldPlay = false;
      audio.pause();
    },

    prev() {
      const prevIndex = currentIndex > 0 ? playableIndex(currentIndex - 1, -1) : -1;
      if (prevIndex !== -1) {
        loadTrack(prevIndex, true);
      } else {
        audio.currentTime = 0;
      }
    },

    next() {
      const nextIndex = playableIndex(currentIndex + 1, 1);
      if (nextIndex !== -1) {
        loadTrack(nextIndex, true);
        return;
      }
      // Am Ende des Ordners wie beim natuerlichen Ende - ausser bei
      // "Titel wiederholen": Dort soll "Naechster" nicht haengen bleiben
      if (repeatMode === 'folder' || repeatMode === 'one') {
        const first = playableIndex(0, 1);
        if (first !== -1) loadTrack(first, true);
      } else if (repeatMode === 'next') {
        finishQueue(false);
      }
    },

    /** Nach dem Offline-Speichern/-Entfernen aufrufen (ab 0.18.3). */
    refreshOffline() {
      return refreshStoredUrls();
    },

    getCurrentPath() {
      return currentIndex >= 0 ? playlist[currentIndex].path : null;
    },

    /** Zuletzt gehoerte, nicht zu Ende gehoerte Aufnahme dieses Links (ab 0.23.0). */
    lastSession() {
      let best = null;
      Object.keys(positions).forEach((k) => {
        if (!k.startsWith(POS_SCOPE + '#')) return;
        const e = positions[k];
        if (e && (!best || e.at > best.at)) best = e;
      });
      return best ? { ...best } : null;
    },

    /** Kurze Meldung ueber der Player-Leiste (ab 0.24.0, fuer app.js). */
    showMessage(text) {
      showToast(text);
    },

    /** Gemerkte Stelle eines Titels (Sekunden, 0 = keine). */
    savedPosition(track) {
      return savedPosition(track);
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
     * Symbolfarbe (ab 0.16) oder Ersatzbild (ab 0.20) haben sich geaendert
     * (Aussehen einer Freigabe): Titel ohne eigenes Cover zeigen das neue.
     */
    refreshIcon() {
      const track = playlist[currentIndex];
      if (!track || els.bar.hidden || coverUrlFor(track)) return;
      els.bar.classList.toggle('cover-generated', !AudioArchive.customCover());
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
