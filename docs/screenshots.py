#!/usr/bin/env python3
"""Bildschirmfotos fuer den Nextcloud App Store neu erzeugen (ab 1.5.1).

Laeuft gegen die Test-Nextcloud im Sandbox (siehe Vault: "Testumgebung mit
echter Nextcloud"), in der die aktuelle Fassung der App aktiv ist. Legt
Demo-Aufnahmen, eine Testnutzerin und eine Freigabe an alle an (mehrfach
aufrufbar) und schreibt die vier Bilder aus appinfo/info.xml nach
screenshots/. So zeigen sie immer die Oberflaeche und Versionsnummer der
Version, die gerade ausgeliefert wird. Setzt ausserdem die Bild-Adressen in
appinfo/info.xml auf den Tag v<version> (der Store speichert Bilder unbegrenzt
zwischen, eine gleichbleibende Adresse zeigte dort fuer immer das alte Bild).

    python3 docs/screenshots.py --nc ~/nc/server
    python3 docs/screenshots.py --nc ~/nc/server --url http://127.0.0.1:8080/index.php

Braucht: php (occ), ffmpeg, Python-Playwright mit Chromium.
Bild 1 zeigt die Gestaltung "Klassisch" (Nextcloud-Optik, Standard-Blau),
Bild 2 und 3 die Gestaltung "Modern", Bild 4 die Verwaltung.

Ab 1.6.0 entstehen dabei auch die Bilder der Anleitung neu (1000 px breit):
Bibliothek, Telefon, Teilen-Formular und Verwaltung, je einmal in "Modern"
(img/manual/) und "Klassisch" (img/manual/klassisch/). Danach
docs/manual-pdf.py laufen lassen, damit die PDFs sie zeigen.
"""
import argparse
import json
import re
import os
import subprocess
from datetime import datetime
from pathlib import Path

from PIL import Image
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent
APP = '/apps/audioarchive'
ADMIN = ('admin', os.environ.get('AA_ADMIN_PASS', 'admin'))  # wie docs/manual-pdf.py
LISTENER = ('anna', 'Anna-Demo-Pass-42!', 'Anna')
SHARE_FOLDER = 'Aufnahmen'
SHARE_TITLE = 'Gemeinsame Aufnahmen'

# Ordner, Datei, Titel, Kuenstler, Sekunden, Tonhoehe (nur hoerbarer Platzhalter), Datum
DEMO = [
    ('Vorträge 2026/2026_01 Januar', '01 Ein neues Jahr', 'Ein neues Jahr', 'Audio Archive Demo', 154, 220, '2026-01-04'),
    ('Vorträge 2026/2026_01 Januar', '02 Hoffnung', 'Hoffnung', 'Audio Archive Demo', 201, 247, '2026-01-11'),
    ('Vorträge 2026/2026_02 Februar', '01 Neu anfangen', 'Neu anfangen', 'Audio Archive Demo', 97, 262, '2026-02-01'),
    ('Vorträge 2026/2026_02 Februar', '02 Dankbarkeit', 'Dankbarkeit', 'Audio Archive Demo', 134, 294, '2026-02-08'),
    ('Vorträge 2026/2026_02 Februar', '03 Unterwegs mit Fragen', 'Unterwegs mit Fragen', 'Audio Archive Demo', 171, 330, '2026-02-15'),
    ('Vorträge 2026/2026_02 Februar', '04 Mut zur Lücke', 'Mut zur Lücke', 'Audio Archive Demo', 208, 349, '2026-02-22'),
    ('Konzerte/Adventskonzert 2025', '01 Macht hoch die Tür', 'Macht hoch die Tür', 'Chor & Orchester', 97, 392, '2025-12-14'),
    ('Konzerte/Adventskonzert 2025', '02 Tochter Zion', 'Tochter Zion', 'Chor & Orchester', 134, 440, '2025-12-14'),
    ('Konzerte/Adventskonzert 2025', '03 Stille Nacht', 'Stille Nacht', 'Chor & Orchester', 171, 494, '2025-12-14'),
    ('Podcast', 'Folge 01 – Wie alles begann', 'Wie alles begann', 'Audio Archive Podcast', 188, 523, '2026-03-02'),
    ('Podcast', 'Folge 02 – Fragen aus der Gemeinde', 'Fragen aus der Gemeinde', 'Audio Archive Podcast', 215, 587, '2026-03-16'),
]

