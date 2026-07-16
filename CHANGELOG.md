# Changelog

## v0.2.0 — 2026-07-16

- Full UPS REST API driver (OAuth2 client credentials)
- `UpsClient` + `PayloadFactory` + real Ship/Track endpoints
- Label GIF/PDF extracted from create response (`extractLabelContents`)
- `label()` returns public UPS tracking URL when image not stored
- COD via `metadata.cod`, configurable service/packaging/units
- Arabic + English docs (`docs/GUIDE_AR.md`, `docs/API.md`)
- Pest Http::fake coverage for OAuth, ship, track, return

## v0.1.1 — 2026-07-16

- Documentation env fixes

## v0.1.0 — 2026-07-16

- Initial UPS driver scaffold
- Create / track / label / return / exchange placeholders
- Status map for common UPS codes
- Pest + Pint + PHPStan CI
