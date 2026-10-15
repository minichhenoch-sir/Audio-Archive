"""Erzeugt die mitgelieferten PDFs der Anleitung (manual/*.pdf).

Aufruf (Testumgebung mit Nextcloud, Anmeldung admin/admin, App-Ordner =
die Arbeitskopie bzw. eine Kopie davon):

    python3 docs/manual-pdf.py http://127.0.0.1:8080/index.php [App-Ordner]

Gedruckt wird die allgemeine Fassung (?fassung=allgemein, ohne die
Einstellungen der Test-Nextcloud). Danach schreibt das Skript die
Pruefsumme der Quellen nach manual/pdf-quellen.txt. Passt sie spaeter nicht
mehr zu den Seiten (Text geaendert, neue Version), liefert die App statt der
alten PDF automatisch die Druckfassung - und release.sh bricht ab.
"""
import asyncio, os, subprocess, sys
from playwright.async_api import async_playwright

B = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080/index.php'
APP = os.path.abspath(sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(__file__), '..'))
OUT = os.path.join(APP, 'manual')

async def main():
    async with async_playwright() as pw:
        b = await pw.chromium.launch()
        ctx = await b.new_context(color_scheme='light')
        p = await ctx.new_page()
        await p.goto(B + '/login')
        await p.fill('#user', 'admin'); await p.fill('#password', 'admin')
        await p.click('button[type=submit]'); await p.wait_for_load_state('networkidle')
        for path, name, label in [('/apps/audioarchive/anleitung', 'Audio-Archive-Anleitung.pdf', 'Anleitung'),
                                  ('/apps/audioarchive/anleitung/admin', 'Audio-Archive-Anleitung-Administration.pdf', 'Anleitung für Administratoren')]:
            await p.goto(B + path + '?fassung=allgemein'); await p.wait_for_load_state('networkidle')
            # Bilder sind lazy - fuer den Druck alle laden
            await p.evaluate("document.querySelectorAll('img').forEach(i => i.loading = 'eager')")
            await p.evaluate("Promise.all([...document.images].map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; })))")
            ver = await p.text_content('.m-kicker')
            footer = ('<div style="width:100%;font:8px system-ui,sans-serif;color:#776b60;padding:0 16mm;display:flex;justify-content:space-between">'
                      f'<span>{ver} – {label}</span><span>Seite <span class="pageNumber"></span> von <span class="totalPages"></span></span></div>')
            await p.pdf(path=os.path.join(OUT, name), format='A4', print_background=True, display_header_footer=True,
                        header_template='<div></div>', footer_template=footer,
                        margin={'top': '14mm', 'bottom': '16mm', 'left': '16mm', 'right': '16mm'})
            print('ok', name)
        await b.close()
    stamp = subprocess.check_output(['php', '-r',
        'require $argv[1] . "/lib/Service/ManualContent.php"; echo \\OCA\\AudioArchive\\Service\\ManualContent::sourcesHash($argv[1]);',
        APP]).decode().strip()
    with open(os.path.join(OUT, 'pdf-quellen.txt'), 'w') as f:
        f.write(stamp + '\n')
    print('Pruefsumme', stamp)

asyncio.run(main())
