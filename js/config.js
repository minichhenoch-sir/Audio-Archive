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
    embedded: el.dataset.embedded === '1',
    design: el.dataset.design || 'custom',
    userSettings: el.dataset.userSettings === '1',
    loggedIn: el.dataset.loggedIn === '1',
    hasShared: el.dataset.hasShared === '1',
    canShare: el.dataset.canShare === '1',
    // Nur auf der Seite einer Nutzer-Freigabe gesetzt (siehe PlayerPage)
    apiToken: el.dataset.apiToken || '',
    openAccess: el.dataset.openAccess === '1',
    requestToken: el.dataset.requesttoken || '',
    standaloneUrl: el.dataset.standaloneUrl || '',
    headerTitle: el.dataset.headerTitle || '',
    headerSubtitle: el.dataset.headerSubtitle || '',
    serviceWorker: el.dataset.serviceWorker || '',
    scope: el.dataset.scope || '',
    assetBase: el.dataset.assetBase || '',
    backgroundUrl: el.dataset.background || '',
    appVersion: el.dataset.appVersion || '',
    betaEnabled: el.dataset.beta === '1',
    betaText: el.dataset.betaText || '',
    betaLinkUrl: el.dataset.betaLinkUrl || '',
    betaLinkLabel: el.dataset.betaLinkLabel || '',
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
  let currentDesign = data.design;

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
      // Mit dem Nutzer geteilter Ordner (ab 0.13): 'in:<id>'
      if (/^in:\d+$/.test(source || '')) return 'source=' + encodeURIComponent(source) + '&';
      return '';
    },

    /** Anmelde-Status der oeffentlichen Seite (mit Token bei einer Freigabe). */
    statusUrl() {
      return this.api('public/status') + (data.apiToken ? '?s=' + encodeURIComponent(data.apiToken) : '');
    },

    logoutUrl() {
      return this.api('public/logout') + (data.apiToken ? '?s=' + encodeURIComponent(data.apiToken) : '');
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
     * Nextcloud-Gestaltung? Dann kommen Farben und Hintergrund aus
     * Nextclouds CSS-Variablen (siehe style.css, .aa-design-nextcloud),
     * und die eigenen Farben werden nicht gesetzt.
     */
    isNextcloudDesign() {
      return currentDesign === 'nextcloud';
    },

    /** Gestaltung wechseln - nur ueber Look in app.js aufrufen. */
    setDesign(design) {
      currentDesign = design === 'nextcloud' ? 'nextcloud' : 'custom';
    },

    /** Ist die Quelle ein mit dem Nutzer geteilter Ordner ('in:<id>')? */
    isIncoming(source) {
      return /^in:\d+$/.test(source || '');
    },

    /** Ist der Aufruf ueber die oeffentliche Seite erfolgt? */
    isPublic() {
      return data.publicToken !== '';
    },
  };
})();
