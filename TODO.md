# TODO

## 🚀 Backlog

- [x] **Cover Art**: Extract and include cover art from ID3 tags in the RSS feed.
- [x] **Autoremove**: Option to automatically remove files from the feed after a certain date or after being played.
- [x] **Host logo**: Allow users to set a custom logo for the podcast feed and host it on Nextcloud.
- [ ] **Metadata Editor**: A Vue.js modal to edit ID3 tags or `podcast.json` directly from Nextcloud.
- [ ] **Smart Feeds**: Create dynamic feeds based on Nextcloud Tags (e.g. "To Listen") instead of folders.
- [ ] **File Hooks**: Listen to file create/update/delete events to invalidate the RSS cache immediately.
- [ ] **Transcoding**: (Ambitious) FFMpeg integration to transcode .wav/.flac to .mp3 on the fly.
- [ ] **Security**: Add expiration date or revocation UI for tokens.
- [ ] **Chapter Support**: Parse chapters from description or ID3.

## 🐛 Known Issues

- [ ] Recursion on very large folders might be slow on first load (Cache TTL is 1h).
- [ ] Large file downloads rely on PHP output buffering settings.
