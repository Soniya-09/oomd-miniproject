# Bug Report — EduFlow

Only defects that were reproduced on the running application (13 October 2026, Chrome 154,
http://127.0.0.1:8000, demo seed data) are listed. Categories, severities and priorities follow
`bug_taxonomy.md`.

## Summary

| Bug ID | Title | Category | Severity | Priority | Found by | Status |
|---|---|---|---|---|---|---|
| BUG-01 | "Class & subject" required error not shown under the field; vague duplicate banner instead | Validation / UI | Minor | P3 | TC23 (scripted) | Open |
| BUG-02 | Double-clicking "Add" in quick-add creates duplicate tasks | Functional | Minor | P3 | Exploratory | Open |

## BUG-01 — "Class & subject" required error is not shown under the field

| Field | Details |
|---|---|
| Bug ID | BUG-01 |
| Module | Teacher → Assignments → New assignment |
| Description | When the new-assignment form is saved empty, Title and Instructions show "This field is required." under each field, but **Class & subject** shows no message and is not highlighted. Instead a red banner at the top of the form says "This field is required. This field is required." without saying which field is meant. |
| Steps to reproduce | 1. Log in as `meera.teacher@eduflow.test` / `Demo@1234`.<br>2. Open **Assignments** and click **New assignment**.<br>3. Leave all fields empty and click **Save as draft**. |
| Expected result | "This field is required." appears under Title, Instructions **and Class & subject**, and the Class & subject drop-down is highlighted. |
| Actual result | Title: "This field is required."; Instructions: "This field is required."; Class & subject: no message, not highlighted; top banner: "This field is required. This field is required." |
| Evidence | `screenshots/TC23_required_fields.png`, `screenshots/FAIL_TC23.png`, pytest-html report entry for TC23 |
| Probable cause | The server validates `class_id` and `subject_id`, but the form shows one combined field named `teaching`, so the two error messages cannot be matched to a field and fall back to the banner. |
| Severity | Minor — the assignment is correctly not saved; only the guidance to the user is unclear. |
| Priority | P3 |
| Category | Validation (wrong/missing error message), UI |
| Status | Open |

## BUG-02 — Double-clicking "Add" in quick-add creates duplicate tasks

| Field | Details |
|---|---|
| Bug ID | BUG-02 |
| Module | Student → Personal Tasks / Dashboard → Quick add |
| Description | Quick-add sends one request per click and does not disable the **Add** button while the first request is in progress, so a double-click (or a fast double press of Enter + click) saves the same task twice. |
| Steps to reproduce | 1. Log in as `priya.student@eduflow.test` / `Demo@1234`.<br>2. Open **Personal Tasks**.<br>3. Type `Double click probe` in the quick-add box.<br>4. Double-click **Add**.<br>5. Search the task list for "Double click probe". |
| Expected result | One task is created (the button is disabled, or the second click is ignored, until the first save completes). |
| Actual result | Two identical tasks are created. Reproduced on 2 of 2 attempts. |
| Evidence | `screenshots/EXP03_double_submit.png`, `exploratory/observations.json` |
| Comparison | The teacher's "New assignment" form handles the same double-click correctly (1 assignment created), because its Save button is disabled while saving. |
| Severity | Minor — data is duplicated but can be deleted; no data loss or security impact. |
| Priority | P3 |
| Category | Functional (double submission) |
| Status | Open |
