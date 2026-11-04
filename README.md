# 🎙️ FolderCast for Nextcloud

**Turn your folders into Podcasts.**

FolderCast is a Nextcloud application designed to generate podcast RSS feeds directly from your file directories. It parses ID3 tags, handles recursive scanning, supports HTTP byte-range streaming, and provides a simple way to listen to your personal audio content in any podcast player (AntennaPod, Apple Podcasts, etc.).

![Preview Screenshot](img/preview.png)
*(Note: Upload a screenshot to img/preview.png)*

## ✨ Features

*   **📂 Folder-to-Feed**: Right-click any folder in Nextcloud Files to turn it into a Podcast feed with one click.
*   **🔓 Public Token Auth**: Generates a unique, token-protected URL for each feed, compatible with all podcast apps (no complex Nextcloud login required).
*   **⏩ HTTP Byte-Range Streaming**: Full HTTP `206 Partial Content` support for smooth audio seeking and compatibility with Apple Podcasts and modern mobile players.
*   **🏷️ Metadata Extraction**: Automatically reads ID3 tags (Title, Duration, Artist) using `getID3`, cached for high performance.
*   **💧 Waterfall Artwork & Config**:
    *   Detects `podcast.json` in the folder for feed metadata overrides.
    *   Detects `cover.jpg`, `cover.png`, or `cover.webp` in the folder for channel artwork fallback.
    *   Uploaded custom logo or remote image URL.
    *   Extracts embedded episode cover art (APIC) from audio tags.
*   **📋 One-Click RSS URL Copy**: Copy the absolute RSS feed link directly to clipboard from Files or Dashboard.
*   **🔀 Episode Ordering**: Configurable sorting (Newest first, Oldest first, or Alphabetical by name/title).
*   **🗑️ Background Autoremove**: Automatically clean up old episodes older than a configured threshold via Nextcloud Background Jobs (`cron`).
*   **📝 Metadata Editor**: Customize podcast title, description, author, ordering, and subfolder inclusion directly from the dashboard.
*   **🚀 Recursive Scanning**: Option to include subfolders in the feed (default: enabled / included).

## 🛠️ Installation

### Requirements
*   Nextcloud 30 – 32
*   PHP 8.2+

### Manual Installation
1.  Clone this repository into your `apps` directory:
    ```bash
    git clone https://github.com/your-username/foldercast.git
    cd foldercast
    ```
2.  Install PHP dependencies:
    ```bash
    composer install
    ```
3.  Install JS dependencies and build:
    ```bash
    npm ci
    npm run build
    ```
4.  Enable the app:
    ```bash
    occ app:enable foldercast
    ```

## 📖 Usage

1.  Navigate to the **Files** app.
2.  Locate a folder with audio files.
3.  Open the item menu (three dots) or right-click.
4.  Select **"Turn into Podcast"**.
5.  The RSS Feed URL is automatically copied to your clipboard (or displayed with a copy action).
6.  Paste it into your Podcast player (e.g. AntennaPod, Apple Podcasts, Pocket Casts).

## 🎵 Audio File Format Requirements

FolderCast automatically detects and processes audio files for podcasting.

### Supported File Types
*   **Audio formats**: Any file with MIME type starting with `audio/` (MP3, M4A, OGG, OPUS, FLAC, WAV, etc.)
*   **Video formats**: Any file with MIME type starting with `video/` (MP4, M4V, etc.)
*   **Fallback**: Files with MIME type `application/octet-stream` and valid audio/video extensions. Technical files (`_logo.*`, `cover.*`, `podcast.json`) and hidden files (`.*`) are excluded automatically.

### ID3 Tags (Metadata)

FolderCast uses the **getID3** library to extract metadata from audio files:

#### Required Tags (fallback to defaults if missing)
*   **Title** (`TIT2` in ID3v2): Episode title (fallback: filename)
*   **Duration**: Automatically calculated from the audio stream

#### Optional Tags
*   **Artist** (`TPE1` in ID3v2): Episode author (maps to `<itunes:author>`)
*   **Album** (`TALB` in ID3v2): Album name
*   **Description** (`USLT` - Unsynchronized Lyrics in ID3v2): Episode description (maps to `<description>` and `<itunes:summary>`)
*   **Comment** (`COMM` in ID3v2): Episode URL link (maps to `<link>` if valid URL)
*   **Date/Year** (`TDRC`/`TDOR`/`TYER` in ID3v2): Publication date (fallback: file modification time)
*   **Cover Art** (`APIC` in ID3v2): Episode artwork (served as `<itunes:image>` in the RSS feed)

## 🏗️ Development

### Setup
```bash
git clone ...
cd foldercast
composer install
npm install
```

### Dev Workflow & Testing
Use these commands to test and build (runs via Docker if local CLI tools are absent):

```bash
make doctor
make test-php
make test-js
# or all at once
make test-all
```

### Building Frontend
```bash
npm run watch
# or
npm run build
```

## 📝 TODO / Roadmap

See [TODO.md](TODO.md) for the list of planned and completed features.
