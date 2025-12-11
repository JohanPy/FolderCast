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

## 🏗️ Development

### Setup
```bash
git clone ...
cd foldercast
composer install
npm install
```

### Building Frontend
```bash
npm run watch
# or
npm run build
```

## 📝 TODO / Roadmap

See [TODO.md](TODO.md) for the list of planned features (Metadata Editor, Smart Feeds, etc.).