# Cover-Bild je Hauptordner: Farbe, Ueberschrift (Unterzeile = Ordnername)
COVERS = {
    'Vorträge 2026': ((185, 196, 106), 'Vorträge'),
    'Konzerte': ((107, 185, 191), 'Konzerte'),
    'Podcast': ((224, 163, 107), 'Podcast'),
}
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'


def make_cover(target, color, heading, sub):
    """Einfaches Cover: Kreise auf Farbflaeche, Ueberschrift und Ordnername."""
    from PIL import ImageDraw, ImageFont
    size = 800
    img = Image.new('RGB', (size, size), color)
    d = ImageDraw.Draw(img)
    for r in range(80, 380, 50):
        d.ellipse((size / 2 - r, size / 2 - r, size / 2 + r, size / 2 + r), outline='white', width=4)
    try:
        big, small = ImageFont.truetype(FONT, 104), ImageFont.truetype(FONT.replace('-Bold', ''), 52)
    except OSError:
        big = small = ImageFont.load_default()
    d.text((size / 2, size / 2 - 40), heading, font=big, fill='white', anchor='mm')
    d.text((size / 2, size / 2 + 60), sub, font=small, fill='white', anchor='mm')
    img.save(target, quality=90)


def occ(nc, *args, env=None):
    return subprocess.run(['php', 'occ', *args], cwd=nc, check=True, capture_output=True,
                          text=True, env={**os.environ, **(env or {})}).stdout


def prepare_files(nc):
    base = Path(nc) / 'data' / ADMIN[0] / 'files' / SHARE_FOLDER
    for folder, name, title, artist, secs, freq, day in DEMO:
        target = base / folder / (name + '.mp3')
        if target.exists():
            continue
        target.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'lavfi',
                        '-i', f'sine=frequency={freq}:duration={secs}', '-ac', '1', '-b:a', '48k',
                        '-metadata', f'title={title}', '-metadata', f'artist={artist}',
                        '-metadata', 'date=' + day[:4], str(target)], check=True)
        stamp = datetime.fromisoformat(day + 'T10:00:00').timestamp()
        os.utime(target, (stamp, stamp))
    for folder in sorted({d[0] for d in DEMO}):
        cover = base / folder / 'cover.jpg'
        if not cover.exists():
            make_cover(cover, *COVERS[folder.split('/')[0]], Path(folder).name)
    occ(nc, 'files:scan', ADMIN[0], '-q')
    users = occ(nc, 'user:list', '--output=json')
    if LISTENER[0] not in json.loads(users):
        occ(nc, 'user:add', '--password-from-env', f'--display-name={LISTENER[2]}', LISTENER[0],
            env={'OC_PASS': LISTENER[1]})
    else:
        occ(nc, 'user:resetpassword', '--password-from-env', LISTENER[0], env={'OC_PASS': LISTENER[1]})


class Session:
    """Angemeldete Browser-Sitzung mit Zugriff auf die App-Schnittstelle."""

    def __init__(self, browser, url, user, password, **context):
        self.url = url
        self.ctx = browser.new_context(locale='de-DE', **context)
        self.page = self.ctx.new_page()
        self.page.goto(url + '/login')
        self.page.fill('#user', user)
        self.page.fill('#password', password)
        self.page.keyboard.press('Enter')
        self.page.wait_for_url(lambda u: '/login' not in u)
        self.page.wait_for_load_state('networkidle')
        self.token = self.page.evaluate('document.head.dataset.requesttoken')

    def api(self, method, path, data=None):
        headers = {'requesttoken': self.token, 'Content-Type': 'application/json'}
        r = self.page.request.fetch(self.url + APP + path, method=method, headers=headers,
                                    data=json.dumps(data) if data is not None else None)
        if not r.ok:
            raise RuntimeError(f'{method} {path}: {r.status} {r.text()[:300]}')
        return r.json()

    def close(self):
        self.ctx.close()


