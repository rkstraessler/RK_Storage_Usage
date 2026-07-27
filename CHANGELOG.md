# Changelog

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
