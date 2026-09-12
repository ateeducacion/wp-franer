---
name: franer-activities
description: Change Franer activity settings, sandbox messages, availability or submission storage.
---

# Franer activities

Read [activity contracts](../../references/activities.md) for settings/data-model
changes and [testing notes](../../references/testing.md) for factory setup.

- Trace `Franer_Site_Repository` → `Franer_Permissions` → REST/public/admin callers.
- A new setting must be registered, typed when read, sanitized on save, revisioned
  if appropriate, and enforced by the actual handler.
- Raw activity HTML and admin view templates are intentional. Preserve sandbox
  isolation and render-time comment stripping; do not KSES the stored source.
- `public/js/franer-shell.js` is the trusted parent bridge. Preserve frame-source
  validation and the submit/result/prefill contracts, and keep the REST nonce out
  of the frame. The view payload must not include stored transport identifiers.
- Test publish/draft, role, schedule, payload validation and duplicate/overwrite
  boundaries relevant to the change; public visibility does not grant admin rights.
