# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-10-29

### Added
- **HTTP Byte-Range streaming (`RangeStreamResponse`)**: Support for HTTP `206 Partial Content` enabling smooth seeking and playback in podcast players like Apple Podcasts and AntennaPod.
- **Conditional HTTP Caching**: ETag and Last-Modified support with `304 Not Modified` on feed, audio download, logo, and cover endpoints, minimizing bandwidth usage for mobile podcast listeners.
- **Modern Files Integration**: Modern `@nextcloud/files` v3 action registration (`registerFileAction`) with conditional script loading via `LoadAdditionalScriptsListener` and `addInitScript`.
- **Dynamic RSS Cache Invalidation**: Automatic feed cache invalidation based on folder ETag changes and configuration hash (`feed_xml_v2_{token}_{etag}_{hash}`).
- **Configurable Episode Ordering**: Choose between Newest first, Oldest first, or Alphabetical order.
- **Artwork Fallback**: Support for `cover.jpg`, `cover.jpeg`, `cover.png`, and `cover.webp` in podcast folders as channel image fallback.
- **Metadata Caching**: Distributed caching for extracted ID3 metadata and covers up to 30 days, avoiding redundant disk I/O.
- **Copy RSS URL**: One-click absolute feed URL copy with clipboard API and toast feedback.
- **Autoremove Background Job**: Safe scheduled background job (`CleanupOldEpisodes`) executing through Nextcloud Cron rather than public HTTP requests.
- **Expanded Test Suite**: 52 unit tests and 110 assertions covering controllers, HTTP range streaming, background jobs, metadata extraction, and feed generation.

### Security
- **Access Control & Attributes**: Migrated all routes to PHP 8 attributes (`#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[PublicPage]`), allowing non-admin users to use API routes while maintaining CSRF protection on mutation endpoints.
- **Path Traversal & Prefix Protection**: Fixed path prefix checks in `FeedService::getFile()` to prevent accessing files outside the podcast folder.
- **Scope Restriction**: Restricted file downloads to valid published audio/video episodes; technical files (`podcast.json`, `_logo.*`, `cover.*`) and hidden files (`.*`) are never served.
- **Safe Autoremove**: Completely removed file deletion logic from the public anonymous `generateXml()` endpoint; deletions now strictly occur in the dedicated background job.
- **Input Validation & Whitelisting**: Added strict validation on podcast names, whitelisted configuration keys in `ApiController::update()` preventing tampering with server-controlled properties like `logoFileId`, and secured logo uploads against non-image MIME types and path injection.
- **Information Disclosure Prevention**: Sanitized error responses on public endpoints so internal server stack traces and exception messages are never leaked to clients.

### Fixed
- **Double Encoding in ID3 tags**: Extracted raw UTF-8 ID3 comments instead of pre-escaped HTML strings, fixing double escaping of quotes and accented characters.
- **XML 1.0 Compliance**: Stripped invalid control characters and null bytes from ID3 metadata and added required RSS tags (`<atom:link>`, `<lastBuildDate>`, `<itunes:explicit>`).
- **Files Action on Nextcloud 30+**: Replaced obsolete `OCA.Files.fileActions` API with modern Nextcloud Files v3 event listeners and action registry.
- **Duplicate Routes**: Removed duplicated `Api#update` route in `appinfo/routes.php`.

## [1.3.0] - 2026-02-21

### Added
- Add support for extracting item cover art directly from MP3 ID3 tags.
- Add support for uploading a custom host logo image for the podcast feed.
- Add Autoremove feature to automatically delete episodes older than a specified number of days from disk.
- Automatically invalidate RSS feed cache upon configuring feed or uploading logo.

### Fixed
- Fixed 500 error during Nextcloud DI instantiation for ApiController and FeedService.
- Fixed 500 error when uploading host logo due to incorrect file array parsing.
- Improved memory stability when parsing large audio files for metadata by using streams.

## [1.2.0]

### Added
- Native Nextcloud integration (Files app actions).
- Initial RSS generation mechanism.
