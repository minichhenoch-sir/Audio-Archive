#!/bin/sh
# Prueft vor jedem Commit/jeder Version, ob die Anleitung zum Code passt (ab 1.0.3).
#   1. Jede Ueberschrift der Verwaltung hat ein "?" mit Sprungmarke
#   2. Jede Sprungmarke (?, Links aus der App) gibt es in der Anleitung
#   3. Die PDFs passen zum Stand der Seiten (sonst docs/manual-pdf.py)
# Aufruf im App-Ordner: sh docs/manual-check.sh
cd "$(dirname "$0")/.." || exit 1
FAIL=0
H3=$(grep -c '<h3>' templates/settings-admin.php)
HELP=$(grep -c "<h3>.*\$help('" templates/settings-admin.php)
[ "$H3" = "$HELP" ] || { echo "Verwaltung: $H3 Ueberschriften, aber nur $HELP mit ?-Verweis"; FAIL=1; }
for a in $(grep -o "\$help('[a-z0-9-]*')" templates/settings-admin.php | sed "s/.*('\(.*\)')/\1/"); do
  case "$a" in kommentare) grep -q "id=\"$a\"" templates/manual/admin.php || { echo "Sprungmarke #$a fehlt"; FAIL=1; } ;; esac
done
for a in installieren; do
  grep -q "id=\"$a\"" templates/manual/user.php || { echo "Sprungmarke #$a fehlt in der Nutzer-Anleitung"; FAIL=1; }
done
WANT=$(php -r 'require "lib/Service/ManualContent.php"; echo \OCA\AudioArchive\Service\ManualContent::sourcesHash(".");')
HAVE=$(tr -d '[:space:]' < manual/pdf-quellen.txt 2>/dev/null)
[ "$WANT" = "$HAVE" ] || { echo "PDFs veraltet: python3 docs/manual-pdf.py <nextcloud>/index.php"; FAIL=1; }
[ $FAIL = 0 ] && echo "Anleitung: alles passt."
exit $FAIL
