## [0.4.3] – 2025-09-20
### Changed
- Registration Endpoint now saves to legacy user meta keys used by existing theme templates:
  - country → nationality
  - tournament/period → period, period_2, period_3
  - club → club, club_2, club_3
  - photo → upload_photo (attachment ID)
  - primary video link → link; additional → v_links; uploaded mp4 → v_upload_id
- Kept marketing/representative consents under _oval15_ keys.
- Added defensive validation that matches the legacy template requirements.

### Notes
- This aligns plugin behavior with current My Profile/Edit Profile templates, removing “*Country/Club/Tournament/Profile/Link/Photo is required*” errors.
- Future migration to a new schema can happen via an alias layer + one-time migrator.

## [0.4.3] – 2025-09-20 (continued)
### Changed
- Media/Video.php: Standardized `handle_upload()` to always return attachment ID in `v_upload_id`.
- Checkout/Flow.php: On order complete, ensures a Complete Registration token and redirects users to legacy-aligned endpoint.
