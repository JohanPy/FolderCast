# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