def ensure_share(admin):
    """Freigabe des Demo-Ordners an alle angemeldeten Nutzer, Kennung zurueck.

    Weitere Freigaben in den Demo-Ordnern (z. B. von Tests) werden entfernt,
    damit die Tabelle der Verwaltung auf Bild 4 immer gleich aussieht.
    """
    for s in admin.api('GET', '/api/shares').get('shares', []):
        if str(s.get('path', '')).startswith(SHARE_FOLDER + '/'):
            admin.api('POST', f"/api/shares/{s['id']}/delete", {})
    for s in admin.api('GET', '/api/shares?source=home&path=' + SHARE_FOLDER).get('shares', []):
        if s.get('kind') == 'internal' and s.get('path') == SHARE_FOLDER:
            return s['id']
    s = admin.api('POST', '/api/shares', {
        'source': 'home', 'path': SHARE_FOLDER, 'kind': 'internal',
        'members': [{'type': 'all', 'id': '*'}],
        'settings': {'title': SHARE_TITLE, 'featureOffline': True},
    })
    return s['share']['id']


def set_share_design(admin, share_id, design):
    share = next(s for s in admin.api('GET', '/api/shares')['shares'] if s['id'] == share_id)
    # Ohne Namensregeln, damit die Bilder die Dateinamen der Demo zeigen
    settings = dict(share['settings'], design=design, nameRules=[])
    admin.api('POST', f'/api/shares/{share_id}', {'settings': settings})


def open_recording(page, url, source, path, play=True, at=37):
    """App mit einer Aufnahme oeffnen; sie laeuft an, steht dann pausiert bei `at` Sekunden."""
    page.goto(url + '#open=' + source + '|' + path)
    page.wait_for_load_state('networkidle')
    name = Path(path).stem
    row = page.locator('.explorer-row', has_text=name).first
    row.wait_for()
    if play:
        row.click()
        page.wait_for_function('() => { const a = document.querySelector("audio"); return a && a.duration > 0; }')
        page.evaluate(f'() => {{ const a = document.querySelector("audio"); a.pause(); a.currentTime = {at}; }}')
    page.evaluate('() => document.activeElement && document.activeElement.blur()')
    page.mouse.move(0, 0)
    page.wait_for_timeout(1200)


def shot_library(browser, url, share_id, out):
    """Bild 1: Bibliothek am Rechner, eingebettet in Nextcloud, Gestaltung Klassisch."""
    s = Session(browser, url, LISTENER[0], LISTENER[1], viewport={'width': 1200, 'height': 900})
    s.api('POST', '/api/user/settings', {'design': 'nextcloud'})
    open_recording(s.page, url + APP + '/', f'in:{share_id}',
                   'Vorträge 2026/2026_02 Februar/02 Dankbarkeit.mp3')
    s.page.screenshot(path=str(out / '1-library.png'))
    Image.open(out / '1-library.png').resize((600, 450), Image.LANCZOS).save(out / '1-library-small.png')
    s.close()


def shot_phone(browser, url, share_id, out):
    """Bild 2: installierte App am Telefon - Liste und Vollbild-Player nebeneinander."""
    phone = dict(viewport={'width': 390, 'height': 844}, device_scale_factor=2,
                 is_mobile=True, has_touch=True)
    s = Session(browser, url, LISTENER[0], LISTENER[1], **phone)
    s.api('POST', '/api/user/settings', {'design': 'custom'})
    open_recording(s.page, url + APP + '/app', f'in:{share_id}',
                   'Konzerte/Adventskonzert 2025/02 Tochter Zion.mp3', at=48)
    left = s.page.screenshot()
    s.page.click('#btn-expand')
    s.page.wait_for_timeout(1500)
    right = s.page.screenshot()
    s.close()
    compose_side_by_side(left, right, out / '2-player-phone.png')


