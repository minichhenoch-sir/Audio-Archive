/**
 * app.js
 * Steuert Login-Flow (inkl. Admin-Trigger), Anzeige-Einstellungen
 * (Titel/Untertitel/Hintergrund) und die Explorer-artige Ordnernavigation.
 */

(() => {
  const loginScreen = document.getElementById('login-screen');
  const mainScreen = document.getElementById('main-screen');
  const loginForm = document.getElementById('login-form');
  const loginPassword = document.getElementById('login-password');
  const loginError = document.getElementById('login-error');
  const loginSubmit = document.getElementById('login-submit');
  const loginTitle = document.getElementById('login-title');
  const logoutBtn = document.getElementById('logout-btn');
  const topbarTitle = document.getElementById('topbar-title');
  const topbarSubtitle = document.getElementById('topbar-subtitle');
  const libraryStatus = document.getElementById('library-status');
  const breadcrumbEl = document.getElementById('breadcrumb');
  const listContainer = document.getElementById('list-container');
  const offlineBar = document.getElementById('offline-bar');
  const offlineBtn = document.getElementById('offline-btn');
  const offlineInfo = document.getElementById('offline-info');

  // Navigationszustand des Explorers: bildet die ECHTE Ordnerstruktur ab.
  // view.source ist die Quelle ('shared' = gemeinsamer Ordner, 'home' =
  // eigene Dateien), view.path der relative Pfad des geöffneten Ordners
  // ('' = Audio-Hauptverzeichnis), beliebig tief verschachtelt.
  let currentEntries = [];
  let view = { source: 'shared', path: '' };

  // ------------------------------------------------------------------
  // Installierte App, aber mit Nextcloud-Rahmen gestartet?
  // ------------------------------------------------------------------
  /*
   * Bis 0.7 war die Startadresse der installierten App /apps/audioarchive/.
   * Dort liegt jetzt die Fassung MIT Nextcloud-Leiste. Bereits installierte
   * Apps starten dort, bis der Browser das neue Manifest uebernommen hat -
   * sie werden deshalb auf die Fassung ohne Leiste umgeleitet.
   */
  const runsAsInstalledApp = window.matchMedia('(display-mode: standalone)').matches
    || window.matchMedia('(display-mode: fullscreen)').matches
    || window.navigator.standalone === true;

  if (AudioArchive.isEmbedded() && runsAsInstalledApp && AudioArchive.standaloneUrl) {
    location.replace(AudioArchive.standaloneUrl);
    return;
  }

  // ------------------------------------------------------------------
  // Knopf "App installieren"
  // ------------------------------------------------------------------
  /*
   * Innerhalb von Nextcloud kann die Seite nicht installiert werden (dort
   * gilt Nextclouds Manifest). Der Knopf fuehrt deshalb zur Fassung ohne
   * Leiste; dort bietet der Browser die Installation an.
   *
   * In der Fassung ohne Leiste erscheint der Knopf nur, wenn der Browser
   * die Installation direkt anbietet (Chrome, Edge, Android). Safari kennt
   * das nicht - dort geht es ueber "Teilen > Zum Home-Bildschirm".
   */
  const installBtn = document.getElementById('install-btn');
  let installPrompt = null;

  if (installBtn) {
    if (AudioArchive.isEmbedded()) {
      installBtn.href = AudioArchive.standaloneUrl;
      installBtn.hidden = false;
    } else if (!runsAsInstalledApp) {
      window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        installBtn.hidden = false;
      });

      installBtn.addEventListener('click', async (event) => {
        event.preventDefault();
        if (!installPrompt) return;
        const prompt = installPrompt;
        installPrompt = null;
        installBtn.hidden = true;
        prompt.prompt();
        try {
          await prompt.userChoice;
        } catch (e) {
          // Abgebrochen - nichts weiter zu tun
        }
      });

      window.addEventListener('appinstalled', () => {
        installPrompt = null;
        installBtn.hidden = true;
      });
    }
  }

  // ------------------------------------------------------------------
  // Service Worker registrieren (PWA-Installierbarkeit + App-Shell-Cache)
  // ------------------------------------------------------------------
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      // Adresse und Geltungsbereich kommen vom Server: Der Worker wird ueber
      // eine Route ausgeliefert, damit er den ganzen App-Pfad abdecken darf.
      navigator.serviceWorker.register(AudioArchive.serviceWorker, { scope: AudioArchive.scope }).catch((err) => {
        console.warn('Service Worker Registrierung fehlgeschlagen:', err);
      });
    });
  }

  // ------------------------------------------------------------------
  // Öffentliche Anzeige-Einstellungen (Titel/Untertitel/Hintergrund) -
  // werden schon VOR dem Login geladen, da sie auch auf dem Login-Screen
  // sichtbar sein sollen.
  // ------------------------------------------------------------------
  /**
   * Titel, Untertitel und Farben stehen bereits im Dokument - der Server hat
   * sie beim Ausliefern der Seite hineingeschrieben. Anders als in der
   * eigenstaendigen Fassung ist dafuer kein eigener Abruf noetig, und die
   * Seite steht sofort richtig da, ohne kurzes Umspringen.
   */
  /**
   * Zeigt die Beta-Kennzeichnung: ein kleines Zeichen neben dem Titel und
   * darunter den vom Administrator formulierten Hinweis ueber dem Pfad.
   *
   * Der Link wird bewusst NUR mit seiner Beschriftung angezeigt, nicht mit
   * der vollen Adresse - lange Adressen sprengen auf dem Telefon die Zeile.
   */
  // Das "Beta"-Zeichen - einmal erzeugt, bei jedem Titelwechsel wieder
  // angehaengt (siehe renderTitles)
  let betaBadge = null;

  function applyBetaNotice() {
    if (!AudioArchive.betaEnabled) return;

    // Kennzeichnung in der Kopfzeile
    betaBadge = document.createElement('span');
    betaBadge.className = 'beta-badge';
    betaBadge.textContent = 'Beta';
    // In die Ueberschrift hinein, nicht daneben: Als eigenstaendiges
    // Element neben dem h1 wuerde es in einer eigenen Zeile landen.
    topbarTitle.appendChild(betaBadge);

    const notice = document.getElementById('beta-notice');
    if (!notice) return;

    const text = AudioArchive.betaText.trim();
    const url = AudioArchive.betaLinkUrl.trim();
    const label = AudioArchive.betaLinkLabel.trim() || 'Mehr erfahren';

    if (text === '' && url === '') return;

    notice.textContent = '';

    if (text !== '') {
      const span = document.createElement('span');
      span.textContent = text;
      notice.appendChild(span);
    }

    if (url !== '') {
      const link = document.createElement('a');
      /*
       * textContent statt innerHTML: Der Text stammt zwar vom
       * Administrator, wird aber allen Nutzern angezeigt - auch denen der
       * oeffentlichen Seite. So kann daraus kein Markup werden.
       */
      link.textContent = label;
      link.href = url;
      link.target = '_blank';
      link.rel = 'noreferrer noopener';
      notice.appendChild(document.createTextNode(' '));
      notice.appendChild(link);
    }

    notice.hidden = false;
  }

  /** Titel und Zusatzzeile in der Kopfzeile setzen (Beta-Zeichen bleibt). */
  function renderTitles(title, subtitle) {
    document.title = title;
    topbarTitle.textContent = title;
    if (betaBadge) topbarTitle.appendChild(betaBadge);
    topbarSubtitle.textContent = subtitle;
    topbarSubtitle.hidden = subtitle === '';
  }

  function applySettingsFromDocument() {
    if (AudioArchive.style) {
      applyStyle(AudioArchive.style);
    } else {
      applyTheme(AudioArchive.themeAccent, AudioArchive.themeBar, AudioArchive.themeBase);
    }

    const title = AudioArchive.headerTitle || 'Audio Archive';
    const subtitle = AudioArchive.headerSubtitle || '';

    renderTitles(title, subtitle);
    loginTitle.textContent = title;

    const loginSubtitle = document.getElementById('login-subtitle');
    if (loginSubtitle) {
      loginSubtitle.textContent = subtitle;
      loginSubtitle.hidden = subtitle === '';
    }

    /*
     * Der Abmelde-Knopf beendet nur die Sitzung der oeffentlichen Seite.
     * Fuer angemeldete Nextcloud-Nutzer ergibt er keinen Sinn - die melden
     * sich ueber Nextcloud selbst ab.
     */
    // Ebenso bei einem Link ohne Passwort: Dort gibt es nichts abzumelden.
    if (!AudioArchive.isPublic() || AudioArchive.openAccess) {
      logoutBtn.hidden = true;
    }

    applyBetaNotice();
  }

  // ------------------------------------------------------------------
  // Design/Farben aus den Einstellungen anwenden.
  // Gesetzt werden nur CSS-Variablen - das gesamte Stylesheet arbeitet
  // ausschliesslich mit diesen Variablen, deshalb faerbt sich die komplette
  // Oberflaeche (Leisten, Knoepfe, Akzente) automatisch mit um.
  // ------------------------------------------------------------------
  function hexToRgb(hex) {
    if (typeof hex !== 'string') return null;
    let h = hex.trim().replace('#', '');
    if (h.length === 3) h = h.split('').map((c) => c + c).join('');
    if (!/^[0-9a-fA-F]{6}$/.test(h)) return null;
    return {
      r: parseInt(h.slice(0, 2), 16),
      g: parseInt(h.slice(2, 4), 16),
      b: parseInt(h.slice(4, 6), 16),
    };
  }

  /** Mischt eine Farbe Richtung Schwarz (amount 0..1) - fuer Hover-/Tiefton. */
  function darken({ r, g, b }, amount) {
    const f = (v) => Math.max(0, Math.round(v * (1 - amount)));
    return `rgb(${f(r)}, ${f(g)}, ${f(b)})`;
  }

  /** Mischt eine Farbe Richtung Weiss (amount 0..1) - fuer helle Verlaufsstufen. */
  function lighten({ r, g, b }, amount) {
    const f = (v) => Math.round(v + (255 - v) * amount);
    return { r: f(r), g: f(g), b: f(b) };
  }

  /**
   * Baut den Verlaufs-Hintergrund aus einem einzigen Grundton auf, damit
   * sich die gesamte Oberflaeche (z.B. auf Blau oder Gruen) umstellen laesst,
   * ohne dass fuer jede Farbe ein eigener Verlauf hinterlegt werden muss.
   */
  function buildBackgroundGradient(base) {
    const rgb = (c, a) => `rgba(${c.r}, ${c.g}, ${c.b}, ${a})`;
    const light = lighten(base, 0.45);
    const mid = base;
    const dark = { r: base.r * 0.45, g: base.g * 0.45, b: base.b * 0.45 };
    const deep = { r: base.r * 0.16, g: base.g * 0.16, b: base.b * 0.16 };
    const rnd = (c) => ({ r: Math.round(c.r), g: Math.round(c.g), b: Math.round(c.b) });

    return [
      `radial-gradient(circle at 12% 18%, ${rgb(light, 0.65)}, transparent 42%)`,
      `radial-gradient(circle at 88% 12%, ${rgb(mid, 0.55)}, transparent 46%)`,
      `radial-gradient(circle at 82% 82%, ${rgb(rnd(dark), 0.55)}, transparent 48%)`,
      `radial-gradient(circle at 15% 85%, ${rgb(rnd(deep), 0.6)}, transparent 46%)`,
      `linear-gradient(160deg, rgb(${light.r}, ${light.g}, ${light.b}) 0%, `
        + `rgb(${mid.r}, ${mid.g}, ${mid.b}) 40%, `
        + `rgb(${Math.round(dark.r)}, ${Math.round(dark.g)}, ${Math.round(dark.b)}) 75%, `
        + `rgb(${Math.round(deep.r)}, ${Math.round(deep.g)}, ${Math.round(deep.b)}) 100%)`,
    ].join(', ');
  }

  function applyTheme(accentHex, barHex, baseHex) {
    // Bei Nextcloud-Gestaltung bleiben Farben und Hintergrund ganz bei
    // Nextclouds Variablen - eigene Werte wuerden sie hier ueberschreiben.
    if (AudioArchive.isNextcloudDesign()) return;

    const root = document.documentElement.style;

    const accent = hexToRgb(accentHex);
    if (accent) {
      const { r, g, b } = accent;
      root.setProperty('--color-accent', `rgb(${r}, ${g}, ${b})`);
      root.setProperty('--color-accent-deep', darken(accent, 0.3));
      root.setProperty('--color-accent-rgb', `${r}, ${g}, ${b}`);
      // Akzent als Schrift auf den hellen Listen: auf Kontrast gerechnet
      // (0.17.1), sonst sind helle Akzente wie Rosé oder Sonne kaum lesbar
      root.setProperty('--aa-accent-on-surface', AAStyle.readableOn(accentHex, '#fffaf2'));
    }

    const bar = hexToRgb(barHex);
    if (bar) {
      const { r, g, b } = bar;
      root.setProperty('--glass-dark-bg', `rgba(${r}, ${g}, ${b}, 0.55)`);
      root.setProperty('--glass-dark-bg-strong', `rgba(${r}, ${g}, ${b}, 0.72)`);

      // Auch die Statusleisten-Farbe der installierten App mitfaerben
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', barHex);
    }

    // Grundton der Flaeche: Nur anwenden, wenn KEIN eigenes Hintergrundbild
    // gesetzt ist - sonst wuerde der Verlauf das Foto ueberdecken.
    const base = hexToRgb(baseHex);
    themeGradient = base ? buildBackgroundGradient(base) : '';
    applyBackgroundLayer();
  }

  // ------------------------------------------------------------------
  // Frei eingestellte Gestaltung anwenden (ab 0.17): "Vom Administrator
  // bereitgestellt" und "Benutzerdefiniert". Die Werte rechnet
  // style-tokens.js in CSS-Variablen um:
  //   - auf <html> die Variablen aus :root (Farben, Glas, Rundung, Schrift)
  //   - beim flachen Grundstil zusaetzlich auf #audioarchive Nextclouds
  //     Variablen, auf denen der flache Aufbau steht. Eingebettet NUR dort,
  //     sonst wuerde sich Nextclouds eigene Kopfleiste mit umfaerben.
  // ------------------------------------------------------------------
  const appRoot = document.getElementById('audioarchive');

  function clearStyleVars() {
    const doc = document.documentElement.style;
    AAStyle.ALL_DOC_KEYS.forEach((k) => doc.removeProperty(k));
    AAStyle.ALL_ROOT_KEYS.forEach((k) => {
      appRoot.style.removeProperty(k);
      if (!AudioArchive.isEmbedded()) doc.removeProperty(k);
    });
    imageOverlay = DEFAULT_IMAGE_OVERLAY;
  }

  function applyStyle(style) {
    clearStyleVars();
    const vars = AAStyle.appVars(style);
    const doc = document.documentElement.style;
    Object.entries(vars.doc).forEach(([k, v]) => doc.setProperty(k, v));
    Object.entries(vars.root).forEach(([k, v]) => {
      appRoot.style.setProperty(k, v);
      if (!AudioArchive.isEmbedded()) doc.setProperty(k, v);
    });

    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', vars.computed.themeColor);

    // Flach: Hintergrund macht der Nextcloud-Aufbau (siehe Look)
    if (vars.computed.flat) return;
    const c = vars.computed;
    themeGradient = c.bgImage === 'none'
      ? `linear-gradient(${c.bgColor}, ${c.bgColor})`
      : c.bgImage;
    imageOverlay = c.imageOverlay;
    applyBackgroundLayer();
  }

  // Merkt sich den aktuellen Stand, damit Verlauf und Hintergrundbild
  // unabhaengig voneinander gesetzt werden koennen.
  const DEFAULT_IMAGE_OVERLAY = 'rgba(20,14,9,0.55)';
  let imageOverlay = DEFAULT_IMAGE_OVERLAY;
  let themeGradient = '';
  // Vom Administrator gesetztes Hintergrundbild; leer bedeutet: Verlauf
  // aus dem Grundton.
  let backgroundImageUrl = AudioArchive.backgroundUrl || '';

  function applyBackgroundLayer() {
    const layer = document.getElementById('bg-layer');
    if (!layer) return;

    if (backgroundImageUrl) {
      layer.style.backgroundImage =
        `linear-gradient(${imageOverlay}, ${imageOverlay}), url("${backgroundImageUrl}")`;
      document.body.classList.add('has-bg-image');
    } else {
      layer.style.backgroundImage = themeGradient;
      document.body.classList.remove('has-bg-image');
    }
  }

  // ------------------------------------------------------------------
  // Aussehen wechseln (ab 0.13)
  //
  // Ein mit dem Nutzer geteilter Ordner kann sein eigenes Aussehen
  // mitbringen: Gestaltung, Farben, Hintergrundbild, Titel. Der Empfaenger
  // entscheidet je Freigabe, ob er es sehen will. Gewechselt wird ohne
  // Neuladen der Seite - der Ordnerbaum und die laufende Wiedergabe bleiben
  // so erhalten. Beim Verlassen des Ordners gilt wieder die eigene Ansicht.
  // ------------------------------------------------------------------
  const Look = (() => {
    const rootEl = document.getElementById('audioarchive');
    const base = {
      design: AudioArchive.design,
      style: AudioArchive.style,
      title: AudioArchive.headerTitle || 'Audio Archive',
      subtitle: AudioArchive.headerSubtitle || '',
      themeAccent: AudioArchive.themeAccent,
      themeBar: AudioArchive.themeBar,
      themeBase: AudioArchive.themeBase,
      backgroundUrl: AudioArchive.backgroundUrl || '',
    };
    let activeKey = 'base';
    let stylesheetsLoaded = AudioArchive.design === 'nextcloud' || AudioArchive.isEmbedded();

    /*
     * Nextclouds Variablen: Innerhalb von Nextcloud sind sie immer da. Auf
     * der Seite ohne Leiste werden sie nur bei Nextcloud-Gestaltung
     * eingebunden und muessen fuer einen geteilten Ordner mit dieser
     * Gestaltung nachgeladen werden.
     */
    function ensureNextcloudStylesheets() {
      if (stylesheetsLoaded) return;
      stylesheetsLoaded = true;
      const first = document.head.querySelector('link[rel="stylesheet"]');
      AudioArchive.themeStylesheets.forEach((sheet) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.media = sheet.media || 'all';
        link.href = sheet.href;
        document.head.insertBefore(link, first);
      });
    }

    let activeLook = base;

    function apply(look, key) {
      if (key === activeKey) return;
      activeKey = key;
      activeLook = look;

      // Frei eingestellte Werte ('admin'/'defined', ab 0.17)
      const style = look.style && look.design !== 'nextcloud' && look.design !== 'custom'
        ? AAStyle.normalize(look.style) : null;
      // Flacher Aufbau: "Klassisch" oder Grundstil flach
      const nc = look.design === 'nextcloud' || (style !== null && style.base === 'classic');
      AudioArchive.setDesign(nc ? 'nextcloud' : 'custom');
      // Nextclouds Variablen braucht nur "Klassisch" - beim flachen
      // Grundstil belegt applyStyle() sie selbst
      if (look.design === 'nextcloud') ensureNextcloudStylesheets();
      rootEl.classList.toggle('aa-design-nextcloud', nc);

      // Bild fuer die Nextcloud-Gestaltung (CSS-Variable, siehe style.css)
      const url = look.backgroundUrl || '';
      rootEl.classList.toggle('aa-has-image', url !== '');
      if (url) {
        rootEl.style.setProperty('--aa-image', `url("${url.replace(/["\\\n]/g, '')}")`);
      } else {
        rootEl.style.removeProperty('--aa-image');
      }

      if (nc) {
        // Die eigene Gestaltung malt auf #bg-layer - das muss weg, sonst
        // verdeckt es Nextclouds ruhige Flaeche
        const layer = document.getElementById('bg-layer');
        if (layer) layer.style.backgroundImage = '';
        document.body.classList.remove('has-bg-image');
      } else {
        backgroundImageUrl = url;
      }
      if (style) {
        applyStyle(style);
      } else {
        clearStyleVars();
        if (!nc) applyTheme(look.themeAccent, look.themeBar, look.themeBase);
      }

      renderTitles(look.title || base.title, look.subtitle || '');
      let iconColor = look.themeBar || base.themeBar;
      if (look.design === 'nextcloud') iconColor = AudioArchive.ncPrimary();
      else if (style) iconColor = AAStyle.compute(style).themeColor;
      updateAppIcon(iconColor);
    }

    // Vorschau beim Einstellen (Zahnrad): danach zurueck zum vorigen Stand
    let beforePreview = null;
    let previewCount = 0;

    /** App-Symbol (Browser-Tab, Player, Benachrichtigung) umfaerben, ab 0.16 */
    function updateAppIcon(color) {
      if (!AudioArchive.setIconColor(color)) return;
      const favicon = document.querySelector('link[rel="icon"]');
      if (favicon && !AudioArchive.isEmbedded()) favicon.href = AudioArchive.iconUrl('any-192');
      Player.refreshIcon();
    }

    return {
      /** Eigene Ansicht wiederherstellen. */
      reset() {
        apply(base, 'base');
      },
      /** Aussehen eines geteilten Ordners anwenden. */
      show(look, key) {
        apply({ ...base, ...look }, key);
      },
      /** Die eigene Ansicht, wie sie gerade gilt (Ausgang fuer Vorschauen). */
      base() {
        return { ...base };
      },
      /** Voruebergehend ein Aussehen zeigen (ab 0.17, beim Einstellen). */
      preview(look) {
        if (beforePreview === null) beforePreview = { look: activeLook, key: activeKey };
        apply({ ...base, ...look }, 'preview-' + (++previewCount));
      },
      /** Vorschau beenden und den Stand davor wiederherstellen. */
      endPreview() {
        if (beforePreview === null) return;
        const { look, key } = beforePreview;
        beforePreview = null;
        activeKey = null;
        apply(look, key);
      },
    };
  })();

  // ------------------------------------------------------------------
  // Bildschirm-Wechsel
  // ------------------------------------------------------------------
  function showLogin() {
    loginScreen.hidden = false;
    mainScreen.hidden = true;
  }

  function showMain() {
    loginScreen.hidden = true;
    mainScreen.hidden = false;
    // Explorer immer sauber im Hauptordner öffnen: angemeldet zuerst der
    // gemeinsame Ordner, sofern eingerichtet, sonst die eigenen Dateien
    view = { source: startSource(), path: '' };
    // Ohne Verbindung mit einer Quelle beginnen, fuer die etwas gespeichert ist
    if (offlineMode) {
      const available = offlineSources();
      if (!available.has(view.source) && available.size > 0) {
        view.source = available.has('shared') ? 'shared'
          : (available.has('home') ? 'home' : Array.from(available)[0]);
      }
    }
    // Basis-Historie-Eintrag setzen (ersetzt den aktuellen Eintrag, statt
    // einen neuen zu erzeugen) - Ausgangspunkt für die Zurück-Geste/-Taste.
    history.replaceState({ view }, '');
    loadLibrary('', view.source);
    Tree.init();
  }

  // ------------------------------------------------------------------
  // Explorer-Navigation über die History-API führen, damit die
  // Zurück-Geste (z. B. Android-Swipe) in der Ordnerstruktur eine Ebene
  // zurückgeht, statt direkt die App zu schließen. Jede Navigation
  // "tiefer" oder "zur Seite" (Breadcrumb) erzeugt einen History-Eintrag;
  // der Zurück-Button/die Geste löst dann popstate aus (siehe unten),
  // statt die App zu verlassen. Nur ganz oben auf der Monatsebene
  // schließt Zurück die App wie gewohnt.
  // ------------------------------------------------------------------
  function navigate(newPath, source = view.source) {
    view = { source, path: newPath };
    history.pushState({ view }, '');
    loadLibrary(newPath, source);
  }

  function startSource() {
    if (AudioArchive.isPublic() || !AudioArchive.loggedIn) return 'shared';
    return AudioArchive.hasShared ? 'shared' : 'home';
  }

  /** Anzeigename einer Quelle - Wurzel im Pfad und im Ordnerbaum. */
  function sourceLabel(source) {
    if (AudioArchive.isIncoming(source)) return Incoming.label(source);
    return source === 'home' ? 'Meine Dateien' : (AudioArchive.loggedIn && !AudioArchive.isPublic()
      ? 'Gemeinsame Aufnahmen'
      : 'Aufnahmen');
  }

  // ------------------------------------------------------------------
  // Mit mir geteilte Ordner (ab 0.13)
  //
  // Andere Nutzer koennen Ordner gezielt mit einem teilen. Sie erscheinen im
  // Ordnerbaum unter "Mit mir geteilt" und haben die Quelle 'in:<id>'. Die
  // Liste wird lokal mitgespeichert, damit Namen und Aussehen auch ohne
  // Verbindung bekannt sind.
  // ------------------------------------------------------------------
  const Incoming = (() => {
    const LS_KEY = 'audioarchive_incoming';
    const enabled = AudioArchive.loggedIn && !AudioArchive.isPublic();
    let byId = lsGet(LS_KEY) || {};
    let loading = null;

    function idOf(source) {
      return String(source).slice(3);
    }

    function remember(list) {
      byId = {};
      list.forEach((share) => { byId[share.id] = share; });
      lsSet(LS_KEY, byId);
    }

    return {
      enabled,

      /** Liste vom Server holen (nur mit Verbindung). */
      load() {
        if (!enabled) return Promise.resolve([]);
        if (offlineMode) return Promise.resolve(Object.values(byId));
        if (!loading) {
          loading = fetch(AudioArchive.api('incoming'), { credentials: 'same-origin' })
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error('incoming'))))
            .then((data) => {
              const list = Array.isArray(data.shares) ? data.shares : [];
              remember(list);
              return list;
            })
            .catch(() => Object.values(byId))
            .finally(() => { loading = null; });
        }
        return loading;
      },

      /** Bekannte Freigaben (zuletzt geladen bzw. gespeichert). */
      all() {
        return Object.values(byId);
      },

      get(source) {
        return AudioArchive.isIncoming(source) ? (byId[idOf(source)] || null) : null;
      },

      label(source) {
        const share = this.get(source);
        return share ? share.label : 'Geteilter Ordner';
      },

      /** Aussehen fuer die geoeffnete Quelle anwenden. */
      applyLookFor(source) {
        const share = this.get(source);
        if (share && share.useShareDesign && share.hasLook && share.look) {
          Look.show(share.look, source);
        } else {
          Look.reset();
        }
      },

      /** Empfaenger waehlt: Design der Freigabe verwenden oder das eigene. */
      async setUseShareDesign(source, value) {
        const share = this.get(source);
        if (!share) return;
        const res = await fetch(AudioArchive.api('incoming/' + share.id + '/design'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', requesttoken: AudioArchive.requestToken },
          body: JSON.stringify({ useShareDesign: value }),
        });
        if (!res.ok) throw new Error('design');
        share.useShareDesign = value;
        lsSet(LS_KEY, byId);
        this.applyLookFor(source);
      },
    };
  })();

  window.addEventListener('popstate', (e) => {
    if (mainScreen.hidden) return; // nicht relevant, solange nicht eingeloggt
    view = (e.state && e.state.view) ? e.state.view : { source: startSource(), path: '' };
    if (!view.source) view.source = 'shared';
    loadLibrary(view.path, view.source);
  });

  // ------------------------------------------------------------------
  // Session-Status beim App-Start prüfen
  // ------------------------------------------------------------------
  // ==================================================================
  // OFFLINE-BETRIEB
  //
  // Ohne Verbindung ist keiner der PHP-Endpunkte erreichbar - weder die
  // Anmeldung noch die Ordnerliste. Damit heruntergeladene Aufnahmen
  // trotzdem nutzbar sind, merkt sich die App zwei Dinge lokal:
  //   1. einen Pruefwert des Passworts (SHA-256 mit zufaelligem Salz),
  //      damit die Anmeldung auch offline geprueft werden kann
  //   2. die Ordnerlisten der offline gespeicherten Ordner
  //
  // Wichtig zur Einordnung: Die eigentliche Passwortpruefung bleibt online
  // Sache des Servers. Der lokale Pruefwert schuetzt nur davor, dass jemand
  // die App am fremden Geraet einfach oeffnet - wer vollen Zugriff auf das
  // Geraet hat, koennte die Dateien im Browser-Cache ohnehin auslesen.
  // ==================================================================
  /*
   * Jede Freigabe hat ihr eigenes Passwort - der Pruefwert wird deshalb je
   * Freigabe getrennt abgelegt. Administrator-Link und angemeldete Nutzer
   * behalten den bisherigen Schluessel.
   */
  const LS_AUTH = 'audioarchive_offline_auth'
    + (AudioArchive.apiToken ? ':' + AudioArchive.apiToken : '');
  const LS_INDEX = 'audioarchive_offline_index';

  let offlineMode = false;

  // Welche Funktionen der Admin freigegeben hat. Bis die Einstellungen
  // geladen sind, gelten die Standardwerte.
  const features = { offline: true, download: false };

  function lsGet(key) {
    try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
  }

  function lsSet(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* Speicher voll */ }
  }

  async function hashPassword(password, salt) {
    const data = new TextEncoder().encode(salt + ':' + password);
    const digest = await crypto.subtle.digest('SHA-256', data);
    return Array.from(new Uint8Array(digest))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
  }

  /** Nach erfolgreicher Anmeldung am Server den lokalen Pruefwert ablegen. */
  async function rememberPasswordForOffline(password) {
    if (!window.crypto || !crypto.subtle) return; // nur ueber HTTPS verfuegbar
    try {
      const saltBytes = crypto.getRandomValues(new Uint8Array(16));
      const salt = Array.from(saltBytes).map((b) => b.toString(16).padStart(2, '0')).join('');
      lsSet(LS_AUTH, { salt, hash: await hashPassword(password, salt) });
    } catch (err) {
      // Ohne gespeicherten Pruefwert ist nur die Online-Anmeldung moeglich.
    }
  }

  /** Liest den Pruefwert, notfalls aus dem Schluessel der aelteren Fassung. */
  function storedAuth() {
    const current = lsGet(LS_AUTH);
    if (current) return current;
    if (AudioArchive.apiToken) return null; // aeltere Fassungen kannten keine Freigaben

    const legacy = lsGet('gp_offline_auth');
    if (legacy) {
      lsSet(LS_AUTH, legacy);
      return legacy;
    }

    return null;
  }

  async function checkPasswordOffline(password) {
    const stored = storedAuth();
    if (!stored || !stored.salt || !stored.hash) return false;
    if (!window.crypto || !crypto.subtle) return false;
    try {
      return (await hashPassword(password, stored.salt)) === stored.hash;
    } catch (err) {
      return false;
    }
  }

  /** Verzeichnis der offline gespeicherten Ordner: { pfad: {entries, savedAt} } */
  function offlineIndex() {
    const current = lsGet(LS_INDEX);
    if (current) return current;

    /*
     * Uebernahme aus einer aelteren Fassung: Die Schluessel hiessen frueher
     * "gp_...". Ohne diesen Schritt waere ein bestehendes Verzeichnis
     * unsichtbar, und die App wuerde es aus dem Audio-Speicher
     * rekonstruieren - dabei gingen Kuenstler, Album und Spieldauer
     * verloren, weil im Speicher nur die Dateien selbst liegen.
     */
    const legacy = lsGet('gp_offline_index');
    if (legacy) {
      lsSet(LS_INDEX, legacy);
      return legacy;
    }

    return {};
  }

  /*
   * Schluessel im Verzeichnis. Der gemeinsame Ordner verwendet - wie bis
   * 0.10 - den blossen Pfad, damit bestehende Verzeichnisse gueltig bleiben.
   * Die eigenen Dateien bekommen eine Vorsilbe, damit sich gleich
   * benannte Ordner beider Quellen nicht in die Quere kommen.
   */
  const HOME_PREFIX = '@@home:';
  // Seite einer Freigabe: eigener Bereich im Verzeichnis, damit sich
  // gleich benannte Ordner verschiedener Links nicht vermischen
  const SHARE_PREFIX = AudioArchive.apiToken ? '@@s:' + AudioArchive.apiToken + ':' : '';
  // Mit dem Nutzer geteilte Ordner (ab 0.13): '@@in:<id>:<pfad>'
  const INCOMING_KEY = /^@@in:(\d+):/;

  function indexKey(source, path) {
    if (SHARE_PREFIX) return SHARE_PREFIX + path;
    if (AudioArchive.isIncoming(source)) return '@@' + source + ':' + path;
    return source === 'home' ? HOME_PREFIX + path : path;
  }

  /**
   * Umkehrung von indexKey(): { source, path } - oder null fuer Eintraege,
   * die zu einer anderen Seite gehoeren (andere Freigabe bzw. umgekehrt).
   */
  function parseIndexKey(key) {
    if (SHARE_PREFIX) {
      return key.startsWith(SHARE_PREFIX)
        ? { source: 'shared', path: key.slice(SHARE_PREFIX.length) }
        : null;
    }
    if (key.startsWith('@@s:')) return null;
    const incoming = key.match(INCOMING_KEY);
    if (incoming) {
      return { source: 'in:' + incoming[1], path: key.slice(incoming[0].length) };
    }
    return key.startsWith(HOME_PREFIX)
      ? { source: 'home', path: key.slice(HOME_PREFIX.length) }
      : { source: 'shared', path: key };
  }

  /** Nur die Eintraege einer Quelle: { pfad: {entries, savedAt} } */
  function offlineIndexFor(source) {
    const index = offlineIndex();
    const result = {};
    Object.keys(index).forEach((key) => {
      const parsed = parseIndexKey(key);
      if (parsed && parsed.source === source) result[parsed.path] = index[key];
    });
    return result;
  }

  function setOfflineFolder(source, path, entries) {
    const index = offlineIndex();
    index[indexKey(source, path)] = { entries, savedAt: Date.now() };
    lsSet(LS_INDEX, index);
  }

  function removeOfflineFolder(source, path) {
    const index = offlineIndex();
    delete index[indexKey(source, path)];
    lsSet(LS_INDEX, index);
  }

  /** Nur die Dateien, die wirklich im Offline-Speicher liegen (ab 0.18.3). */
  async function cachedFiles(files) {
    if (!('caches' in window) || files.length === 0) return [];
    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE_NAME);
      const hits = await Promise.all(
        files.map((f) => cache.match(AudioArchive.streamUrl(f.path, f.source)).then((r) => !!r))
      );
      return files.filter((f, i) => hits[i]);
    } catch (err) {
      return [];
    }
  }

  /**
   * Verzeichnis eines gespeicherten Ordners mit frischen Angaben
   * auffrischen - aber nur mit den Dateien, die wirklich gespeichert sind.
   * Vorher landete hier die ganze Ordnerliste, und ein nur teilweise
   * gespeicherter Ordner zaehlte offline auch die fehlenden Titel mit.
   */
  async function refreshOfflineFolder(source, path, files) {
    const stored = await cachedFiles(files);
    if (stored.length > 0) setOfflineFolder(source, path, stored);
  }

  /**
   * Ohne Verbindung: nicht gespeicherte Titel in der Liste abgeblendet
   * zeigen (ab 0.18.3). Die Liste kann sie enthalten, wenn ein Ordner nur
   * teilweise gespeichert wurde - die gespeicherte Ordnerliste ist die
   * vollstaendige des Servers.
   */
  async function markUnavailableRows() {
    if (!offlineMode) return;
    const files = currentEntries.filter((e) => e.type === 'file');
    const stored = new Set((await cachedFiles(files)).map((f) => f.key));
    listContainer.querySelectorAll('.explorer-row[data-key]').forEach((row) => {
      const missing = !stored.has(row.dataset.key);
      row.classList.toggle('is-unavailable', missing);
      if (missing) {
        const meta = row.querySelector('.explorer-row-meta');
        if (meta) meta.textContent = 'Nicht offline gespeichert';
      }
    });
  }

  /** Quellen, fuer die offline etwas gespeichert ist */
  function offlineSources() {
    const sources = new Set();
    Object.keys(offlineIndex()).forEach((key) => {
      const parsed = parseIndexKey(key);
      if (parsed) sources.add(parsed.source);
    });
    return sources;
  }

  function hasOfflineContent() {
    return offlineSources().size > 0;
  }

  /**
   * Stellt das Ordner-Verzeichnis aus dem Audio-Cache wieder her.
   *
   * Noetig fuer Aufnahmen, die gespeichert wurden, BEVOR es dieses
   * Verzeichnis gab - und generell als Selbstheilung, falls der
   * localStorage-Eintrag verloren geht (z.B. Browserdaten teilweise
   * geloescht), die Audiodateien im Cache aber noch liegen.
   */
  async function rebuildOfflineIndexFromCache() {
    if (!offlineSupportedStorage()) return false;

    try {
      // Auch den Speichernamen der aelteren Fassung mitlesen
      const keys = [];
      for (const name of [OFFLINE_AUDIO_CACHE_NAME, 'gemeinde-offline-audio']) {
        try {
          const cache = await caches.open(name);
          keys.push(...await cache.keys());
        } catch (e) { /* Speicher existiert nicht */ }
      }
      if (keys.length === 0) return false;

      const folders = {};

      keys.forEach((request) => {
        let path;
        let source;
        try {
          const url = new URL(request.url);
          // Nur Aufnahmen - die mitgespeicherten Ordnerlisten liegen im
          // selben Speicher
          if (!url.pathname.endsWith('/api/stream')) return;
          // Nur Aufnahmen dieser Seite: einer Freigabe bzw. ohne Freigabe
          if ((url.searchParams.get('s') || '') !== AudioArchive.apiToken) return;
          path = url.searchParams.get('path');
          const param = url.searchParams.get('source') || '';
          source = param === 'home' ? 'home' : (AudioArchive.isIncoming(param) ? param : 'shared');
        } catch (e) {
          return;
        }
        if (!path) return;

        const parts = path.split('/');
        const fileName = parts.pop();
        const folderKey = indexKey(source, parts.join('/'));

        if (!folders[folderKey]) folders[folderKey] = [];
        folders[folderKey].push({
          type: 'file',
          name: fileName.replace(/\.[^.]+$/, ''),
          file: fileName,
          path,
        });
      });

      const index = offlineIndex();
      let added = false;

      Object.keys(folders).forEach((folderKey) => {
        if (!index[folderKey]) {
          folders[folderKey].sort((a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }));
          index[folderKey] = { entries: folders[folderKey], savedAt: Date.now(), rebuilt: true };
          added = true;
        }
      });

      if (added) lsSet(LS_INDEX, index);
      return added || hasOfflineContent();
    } catch (err) {
      return false;
    }
  }

  function offlineSupportedStorage() {
    return 'caches' in window;
  }

  const OFFLINE_AUDIO_CACHE_NAME = 'audioarchive-offline-audio';

  /**
   * Baut im Offline-Betrieb die Ordneransicht aus dem lokalen Verzeichnis.
   * Auf der obersten Ebene werden die gespeicherten Ordner selbst als
   * Eintraege gezeigt, darunter deren Dateien.
   */
  /**
   * Baut die Offline-Ansicht als echten Ordnerbaum auf.
   *
   * Gespeichert wird je Ordner unter seinem VOLLEN Pfad. Damit die Ansicht
   * ohne Verbindung genauso aussieht wie online, werden die Zwischenebenen
   * aus diesen Pfaden abgeleitet: Liegt etwa "2026_08/Sonntag" vor, zeigt die
   * oberste Ebene "2026_08" und darin erst "Sonntag" - statt wie zuvor alle
   * gespeicherten Ordner flach nebeneinander.
   */
  function offlineEntriesFor(path, source) {
    const index = offlineIndexFor(source);
    const prefix = path === '' ? '' : path + '/';

    const dirs = new Map();
    const files = [];

    Object.keys(index).forEach((folderPath) => {
      if (folderPath === path) {
        // Dateien genau dieses Ordners
        (index[folderPath].entries || []).forEach((entry) => files.push(entry));
        return;
      }

      if (prefix !== '' && !folderPath.startsWith(prefix)) return;
      if (prefix === '' && folderPath === '') return;

      // Naechste Ebene unterhalb des aktuellen Pfades
      const rest = folderPath.slice(prefix.length);
      if (rest === '') return;

      const name = rest.split('/')[0];
      const childPath = prefix + name;

      if (!dirs.has(childPath)) {
        dirs.set(childPath, { type: 'dir', name, path: childPath, count: 0 });
      }
    });

    // Aufnahmen je Unterordner zaehlen - auch die in tieferen Ebenen
    dirs.forEach((dir) => {
      Object.keys(index).forEach((folderPath) => {
        if (folderPath === dir.path || folderPath.startsWith(dir.path + '/')) {
          dir.count += (index[folderPath].entries || []).filter((e) => e.type === 'file').length;
        }
      });
    });

    const dirList = Array.from(dirs.values())
      .sort((a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }));

    files.sort((a, b) => a.name.localeCompare(b.name, 'de', { numeric: true }));

    return dirList.concat(files);
  }

  /**
   * Liest die gespeicherte Ordnerliste. Gibt null zurueck, wenn fuer diesen
   * Ordner nichts hinterlegt ist.
   */
  async function offlineEntriesFromCache(path, source) {
    if (!offlineSupportedStorage()) return null;

    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE_NAME);
      const cached = await cache.match(listUrlFor(path, source));
      if (!cached) return null;

      const data = await cached.json();
      return Array.isArray(data.entries) ? data.entries : null;
    } catch (err) {
      return null;
    }
  }

  function enterOfflineMode() {
    offlineMode = true;
    document.body.classList.add('is-offline');

    // Angemeldete Nutzer geben hier ihre selbst vergebene PIN ein, nicht
    // das Nextcloud-Passwort - das muss auf dem Bildschirm stehen.
    const hint = document.querySelector('.login-hint');
    if (hint) {
      hint.textContent = AudioArchive.isPublic()
        ? 'Keine Internetverbindung – bitte das Zugangspasswort eingeben.'
        : 'Keine Internetverbindung – bitte die Offline-PIN eingeben.';
    }

    const banner = document.getElementById('offline-banner');
    if (banner) banner.hidden = false;

    // Der Baum zeigt ohne Verbindung nur, was gespeichert ist
    Tree.rebuild();

    // Im Offline-Betrieb gibt es kein Admin-Portal und keine Abmeldung am
    // Server - der Abmelde-Knopf wuerde nur in einen Fehler laufen.
    const logout = document.getElementById('logout-btn');
    if (logout) logout.hidden = true;

    // Persoenliche Einstellungen brauchen den Server
    const settingsBtn = document.getElementById('user-settings-btn');
    if (settingsBtn) settingsBtn.hidden = true;
  }

  /**
   * Klaert beim Start, ob bereits Zugang besteht.
   *
   * Angemeldete Nextcloud-Nutzer kommen immer durch. Auf der oeffentlichen
   * Seite entscheidet, ob dort schon das gemeinsame Passwort eingegeben
   * wurde.
   */
  async function checkSession() {
    try {
      const res = await fetch(AudioArchive.statusUrl(), { credentials: 'same-origin' });
      const data = await res.json();

      if (data.authenticated) {
        showMain();
      } else {
        showLogin();
      }
    } catch (err) {
      // Server nicht erreichbar. Liegen Aufnahmen offline vor und ist ein
      // Pruefwert hinterlegt, ist die Anmeldung trotzdem moeglich.
      await rebuildOfflineIndexFromCache();

      if (AudioArchive.openAccess && hasOfflineContent()) {
        // Freigabe ohne Passwort: direkt zu den gespeicherten Aufnahmen
        enterOfflineMode();
        showMain();
        return;
      }

      if (storedAuth()) {
        enterOfflineMode();
      } else if (hasOfflineContent()) {
        offlineHint(
          'Es sind Aufnahmen gespeichert, aber die Offline-Anmeldung ist noch nicht '
          + 'eingerichtet. Dazu bitte einmal mit Internetverbindung das Passwort eingeben.'
        );
      }
      showLogin();
    }
  }

  /** Blendet einen Hinweis auf dem Anmelde-Bildschirm ein. */
  function offlineHint(message) {
    loginError.textContent = message;
    loginError.hidden = false;
  }

  // ------------------------------------------------------------------
  // Login
  // ------------------------------------------------------------------
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginError.hidden = true;

    const entered = loginPassword.value;

    loginSubmit.disabled = true;
    loginSubmit.textContent = 'Anmelden …';

    // Kein Netz: gegen den lokal hinterlegten Pruefwert anmelden
    if (offlineMode) {
      const ok = await checkPasswordOffline(entered);
      loginSubmit.disabled = false;
      loginSubmit.textContent = 'Anmelden';

      if (ok) {
        loginPassword.value = '';
        await rebuildOfflineIndexFromCache();
        showMain();
      } else {
        loginError.textContent = 'Passwort falsch (Anmeldung ohne Internetverbindung).';
        loginError.hidden = false;
      }
      return;
    }

    try {
      const res = await fetch(AudioArchive.api('public/login'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: AudioArchive.publicToken, password: entered }),
      });
      const data = await res.json();

      if (res.ok && data.success) {
        // Fuer die spaetere Anmeldung ohne Verbindung merken
        await rememberPasswordForOffline(entered);
        loginPassword.value = '';
        showMain();
      } else {
        loginError.textContent = data.error || 'Anmeldung fehlgeschlagen.';
        loginError.hidden = false;
      }
    } catch (err) {
      await rebuildOfflineIndexFromCache();

      if (storedAuth() && await checkPasswordOffline(entered)) {
        // Verbindung erst jetzt verloren - trotzdem in den Offline-Betrieb
        enterOfflineMode();
        loginPassword.value = '';
        showMain();
        return;
      }
      loginError.textContent = 'Verbindung zum Server fehlgeschlagen.';
      loginError.hidden = false;
    } finally {
      loginSubmit.disabled = false;
      loginSubmit.textContent = 'Anmelden';
    }
  });

  // ------------------------------------------------------------------
  // Logout
  // ------------------------------------------------------------------
  logoutBtn.addEventListener('click', async () => {
    try {
      await fetch(AudioArchive.logoutUrl(), { method: 'POST', credentials: 'same-origin' });
    } catch (err) {
      // ignorieren, wir loggen lokal trotzdem aus
    }
    listContainer.innerHTML = '';
    showLogin();
  });

  // ------------------------------------------------------------------
  // Ordnerstruktur laden
  // ------------------------------------------------------------------
  async function loadLibrary(path = '', source = view.source) {
    libraryStatus.hidden = false;
    libraryStatus.textContent = 'Lade Aufnahmen \u2026';
    listContainer.innerHTML = '';

    // Ohne Verbindung: Ansicht aus dem Offline-Speicher aufbauen
    if (offlineMode) {
      view = { source, path };

      /*
       * Zuerst die gespeicherte Ordnerliste versuchen - sie enthaelt
       * Kuenstler, Album und Spieldauer. Der Service Worker beantwortet den
       * Aufruf ohne Verbindung aus dem Speicher. Erst wenn dort nichts
       * liegt, greift das lokale Verzeichnis als Rueckfall; dort fehlen
       * diese Angaben moeglicherweise.
       */
      currentEntries = await offlineEntriesFromCache(path, source);
      if (currentEntries === null) {
        currentEntries = offlineEntriesFor(path, source);
      }
      tagEntries(currentEntries, source);
      Incoming.applyLookFor(source);
      Tree.select(source, path);
      Shares.onFolderLoaded();
      renderBreadcrumb();

      if (currentEntries.length === 0) {
        offlineBar.hidden = true;
        libraryStatus.textContent = 'Keine offline gespeicherten Aufnahmen vorhanden.';
        return;
      }

      /*
       * Ist dieser Ordner bereits offline gespeichert, das hinterlegte
       * Verzeichnis mit den frischen Angaben auffrischen. Dadurch fuellen
       * sich Kuenstler, Album und Spieldauer auch bei Eintraegen wieder auf,
       * die aus dem Audio-Speicher rekonstruiert werden mussten - sie sind
       * dort nicht enthalten.
       */
      const index = offlineIndex();
      if (index[indexKey(source, view.path)]) {
        const files = currentEntries.filter((e) => e.type === 'file');
        if (files.length > 0) refreshOfflineFolder(source, view.path, files);
      }

      libraryStatus.hidden = true;
      renderEntries();
      markUnavailableRows();
      refreshOfflineBar();
      return;
    }

    try {
      const res = await fetch(AudioArchive.listUrl(path, source), {
        credentials: 'same-origin',
      });

      if (res.status === 401) {
        showLogin();
        return;
      }

      const data = await res.json();

      if (!res.ok) {
        libraryStatus.textContent = res.status === 404 && AudioArchive.isIncoming(source)
          ? 'Dieser Ordner ist nicht mehr mit dir geteilt.'
          : (data.error || 'Fehler beim Laden der Aufnahmen.');
        Look.reset();
        renderBreadcrumb();
        return;
      }

      view = { source, path: data.path || '' };
      currentEntries = tagEntries(data.entries || [], source);
      Incoming.applyLookFor(source);
      Tree.select(source, view.path);
      Shares.onFolderLoaded();

      // Die vom Administrator freigegebenen Funktionen liefert der Server
      // zusammen mit der Ordnerliste mit.
      if (data.features) {
        features.offline = data.features.offline !== false;
        features.download = data.features.download === true;
      }

      renderBreadcrumb();

      if (currentEntries.length === 0) {
        offlineBar.hidden = true;
        libraryStatus.textContent = view.path === ''
          ? 'Es wurden noch keine Aufnahmen gefunden.'
          : 'Dieser Ordner ist leer.';
        return;
      }

      /*
       * Ist dieser Ordner bereits offline gespeichert, das hinterlegte
       * Verzeichnis mit den frischen Angaben auffrischen. Dadurch fuellen
       * sich Kuenstler, Album und Spieldauer auch bei Eintraegen wieder auf,
       * die aus dem Audio-Speicher rekonstruiert werden mussten - sie sind
       * dort nicht enthalten.
       */
      const index = offlineIndex();
      if (index[indexKey(source, view.path)]) {
        const files = currentEntries.filter((e) => e.type === 'file');
        if (files.length > 0) refreshOfflineFolder(source, view.path, files);
      }

      libraryStatus.hidden = true;
      renderEntries();
      refreshOfflineBar();
    } catch (err) {
      libraryStatus.textContent = 'Verbindung zum Server fehlgeschlagen.';
    }
  }

  // ------------------------------------------------------------------
  // Explorer-Ansicht: zeigt den Inhalt des aktuellen Ordners 1:1 so an,
  // wie er auf der Platte liegt (Unterordner zuerst, dann Dateien) -
  // beliebig tief verschachtelt, ohne feste Monats-/Tages-Ebenen.
  // ------------------------------------------------------------------
  /**
   * Haengt jedem Eintrag seine Quelle und eine eindeutige Kennung an. Der
   * Player braucht die Quelle fuer die Adresse der Aufnahme, die Liste die
   * Kennung fuer die Markierung des laufenden Titels.
   */
  function tagEntries(entries, source) {
    entries.forEach((entry) => {
      entry.source = source;
      entry.key = source + '|' + entry.path;
    });
    return entries;
  }

  function renderEntries() {
    listContainer.innerHTML = '';
    listContainer.classList.remove('entering');
    void listContainer.offsetWidth; // Reflow erzwingen, damit die Animation neu startet
    listContainer.classList.add('entering');

    // Nur die Audiodateien dieses Ordners bilden die Abspielliste
    const folderFiles = currentEntries.filter((e) => e.type === 'file');
    const folderLabel = view.path === '' ? sourceLabel(view.source) : view.path.split('/').join(' \u00b7 ');

    currentEntries.forEach((entry) => {
      if (entry.type === 'dir') {
        const count = entry.count || 0; // null bei den eigenen Dateien (nicht gezaehlt)
        listContainer.appendChild(makeRow({
          icon: folderIcon(),
          label: entry.name,
          meta: count > 0 ? count + (count === 1 ? ' Aufnahme' : ' Aufnahmen') : '',
          onClick: () => navigate(entry.path),
        }));
        return;
      }

      const fileIndex = folderFiles.findIndex((f) => f.path === entry.path);
      const isActive = Player.getCurrentKey() === entry.key;

      const row = makeRow({
        icon: isActive ? playingIcon() : fileIcon(),
        label: entry.name,
        meta: isActive ? 'L\u00e4uft gerade' : trackMeta(entry),
        onClick: () => Player.playFolder(folderFiles, fileIndex, folderLabel),
      });
      // Download-Knopf nur, wenn der Admin das Herunterladen freigegeben hat
      // und eine Verbindung besteht (offline gaebe es nichts zu holen).
      if (features.download && !offlineMode) {
        const dl = document.createElement('a');
        dl.className = 'row-download';
        dl.href = AudioArchive.streamUrl(entry.path, entry.source, true);
        dl.setAttribute('download', entry.file || entry.name);
        dl.title = 'Aufnahme herunterladen';
        dl.setAttribute('aria-label', 'Aufnahme herunterladen');
        dl.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">'
          + '<path d="M12 3v10.6l3.3-3.3 1.4 1.4-5.7 5.7-5.7-5.7 1.4-1.4L10 13.6V3h2zM5 19h14v2H5z"/></svg>';
        // Klick darf nicht die Zeile (= Wiedergabe starten) ausloesen
        dl.addEventListener('click', (ev) => ev.stopPropagation());
        row.appendChild(dl);
      }

      row.dataset.key = entry.key;
      if (isActive) {
        row.classList.add('active');
        if (!Player.isPlaying()) row.classList.add('paused');
      }
      listContainer.appendChild(row);
    });
  }

  function renderBreadcrumb() {
    breadcrumbEl.innerHTML = '';

    const segments = view.path === '' ? [] : view.path.split('/');

    // Wurzel ("Aufnahmen") ist anklickbar, sobald man tiefer steht
    const rootIsCurrent = segments.length === 0;
    const rootEl = document.createElement(rootIsCurrent ? 'span' : 'button');
    rootEl.textContent = sourceLabel(view.source);
    if (rootIsCurrent) {
      rootEl.className = 'crumb crumb--current';
    } else {
      rootEl.type = 'button';
      rootEl.className = 'crumb';
      rootEl.addEventListener('click', () => navigate(''));
    }
    breadcrumbEl.appendChild(rootEl);

    segments.forEach((segment, i) => {
      breadcrumbEl.appendChild(crumbSeparator());

      const isLast = i === segments.length - 1;
      const targetPath = segments.slice(0, i + 1).join('/');
      const el = document.createElement(isLast ? 'span' : 'button');
      el.textContent = segment;

      if (isLast) {
        el.className = 'crumb crumb--current';
      } else {
        el.type = 'button';
        el.className = 'crumb';
        el.addEventListener('click', () => navigate(targetPath));
      }
      breadcrumbEl.appendChild(el);
    });
  }

  function crumbSeparator() {
    const s = document.createElement('span');
    s.className = 'crumb-sep';
    s.textContent = '/';
    return s;
  }

  function makeRow({ icon, label, meta, onClick }) {
    const row = document.createElement('button');
    row.type = 'button';
    row.className = 'explorer-row';
    row.innerHTML = `
      <span class="explorer-row-icon">${icon}</span>
      <span class="explorer-row-label">${escapeHtml(label)}</span>
      <span class="explorer-row-meta">${escapeHtml(meta || '')}</span>
    `;
    row.addEventListener('click', onClick);
    return row;
  }

  function folderIcon() {
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>';
  }

  function fileIcon() {
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
  }

  // Kleine animierte "Equalizer"-Balken statt Datei-Icon, damit auf einen
  // Blick klar ist, welcher Titel gerade tatsächlich läuft (Anforderung:
  // bei mehreren angetippten Titeln nacheinander soll eindeutig erkennbar
  // sein, welcher zuletzt/aktuell läuft).
  function playingIcon() {
    return `<span class="eq-icon" aria-hidden="true">
      <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
    </span>`;
  }

  /**
   * Spieldauer als mm:ss bzw. h:mm:ss. Faellt auf die Dateigroesse zurueck,
   * wenn die Dauer nicht ermittelt werden konnte (z.B. beschaedigte Datei).
   */
  function formatDuration(seconds) {
    if (!seconds || !isFinite(seconds) || seconds <= 0) return '';
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const sec = total % 60;

    if (h > 0) {
      return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    }
    return `${m}:${String(sec).padStart(2, '0')}`;
  }

  /** Anzeigetext rechts in der Zeile: bevorzugt die Laenge, sonst die Groesse. */
  function trackMeta(entry) {
    return formatDuration(entry.duration) || formatSize(entry.size);
  }

  function formatSize(bytes) {
    if (!bytes || bytes <= 0) return '';
    const mb = bytes / (1024 * 1024);
    if (mb >= 1) return mb.toFixed(1).replace('.', ',') + ' MB';
    return Math.round(bytes / 1024) + ' KB';
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ------------------------------------------------------------------
  // Offline-Speicherung eines Ordners.
  //
  // Die Aufnahmen werden in einen eigenen Cache der PWA gelegt (Cache API).
  // Sie liegen damit NICHT als mp3-Datei im Dateisystem/Download-Ordner des
  // Geraets, sondern nur innerhalb der App - der Service Worker liefert sie
  // von dort aus, wenn keine Verbindung besteht. Beim Abmelden bzw. ueber den
  // Knopf lassen sie sich wieder entfernen.
  // ------------------------------------------------------------------
  const OFFLINE_AUDIO_CACHE = 'audioarchive-offline-audio';
  const offlineSupported = 'caches' in window;
  let offlineBusy = false;

  function streamUrlFor(path, source) {
    return AudioArchive.streamUrl(path, source);
  }

  /** Zaehlt, wie viele Dateien dieses Ordners bereits offline vorliegen. */
  async function countOfflineFiles(files) {
    if (!offlineSupported || files.length === 0) return 0;
    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE);
      const results = await Promise.all(
        files.map((f) => cache.match(streamUrlFor(f.path, f.source)).then((r) => !!r))
      );
      return results.filter(Boolean).length;
    } catch (err) {
      return 0;
    }
  }

  async function refreshOfflineBar() {
    const files = currentEntries.filter((e) => e.type === 'file');

    if (!features.offline || !offlineSupported || files.length === 0 || offlineBusy) {
      if (!offlineBusy) offlineBar.hidden = true;
      return;
    }

    // Ohne Verbindung laesst sich nichts nachladen - nur das Entfernen
    // gespeicherter Aufnahmen bleibt sinnvoll.
    if (offlineMode && view.path === '') {
      offlineBar.hidden = true;
      return;
    }

    offlineBar.hidden = false;
    const stored = await countOfflineFiles(files);
    const allStored = stored === files.length;

    offlineBtn.textContent = allStored
      ? 'Offline-Aufnahmen entfernen'
      : 'Diesen Ordner offline verfügbar machen';
    offlineBtn.classList.toggle('is-stored', allStored);
    offlineBtn.disabled = false;

    offlineInfo.textContent = stored === 0
      ? ''
      : `${stored} von ${files.length} offline verfügbar`;
  }

  /** Adresse der Ordnerliste - wird zusammen mit den Dateien gespeichert. */
  function listUrlFor(path, source) {
    return AudioArchive.listUrl(path, source);
  }

  /**
   * Legt die Ordnerliste mit in den Offline-Speicher.
   *
   * Kuenstler, Album und Spieldauer stehen NUR in dieser Antwort - die
   * Audiodateien selbst enthalten sie nicht in einer Form, die die App ohne
   * Server auslesen koennte. Ohne diesen Schritt saehe man ohne Verbindung
   * nur die Dateinamen.
   */
  async function storeFolderListing(path, source) {
    try {
      const cache = await caches.open(OFFLINE_AUDIO_CACHE);
      const url = listUrlFor(path, source);
      const res = await fetch(url, { credentials: 'same-origin' });
      if (res.ok) await cache.put(url, res);
    } catch (err) {
      // Nicht kritisch: Dann greift ersatzweise das lokale Verzeichnis.
    }
  }

  async function downloadFolderOffline(files, source, path) {
    const cache = await caches.open(OFFLINE_AUDIO_CACHE);
    let done = 0;
    let failed = 0;
    const saved = [];

    /*
     * Ab 0.18.3: Ordnerliste ZUERST ablegen und das Verzeichnis nach JEDER
     * gespeicherten Datei fortschreiben. Vorher geschah beides erst nach
     * der letzten Datei - brach das Speichern ab (App geschlossen,
     * Verbindung weg), fehlte der Ordner offline ganz, obwohl schon
     * Aufnahmen auf dem Geraet lagen.
     */
    await storeFolderListing(path, source);

    for (const file of files) {
      const url = streamUrlFor(file.path, file.source);
      try {
        if (await cache.match(url)) {
          done++;
          saved.push(file);
          continue;
        }

        // Bewusst ohne Range-Header anfordern, damit die VOLLSTAENDIGE Datei
        // als 200-Antwort im Cache landet (Teilantworten mit 206 lassen sich
        // nicht speichern). Der Service Worker schneidet spaeter selbst die
        // angeforderten Bereiche heraus.
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) { failed++; continue; }
        await cache.put(url, res);
        done++;
        saved.push(file);
        setOfflineFolder(source, path, saved.slice());
      } catch (err) {
        failed++;
      }
      offlineInfo.textContent = `Speichere … ${done + failed} von ${files.length}`;
    }
    if (saved.length > 0) setOfflineFolder(source, path, saved.slice());

    // Cover mitspeichern (ab 0.14) - mehrere Titel teilen sich oft eines
    // (cover.jpg im Ordner), deshalb jede Adresse nur einmal. Fehlt eines,
    // zeigt der Player offline eben das App-Symbol.
    const covers = new Set(files.filter((f) => f.cover)
      .map((f) => AudioArchive.coverUrl(f.path, f.source, f.cover)));
    for (const url of covers) {
      try {
        if (await cache.match(url)) continue;
        const res = await fetch(url, { credentials: 'same-origin' });
        if (res.ok) await cache.put(url, res);
      } catch (err) { /* nicht kritisch */ }
    }

    return { done, failed };
  }

  async function removeFolderOffline(files) {
    const cache = await caches.open(OFFLINE_AUDIO_CACHE);
    for (const file of files) {
      await cache.delete(streamUrlFor(file.path, file.source));
      if (file.cover) await cache.delete(AudioArchive.coverUrl(file.path, file.source, file.cover));
    }
    // Die mitgespeicherte Ordnerliste ebenfalls entfernen
    await cache.delete(listUrlFor(view.path, view.source));
  }

  /**
   * Fragt eine Offline-PIN ab und legt ihren Pruefwert lokal ab.
   *
   * Warum ueberhaupt eine PIN? Angemeldete Nextcloud-Nutzer haben kein
   * App-Passwort, und ihre Nextcloud-Anmeldung laesst sich ohne Verbindung
   * nicht pruefen - das Kontopasswort darf dafuer keinesfalls lokal liegen.
   * Die PIN schuetzt deshalb ausschliesslich den Zugriff auf die bereits
   * heruntergeladenen Aufnahmen am Geraet. Gespeichert wird wie beim
   * oeffentlichen Passwort nur ein gesalzener Pruefwert, nie die PIN selbst.
   */
  function askOfflinePin() {
    return new Promise((resolve) => {
      const box = document.createElement('div');
      box.className = 'offline-pin';
      box.innerHTML = `
        <p>Lege eine PIN fest. Sie wird abgefragt, wenn du die gespeicherten
        Aufnahmen ohne Internetverbindung hörst.</p>
        <input type="password" id="offline-pin-input" inputmode="numeric"
               autocomplete="new-password" placeholder="PIN">
        <div class="offline-pin-actions">
          <button type="button" id="offline-pin-ok">Übernehmen</button>
          <button type="button" id="offline-pin-cancel">Abbrechen</button>
        </div>
        <p class="offline-pin-error" id="offline-pin-error" hidden></p>
      `;
      offlineBar.after(box);

      const input = box.querySelector('#offline-pin-input');
      const error = box.querySelector('#offline-pin-error');
      input.focus();

      const finish = (value) => { box.remove(); resolve(value); };

      box.querySelector('#offline-pin-ok').addEventListener('click', () => {
        const value = input.value.trim();
        if (value.length < 4) {
          error.textContent = 'Bitte mindestens vier Zeichen.';
          error.hidden = false;
          return;
        }
        finish(value);
      });

      box.querySelector('#offline-pin-cancel').addEventListener('click', () => finish(null));
      input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') box.querySelector('#offline-pin-ok').click();
      });
    });
  }

  offlineBtn.addEventListener('click', async () => {
    const files = currentEntries.filter((e) => e.type === 'file');
    if (files.length === 0 || offlineBusy) return;

    const removing = offlineBtn.classList.contains('is-stored');

    /*
     * Vor dem ersten Speichern sicherstellen, dass ueberhaupt eine
     * Offline-Anmeldung moeglich ist. Ohne sie waeren die Aufnahmen zwar
     * gespeichert, aber ohne Verbindung nicht erreichbar.
     */
    if (!removing && !storedAuth() && !AudioArchive.openAccess) {
      const pin = await askOfflinePin();
      if (pin === null) return;
      await rememberPasswordForOffline(pin);
    }

    offlineBusy = true;
    offlineBtn.disabled = true;

    try {
      if (removing) {
        offlineInfo.textContent = 'Entferne …';
        await removeFolderOffline(files);
        // Auch aus dem Verzeichnis nehmen, sonst bliebe der Ordner offline
        // sichtbar, obwohl seine Aufnahmen geloescht sind.
        removeOfflineFolder(view.source, view.path);
        offlineInfo.textContent = 'Offline-Aufnahmen entfernt.';
      } else {
        offlineInfo.textContent = `Speichere … 0 von ${files.length}`;
        const { done, failed } = await downloadFolderOffline(files, view.source, view.path);

        /*
         * Entscheidend: Die Dateiliste MIT allen Angaben ins Verzeichnis
         * schreiben. Im Audio-Speicher liegen nur die Aufnahmen selbst -
         * Kuenstler, Album und Spieldauer stehen ausschliesslich hier.
         * Ohne diesen Schritt muss die App ohne Verbindung alles aus den
         * Dateinamen rekonstruieren, und genau diese Angaben fehlen dann.
         */
        if (done > 0) {
          /*
           * Ab 0.18.3 schreibt downloadFolderOffline() beides schon
           * unterwegs; hier nur noch die frische Ordnerliste.
           *
           * Zwei Ablagen mit Absicht:
           *   - die Antwort des Servers im Offline-Speicher (vollstaendig,
           *     ueberlebt das Loeschen der Browserdaten nicht, wohl aber
           *     einen leeren localStorage)
           *   - dieselben Angaben im lokalen Verzeichnis (Grundlage fuer die
           *     Ordneransicht ohne Verbindung)
           * Kuenstler, Album und Spieldauer stehen NUR hier - die
           * Audiodateien selbst liefern sie der App nicht.
           */
          await storeFolderListing(view.path, view.source);
        }

        offlineInfo.textContent = failed === 0
          ? `${done} Aufnahmen offline verfügbar.`
          : `${done} gespeichert, ${failed} fehlgeschlagen.`;
      }
    } catch (err) {
      offlineInfo.textContent = 'Offline-Speichern fehlgeschlagen.';
    } finally {
      offlineBusy = false;
      offlineBtn.disabled = false;
      Player.refreshOffline();
      // Beschriftung/Zaehler frisch bestimmen, Statustext dabei kurz stehen lassen
      const message = offlineInfo.textContent;
      await refreshOfflineBar();
      if (message) offlineInfo.textContent = message;
    }
  });

  // ------------------------------------------------------------------
  // Aktive Zeile aktualisieren, OHNE die ganze Liste neu aufzubauen -
  // das hält das Scrollen flüssig (kein Neuaufbau aller DOM-Knoten, keine
  // erneute Ein-Animation) und verhindert Ruckler beim Titelwechsel.
  // ------------------------------------------------------------------
  function updateActiveRow() {
    const currentKey = Player.getCurrentKey();
    const playing = Player.isPlaying();

    listContainer.querySelectorAll('.explorer-row[data-key]').forEach((row) => {
      const isActive = row.dataset.key === currentKey;
      const wasActive = row.classList.contains('active');

      // Zeile pausiert/läuft: steuert nur die Animation der Equalizer-Balken
      row.classList.toggle('paused', isActive && !playing);

      if (isActive === wasActive) return; // Icon/Text müssen nicht neu gesetzt werden

      row.classList.toggle('active', isActive);

      const iconEl = row.querySelector('.explorer-row-icon');
      const metaEl = row.querySelector('.explorer-row-meta');
      const entry = currentEntries.find((e) => e.key === row.dataset.key);

      if (iconEl) iconEl.innerHTML = isActive ? playingIcon() : fileIcon();
      if (metaEl) {
        metaEl.textContent = isActive
          ? 'L\u00e4uft gerade'
          : (entry ? trackMeta(entry) : '');
      }
    });
  }

  // Titelwechsel (Autoplay, Sperrbildschirm-"Nächster Titel") und
  // Play/Pause spiegeln sich beide in der Liste wider.
  Player.onTrackChange(updateActiveRow);
  Player.onPlayStateChange(updateActiveRow);

  // ------------------------------------------------------------------
  // "Danach naechster Ordner" (ab 0.15)
  //
  // Ist ein Ordner fertig, sucht der Server den naechsten Ordner mit
  // Aufnahmen in Baum-Reihenfolge (erst Unterordner, dann daneben, dann eine
  // Ebene hoeher). Ohne Verbindung geht es nur durch die offline
  // gespeicherten Ordner, in derselben Reihenfolge.
  // Zeigt die Liste gerade den fertigen Ordner, wandert sie mit.
  // ------------------------------------------------------------------
  /** Pfade in Baum-Reihenfolge vergleichen (Abschnitt fuer Abschnitt, 2 vor 10). */
  function compareTreePaths(a, b) {
    const pa = a === '' ? [] : a.split('/');
    const pb = b === '' ? [] : b.split('/');
    for (let i = 0; i < Math.min(pa.length, pb.length); i++) {
      const c = pa[i].localeCompare(pb[i], 'de', { numeric: true, sensitivity: 'base' });
      if (c !== 0) return c;
    }
    return pa.length - pb.length;
  }

  async function findNextFolder(source, folder) {
    if (offlineMode) {
      const saved = Object.keys(offlineIndexFor(source))
        .filter((p) => (offlineIndexFor(source)[p].entries || []).some((e) => e.type === 'file'))
        .sort(compareTreePaths);
      return saved.find((p) => compareTreePaths(p, folder) > 0) ?? null;
    }
    const res = await fetch(AudioArchive.nextFolderUrl(folder, source), { credentials: 'same-origin' });
    if (!res.ok) return null;
    const data = await res.json();
    return typeof data.path === 'string' ? data.path : null;
  }

  async function filesOfFolder(source, path) {
    if (offlineMode) {
      const entries = (await offlineEntriesFromCache(path, source)) || offlineEntriesFor(path, source);
      return tagEntries(entries, source).filter((e) => e.type === 'file');
    }
    const res = await fetch(AudioArchive.listUrl(path, source), { credentials: 'same-origin' });
    if (!res.ok) return [];
    const data = await res.json();
    return tagEntries(data.entries || [], source).filter((e) => e.type === 'file');
  }

  Player.onQueueEnd(async ({ source, folder }) => {
    // Bis zu einigen Ordner weit suchen, falls einer leer zurueckkommt
    let current = folder;
    for (let attempt = 0; attempt < 5; attempt++) {
      const next = await findNextFolder(source, current);
      if (next === null) return null;
      const tracks = await filesOfFolder(source, next);
      if (tracks.length > 0) {
        return {
          tracks,
          label: next === '' ? sourceLabel(source) : next.split('/').join(' \u00b7 '),
          /*
           * Erst beim tatsaechlichen Wechsel aufgerufen - gesucht wird schon
           * waehrend des letzten Titels. Die Liste wandert nur mit, wenn sie
           * dann noch den fertigen Ordner zeigt.
           */
          onStart: () => {
            if (!mainScreen.hidden && view.source === source && view.path === folder) {
              loadLibrary(next, source);
            }
          },
        };
      }
      current = next;
    }
    return null;
  });

  // ------------------------------------------------------------------
  // Ordnerbaum in der Seitenleiste (nur angemeldet)
  //
  // Zwei Wurzeln: der gemeinsame Ordner des Administrators (falls
  // eingerichtet) und die eigenen Dateien. Unterordner werden erst beim
  // Aufklappen geladen - die eigenen Dateien koennen sehr umfangreich sein.
  // Ohne Verbindung baut sich der Baum aus den offline gespeicherten
  // Ordnern auf.
  // ------------------------------------------------------------------
  const Tree = (() => {
    const enabled = AudioArchive.loggedIn && !AudioArchive.isPublic();
    const sidebar = document.getElementById('sidebar');
    const treeEl = document.getElementById('tree');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const backdrop = document.getElementById('sidebar-backdrop');
    const root = document.getElementById('audioarchive');

    /** Knoten je Kennung "quelle|pfad": { li, item, toggle, children, loaded, expanded } */
    const nodes = new Map();
    let selectedKey = null;
    let built = false;
    // Wird erfuellt, sobald "Mit mir geteilt" aufgebaut ist
    let incomingReady = Promise.resolve();

    const key = (source, path) => source + '|' + path;

    const folderSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>';
    const homeSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 3 2 12h3v8h6v-6h2v6h6v-8h3z"/></svg>';
    const sharedSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm-1 13.5v-9l6 4.5z"/></svg>';
    const peopleSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm7 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3.3 0-7 1.7-7 4v3h14v-3c0-2.3-3.7-4-7-4zm7 0c-.5 0-1 0-1.5.1 1.5 1 2.5 2.3 2.5 3.9v3h5v-3c0-2.2-3.4-4-6-4z"/></svg>';
    const linkSvg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>';
    const chevronSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>';

    function isNarrow() {
      return window.matchMedia('(max-width: 1023px)').matches;
    }

    function setOpen(open) {
      root.classList.toggle('aa-sidebar-open', open);
      backdrop.hidden = !open;
      toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    /** Unterordner laden - online vom Server, offline aus dem Verzeichnis. */
    async function fetchChildren(source, path) {
      if (offlineMode) {
        return offlineEntriesFor(path, source)
          .filter((e) => e.type === 'dir')
          .map((d) => ({
            name: d.name,
            path: d.path,
            hasChildren: offlineEntriesFor(d.path, source).some((e) => e.type === 'dir'),
          }));
      }
      const res = await fetch(AudioArchive.treeUrl(path, source), { credentials: 'same-origin' });
      if (!res.ok) throw new Error('tree');
      const data = await res.json();
      return Array.isArray(data.dirs) ? data.dirs : [];
    }

    /**
     * @param {object} [opts] onSelect: eigener Klick statt Ordnerwechsel
     *                        (Freigaben), loader: eigene Kinder
     */
    function createNode(source, path, label, hasChildren, icon, isRoot, opts = {}) {
      const li = document.createElement('li');
      li.setAttribute('role', 'treeitem');
      if (isRoot) li.className = 'tree-root';

      const item = document.createElement('div');
      item.className = 'tree-item';

      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'tree-toggle' + (hasChildren ? '' : ' is-empty');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Aufklappen');
      toggle.innerHTML = chevronSvg;

      const labelBtn = document.createElement('button');
      labelBtn.type = 'button';
      labelBtn.className = 'tree-label';
      labelBtn.innerHTML = `<span class="tree-icon">${icon}</span><span class="tree-label-text"></span>`;
      labelBtn.querySelector('.tree-label-text').textContent = label;
      labelBtn.title = label;

      item.append(toggle, labelBtn);
      li.appendChild(item);

      const children = document.createElement('ul');
      children.setAttribute('role', 'group');
      children.hidden = true;
      li.appendChild(children);

      const node = { li, item, toggle, children, loaded: false, expanded: false, source, path, loader: opts.loader };
      nodes.set(key(source, path), node);

      toggle.addEventListener('click', () => (node.expanded ? collapse(node) : expand(node)));
      labelBtn.addEventListener('click', () => {
        if (isNarrow()) setOpen(false);
        if (opts.onSelect) {
          opts.onSelect();
          if (!node.expanded && hasChildren) expand(node);
          return;
        }
        if (key(view.source, view.path) !== key(source, path)) navigate(path, source);
        if (!node.expanded && hasChildren) expand(node);
      });

      return node;
    }

    function expand(node) {
      node.expanded = true;
      node.toggle.setAttribute('aria-expanded', 'true');
      node.children.hidden = false;
      if (node.loaded) return Promise.resolve();
      // Laeuft das Laden schon (z.B. Aufbau und Auswahl gleichzeitig), auf
      // denselben Vorgang warten statt die Kinder doppelt anzulegen
      if (!node.loading) {
        node.loading = loadChildren(node).finally(() => { node.loading = null; });
      }
      return node.loading;
    }

    async function loadChildren(node) {
      node.children.innerHTML = '<li class="tree-status">Lade …</li>';
      if (node.loader) {
        try {
          await node.loader(node);
          node.loaded = true;
        } catch (err) {
          node.children.innerHTML = '<li class="tree-status">Nicht erreichbar</li>';
        }
        return;
      }
      try {
        const dirs = await fetchChildren(node.source, node.path);
        node.children.innerHTML = '';
        dirs.forEach((dir) => {
          const child = createNode(node.source, dir.path, dir.name, dir.hasChildren, folderSvg, false);
          node.children.appendChild(child.li);
        });
        node.loaded = true;
        if (dirs.length === 0) node.toggle.classList.add('is-empty');
        markSelected();
      } catch (err) {
        node.children.innerHTML = '<li class="tree-status">Nicht erreichbar</li>';
      }
    }

    function collapse(node) {
      node.expanded = false;
      node.toggle.setAttribute('aria-expanded', 'false');
      node.children.hidden = true;
    }

    function markSelected() {
      nodes.forEach((node, k) => node.item.classList.toggle('is-active', k === selectedKey));
    }

    function build() {
      treeEl.innerHTML = '';
      nodes.clear();

      const roots = [];
      if (offlineMode) {
        // Ohne Verbindung nur Quellen, fuer die etwas gespeichert ist
        const available = offlineSources();
        if (available.has('shared')) roots.push('shared');
        if (available.has('home')) roots.push('home');
      } else {
        if (AudioArchive.hasShared) roots.push('shared');
        roots.push('home');
      }

      roots.forEach((source) => {
        const node = createNode(source, '', sourceLabel(source), true,
          source === 'home' ? homeSvg : sharedSvg, true);
        treeEl.appendChild(node.li);
        expand(node);
      });

      // Mit mir geteilt (ab 0.13) - erscheint nur, wenn es etwas gibt
      const incomingSlot = document.createElement('li');
      incomingSlot.hidden = true;
      treeEl.appendChild(incomingSlot);
      incomingReady = Incoming.load().then((list) => {
        const shares = offlineMode
          ? list.filter((share) => offlineSources().has('in:' + share.id))
          : list;
        if (shares.length === 0) {
          incomingSlot.remove();
          return;
        }
        const group = createNode('incoming', '', 'Mit mir geteilt', true, peopleSvg, true, {
          onSelect: () => {},
          loader: async (node) => {
            node.children.innerHTML = '';
            shares.forEach((share) => {
              const child = createNode('in:' + share.id, '', share.label, true, sharedSvg, false);
              child.li.title = 'Geteilt von ' + share.creatorName;
              node.children.appendChild(child.li);
            });
          },
        });
        incomingSlot.replaceWith(group.li);
        return expand(group);
      }).then(() => {
        markSelected();
        // Namen und Aussehen sind jetzt bekannt
        if (AudioArchive.isIncoming(view.source)) {
          Incoming.applyLookFor(view.source);
          renderBreadcrumb();
        }
      }).catch(() => incomingSlot.remove());

      // Eigene Freigaben - nur mit Verbindung, sie sind nicht offline gespeichert
      if (AudioArchive.canShare && !offlineMode) {
        const sharesNode = createNode('shares', '', 'Meine Freigaben', true, linkSvg, true, {
          loader: loadShareItems,
          onSelect: () => {},
        });
        treeEl.appendChild(sharesNode.li);
      }
      built = true;
    }

    /** Kinder von "Meine Freigaben": eine Zeile je Freigabe. */
    async function loadShareItems(node) {
      const shares = await Shares.fetchOwn();
      node.children.innerHTML = '';
      if (shares.length === 0) {
        node.children.innerHTML = '<li class="tree-status">Noch keine Freigaben</li>';
        return;
      }
      shares.forEach((share) => {
        const label = share.settings.title || share.folderName || share.path || 'Freigabe';
        const child = createNode('share', String(share.id), label, false,
          share.kind === 'internal' ? peopleSvg : linkSvg, false, {
          onSelect: () => Shares.openFromList(share),
        });
        if (share.expired || share.missing) child.item.classList.add('is-inactive');
        node.children.appendChild(child.li);
      });
    }

    return {
      init() {
        if (!enabled) return;
        sidebar.hidden = false;
        toggleBtn.hidden = false;
        toggleBtn.addEventListener('click', () => setOpen(!root.classList.contains('aa-sidebar-open')));
        backdrop.addEventListener('click', () => setOpen(false));
        build();
      },

      /** "Meine Freigaben" neu laden (nach Anlegen/Loeschen). */
      refreshShares() {
        const node = nodes.get(key('shares', ''));
        if (!node) return;
        node.loaded = false;
        if (node.expanded) {
          node.expanded = false;
          expand(node);
        }
      },

      /** Nach dem Wechsel in den Offline-Betrieb neu aufbauen. */
      rebuild() {
        if (!enabled || !built) return;
        build();
        if (selectedKey) {
          const [source, ...rest] = selectedKey.split('|');
          this.select(source, rest.join('|'));
        }
      },

      /**
       * Markiert den geoeffneten Ordner und klappt seine Vorfahren auf,
       * damit er im Baum sichtbar ist.
       */
      async select(source, path) {
        if (!enabled) return;
        selectedKey = key(source, path);
        markSelected();

        const segments = path === '' ? [] : path.split('/');
        let current = '';
        if (AudioArchive.isIncoming(source)) await incomingReady;
        const rootNode = nodes.get(key(source, ''));
        if (rootNode && !rootNode.expanded) await expand(rootNode);

        for (const segment of segments.slice(0, -1)) {
          current = current === '' ? segment : current + '/' + segment;
          const node = nodes.get(key(source, current));
          if (!node) break;
          if (!node.expanded || !node.loaded) await expand(node);
        }

        markSelected();
        const active = nodes.get(selectedKey);
        if (active && !isNarrow()) active.item.scrollIntoView({ block: 'nearest' });
      },
    };
  })();

  // ------------------------------------------------------------------
  // Freigaben: Ordner samt Unterordnern ueber einen eigenen Link teilen
  //
  // Jede Freigabe hat eigene Einstellungen wie der Administrator-Link:
  // Passwort (optional), Ablaufdatum, Offline/Download, Aussehen,
  // Hintergrundbild, Beta-Hinweis. Verwaltet wird hier in der App - ueber
  // den Knopf ueber der Liste oder "Meine Freigaben" im Ordnerbaum.
  // ------------------------------------------------------------------
  // ------------------------------------------------------------------
  // Leiste ueber einem mit mir geteilten Ordner (ab 0.13): wer ihn geteilt
  // hat, und die Wahl, ob sein Aussehen gelten soll
  // ------------------------------------------------------------------
  const IncomingBar = (() => {
    const bar = document.getElementById('incoming-bar');
    const info = document.getElementById('incoming-info');
    const toggle = document.getElementById('incoming-design');
    const toggleWrap = document.getElementById('incoming-design-wrap');

    if (toggle) {
      toggle.addEventListener('change', async () => {
        toggle.disabled = true;
        try {
          await Incoming.setUseShareDesign(view.source, toggle.checked);
        } catch (err) {
          toggle.checked = !toggle.checked;
        } finally {
          toggle.disabled = false;
        }
      });
    }

    return {
      update() {
        if (!bar) return;
        const share = Incoming.get(view.source);
        if (!share) {
          bar.hidden = true;
          return;
        }
        info.textContent = 'Geteilt von ' + share.creatorName
          + (share.expires ? ' · bis ' + share.expires.split('-').reverse().join('.') : '');
        // Die Wahl gibt es nur, wenn der Teilende ein Aussehen festgelegt
        // hat - und nur mit Verbindung, sie wird auf dem Server gespeichert
        toggleWrap.hidden = !share.hasLook || offlineMode;
        toggle.checked = share.useShareDesign !== false;
        bar.hidden = false;
      },
    };
  })();

  const Shares = (() => {
    const enabled = AudioArchive.canShare && !AudioArchive.isPublic();
    const actions = document.getElementById('folder-actions');
    const shareBtn = document.getElementById('share-btn');
    const panel = document.getElementById('share-panel');

    // Nach dem Oeffnen aus "Meine Freigaben": diese Freigabe bearbeiten,
    // sobald ihr Ordner geladen ist
    let pendingShareId = null;
    let current = []; // Freigaben des geoeffneten Ordners

    function folderName() {
      return view.path === '' ? sourceLabel(view.source) : view.path.split('/').pop();
    }

    async function request(url, options = {}) {
      const res = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { requesttoken: AudioArchive.requestToken, ...(options.headers || {}) },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || (res.status === 412
          ? 'Sitzung abgelaufen – bitte die Seite neu laden.'
          : 'Aktion fehlgeschlagen.'));
      }
      return data;
    }

    function sharesUrl(query) {
      return AudioArchive.api('shares') + (query ? '?' + query : '');
    }

    async function fetchOwn() {
      const data = await request(sharesUrl(''));
      return data.shares || [];
    }

    async function fetchForFolder() {
      const data = await request(sharesUrl(
        'source=' + encodeURIComponent(view.source) + '&path=' + encodeURIComponent(view.path)
      ));
      return data.shares || [];
    }

    function el(tag, className, text) {
      const e = document.createElement(tag);
      if (className) e.className = className;
      if (text !== undefined) e.textContent = text;
      return e;
    }

    function button(label, className, onClick) {
      const b = el('button', 'panel-button' + (className ? ' ' + className : ''), label);
      b.type = 'button';
      b.addEventListener('click', onClick);
      return b;
    }

    function close() {
      panel.hidden = true;
      panel.textContent = '';
    }

    function showError(target, message) {
      target.textContent = message || '';
      target.hidden = !message;
    }

    async function copyLink(input, feedback) {
      try {
        await navigator.clipboard.writeText(input.value);
        feedback.textContent = 'Link kopiert.';
      } catch (err) {
        input.select();
        feedback.textContent = 'Bitte von Hand kopieren.';
      }
    }

    // ---------- Liste der Freigaben dieses Ordners ----------
    async function openList(highlightId) {
      panel.hidden = false;
      panel.textContent = '';
      panel.appendChild(el('h2', 'panel-title', 'Teilen: ' + folderName()));
      const status = el('p', 'panel-hint', 'Lade Freigaben …');
      panel.appendChild(status);

      try {
        current = await fetchForFolder();
      } catch (err) {
        status.textContent = err.message;
        panel.appendChild(button('Schließen', '', close));
        return;
      }
      status.textContent = 'Eine Freigabe umfasst immer auch alle Unterordner.';

      const links = current.filter((share) => share.kind !== 'internal');
      const internal = current.filter((share) => share.kind === 'internal');

      // Mit Personen aus Nextcloud
      const people = group('Mit Personen und Gruppen');
      people.appendChild(el('p', 'panel-hint', internal.length === 0
        ? 'Noch nicht geteilt. Die Personen sehen den Ordner in dieser App unter „Mit mir geteilt".'
        : 'Die Personen sehen den Ordner in dieser App unter „Mit mir geteilt".'));
      internal.forEach((share) => people.appendChild(renderShareItem(share, share.id === highlightId)));
      const peopleRow = el('div', 'panel-row');
      peopleRow.appendChild(button('Mit Personen teilen', 'panel-button--primary', () => openForm(null, 'internal')));
      people.appendChild(peopleRow);
      panel.appendChild(people);

      // Oeffentliche Links
      const linkGroup = group('Öffentliche Links');
      linkGroup.appendChild(el('p', 'panel-hint', links.length === 0
        ? 'Noch kein Link. Über einen Link kann man auch ohne Nextcloud-Konto zuhören.'
        : 'Jeder Link hat eigene Einstellungen. Zuhören geht auch ohne Nextcloud-Konto.'));
      links.forEach((share) => linkGroup.appendChild(renderShareItem(share, share.id === highlightId)));
      const linkRow = el('div', 'panel-row');
      linkRow.appendChild(button('Neuer Link', 'panel-button--primary', () => openForm(null, 'link')));
      linkGroup.appendChild(linkRow);
      panel.appendChild(linkGroup);

      const row = el('div', 'panel-row panel-actions');
      row.append(button('Schließen', '', close));
      panel.appendChild(row);
      panel.scrollIntoView({ block: 'nearest' });
    }

    function formatDate(iso) {
      return iso.split('-').reverse().join('.');
    }

    function describe(share) {
      const parts = [];
      if (share.kind === 'internal') {
        parts.push(share.members.map((m) => (m.type === 'group' ? 'Gruppe ' : '') + m.label).join(', ') || 'niemand');
      } else {
        parts.push(share.hasPassword ? 'mit Passwort' : 'ohne Passwort');
      }
      parts.push(share.expires ? 'gültig bis ' + formatDate(share.expires) : 'unbegrenzt');
      if (share.expired) parts.push('ABGELAUFEN');
      if (share.missing) parts.push('ORDNER FEHLT');
      return parts.join(' · ');
    }

    function renderShareItem(share, highlight) {
      const item = el('div', 'share-item' + (highlight ? ' is-new' : ''));
      item.appendChild(el('p', 'share-item-title', share.settings.title || share.folderName || 'Freigabe'));
      item.appendChild(el('p', 'panel-hint', describe(share)));
      const feedback = el('span', 'panel-hint share-feedback');

      if (share.kind !== 'internal') {
        const linkRow = el('div', 'panel-row');
        const input = el('input', 'share-link');
        input.type = 'text';
        input.readOnly = true;
        input.value = share.url;
        linkRow.append(input, button('Kopieren', '', () => copyLink(input, feedback)));
        item.appendChild(linkRow);
      }

      const row = el('div', 'panel-row');
      row.appendChild(button('Bearbeiten', '', () => openForm(share, share.kind)));
      if (share.kind !== 'internal') {
        row.appendChild(button('Öffnen', '', () => window.open(share.url, '_blank', 'noopener')));
      }
      row.append(
        button('Löschen', 'panel-button--danger', async () => {
          const name = share.settings.title || share.folderName || 'diese Freigabe';
          const consequence = share.kind === 'internal'
            ? 'Die Personen sehen den Ordner danach nicht mehr.'
            : 'Der Link funktioniert danach nicht mehr.';
          if (!window.confirm('„' + name + '" löschen? ' + consequence)) return;
          try {
            await request(AudioArchive.api('shares/' + share.id + '/delete'), { method: 'POST' });
            Tree.refreshShares();
            openList();
          } catch (err) {
            feedback.textContent = err.message;
          }
        }),
        feedback
      );
      item.appendChild(row);
      return item;
    }

    // ---------- Formular: neue Freigabe oder bearbeiten ----------
    function field(label, input, hint) {
      const wrap = el('label', 'panel-field');
      wrap.appendChild(el('span', 'panel-field-label', label));
      wrap.appendChild(input);
      if (hint) wrap.appendChild(el('span', 'panel-hint', hint));
      return wrap;
    }

    function textInput(value, placeholder, type = 'text') {
      const input = el('input', 'panel-input');
      input.type = type;
      input.value = value || '';
      if (placeholder) input.placeholder = placeholder;
      return input;
    }

    function checkbox(label, checked) {
      const wrap = el('label', 'panel-choice');
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.checked = !!checked;
      wrap.append(input, document.createTextNode(' ' + label));
      return { wrap, input };
    }

    function group(title) {
      const fs = el('fieldset', 'panel-group');
      fs.appendChild(el('legend', '', title));
      return fs;
    }

    /**
     * Wunschname wie auf dem Server: klein, Umlaute ausgeschrieben,
     * Sonderzeichen als Bindestrich. Nur fuer die Vorschau - geprueft wird
     * auf dem Server.
     */
    function slugify(text) {
      return String(text || '').trim().toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    }

    /** Anfang jeder Link-Adresse: .../apps/audioarchive/s/ */
    function linkBase() {
      return new URL(AudioArchive.scope + 's/', location.href).href;
    }

    /**
     * Auswahl von Personen und Gruppen mit Suche. Die Suche laeuft ueber
     * Nextcloud und beachtet dessen Einstellungen zum Teilen.
     */
    function memberPicker(initial) {
      const chosen = new Map();
      (initial || []).forEach((m) => chosen.set(m.type + '|' + m.id, m));

      const wrap = el('div', 'member-picker');
      const chips = el('div', 'member-chips');
      const input = textInput('', 'Name oder Gruppe suchen …');
      input.setAttribute('autocomplete', 'off');
      const results = el('div', 'member-results');
      results.hidden = true;
      wrap.append(chips, input, results);

      function renderChips() {
        chips.textContent = '';
        if (chosen.size === 0) {
          chips.appendChild(el('span', 'panel-hint', 'Noch niemand ausgewählt.'));
          return;
        }
        chosen.forEach((member, id) => {
          const chip = el('span', 'member-chip');
          chip.appendChild(el('span', '', (member.type === 'group' ? 'Gruppe: ' : '') + member.label));
          const remove = el('button', 'member-chip-remove', '×');
          remove.type = 'button';
          remove.setAttribute('aria-label', member.label + ' entfernen');
          remove.addEventListener('click', () => { chosen.delete(id); renderChips(); });
          chip.appendChild(remove);
          chips.appendChild(chip);
        });
      }

      let timer = null;
      let seq = 0;
      input.addEventListener('input', () => {
        clearTimeout(timer);
        const term = input.value.trim();
        if (term.length < 1) {
          results.hidden = true;
          return;
        }
        timer = setTimeout(async () => {
          const mine = ++seq;
          try {
            const data = await request(AudioArchive.api('members/search') + '?search=' + encodeURIComponent(term));
            if (mine !== seq) return; // veraltete Antwort
            results.textContent = '';
            const list = (data.results || []).filter((m) => !chosen.has(m.type + '|' + m.id));
            if (list.length === 0) {
              results.appendChild(el('p', 'panel-hint', 'Niemand gefunden.'));
            }
            list.forEach((member) => {
              const b = el('button', 'member-result', (member.type === 'group' ? 'Gruppe: ' : '') + member.label);
              b.type = 'button';
              b.addEventListener('click', () => {
                chosen.set(member.type + '|' + member.id, member);
                input.value = '';
                results.hidden = true;
                renderChips();
                input.focus();
              });
              results.appendChild(b);
            });
            results.hidden = false;
          } catch (err) {
            results.textContent = '';
            results.appendChild(el('p', 'panel-hint', err.message));
            results.hidden = false;
          }
        }, 250);
      });

      renderChips();
      return {
        wrap,
        value: () => Array.from(chosen.values()).map((m) => ({ type: m.type, id: m.id })),
      };
    }

    /**
     * @param {object|null} share bestehende Freigabe oder null (neu)
     * @param {string} kind 'link' oder 'internal'
     */
    function openForm(share, kind) {
      const isNew = share === null;
      const isInternal = (isNew ? kind : share.kind) === 'internal';
      const st = isNew ? {} : share.settings;

      panel.hidden = false;
      panel.textContent = '';
      const heading = isNew
        ? (isInternal ? 'Mit Personen teilen: ' : 'Neuer Link: ') + folderName()
        : (isInternal ? 'Freigabe bearbeiten: ' : 'Link bearbeiten: ') + (share.folderName || folderName());
      panel.appendChild(el('h2', 'panel-title', heading));
      panel.appendChild(el('p', 'panel-hint', 'Umfasst den Ordner mit allen Unterordnern.'));

      // Zugang
      const access = group(isInternal ? 'Personen und Gruppen' : 'Zugang');
      let members = null;
      let password = null;
      let removePassword = null;
      let slug = null;

      if (isInternal) {
        members = memberPicker(isNew ? [] : share.members);
        access.appendChild(members.wrap);
        access.appendChild(el('p', 'panel-hint',
          'Sichtbar nur in dieser App, nicht in der Dateien-App. Weiterteilen können die Personen nicht.'));
      } else {
        // Wunschname
        slug = textInput(isNew ? '' : share.slug, 'z. B. gottesdienst-sonntag');
        slug.setAttribute('autocomplete', 'off');
        const preview = el('span', 'panel-hint share-slug-preview');
        const syncPreview = () => {
          const value = slugify(slug.value);
          preview.textContent = value
            ? linkBase() + value
            : (isNew ? 'Leer = zufällige Adresse (schwer zu erraten).' : 'Leer = zufällige Adresse.');
        };
        slug.addEventListener('input', syncPreview);
        syncPreview();
        const slugField = field('Wunschname im Link (optional)', slug);
        slugField.appendChild(preview);
        if (!isNew) {
          slugField.appendChild(el('span', 'panel-hint',
            'Wird der Name geändert, funktioniert der bisherige Link nicht mehr.'));
        }
        access.appendChild(slugField);

        password = textInput('', isNew ? 'Ohne Passwort leer lassen' : 'Leer lassen = unverändert', 'password');
        password.autocomplete = 'new-password';
        access.appendChild(field('Passwort (optional)', password,
          isNew ? 'Ohne Passwort kann jeder mit dem Link zuhören. Wunschnamen sind leichter zu erraten.'
            : (share.hasPassword ? 'Ein Passwort ist gesetzt.' : 'Derzeit ohne Passwort.')));
        if (!isNew && share.hasPassword) {
          removePassword = checkbox('Passwort entfernen', false);
          access.appendChild(removePassword.wrap);
        }
      }

      const expires = textInput(isNew ? '' : share.expires, '', 'date');
      expires.min = new Date().toISOString().slice(0, 10);
      access.appendChild(field('Ablaufdatum (optional)', expires,
        'Leer = unbegrenzt. Gilt bis einschließlich dieses Tages.'));
      panel.appendChild(access);

      // Funktionen
      const functions = group('Funktionen');
      const offline = checkbox('Offline speichern erlauben', isNew ? true : st.featureOffline);
      const download = checkbox('Herunterladen als Datei erlauben', isNew ? false : st.featureDownload);
      functions.append(offline.wrap, download.wrap);
      panel.appendChild(functions);

      // Aussehen
      const look = group('Aussehen');
      if (isInternal) {
        look.appendChild(el('p', 'panel-hint',
          'Die Personen können wählen, ob sie dieses Aussehen oder ihr eigenes sehen. Leere Felder übernehmen ihre eigene Darstellung.'));
      }
      const title = textInput(st.title, folderName());
      look.appendChild(field('Titel', title, isInternal ? 'Leer = Name des Ordners im Baum, eigener Titel oben' : 'Leer = Name des Ordners'));
      const subtitle = textInput(st.subtitle, '');
      look.appendChild(field('Zusatzzeile (optional)', subtitle));

      /*
       * Gestaltung der Freigabe (ab 0.17): dieselben vier wie persoenlich,
       * dazu "Vorgabe" (Administrator bzw. beim Empfaenger dessen eigene).
       * "Benutzerdefiniert" speichert die Werte in der Freigabe selbst.
       */
      let chosenDesign = st.design || '';
      if (chosenDesign === 'admin' && !AudioArchive.adminStyleOffered) chosenDesign = '';
      const classicThumb = AAStyle.classicThumb(AudioArchive.ncPrimary() ? '#' + AudioArchive.ncPrimary().replace('#', '') : '');
      const cardOptions = [
        { value: '', label: 'Vorgabe', desc: isInternal ? 'Wie beim Empfänger' : 'Wie vom Administrator eingestellt', style: AAStyle.DEFAULTS },
        { value: 'nextcloud', label: 'Klassisch', desc: 'Nextcloud-Design, Hell/Dunkel automatisch', style: classicThumb },
        { value: 'custom', label: 'Modern', desc: 'Rund mit Glaseffekt, Farben wählbar', style: AAStyle.DEFAULTS },
      ];
      if (AudioArchive.adminStyleOffered) {
        cardOptions.push({ value: 'admin', label: 'Vom Administrator', desc: 'Gestaltung des Administrators', style: AAStyle.DEFAULTS });
      }
      cardOptions.push({ value: 'defined', label: 'Benutzerdefiniert', desc: 'Alles selbst einstellen', style: st.style || AAStyle.DEFAULTS });

      const modernBox = el('div', 'share-design-modern');
      const definedBox = el('div', 'share-design-defined');
      const syncDesign = () => {
        modernBox.hidden = chosenDesign !== 'custom';
        definedBox.hidden = chosenDesign !== 'defined';
      };
      const cards = AAStyle.createDesignCards({
        name: 'share-design-' + (isNew ? 'new' : share.id),
        options: cardOptions,
        value: chosenDesign,
        onChange(value) {
          chosenDesign = value;
          syncDesign();
        },
      });
      look.appendChild(el('span', 'panel-field-label', 'Gestaltung'));
      look.appendChild(cards.el);

      // Vorschaubilder mit den echten Vorgaben des Administrators
      fetch(AudioArchive.api('user/settings'), { credentials: 'same-origin' })
        .then((res) => (res.ok ? res.json() : null))
        .then((data) => {
          if (!data) return;
          const admin = data.admin || {};
          const adminModern = AAStyle.modernThumb({ accent: admin.themeAccent, bar: admin.themeBar, base: admin.themeBase });
          const adminStyle = data.adminStyle ? AAStyle.normalize(data.adminStyle) : null;
          if (adminStyle) cards.setThumb('admin', adminStyle);
          if (!isInternal) {
            cards.setThumb('', data.adminDesign === 'nextcloud' ? classicThumb
              : (data.adminDesign === 'admin' && adminStyle ? adminStyle : adminModern));
          }
          if (!ownColors.input.checked) cards.setThumb('custom', adminModern);
        })
        .catch(() => {});

      // Modern: Farben mit Vorlagen
      const ownColors = checkbox('Eigene Farben', !!(st.themeAccent || st.themeBar || st.themeBase));
      modernBox.appendChild(ownColors.wrap);
      const colorWrap = el('div', 'share-colors-wrap');
      const colorRow = el('div', 'panel-row share-colors');
      const colorInputs = {};
      const syncModernThumb = () => {
        if (!ownColors.input.checked) return;
        cards.setThumb('custom', AAStyle.modernThumb({
          accent: colorInputs.themeAccent.value, bar: colorInputs.themeBar.value, base: colorInputs.themeBase.value,
        }));
      };
      const palettes = AAStyle.createPalettePicker((p) => {
        ownColors.input.checked = true;
        colorInputs.themeAccent.value = p.accent;
        colorInputs.themeBar.value = p.bar;
        colorInputs.themeBase.value = p.base;
        syncColors();
      }, st.themeAccent ? { accent: st.themeAccent, bar: st.themeBar, base: st.themeBase } : null);
      colorWrap.appendChild(palettes.el);
      [['themeAccent', 'Akzent', AudioArchive.themeAccent], ['themeBar', 'Leisten', AudioArchive.themeBar], ['themeBase', 'Grundton', AudioArchive.themeBase]]
        .forEach(([keyName, label, fallback]) => {
          const input = el('input', 'panel-color');
          input.type = 'color';
          input.value = st[keyName] || fallback || '#888888';
          input.addEventListener('input', () => {
            palettes.mark({ accent: colorInputs.themeAccent.value, bar: colorInputs.themeBar.value, base: colorInputs.themeBase.value });
            syncModernThumb();
          });
          colorInputs[keyName] = input;
          const wrap = el('label', 'panel-color-field');
          wrap.append(input, el('span', 'panel-hint', label));
          colorRow.appendChild(wrap);
        });
      colorWrap.appendChild(colorRow);
      modernBox.appendChild(colorWrap);
      const syncColors = () => {
        colorWrap.hidden = !ownColors.input.checked;
        syncModernThumb();
      };
      ownColors.input.addEventListener('change', syncColors);
      syncColors();
      look.appendChild(modernBox);

      // Benutzerdefiniert: Editor
      const editor = AAStyle.createEditor({
        value: st.style || AAStyle.DEFAULTS,
        onChange(style) { cards.setThumb('defined', style); },
      });
      definedBox.appendChild(editor.el);
      look.appendChild(definedBox);
      syncDesign();

      // Hintergrundbild - braucht eine gespeicherte Freigabe
      const bgState = el('p', 'panel-hint');
      look.appendChild(el('span', 'panel-field-label', 'Hintergrundbild'));
      look.appendChild(bgState);
      if (isNew) {
        bgState.textContent = 'Nach dem Anlegen einstellbar.';
      } else {
        const bgRow = el('div', 'panel-row');
        const pick = el('label', 'panel-button', 'Bild wählen …');
        const file = document.createElement('input');
        file.type = 'file';
        file.accept = 'image/png,image/jpeg,image/webp';
        file.hidden = true;
        pick.appendChild(file);
        const removeBg = button('Entfernen', '', async () => {
          try {
            await request(AudioArchive.api('shares/' + share.id + '/background/remove'), { method: 'POST' });
            share.hasBackground = false;
            syncBg();
          } catch (err) { bgState.textContent = err.message; }
        });
        const syncBg = () => {
          removeBg.hidden = !share.hasBackground;
          bgState.textContent = share.hasBackground
            ? 'Eigenes Bild gesetzt – gilt in allen Gestaltungen.'
            : 'Kein eigenes Bild – es gilt die Vorgabe.';
        };
        file.addEventListener('change', async () => {
          if (!file.files[0]) return;
          const form = new FormData();
          form.append('file', file.files[0]);
          bgState.textContent = 'Lade hoch …';
          try {
            await request(AudioArchive.api('shares/' + share.id + '/background'), { method: 'POST', body: form });
            share.hasBackground = true;
            syncBg();
          } catch (err) {
            bgState.textContent = err.message;
          } finally {
            file.value = '';
          }
        });
        bgRow.append(pick, removeBg);
        look.appendChild(bgRow);
        syncBg();
      }
      panel.appendChild(look);

      const error = el('p', 'panel-error');
      error.hidden = true;
      panel.appendChild(error);

      const saveLabel = isNew ? (isInternal ? 'Teilen' : 'Link anlegen') : 'Speichern';
      const save = button(saveLabel, 'panel-button--primary', async () => {
        showError(error, '');
        const settings = {
          title: title.value,
          subtitle: subtitle.value,
          design: chosenDesign,
          // Werte fuer "Benutzerdefiniert": bei Wahl, und bereits vorhandene
          // bleiben erhalten, wenn voruebergehend anders gewaehlt wird
          style: (chosenDesign === 'defined' || st.style) ? editor.get() : null,
          themeAccent: ownColors.input.checked ? colorInputs.themeAccent.value : '',
          themeBar: ownColors.input.checked ? colorInputs.themeBar.value : '',
          themeBase: ownColors.input.checked ? colorInputs.themeBase.value : '',
          featureOffline: offline.input.checked,
          featureDownload: download.input.checked,
        };
        if (isInternal && members.value().length === 0) {
          showError(error, 'Bitte mindestens eine Person oder Gruppe auswählen.');
          return;
        }
        const body = { settings, expires: expires.value };
        if (isInternal) {
          body.members = members.value();
        } else {
          body.slug = slug.value;
          body.password = password.value;
        }
        save.disabled = true;
        try {
          let data;
          if (isNew) {
            data = await request(AudioArchive.api('shares'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                ...body,
                kind: isInternal ? 'internal' : 'link',
                source: view.source,
                path: view.path,
              }),
            });
          } else {
            if (!isInternal) body.removePassword = removePassword ? removePassword.input.checked : false;
            data = await request(AudioArchive.api('shares/' + share.id), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(body),
            });
          }
          Tree.refreshShares();
          openList(data.share ? data.share.id : null);
        } catch (err) {
          showError(error, err.message);
          save.disabled = false;
        }
      });

      const row = el('div', 'panel-row panel-actions');
      row.append(save, button('Abbrechen', '', () => openList()));
      panel.appendChild(row);
      panel.scrollIntoView({ block: 'nearest' });
    }

    if (enabled) {
      shareBtn.addEventListener('click', () => {
        if (panel.hidden) openList(); else close();
      });
    }

    return {
      fetchOwn,

      /** Nach jedem Ordnerwechsel: Knopf zeigen/verbergen, Panel schliessen. */
      onFolderLoaded() {
        IncomingBar.update();
        if (!enabled) return;
        // Die eigenen Dateien als Ganzes lassen sich nicht teilen, mit mir
        // geteilte Ordner nicht weiterteilen
        const shareable = !offlineMode && !(view.source === 'home' && view.path === '')
          && !AudioArchive.isIncoming(view.source);
        actions.hidden = !shareable;

        if (pendingShareId !== null) {
          const id = pendingShareId;
          pendingShareId = null;
          fetchForFolder().then((list) => {
            const share = list.find((s) => s.id === id);
            if (share) openForm(share); else openList();
          }).catch(() => openList());
          return;
        }
        close();
      },

      /** Aus "Meine Freigaben": zum Ordner wechseln und die Freigabe oeffnen. */
      openFromList(share) {
        const source = share.source === 'home' ? 'home' : 'shared';
        if (share.missing) {
          window.alert('Der Ordner dieser Freigabe existiert nicht mehr. Die Freigabe lässt sich in den Einstellungen der Verwaltung löschen.');
          return;
        }
        pendingShareId = share.id;
        if (view.source === source && view.path === share.path) {
          Shares.onFolderLoaded();
        } else {
          navigate(share.path, source);
        }
      },
    };
  })();

  // ------------------------------------------------------------------
  // Persoenliche Darstellung (Zahnrad, nur angemeldet)
  //
  // Seit 0.13 alle Oberflaechen-Einstellungen: Gestaltung, Titel,
  // Zusatzzeile, Farben und Hintergrundbild. Sie gelten nur fuer die eigene
  // Ansicht. Den Beta-Hinweis schaltet nur der Administrator.
  // Nach dem Uebernehmen wird die Seite neu geladen: Gestaltung und Bild
  // setzt der Server bereits beim Ausliefern, so steht alles sofort richtig
  // da - auch in der installierten App und im Offline-Start.
  // ------------------------------------------------------------------
  const userSettingsBtn = document.getElementById('user-settings-btn');
  const userSettingsPanel = document.getElementById('user-settings');

  if (AudioArchive.userSettings && userSettingsBtn && userSettingsPanel) {
    userSettingsBtn.hidden = false;

    const usError = document.getElementById('us-error');
    const usBackgroundState = document.getElementById('us-background-state');
    const usBackgroundFile = document.getElementById('us-background-file');
    const usBackgroundRemove = document.getElementById('us-background-remove');
    const usTitle = document.getElementById('us-title');
    const usSubtitle = document.getElementById('us-subtitle');
    const usOwnColors = document.getElementById('us-own-colors');
    const usColors = document.getElementById('us-colors');
    const usModern = document.getElementById('us-modern');
    const usDefined = document.getElementById('us-defined');
    const usCardsMount = document.getElementById('us-design-cards');
    const usDesignHint = document.getElementById('us-design-hint');
    const usColorInputs = {
      themeAccent: document.getElementById('us-accent'),
      themeBar: document.getElementById('us-bar'),
      themeBase: document.getElementById('us-base'),
    };
    let usChanged = false;
    let usSaved = false;

    /*
     * Zustand des Panels (ab 0.17). Vier Gestaltungen plus "Vorgabe":
     *   ''          Vorgabe des Administrators
     *   'nextcloud' Klassisch
     *   'custom'    Modern (Farben waehlbar, mit Vorlagen)
     *   'admin'     vom Administrator bereitgestellt (nur wenn angeboten)
     *   'defined'   benutzerdefiniert (Editor)
     * Jede Aenderung wird sofort als Vorschau auf der Seite gezeigt.
     */
    const us = {
      loaded: null,     // Antwort von api/user/settings
      design: '',
      cards: null,
      palettes: null,
      editor: null,
    };

    const DESIGN_NAMES = {
      nextcloud: 'Klassisch',
      custom: 'Modern',
      admin: 'Vom Administrator',
      defined: 'Benutzerdefiniert',
    };

    function usShowError(message) {
      usError.textContent = message || '';
      usError.hidden = !message;
    }

    function usShowBackground(has) {
      usBackgroundRemove.hidden = !has;
      usBackgroundState.textContent = has
        ? 'Eigenes Bild gesetzt. Es gilt in allen Gestaltungen.'
        : 'Kein eigenes Bild – es gilt die Vorgabe.';
    }

    /** Schreibender Aufruf mit Nextclouds Anfrage-Token. */
    async function usRequest(name, options) {
      const res = await fetch(AudioArchive.api(name), {
        credentials: 'same-origin',
        ...options,
        headers: { requesttoken: AudioArchive.requestToken, ...(options.headers || {}) },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || (res.status === 412
          ? 'Sitzung abgelaufen – bitte die Seite neu laden.'
          : 'Speichern fehlgeschlagen.'));
      }
      return data;
    }

    /** Farben fuer "Modern": eigene, sonst die des Administrators. */
    function modernColors() {
      const admin = (us.loaded && us.loaded.admin) || {};
      if (usOwnColors.checked) {
        return {
          themeAccent: usColorInputs.themeAccent.value,
          themeBar: usColorInputs.themeBar.value,
          themeBase: usColorInputs.themeBase.value,
        };
      }
      return {
        themeAccent: admin.themeAccent || AudioArchive.themeAccent,
        themeBar: admin.themeBar || AudioArchive.themeBar,
        themeBase: admin.themeBase || AudioArchive.themeBase,
      };
    }

    /** Aussehen, das eine Wahl ergibt - fuer die Vorschau auf der Seite. */
    function lookFor(choice) {
      const data = us.loaded || {};
      let design = choice;
      if (design === '' || (design === 'admin' && !data.adminStyleOffered)) {
        design = data.adminDesign || 'custom';
      }
      const look = { design, style: null, ...modernColors() };
      if (design === 'admin') look.style = data.adminStyle || AAStyle.DEFAULTS;
      if (design === 'defined') look.style = us.editor ? us.editor.get() : (data.style || AAStyle.DEFAULTS);
      return look;
    }

    function usPreview() {
      Look.preview(lookFor(us.design));
    }

    function syncSections() {
      usModern.hidden = us.design !== 'custom';
      usDefined.hidden = us.design !== 'defined';
      usColors.hidden = !usOwnColors.checked;
      const data = us.loaded || {};
      if (us.design === '') {
        usDesignHint.textContent = 'Es gilt, was der Administrator vorgibt – derzeit „'
          + (DESIGN_NAMES[data.adminDesign] || 'Modern') + '“.';
        usDesignHint.hidden = false;
      } else if (us.design === 'nextcloud') {
        usDesignHint.textContent = 'Farben, Hintergrund und Schrift kommen von Nextcloud und wechseln mit Hell/Dunkel.';
        usDesignHint.hidden = false;
      } else if (us.design === 'admin') {
        usDesignHint.textContent = 'Vom Administrator gestaltet – ändert er sie, siehst du das automatisch.';
        usDesignHint.hidden = false;
      } else {
        usDesignHint.hidden = true;
      }
    }

    function updateModernThumb() {
      if (!us.cards) return;
      const c = modernColors();
      us.cards.setThumb('custom', AAStyle.modernThumb({ accent: c.themeAccent, bar: c.themeBar, base: c.themeBase }));
    }

    function buildDesignUi(data) {
      usCardsMount.textContent = '';
      const admin = data.admin || {};
      const adminModern = AAStyle.modernThumb({ accent: admin.themeAccent, bar: admin.themeBar, base: admin.themeBase });
      const classic = AAStyle.classicThumb(AudioArchive.ncPrimary() ? '#' + AudioArchive.ncPrimary().replace('#', '') : '');
      const adminStyle = data.adminStyle ? AAStyle.normalize(data.adminStyle) : AAStyle.DEFAULTS;
      const defaultThumb = data.adminDesign === 'nextcloud' ? classic
        : (data.adminDesign === 'admin' ? adminStyle : adminModern);

      const options = [
        { value: '', label: 'Vorgabe', desc: 'Wie vom Administrator eingestellt', style: defaultThumb },
        { value: 'nextcloud', label: 'Klassisch', desc: 'Nextcloud-Design, Hell/Dunkel automatisch', style: classic },
        { value: 'custom', label: 'Modern', desc: 'Rund mit Glaseffekt, Farben wählbar', style: adminModern },
      ];
      if (data.adminStyleOffered) {
        options.push({ value: 'admin', label: 'Vom Administrator', desc: 'Gestaltung des Administrators', style: adminStyle });
      }
      options.push({ value: 'defined', label: 'Benutzerdefiniert', desc: 'Alles selbst einstellen', style: data.style || AAStyle.DEFAULTS });

      us.cards = AAStyle.createDesignCards({
        name: 'us-design',
        options,
        value: us.design,
        onChange(value) {
          us.design = value;
          syncSections();
          usPreview();
        },
      });
      usCardsMount.appendChild(us.cards.el);

      // Farbvorlagen fuer Modern
      const paletteMount = document.getElementById('us-palettes');
      paletteMount.textContent = '';
      us.palettes = AAStyle.createPalettePicker((p) => {
        usOwnColors.checked = true;
        usColorInputs.themeAccent.value = p.accent;
        usColorInputs.themeBar.value = p.bar;
        usColorInputs.themeBase.value = p.base;
        syncSections();
        updateModernThumb();
        usPreview();
      }, null);
      paletteMount.appendChild(us.palettes.el);

      // Editor fuer Benutzerdefiniert
      const editorMount = document.getElementById('us-editor');
      editorMount.textContent = '';
      us.editor = AAStyle.createEditor({
        value: data.style || AAStyle.DEFAULTS,
        imageUrl: AudioArchive.backgroundUrl || '',
        onChange(style) {
          us.cards.setThumb('defined', style);
          if (us.design === 'defined') usPreview();
        },
      });
      editorMount.appendChild(us.editor.el);
    }

    function markPalette() {
      if (!us.palettes) return;
      us.palettes.mark(usOwnColors.checked ? {
        accent: usColorInputs.themeAccent.value,
        bar: usColorInputs.themeBar.value,
        base: usColorInputs.themeBase.value,
      } : null);
    }

    usOwnColors.addEventListener('change', () => {
      syncSections();
      markPalette();
      updateModernThumb();
      usPreview();
    });
    Object.values(usColorInputs).forEach((input) => {
      input.addEventListener('input', () => {
        markPalette();
        updateModernThumb();
        usPreview();
      });
    });

    async function openUserSettings() {
      usShowError('');
      usSaved = false;
      userSettingsPanel.hidden = false;
      userSettingsPanel.scrollIntoView({ block: 'nearest' });
      try {
        const res = await fetch(AudioArchive.api('user/settings'), { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || '');
        us.loaded = data;
        us.design = data.design || '';
        // "Vom Administrator" gewaehlt, aber nicht mehr angeboten: Vorgabe
        if (us.design === 'admin' && !data.adminStyleOffered) us.design = '';

        const values = data.values || {};
        const admin = data.admin || {};
        usTitle.value = values.title || '';
        usTitle.placeholder = admin.title || '';
        usSubtitle.value = values.subtitle || '';
        usSubtitle.placeholder = admin.subtitle || '';
        const ownColors = !!(values.themeAccent || values.themeBar || values.themeBase);
        usOwnColors.checked = ownColors;
        Object.keys(usColorInputs).forEach((keyName) => {
          usColorInputs[keyName].value = values[keyName] || admin[keyName] || '#888888';
        });

        buildDesignUi(data);
        markPalette();
        updateModernThumb();
        syncSections();
        usShowBackground(data.hasBackground === true);
      } catch (err) {
        usShowError('Einstellungen konnten nicht geladen werden (keine Verbindung?).');
      }
    }

    function closeUserSettings() {
      userSettingsPanel.hidden = true;
      // Nicht uebernommene Vorschau zuruecknehmen
      if (!usSaved) Look.endPreview();
      // Ein neues oder entferntes Bild zeigt sich erst nach dem Neuladen
      if (usChanged) location.reload();
    }

    userSettingsBtn.addEventListener('click', () => {
      if (userSettingsPanel.hidden) openUserSettings(); else closeUserSettings();
    });
    document.getElementById('us-cancel').addEventListener('click', closeUserSettings);

    document.getElementById('us-save').addEventListener('click', async () => {
      usShowError('');
      const payload = {
        design: us.design,
        title: usTitle.value,
        subtitle: usSubtitle.value,
        themeAccent: usOwnColors.checked ? usColorInputs.themeAccent.value : '',
        themeBar: usOwnColors.checked ? usColorInputs.themeBar.value : '',
        themeBase: usOwnColors.checked ? usColorInputs.themeBase.value : '',
      };
      // Benutzerdefinierte Werte immer mitsichern - auch wenn gerade eine
      // andere Gestaltung gewaehlt ist, bleiben sie so fuer spaeter erhalten
      if (us.editor) payload.style = us.editor.get();
      try {
        await usRequest('user/settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        usSaved = true;
        location.reload();
      } catch (err) {
        usShowError(err.message);
      }
    });

    usBackgroundFile.addEventListener('change', async () => {
      const file = usBackgroundFile.files[0];
      if (!file) return;
      usShowError('');
      usBackgroundState.textContent = 'Lade hoch …';
      const form = new FormData();
      form.append('file', file);
      try {
        await usRequest('user/background', { method: 'POST', body: form });
        usChanged = true;
        usShowBackground(true);
      } catch (err) {
        usShowError(err.message);
        usShowBackground(!usBackgroundRemove.hidden);
      } finally {
        usBackgroundFile.value = '';
      }
    });

    usBackgroundRemove.addEventListener('click', async () => {
      usShowError('');
      try {
        await usRequest('user/background/remove', { method: 'POST' });
        usChanged = true;
        usShowBackground(false);
      } catch (err) {
        usShowError(err.message);
      }
    });
  }

  // ------------------------------------------------------------------
  // Start
  // ------------------------------------------------------------------
  // ------------------------------------------------------------------
  // Versionsanzeige (ab 0.15.2): unten in der Liste und in der Darstellung.
  // Offline gestartet zeigt sie die Fassung, die gerade tatsaechlich laeuft
  // (die gespeicherte Seite) - genau das ist bei Rueckfragen gefragt.
  // ------------------------------------------------------------------
  (() => {
    if (!AudioArchive.appVersion) return;
    const text = 'Audio Archive · Version ' + AudioArchive.appVersion;
    const footer = document.getElementById('app-version');
    if (footer) {
      footer.textContent = text;
      footer.hidden = false;
    }
    const panel = document.getElementById('us-version');
    if (panel) panel.textContent = text;
  })();

  applySettingsFromDocument();
  checkSession();
})();
