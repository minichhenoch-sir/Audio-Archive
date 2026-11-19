#!/bin/sh
# Builds the App Store archive for the version in appinfo/info.xml and signs it.
#
#   ./release.sh            archive + signature (needs the tag v<version>)
#
# Result in build/:
#   audioarchive-<version>.tar.gz       -> attach to the GitHub release
#   audioarchive-<version>.tar.gz.sig   -> paste into the App Store form
#
# The private key is expected next to (not inside) the repository:
#   ../.certificates/audioarchive.key   (override with KEY=/path/to/key)
set -e
cd "$(dirname "$0")"
V=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)
TAG="v$V"
KEY="${KEY:-../.certificates/audioarchive.key}"
OUT="build/audioarchive-$V.tar.gz"

git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || { echo "Tag $TAG fehlt"; exit 1; }
grep -q "^## $V" CHANGELOG.md || { echo "CHANGELOG.md hat keinen Abschnitt ## $V"; exit 1; }

# Bildschirmfotos fuer den Store (ab 1.5.0): mit docs/screenshots.py in der
# Test-Nextcloud erzeugt, sonst zeigen sie eine alte Oberflaeche und Version
SHOTS=$(tr -d '[:space:]' < screenshots/version.txt 2>/dev/null || true)
[ "$SHOTS" = "$V" ] || { echo "Bildschirmfotos sind von ${SHOTS:-unbekannt}, nicht $V - docs/screenshots.py ausfuehren"; exit 1; }
# ... und die Adressen in info.xml muessen auf diesen Tag zeigen (Store-Zwischenspeicher)
if grep '<screenshot' appinfo/info.xml | grep -v "/$TAG/screenshots/" | grep -q .; then
    echo "info.xml: Bild-Adressen zeigen nicht auf $TAG - docs/screenshots.py ausfuehren"; exit 1
fi

# Anleitung (ab 1.0.3): Die PDFs muessen zum Stand der Seiten passen
# (Pruefsumme in manual/pdf-quellen.txt, erzeugt von docs/manual-pdf.py)
WANT=$(php -r 'require "lib/Service/ManualContent.php"; echo \OCA\AudioArchive\Service\ManualContent::sourcesHash(".");')
HAVE=$(tr -d '[:space:]' < manual/pdf-quellen.txt 2>/dev/null || true)
[ "$WANT" = "$HAVE" ] || { echo "Anleitung: PDFs veraltet - docs/manual-pdf.py ausfuehren"; exit 1; }

mkdir -p build
git archive --format=tar --prefix=audioarchive/ "$TAG" | gzip -n -9 > "$OUT"

# Checks the App Store also does
TOP=$(tar tzf "$OUT" | cut -d/ -f1 | sort -u)
[ "$TOP" = "audioarchive" ] || { echo "Falsche oberste Ebene: $TOP"; exit 1; }
tar tzf "$OUT" | grep -q '^audioarchive/appinfo/info.xml$' || { echo "info.xml fehlt"; exit 1; }
if tar tzf "$OUT" | grep -q '/\.git/'; then echo ".git im Archiv"; exit 1; fi

echo "Archiv:  $OUT ($(wc -c < "$OUT") Bytes)"
shasum -a 256 "$OUT" 2>/dev/null || sha256sum "$OUT"

if [ -f "$KEY" ]; then
    openssl dgst -sha512 -sign "$KEY" "$OUT" | openssl base64 > "$OUT.sig"
    echo "Signatur: $OUT.sig"
    if [ -f "${KEY%.key}.crt" ]; then
        openssl x509 -in "${KEY%.key}.crt" -pubkey -noout > build/pub.pem
        openssl base64 -d -in "$OUT.sig" -out build/sig.bin
        openssl dgst -sha512 -verify build/pub.pem -signature build/sig.bin "$OUT"
        rm -f build/pub.pem build/sig.bin
    fi
else
    echo "Kein Schluessel unter $KEY - Archiv nicht signiert."
fi