def compose_side_by_side(left_png, right_png, target, margin=40, gap=40, bg=(241, 236, 230)):
    import io
    a = Image.open(io.BytesIO(left_png)).convert('RGB')
    b = Image.open(io.BytesIO(right_png)).convert('RGB')
    w = margin * 2 + a.width + gap + b.width
    h = margin * 2 + max(a.height, b.height)
    canvas = Image.new('RGB', (w, h), bg)
    canvas.paste(a, (margin, margin))
    canvas.paste(b, (margin + a.width + gap, margin))
    canvas.save(target, optimize=True)


def shot_share(browser, url, out):
    """Bild 3: Ordner teilen - Formular fuer einen neuen Link (Gestaltung Modern)."""
    s = Session(browser, url, *ADMIN, viewport={'width': 1200, 'height': 900})
    s.api('POST', '/api/user/settings', {'design': 'custom'})
    open_recording(s.page, url + APP + '/', 'home',
                   SHARE_FOLDER + '/Vorträge 2026/2026_02 Februar/01 Neu anfangen.mp3', play=False)
    s.page.click('#share-btn')
    s.page.locator('#share-panel button', has_text='Neuer Link').first.click()
    s.page.wait_for_timeout(1200)
    s.page.mouse.move(0, 0)
    s.page.screenshot(path=str(out / '3-share.png'))
    s.close()


MANUAL = ROOT / 'img' / 'manual'


def save_jpg(png, target, width=1000):
    """PNG (Bytes oder Pfad) als JPEG in Anleitungsgroesse speichern."""
    import io
    img = Image.open(io.BytesIO(png) if isinstance(png, bytes) else png).convert('RGB')
    if img.width != width:
        img = img.resize((width, round(img.height * width / img.width)), Image.LANCZOS)
    img.save(target, 'JPEG', quality=85, optimize=True, progressive=True)


def phone_pair(browser, url, share_id, design):
    """Telefon: Liste und Vollbild-Player nebeneinander (PNG-Bytes je Seite)."""
    phone = dict(viewport={'width': 390, 'height': 844}, device_scale_factor=2,
                 is_mobile=True, has_touch=True)
    s = Session(browser, url, LISTENER[0], LISTENER[1], **phone)
    s.api('POST', '/api/user/settings', {'design': design})
    open_recording(s.page, url + APP + '/app', f'in:{share_id}',
                   'Konzerte/Adventskonzert 2025/02 Tochter Zion.mp3', at=48)
    left = s.page.screenshot()
    s.page.click('#btn-expand')
    s.page.wait_for_timeout(1500)
    right = s.page.screenshot()
    s.close()
    return left, right


def shot_manual(browser, url, admin, share_id, out):
    """Bilder der Anleitung (ab 1.6.0) - vorher noch von 1.0.0.

    Zwei Saetze: Modern (img/manual/, auch fuer die PDFs) und Klassisch
    (img/manual/klassisch/). Die Anleitung zeigt den, der zur Gestaltung
    des Lesers passt (ManualController::imageSet).
    """
    import tempfile
    view = {'viewport': {'width': 1000, 'height': 750}}
    for design, target in (('custom', MANUAL), ('nextcloud', MANUAL / 'klassisch')):
        target.mkdir(parents=True, exist_ok=True)
        # Die Freigabe bringt ihre eigene Gestaltung mit - passend setzen
        set_share_design(admin, share_id, design)
        # Bibliothek in der App
        s = Session(browser, url, LISTENER[0], LISTENER[1], **view)
        s.api('POST', '/api/user/settings', {'design': design})
        open_recording(s.page, url + APP + '/app', f'in:{share_id}',
                       'Vorträge 2026/2026_02 Februar/02 Dankbarkeit.mp3')
        save_jpg(s.page.screenshot(), target / 'library.jpg')
        s.close()
        # Ordner teilen: neuer Link mit den Tabs
        s = Session(browser, url, *ADMIN, **view)
        s.api('POST', '/api/user/settings', {'design': design})
        open_recording(s.page, url + APP + '/app', 'home',
                       SHARE_FOLDER + '/Vorträge 2026/2026_02 Februar/01 Neu anfangen.mp3', play=False)
        s.page.click('#share-btn')
        s.page.locator('#share-panel button', has_text='Neuer Link').first.click()
        s.page.wait_for_timeout(1200)
        s.page.mouse.move(0, 0)
        save_jpg(s.page.screenshot(), target / 'share.jpg')
        s.close()
        # Telefon
        left, right = phone_pair(browser, url, share_id, design)
        with tempfile.TemporaryDirectory() as tmp:
            compose_side_by_side(left, right, Path(tmp) / 'phone.png')
            save_jpg(Path(tmp) / 'phone.png', target / 'player-phone.jpg')
        # Verwaltung (sieht in beiden gleich aus: Nextcloud selbst)
        s = Session(browser, url, *ADMIN, **view)
        s.page.goto(url + '/settings/admin/audioarchive')
        s.page.wait_for_load_state('networkidle')
        s.page.wait_for_timeout(1000)
        save_jpg(s.page.screenshot(), target / 'admin.jpg')
        s.close()
    set_share_design(admin, share_id, 'custom')
    # Danach wieder Modern fuer beide Testkonten
    for user, password in ((LISTENER[0], LISTENER[1]), ADMIN):
        s = Session(browser, url, user, password)
        s.api('POST', '/api/user/settings', {'design': 'custom'})
        s.close()


