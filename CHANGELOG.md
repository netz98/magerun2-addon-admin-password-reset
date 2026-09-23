# Changelog

All notable changes to this project are documented in this file.

## 1.0.0

### Added

- Added the `admin:user:force-password-reset` n98-magerun2 command.
- Added admin-user selection by username, user ID, role ID, or all active users.
- Added username exclusions and optional inclusion of inactive users.
- Added dry-run mode and explicit confirmation for credential changes.
- Added optional Magento password-reset email delivery.
- Added optional invalidation of tracked active admin sessions.
- Prevented password-reset emails from being sent to inactive users when `--include-inactive` and `--send-email` are used together.
