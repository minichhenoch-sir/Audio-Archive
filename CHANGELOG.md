# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Detailed German
notes for every version: [docs/ENTWICKLUNG.md](docs/ENTWICKLUNG.md).

## 1.0.6 – 2026-11-06

### Fixed
- Switching tracks or seeking quickly could crash PHP workers of the server
  (SIGSEGV, "TypeError: smbclient_…") when the recordings are on SMB
  external storage: an aborted transfer now ends on its own and closes the
  file properly instead of being killed by PHP with the file still open

## 1.0.5 – 2026-11-06

### Changed
- Title, subtitle and the text above the list belong to the area: the
  shared recordings show the administrator's texts, "Meine Dateien" shows the
  user's own texts (default: title "Meine Dateien", no subtitle, no text).
  The user's own texts (gear → Texte) now apply to "Meine Dateien" only
- The text above the list can be hidden with ✕ (remembered per device until
  the text changes); new admin switch to keep it permanently visible
- Download button of a track sits at the far right of the row (behind the
  star); on phones it no longer slips under the play symbol

## 1.0.4 – 2026-11-05

### Fixed
- With the screen locked, playback sometimes stopped at the end of a track
  instead of continuing with the next one: a refused start is now retried
  (at the latest when the app becomes visible again), and a track that hangs
  just before its calculated end counts as finished
- After a navigation announcement (e.g. Google Maps via CarPlay) or a call,
  playback continues by itself where the browser reports the end of the
  interruption (navigator.audioSession, Safari); a pause pressed by the user is never undone

### Added
- The log under "Angaben" (i) now also lists playback events (track end,
  refused start, pause by the system, interruption, reload) for
  troubleshooting in the car

## 1.0.3 – 2026-10-15

### Changed
- The manual keeps itself up to date: the administrator manual's settings
  reference is read directly from the settings page and from the design
  editor, so new or changed settings appear without editing the manual
- The listener manual shows which functions are switched on or off on this
  Nextcloud, and follows settings such as search scope and offline access
- "Als PDF" only offers the bundled PDF while it matches the pages;
  otherwise it opens the print view ("Save as PDF") instead
- Browser install instructions come from one place for the app and the
  manual

## 1.0.2 – 2026-10-10

### Added
- Built-in manual (German): one for listeners and users, one for
  administrators – as a page in the app's look and as a PDF download
- The (i) button is now always shown and opens "Hilfe" with the manual;
  e-mail and message form are added when configured
- Links to the manual in the administration and personal settings, plus a
  "?" next to every admin heading that jumps to the matching section
- The manual is stored for offline reading in the installed app

## 1.0.1 – 2026-10-09

### Changed
- Supports Nextcloud 29 to 36 (tested on 29, 31, 33 and 35)

### Fixed
- Player did not start on Nextcloud 29 (script nonce is computed differently there)

## 1.0.0 – 2026-10-08

First release in the Nextcloud App Store.

### Added
- Space bar toggles play/pause
- Sorting by name, newest or shuffle, with a direction arrow
- Reload button in the header (useful in the installed app)
- Option "Open offline without password": saved recordings open offline
  without password or PIN; logging out locks them again
- "Install app" opens the browser's install dialog directly where supported
- `occ audioarchive:remove-data` to delete all app data before removing it

### Changed
- Automatic advance and previous/next always start the next recording at
  the beginning
- Search shows progress while titles are still being read and no longer
  flickers
- Logging out stops playback
- Store metadata: English description, SPDX licence, supported Nextcloud 33–36

### Fixed
- Text editor for the notice above the list used only a narrow column

## 0.37.0 – 2026-10-03
- Formatted notice text, help and contact button, browser overview for
  installing

## 0.36.0 – 2026-10-03
- Comments: permissions, Excel export, print view, jump to recording

## 0.35.0 – 2026-10-02
- Comment overview and export

## 0.34.0 – 2026-10-02
- Faster: metadata read in the background, smaller covers

## 0.33.0 – 2026-10-02
- Original file and folder names, configurable list display, general texts

## 0.32.0 – 2026-10-02
- Several source folders, groups allowed to share

## 0.29.0 – 2026-10-02
- Comments and ratings for recordings

## 0.27.0 – 2026-10-01
- Optional conversion to MP3 with ffmpeg

## 0.24.0 – 2026-10-01
- Favourites

## 0.22.0 – 2026-10-01
- Search and sorting by date

## 0.21.0 – 2026-10-01
- More audio formats besides MP3

## 0.12.0 – 2026-09-18
- Shares created by users

## 0.4.0 – 2026-09-17
- Complete player interface, offline mode, public access
