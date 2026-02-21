# 🎙️ FolderCast for Nextcloud

**Turn your folders into Podcasts.**

FolderCast is a Nextcloud application designed to generate podcast RSS feeds directly from your file directories. It parses ID3 tags, handles recursive scanning, and provides a simple way to listen to your personal audio content in any podcast player (AntennaPod, Apple Podcasts, etc.).

![Preview Screenshot](img/preview.png)
*(Note: Upload a screenshot to img/preview.png)*

## ✨ Features

*   **📂 Folder-to-Feed**: Right-click any folder in Nextcloud to turn it into a Podcast.
*   **🔓 Public Token Auth**: Generates a unique, token-protected URL for each feed, compatible with all podcast apps (no complex Nextcloud login required).
*   **🏷️ Metadata Extraction**: Automatically reads ID3 tags (Title, Duration, Artist) using `getid3`.
*   **💧 Waterfall Config**:
    *   Detects `podcast.json` in the folder for feed metadata.
    *   Detects `cover.jpg` for channel artwork.
*   **🖼️ Host Logo & Episode Artwork**: Upload a custom podcast logo, or use a URL. Extracts individual episode cover art from MP3 ID3 tags.
*   **🗑️ Autoremove**: Automatically delete episodes from disk that are older than a configured number of days.
*   **📝 Metadata Editor**: Customize podcast title, description, and author directly from the dashboard.
*   **🚀 Recursive Scanning**: Option to include subfolders in the feed (default: flattened).

## 🛠️ Installation

### Requirements
*   Nextcloud 28+
*   PHP 8.1+

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
5.  Copy the generated RSS Link.
6.  Paste it into your Podcast player.

## 🎵 Audio File Format Requirements

FolderCast automatically detects and processes audio files for podcasting. Here's what you need to know about file formats and metadata:

### Supported File Types
*   **Audio formats**: Any file with MIME type starting with `audio/` (MP3, M4A, OGG, FLAC, etc.)
*   **Video formats**: Any file with MIME type starting with `video/` (MP4, MKV, etc.)
*   **Fallback**: Files with MIME type `application/octet-stream` (often MP3 files with incorrect MIME detection)

### ID3 Tags (Metadata)

FolderCast uses the **getID3** library to extract metadata from audio files. The following ID3 tags are recognized:

#### Required Tags (fallback to defaults if missing)
*   **Title** (`TIT2` in ID3v2): Episode title
    *   Fallback: Uses the filename if tag is missing
*   **Duration**: Automatically calculated from the audio stream
    *   Fallback: 0 if detection fails

#### Optional Tags
*   **Artist** (`TPE1` in ID3v2): Episode author
    *   Maps to `<itunes:author>` in RSS feed
*   **Album** (`TALB` in ID3v2): Album name
    *   Currently extracted but not used in RSS feed
*   **Description** (`USLT` - Unsynchronized Lyrics in ID3v2): Episode description
    *   Maps to `<description>` and `<itunes:summary>` in RSS feed
    *   Fallback: Tries `description` tag or `USLT` frame data
*   **Comment** (`COMM` in ID3v2): Episode URL/link
    *   Maps to `<link>` in RSS feed
*   **Date/Year** (`TDRC`/`TDOR`/`TYER` in ID3v2): Publication date
    *   Tries `recording_time`, then `date`, then `year`
    *   Fallback: File modification time if all are missing
*   **Cover Art** (`APIC` in ID3v2): Episode artwork
    *   Detected via `picture` comment from getID3
    *   Extracted and served as `<itunes:image>` in the RSS feed

### File Size and Quality Recommendations
*   **No strict size limits**, but smaller files load faster in podcast apps
*   **Recommended bitrate**: 128-192 kbps for voice, 256 kbps for music
*   **Sample rate**: 44.1 kHz or 48 kHz

### Example: Properly Tagged MP3 File

```
Title:       "Episode 1: Introduction"
Artist:      "John Doe"
Album:       "My Podcast Series"
Comment:     "https://example.com/episode1"
Description: "In this episode, we discuss..."
Date:        "2024-01-15"
Duration:    1234 seconds (auto-detected)
Cover Art:   Embedded JPEG/PNG image
```

### Notes
*   Files **without ID3 tags** will still work—the filename becomes the title
*   **Editing metadata**: You can edit ID3 tags using tools like [Kid3](https://kid3.kde.org/) or [MP3Tag](https://www.mp3tag.de/)
*   **Batch processing**: Consider using [EasyTAG](https://wiki.gnome.org/Apps/EasyTAG) for bulk metadata editing

## 🏗️ Development

### setup
```bash
git clone ...
cd foldercast
composer install
npm install
```

### Docker Environment
to start a local nextcloud instance with the app enabled:
```bash
docker compose up -d
```
Access it at http://localhost:8080 (User: admin / Pass: admin).
The code is mounted live, so changes (php) apply immediately. for vue.js, run `npm run watch`.

### Building Frontend
```bash
npm run watch
# or
npm run build
```

## 📝 TODO / Roadmap

See [TODO.md](TODO.md) for the list of planned features (Metadata Editor, Smart Feeds, etc.).
