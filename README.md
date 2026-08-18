# Saylor Code Studio — activity (`mod_saylorcode`)

Moodle activity module for [Saylor Code Studio](https://github.com/saylordotorg/moodle-local_saylorcode).
Presents coding exercises as guided lessons, challenges, projects or playgrounds inside a course.

**Status: alpha, Phase 1 vertical slice.** The data model, settings, grading, completion, privacy
and backup layers are implemented. The execution web services are not yet wired — see
[What is not here yet](#what-is-not-here-yet).

## Requirements

| | |
|---|---|
| Moodle | 4.5 (build 2024100700) |
| PHP | 8.1 – 8.3 |
| Depends on | `local_saylorcode` |

## What this plugin does

Provides the student-facing activity and everything Moodle needs to treat coding work as
first-class course content:

- **Four modes** — guided lesson, coding challenge, project, playground (spec §8).
- **Attempts, steps and snapshots** — the data model from spec §12.5–12.8, including per-step
  run/check/submit counts and hint usage.
- **Grading** — ungraded, completion-only, automatic from tests, manual, or mixed, pushed to the
  Moodle gradebook with a best-attempt policy.
- **Completion** — standard view/grade rules plus two custom rules: pass the required tests, and
  reach a minimum score.
- **Backup and restore** — full structure including user attempts and code snapshots.
- **Privacy** — export and deletion for attempts, step progress, snapshots and execution records.

## Design notes

**Exercises are referenced, never copied.** An activity stores a stable ID such as
`CS101-U05-E03` plus a version policy. The exercise itself lives once, centrally. This is what
lets the same exercise appear in an activity, a Book embed and a quiz without three copies
drifting apart (spec §5.2).

**Graded work should pin its version.** `versionpolicy` defaults to *latest approved*, which keeps
formative activities current as exercises improve. For graded activities, pin a version — a
content edit should never silently change a live assessment (spec §10.9).

**Solution visibility is granted to nobody by default.** `mod/saylorcode:viewsolutions` has no
archetype in `db/access.php`. A site has to decide deliberately who may see answers, including
which staff roles.

**Execution records hold no source code.** The `saylorcode_executions` table stores timing,
outcome and a sanitised diagnostic only. Student code lives in snapshots, which are what the
privacy provider exports and deletes. Backup deliberately skips execution records — they are
operational telemetry, not student work.

**Accessibility is in the template, not bolted on.** The three panes are landmark regions with
headings so a screen-reader user can jump between instructions, editor and results directly. The
status line is a live region, so save and execution state changes are announced without stealing
focus (spec §15).

## What is not here yet

Honest scope. This plugin is structurally complete but not yet functional end to end:

- **Execution web services.** `db/services.php` and the external API for save / run / check /
  submit are the next increment. The client-side state machine in `amd/src/workspace.js` is
  written against that seam, so the wiring is a defined piece of work rather than a redesign.
- **Guided step navigation UI.** The `saylorcode_steps` schema and backup support exist; the
  student-facing step sequencer does not.
- **Instructor review screens** (spec §17).
- **Editor upgrade.** The MVP uses a plain accessible `<textarea>`. Spec §9.3 treats replacing it
  with Monaco or CodeMirror as a separate decision gated on accessibility testing.

## Development

CI runs [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) against
`MOODLE_405_STABLE` on PHP 8.1 and 8.3 over PostgreSQL and MariaDB, pulling in
`local_saylorcode` as a dependency first.

## Licence

GPL-3.0-or-later, matching Moodle.
