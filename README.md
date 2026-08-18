# Saylor Code Studio — activity (`mod_saylorcode`)

Moodle activity module for [Saylor Code Studio](https://github.com/saylordotorg/moodle-local_saylorcode).
Presents coding exercises as guided lessons, challenges, projects or playgrounds inside a course.

**Status: alpha, Phase 1 vertical slice.** Run, Check and Submit work end to end against a Jobe
runner. See [What is not here yet](#what-is-not-here-yet) for the honest gaps.

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

## The three actions

They differ in consequence, not mechanism — that difference is what `execution_service` encodes
(spec §9.4):

| Action | Runs | Grades | Records |
|---|---|---|---|
| **Run** | The program, with your standard input | Never | Nothing |
| **Check** | Public test cases only | No | Nothing |
| **Submit** | Every test case | Yes | Attempt, gradebook, completion |

Hidden cases count towards a submission's score without ever being described to the student —
not their name, expected value, or feedback. Every action saves first, so what executes is always
what is stored.

**Output comparison is normalised, not exact.** Trailing whitespace and trailing blank lines are
not what a CS101 exercise is teaching, and failing a student for them teaches the wrong lesson.

**A stale save is refused, not applied.** Two tabs open on one exercise is common; neither may
silently discard the other's work, so a save arriving behind newer work is reported and the
student is asked.

### Test case format

A JSON array on the activity. Each entry needs at least `expected`:

```json
[
  {"id": "T1", "name": "Doubles four", "stdin": "4\n", "expected": "8\n",
   "ispublic": true, "weight": 1, "feedback": "Check the arithmetic."},
  {"id": "T2", "name": "Handles a negative", "stdin": "-7\n", "expected": "-14\n",
   "ispublic": false, "weight": 2}
]
```

Malformed JSON is rejected in the settings form rather than failing at Check time, long after the
author has moved on.

## What is not here yet

Honest scope:

- **Starter code and test cases live on the activity**, not in a central library. That keeps CS101
  unblocked, but it means an exercise used in two places is defined twice — exactly what spec §5.2
  wants to avoid. The library supersedes this in Phase 3.
- **Only stdin/stdout test cases.** The other test types in spec §10.5 — unit-test frameworks,
  numeric tolerance, regular expressions — are not implemented.
- **Guided step navigation UI.** The `saylorcode_steps` schema and backup support exist; the
  student-facing step sequencer does not.
- **Instructor review screens** (spec §17).
- **Hints and solutions.** The settings and capability exist; the student-facing flow does not.
- **Editor upgrade.** The MVP uses a plain accessible `<textarea>`. Spec §9.3 treats replacing it
  with Monaco or CodeMirror as a separate decision gated on accessibility testing.

## Development

CI runs [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) against
`MOODLE_405_STABLE` on PHP 8.1 and 8.3 over PostgreSQL and MariaDB, pulling in
`local_saylorcode` as a dependency first.

## Licence

GPL-3.0-or-later, matching Moodle.
