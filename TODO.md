# TODO

## 🚀 Backlog

- [ ] **Metadata Editor**: A Vue.js modal to edit ID3 tags or `podcast.json` directly from Nextcloud.
- [ ] **Smart Feeds**: Create dynamic feeds based on Nextcloud Tags (e.g. "To Listen") instead of folders.
- [ ] **File Hooks**: Listen to file create/update/delete events to invalidate the RSS cache immediately.
- [ ] **Transcoding**: (Ambitious) FFMpeg integration to transcode .wav/.flac to .mp3 on the fly.
- [ ] **Security**: Add expiration date or revocation UI for tokens.
- [ ] **Chapter Support**: Parse chapters from description or ID3.

## 🐛 Known Issues

- [ ] Recursion on very large folders might be slow on first load (Cache TTL is 1h).
- [ ] Large file downloads rely on PHP output buffering settings.
