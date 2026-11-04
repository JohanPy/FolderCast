# TODO

## 🚀 Backlog

- [x] **Cover Art**: Extract and include cover art from ID3 tags in the RSS feed.
- [x] **Autoremove**: Option to automatically remove files from the feed after a certain date (via BackgroundJob).
- [x] **Host logo**: Allow users to set a custom logo for the podcast feed and host it on Nextcloud.
- [x] **Metadata Editor**: Dashboard editor to customize podcast title, description, author, ordering, and autoremove.
- [x] **File Hooks / Invalidation**: Dynamic cache invalidation using Nextcloud folder ETag and configuration hash.
- [x] **Large file downloads & streaming**: HTTP Byte-Range support (`206 Partial Content`) for seeking and Apple Podcasts compatibility.
- [ ] **Smart Feeds**: Create dynamic feeds based on Nextcloud Tags (e.g. "To Listen") instead of folders.
- [ ] **Transcoding**: (Ambitious) FFMpeg integration to transcode .wav/.flac to .mp3 on the fly.
- [ ] **Security**: Add expiration date or revocation UI for tokens.
- [ ] **Chapter Support**: Parse chapters from description or ID3.

## 🐛 Known Issues

- [x] Recursion on very large folders might be slow on first load (mitigated: 30-day metadata cache with getID3).
- [x] Large file downloads rely on PHP output buffering settings (solved: RangeStreamResponse streaming).
