# Changelog

## Unreleased

### Added

- Configurable list of separately reported folders with an individual JSON key
  and output unit for every entry
- Folder picker with navigation into subfolders in the Nextcloud admin settings
- Direct API-link button in the admin settings
- Per-folder option to exclude its storage usage from the reported total
- Stable source identities for folders shared with the configuring administrator
- Exact unadjusted and excluded byte values in the API response
- Explicit `unavailable` and `not_in_total` states for folder entries that
  cannot safely affect the total

### Fixed

- Keep the folder picker hidden while closed and center it independently of
  Nextcloud's global dialog styles

## 1.0.2

### Added

- Native Nextcloud admin settings for output unit and cache duration
- Configurable binary and decimal output units: Auto, B, kB, KiB, MB, MiB, GB,
  GiB, TB, and TiB
- Exact byte value and active cache duration in the API response
- Full REIKEM logo as App Store preview image

### Fixed

- Replaced the externally referenced PNG icon with a self-contained SVG icon

## 1.0.1

### Added

- Public read-only endpoint for aggregated user storage usage
- 60-second local cache
- App Store metadata and documentation

### Security

- Documented that the endpoint is publicly accessible without authentication
- The response contains no usernames, individual usage values, file names, or
  file contents
