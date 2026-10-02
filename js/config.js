/**
 * Gemeinsame Grundlage fuer player.js und app.js.
 *
 * Buendelt die Werte, die der Server ins Dokument geschrieben hat, und die
 * Bildung der Endpunkt-Adressen an einer Stelle. Damit muessen die uebrigen
 * Dateien nichts darueber wissen, unter welchem Pfad die App laeuft - das
 * ist in Nextcloud anders als in der eigenstaendigen Fassung, wo alles
 * relativ zum Dokument lag.
 */
const AudioArchive = (() => {
  'use strict';

  const el = document.getElementById('app-config');

  const data = {
    publicToken: el.dataset.publicToken || '',
    // Bild fuer Aufnahmen ohne Cover, wie vom Server bestimmt (ab 0.20)
    coverIcon: el.dataset.coverIcon || '',
    coverUrl: el.dataset.coverUrl || '',
    embedded: el.dataset.embedded === '1',
    design: el.dataset.design || 'custom',
    // Vollstaendige Werte bei 'admin'/'defined' (ab 0.17), sonst null
    style: (window.AAStyle && el.dataset.style) ? window.AAStyle.parse(el.dataset.style) : null,
    adminStyleOffered: el.dataset.adminStyleOffered === '1',
    userSettings: el.dataset.userSettings === '1',
    loggedIn: el.dataset.loggedIn === '1',
    hasShared: el.dataset.hasShared === '1',
    canShare: el.dataset.canShare === '1',
    // Nur auf der Seite einer Nutzer-Freigabe gesetzt (siehe PlayerPage)
    apiToken: el.dataset.apiToken || '',
    openAccess: el.dataset.openAccess === '1',
    requestToken: el.dataset.requesttoken || '',
    standaloneUrl: el.dataset.standaloneUrl || '',
    nextcloudUrl: el.dataset.nextcloudUrl || '',
    sortDefault: el.dataset.sortDefault === 'newest' ? 'newest' : 'name',
    // Anzahl der Aufnahmen neben Ordnern (ab 0.30.0, Vikunja #42)
    showFolderCount: el.dataset.showFolderCount === '1',
    // Was in den Zeilen steht und wie Namen erscheinen (ab 0.33.0, Vikunja #50, #44)
    listDisplay: (() => {
      let d = {};
      try { d = JSON.parse(el.dataset.listDisplay || '{}') || {}; } catch (e) { d = {}; }
      return {
        folderDate: d.folderDate !== false,
        trackDuration: d.trackDuration !== false,
        trackDate: d.trackDate !== false,
        prettyFolderNames: d.prettyFolderNames === true,
        titleFromTags: d.titleFromTags === true,
      };
    })(),
    // Farbe des Favoriten-Sterns (ab 0.33.0, Vikunja #3)
    starColor: ['accent', 'yellow', 'text'].includes(el.dataset.starColor) ? el.dataset.starColor : 'accent',
    // Weitere Quellen des Administrators: [{id: 'src:<n>', name}] (ab 0.32.0, Vikunja #8)
    extraSources: (() => {
      try {
        const list = JSON.parse(el.dataset.sources || '[]');
        return Array.isArray(list) ? list.filter((s) => s && /^src:\d+$/.test(s.id)) : [];
      } catch (e) {
        return [];
      }
    })(),
    // ab 0.28.0 (Vikunja #32/#2)
    commentsOffered: el.dataset.commentsOffered === '1',
    repeatLabels: {
      off: 'Aus',
      next: 'Danach nächster Ordner',
      folder: 'Ordner wiederholen',
      one: 'Titel wiederholen',
    },
    searchScope: el.dataset.searchScope === 'all' ? 'all' : 'folder',
    repeatDefault: ['off', 'next', 'folder', 'one'].includes(el.dataset.repeatDefault) ? el.dataset.repeatDefault : 'next',
    adminRepeatDefault: ['off', 'next', 'folder', 'one'].includes(el.dataset.adminRepeatDefault) ? el.dataset.adminRepeatDefault : 'next',
    sharedLabel: (el.dataset.sharedLabel || '').trim(),
    favorites: el.dataset.favorites === '1',
    headerTitle: el.dataset.headerTitle || '',
    headerSubtitle: el.dataset.headerSubtitle || '',
    serviceWorker: el.dataset.serviceWorker || '',
    scope: el.dataset.scope || '',
    assetBase: el.dataset.assetBase || '',
    backgroundUrl: el.dataset.background || '',
    appVersion: el.dataset.appVersion || '',
    betaEnabled: el.dataset.beta === '1',
    // Text ueber den Aufnahmen (ab 0.31.0; vorher betaText/betaLink*)
    noticeText: el.dataset.noticeText || '',
    noticeLinkUrl: el.dataset.noticeLinkUrl || '',
    noticeLinkLabel: el.dataset.noticeLinkLabel || '',
    themeAccent: el.dataset.themeAccent || '#b9793f',
    themeBar: el.dataset.themeBar || '#291c12',
    themeBase: el.dataset.themeBase || '#a86a3d',
    // Nextclouds Gestaltungs-Stylesheets zum Nachladen, falls ein mit dem
    // Nutzer geteilter Ordner die Nextcloud-Gestaltung verwendet (ab 0.13)
    themeStylesheets: (() => {
      try {
        const list = JSON.parse(el.dataset.themeStylesheets || '[]');
        return Array.isArray(list) ? list : [];
      } catch (e) {
        return [];
      }
    })(),
  };

  // Aktuelle Gestaltung. Veraenderlich, weil ein mit dem Nutzer geteilter
  // Ordner sein eigenes Aussehen mitbringen kann (siehe app.js, Look).
  // Gemeint ist hier der AUFBAU: 'nextcloud' (flach, Nextclouds
  // Variablen) oder 'custom' (Glas). Flach sind "Klassisch" und frei
  // eingestellte Gestaltungen mit Grundstil "flach" (ab 0.17).
  const rootEl = document.getElementById('audioarchive');
  let currentDesign = rootEl && rootEl.classList.contains('aa-design-nextcloud') ? 'nextcloud' : 'custom';
  // Farbe des App-Symbols (6 Hexziffern) - folgt der Leistenfarbe (ab 0.16)
  let iconColor = /^[0-9a-f]{6}$/.test(el.dataset.iconColor || '') ? el.dataset.iconColor : '';
  const ncPrimary = el.dataset.ncPrimary || '';
  // Bild fuer Aufnahmen ohne Cover (ab 0.20): Schluessel eines mitgelieferten
  // Bildes oder 'custom' (eigenes Bild der Freigabe unter coverImage)
  const COVER_ICONS = ['speaker', 'badge', 'box', 'phones', 'play', 'mic'];
  let coverIcon = 'speaker';
  let coverImage = '';
  function setCoverState(icon, url) {
    const next = icon === 'custom' && url ? 'custom' : (COVER_ICONS.includes(icon) ? icon : 'speaker');
    const nextUrl = next === 'custom' ? new URL(url, location.href).href : '';
    const changed = next !== coverIcon || nextUrl !== coverImage;
    coverIcon = next;
    coverImage = nextUrl;
    return changed;
  }
  setCoverState(el.dataset.coverIcon || '', el.dataset.coverUrl || '');

  return {
    ...data,

    /** Adresse eines Endpunkts, z.B. api('list') oder api('public/login'). */
    api(name) {
      return data.scope + 'api/' + name;
    },

    /*
     * Adressen fuer Ordnerliste, Baum und Aufnahmen einer Quelle.
     *
     * WICHTIG: Fuer den gemeinsamen Ordner ('shared') bleibt die Adresse
     * exakt so wie bis 0.10 - ohne source-Angabe. Offline gespeicherte
     * Aufnahmen liegen unter genau dieser Adresse im Speicher; eine
     * veraenderte Adresse wuerde sie unauffindbar machen.
     */
    sourceQuery(source) {
      // Seite einer Freigabe: alles laeuft ueber deren Token
      if (data.apiToken) return 's=' + encodeURIComponent(data.apiToken) + '&';
      if (source === 'home') return 'source=home&';
      // Mit dem Nutzer geteilter Ordner (ab 0.13): 'in:<id>', weitere Quelle (ab 0.32.0): 'src:<id>'
      if (/^(in|src):\d+$/.test(source || '')) return 'source=' + encodeURIComponent(source) + '&';
      return '';
    },

    /** Anmelde-Status der oeffentlichen Seite (mit Token bei einer Freigabe). */
    statusUrl() {
      return this.api('public/status') + (data.apiToken ? '?s=' + encodeURIComponent(data.apiToken) : '');
    },

    logoutUrl() {
      return this.api('public/logout') + (data.apiToken ? '?s=' + encodeURIComponent(data.apiToken) : '');
    },

    /** Ordner als ZIP (ab 0.25.0). */
    zipUrl(path, source) {
      return new URL(this.api('zip') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path || ''), location.href).href;
    },

    listUrl(path, source) {
      return new URL(this.api('list') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path || ''), location.href).href;
    },

    treeUrl(path, source) {
      return new URL(this.api('tree') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path || ''), location.href).href;
    },

    /** Ausfuehrliche Angaben zu einer Aufnahme (ab 0.15). */
    infoUrl(path, source) {
      return new URL(this.api('info') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path), location.href).href;
    },

    /** Naechster Ordner mit Aufnahmen in Baum-Reihenfolge (ab 0.15). */
    nextFolderUrl(path, source) {
      return new URL(this.api('next') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path || ''), location.href).href;
    },

    streamUrl(path, source, download) {
      return new URL(this.api('stream') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path) + (download ? '&download=1' : ''), location.href).href;
    },

    /**
     * Cover einer Aufnahme (ab 0.14). version stammt aus der Ordnerliste
     * und aendert sich mit dem Bild - so kommt nie ein veraltetes Cover aus
     * dem Browser-Speicher.
     */
    coverUrl(path, source, version) {
      return new URL(this.api('cover') + '?' + this.sourceQuery(source)
        + 'path=' + encodeURIComponent(path) + '&v=' + encodeURIComponent(version || ''), location.href).href;
    },

    /**
     * Vollstaendige Adresse einer mitgelieferten Datei (Bilder usw.).
     *
     * Bewusst absolut aufgeloest: Relative Angaben wuerden auf der
     * oeffentlichen Seite gegen /apps/audioarchive/s/<token>/ aufgeloest
     * und ins Leere zeigen.
     */
    /**
     * App-Symbol in der aktuellen Leistenfarbe (ab 0.16). Varianten:
     * any-64, any-192, any-512, maskable-512, apple-180, cover<Zeichen>-512
     * (ab 0.20, Ersatzbild ohne Cover). Ohne Farbe das
     * mitgelieferte blaue Symbol.
     */
    iconUrl(variant) {
      if (!iconColor) {
        const cover = /^cover([a-z]+)-512$/.exec(variant);
        if (cover) return this.asset('img/cover-' + cover[1] + '.png');
        return this.asset(variant === 'any-192' ? 'img/icon-192.png' : 'img/icon-512.png');
      }
      return new URL(data.scope + 'icon/' + iconColor + '/' + variant
        + '?v=' + encodeURIComponent(data.appVersion), location.href).href;
    },

    /** Symbolfarbe setzen ('#rrggbb'); true, wenn sie sich geaendert hat. */
    setIconColor(color) {
      let hex = String(color || '').trim().replace(/^#/, '').toLowerCase();
      if (/^[0-9a-f]{3}$/.test(hex)) hex = hex.replace(/(.)/g, '$1$1');
      hex = hex.slice(0, 6);
      if (!/^[0-9a-f]{6}$/.test(hex) || hex === iconColor) return false;
      iconColor = hex;
      return true;
    },

    /**
     * Bild fuer Aufnahmen ohne Cover (ab 0.20): randlose Flaeche in der
     * Leistenfarbe mit dem gewaehlten Zeichen, oder das eigene Bild der
     * Freigabe. Ohne Farbe das mitgelieferte blaue Bild.
     */
    fallbackCoverUrl() {
      if (coverIcon === 'custom' && coverImage) return coverImage;
      if (!iconColor) return this.asset('img/cover-' + coverIcon + '.png');
      return this.iconUrl('cover' + coverIcon + '-512');
    },

    /** Ist das Ersatzbild ein eigenes Bild der Freigabe (kein Zeichen)? */
    customCover() {
      return coverIcon === 'custom' && coverImage !== '';
    },

    /** Letzte Rueckfallstufe, wenn auch das farbige Bild nicht laedt. */
    plainCoverUrl() {
      return this.asset('img/cover-speaker.png');
    },

    /**
     * Auswahl setzen (Look in app.js: eigene Ansicht bzw. geteilter
     * Ordner). true, wenn sich etwas geaendert hat.
     */
    setCover(icon, url) {
      return setCoverState(icon || '', url || '');
    },

    /** Nextclouds Hauptfarbe - Symbolfarbe bei Nextcloud-Gestaltung */
    ncPrimary() {
      return ncPrimary;
    },

    asset(file) {
      return new URL(data.assetBase + file, location.href).href;
    },

    /**
     * Laeuft der Player innerhalb von Nextcloud (mit dessen Kopfleiste)?
     * Dann fehlt das eigene Manifest, installiert wird ueber die
     * eigenstaendige Fassung (standaloneUrl).
     */
    isEmbedded() {
      return data.embedded;
    },

    /**
     * Flacher Nextcloud-Aufbau? Dann kommen Farben und Hintergrund aus
     * Nextclouds CSS-Variablen (siehe style.css, .aa-design-nextcloud),
     * und die eigenen Farben werden nicht gesetzt. (Bei einer frei
     * eingestellten flachen Gestaltung belegt app.js diese Variablen fuer
     * den Player mit den eigenen Farben.)
     */
    isNextcloudDesign() {
      return currentDesign === 'nextcloud';
    },

    /** Aufbau wechseln ('nextcloud' = flach) - nur ueber Look in app.js aufrufen. */
    setDesign(design) {
      currentDesign = design === 'nextcloud' ? 'nextcloud' : 'custom';
    },

    /** Ist die Quelle ein mit dem Nutzer geteilter Ordner ('in:<id>')? */
    /**
     * Empfaenger einer Freigabe lesbar: "👤 Anna Beispiel, 👥 Gruppe Team"
     * (ab 0.26.0, Vikunja #8: Person oder Gruppe muss erkennbar sein).
     */
    describeMembers(members) {
      const list = (members || []).map((m) => (m.type === 'group' ? '👥 Gruppe ' : '👤 ') + m.label);
      return list.length ? list.join(', ') : 'niemand';
    },

    isIncoming(source) {
      return /^in:\d+$/.test(source || '');
    },

    /** Ist die Quelle eine weitere Quelle des Administrators ('src:<id>', ab 0.32.0)? */
    isExtra(source) {
      return /^src:\d+$/.test(source || '');
    },

    /** Name einer weiteren Quelle, wie in der Verwaltung eingetragen. */
    extraName(source) {
      const found = data.extraSources.find((s) => s.id === source);
      return found ? found.name : 'Weitere Quelle';
    },

    /** Ist der Aufruf ueber die oeffentliche Seite erfolgt? */
    isPublic() {
      return data.publicToken !== '';
    },
  };
})();
