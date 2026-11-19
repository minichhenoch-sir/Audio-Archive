# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Detailed German
notes for every version: [docs/ENTWICKLUNG.md](docs/ENTWICKLUNG.md).

## 1.5.2 – 2026-11-19

### Fixed
- Display names: values such as "Do" → "Donnerstag" that were entered for a
  part which had no values when the share was first saved were lost on the
  next save. Values are now always kept when a share is edited again

## 1.5.1 – 2026-11-19

### Changed
- New screenshots for the App Store showing the current version, the first
  one in Nextcloud's own look

## 1.5.0 – 2026-11-18

### Changed
- The share form is split into tabs: access, features, appearance, names
- The share form stays open while browsing the subfolders of the shared
  folder; list, path and sidebar already show the display names of the
  unsaved rules. Leaving the shared folder with unsaved changes asks:
  save, discard or cancel
- Display names: values are translated right at the part of the name
  (e.g. "So" → "Sonntag"), including values that do not occur yet

## 1.4.0 – 2026-11-18

### Added
- Builder for display names: pick an example name, the app splits it into
  parts (text, date, always the same, leave out), the display is put
  together by tapping – no braces to type. Found values can be translated
  one by one (e.g. "So" → "Sonntag")
- Dates in any format: presets or an own format from simple building blocks
  (day, month, month name, year, weekday), month and day names in a chosen
  language or in the listener's language
- Rules only for a chosen folder (with or without subfolders), only for one
  folder level, with exceptions; own names for single folders or recordings
- Overview table of all building blocks in the form and in the manual

### Changed
- The former notation stays available for advanced users; rules from
  earlier versions keep working unchanged
- The app starts in a share (since 1.3.0): the list now shows the display
  names right away, not only after opening another folder

## 1.3.0 – 2026-11-18

### Changed
- The admin settings no longer hold sources, appearance or the text above
  the recordings. Every share sets its own title, design, colours,
  background image and text; new shares start with the standard look
- Admins can share a folder with **all signed-in users** (🌐). Such shares
  replace the former "shared folder" and the additional sources
- Whether the text above the recordings can be closed with ✕ can be set per
  share (the admin setting stays as the default)
- The background job that reads titles, lengths and covers now covers the
  shares with all signed-in users

### Migration (runs once when updating)
- The shared folder and every additional source become shares with all
  signed-in users, created in the name of the folder's owner (resharing
  allowed). Existing links on these folders keep working
- Text, design, colours and background image of the admin settings are
  copied into existing shares, and the former look into the personal view
  of every user who had not chosen their own (users who have signed in at
  least once)
- Each browser moves offline recordings and remembered positions to the new
  share on its next start with a connection (for the folder's owner: into
  their own files); old entries are removed

## 1.2.4 – 2026-11-18

### Changed
- Preview of display patterns includes the names of all subfolders (grouped
  per folder, so `{x:mehrere}` works as in the list), shows how many names
  match, and has a field to try any name

## 1.2.3 – 2026-11-18

### Added
- Display patterns: `{x:mehrere}` shows a part only if the same folder has
  another name that differs just in that part (e.g. morning and evening
  service on the same day); text in `[ … ]` is dropped when all its
  placeholders are empty. Several options with a comma: `{x:mehrere,groß}`
- Search results carry the displayed name computed by the server

## 1.2.2 – 2026-11-17

### Added
- While browsing, the folder of the recording that is playing – and every
  folder above it – is highlighted in the list and in the sidebar

### Fixed
- Display patterns: a year-and-month date like `2026_05` was not recognised
  as a date (`{Datum}` now shows "Mai 2026")
- Display patterns are computed once per name and cached, also on the
  server – no slowdown for large folders or searches

## 1.2.1 – 2026-11-17

### Changed
- Search on shares with display patterns finds the displayed names of
  folders and recordings, no longer the file names (also offline); title,
  artist and album from the file still count
- Folder patterns also apply to the share's own folder: page title of a
  link without its own title and the name under "Mit mir geteilt"

## 1.2.0 – 2026-11-17

### Added
- Display patterns per share: rules with placeholders (e.g.
  `{Code:3}_{Date}_{Title}`), value replacements and a display template turn
  file and folder names into readable labels for the recipients – the files
  themselves stay unchanged. Live preview in the share form

### Changed
- The section "Öffentlicher Zugang" (administrator link) is gone from the
  admin settings. Links are created and managed in the app. On update an
  existing administrator link becomes a normal link share: same address,
  same password, same features; remembered logins and offline downloads of
  listeners keep working. A disabled link is kept as expired
- "Angemeldet bleiben" moved to "Freigaben durch Nutzer"

## 1.1.0 – 2026-11-17

### Added
- Folders can be downloaded as ZIP directly from their row in the list:
  download button at the far right, like the one of a single recording
  (only where folder download is allowed)
- The player shows the folder of the current recording in front of artist
  and album – also when it was started from the search, the favourites or
  "continue with the next folder". Tapping the folder name opens it

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