def shot_admin(browser, url, out):
    """Bild 4: Einstellungen der Verwaltung in Nextcloud."""
    s = Session(browser, url, *ADMIN, viewport={'width': 1200, 'height': 900})
    s.page.goto(url + '/settings/admin/audioarchive')
    s.page.wait_for_load_state('networkidle')
    s.page.wait_for_timeout(1000)
    s.page.screenshot(path=str(out / '4-admin.png'))
    s.close()


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--nc', required=True, help='Verzeichnis der Test-Nextcloud (mit occ)')
    ap.add_argument('--url', default='http://127.0.0.1:8080/index.php')
    ap.add_argument('--out', default=str(ROOT / 'screenshots'))
    args = ap.parse_args()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    prepare_files(args.nc)
    with sync_playwright() as p:
        # Deutsches Datumsformat in Datumsfeldern: Chromium richtet sich
        # nach der Sprache des Prozesses, nicht nach locale des Kontexts
        browser = p.chromium.launch(args=['--lang=de-DE'], env={
            **os.environ, 'LANG': 'de_DE.UTF-8', 'LANGUAGE': 'de', 'LC_ALL': 'de_DE.UTF-8'})
        admin = Session(browser, args.url, *ADMIN)
        share_id = ensure_share(admin)
        set_share_design(admin, share_id, 'nextcloud')
        shot_library(browser, args.url, share_id, out)
        set_share_design(admin, share_id, 'custom')
        shot_phone(browser, args.url, share_id, out)
        shot_share(browser, args.url, out)
        shot_admin(browser, args.url, out)
        shot_manual(browser, args.url, admin, share_id, out)
        admin.close()
        browser.close()
    version = (ROOT / 'appinfo' / 'info.xml').read_text().split('<version>')[1].split('<')[0]
    # release.sh prueft diese Datei: Bilder muessen zur Version passen
    (out / 'version.txt').write_text(version + '\n')
    # Bild-Adressen in info.xml auf den Tag dieser Version (ab 1.5.1): Der
    # Store speichert Bilder unbegrenzt zwischen - unter gleicher Adresse
    # bliebe dort das alte Bild stehen
    info = ROOT / 'appinfo' / 'info.xml'
    text = info.read_text()
    new = re.sub(r'(raw\.githubusercontent\.com/[^/]+/[^/]+/)[^/]+(/screenshots/)',
                 lambda m: m.group(1) + 'v' + version + m.group(2), text)
    if new != text:
        info.write_text(new)
        print(f'info.xml: Bild-Adressen auf v{version} gesetzt')
    print(f'Bildschirmfotos fuer {version} in {out}:')
    for f in sorted(out.glob('*.png')):
        print(f'  {f.name}  {Image.open(f).size}')
    print(f'Bilder der Anleitung in {MANUAL}:')
    for f in sorted(MANUAL.glob('**/*.jpg')):
        print(f'  {f.relative_to(MANUAL)}  {Image.open(f).size}')


if __name__ == '__main__':
    main()
