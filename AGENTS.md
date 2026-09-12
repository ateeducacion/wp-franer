# AGENTS.md — Franer

Franer publishes generated form activities inside a sandbox and stores their
submissions. WordPress 6.1+, PHP 8.0+; use the existing wp-env configuration.

## Architecture and security boundaries

- `franer.php` is bootstrap; `includes/class-franer.php` registers hooks through
  `Franer_Loader` in its `define_*_hooks()` methods. Add new wiring there.
- `franer_site` activity HTML lives raw in `post_content`. `set_raw_html()`
  deliberately bypasses KSES; the non-public CPT is excluded from REST/search.
  Render activity HTML only in the sandbox, never in the parent DOM.
- The iframe uses `allow-scripts allow-forms`, without `allow-same-origin`.
  The trusted parent makes the nonced REST call; it validates frame identity
  and message shape. Do not send credentials/nonces into the activity frame.
- Visibility follows post status; availability follows enabled/start/end settings.
  `Franer_Permissions` and the REST handler enforce roles, schedule, payload size,
  schema and duplicate/overwrite rules. A generic permission callback is not the
  whole authorization chain.
- Generation prompts and view-generation metadata remain admin-only. Store only
  hashes of transport IP/user agent; do not expose them to activity/view frames.
- For settings, submission flows or message contracts, load `franer-activities`.

## Verification

- `make up` starts the local wp-env site; do not replace its container setup.
- PHP: `make lint`, `make test` (`FILE=` / `FILTER=` supported). PHPMD uses
  `phpmd.xml`; do not raise its budgets to silence new complexity.
- JS: `make test-js`; browser behavior: `make test-e2e` (test site port 8889).
- Packaging: `make check-plugin`; translation strings: `make check-untranslated`.
- `make check` includes `fix` and catalog regeneration; it modifies files.
- Use [testing notes](.agents/references/testing.md) for the project factories and
  coverage attribution; `codecov.yml` is the coverage gate, not a stale percentage.

## Working conventions

- Branches use English names with `feature/` or `hotfix/`; PRs target `main`.
- Follow the repository PHPCS ruleset and current source. English PHPDoc precedes
  functions/methods. Unslash request data before sanitizing; escape at output.
- Check capabilities and resource ownership as well as nonces at write boundaries;
  follow the full caller chain before declaring a deliberately delegated guard missing.
- Read only the domain docs needed by the task. Keep changes focused and report
  what changed, what was verified, and any unresolved check failure concisely.
- Agent guidance/workflow changes need frontmatter, link, provenance and `actionlint`
  checks. Runtime changes need the relevant tests above. Do not weaken CI gates.
- No production deployment, release publication or data mutation is implied by
  a local implementation task. Respect authorization already given in the session.

English source strings use the plugin text domain; Spanish translations and
assertions preserve the user-facing language. Update catalogs with string changes,
use plural-aware translation functions, and add `translators:` comments for
placeholders. JS strings/nonces/URLs use the existing localization pipeline.

## Skills

Load only the skill relevant to the task. Local contracts override generic examples.
- [blueprint](.agents/skills/blueprint/SKILL.md): WordPress Playground blueprint JSON.
- [franer-activities](.agents/skills/franer-activities/SKILL.md): Activity settings, sandbox and submission contracts.
- [github-actions-hardening](.agents/skills/github-actions-hardening/SKILL.md): Author/review GitHub Actions workflows.
- [playwright-cli](.agents/skills/playwright-cli/SKILL.md): Terminal browser exploration; keep the existing test runner.
- [security-audit](.agents/skills/security-audit/SKILL.md): Requested vulnerability audits.
- [wp-performance](.agents/skills/wp-performance/SKILL.md): Measured backend performance work.
- [wp-plugin-development](.agents/skills/wp-plugin-development/SKILL.md): WordPress hooks, lifecycle and settings.
- [wp-plugin-directory-guidelines](.agents/skills/wp-plugin-directory-guidelines/SKILL.md): Distribution/readme and directory checks.
- [wp-plugin-security](.agents/skills/wp-plugin-security/SKILL.md): WordPress input/output and authorization review.
- [wp-project-triage](.agents/skills/wp-project-triage/SKILL.md): Identify existing WordPress tooling and layout.
- [wp-rest-api](.agents/skills/wp-rest-api/SKILL.md): REST schemas, routes and permissions.

### Skill maintenance

Install upstream skills with `gh skills install OWNER/REPO skills/NAME --dir .agents/skills`.
Keep upstream text and `metadata.github-*` unchanged; fix upstream and reinstall.
Local skills have no GitHub provenance and the updater skips them. Put project
exceptions in local guidance, not inside installed upstream folders.

WordPress skills may target 7.0+: verify APIs against this project's supported
versions. Do not upgrade requirements, scaffold new packages or change architecture
merely because a generic skill recommends it. Resolve example `skills/...` paths
under the actual `.agents/skills/` installation; use existing commands first.

New Claude entries are symlinks to `../../.agents/skills/NAME`.
Preserve existing Claude copies; the workflow updates both host directories.

`.github/workflows/update-agent-skills.yml` checks weekly/on dispatch, scoped to
installed skills, and opens a review PR on `main`. It never merges updates.
Review prompt diffs as behavior changes. PRs made with the default GitHub token
may not trigger CI; do not assume green checks will appear automatically.
