# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Detailed German
notes for every version: [docs/ENTWICKLUNG.md](docs/ENTWICKLUNG.md).

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
