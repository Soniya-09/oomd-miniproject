# Bug Taxonomy — EduFlow

A bug taxonomy is a classification scheme for defects. Every bug in `bug_report.md` is assigned
one category, one severity and one priority from this document.

## Categories

| Category | Definition | Examples in EduFlow |
|---|---|---|
| **Functional** | A feature does not do what it should, or gives wrong results | Publishing an assignment does not notify students; marks saved against the wrong submission; dashboard "Completed" count does not match the task list |
| **UI (User Interface)** | Layout, text, styling or visual feedback is wrong, but the function works | Overlapping chart labels; button text cut off; success message not shown after saving |
| **Validation** | Input is accepted when it should be rejected, rejected when valid, or the error message is wrong/missing | 7-character password accepted; `.txt` upload accepted where only PDF is allowed; empty title accepted |
| **Navigation** | Links, redirects, back/refresh or URL handling take the user to the wrong place | Logout leaves the dashboard reachable with the Back button; card click opens an unfiltered list; 404 for an existing page |
| **Performance** | Pages or actions are unacceptably slow, or resources leak | Dashboard takes more than 3 s to load with demo data; repeated chart redraws freeze the page |
| **Security** | Data or actions are available to someone who should not have them | Student opens `/admin/users`; another student's file downloadable by changing the id; form works without CSRF token |
| **Compatibility** | Works in one browser, screen size or device but not another | Horizontal scrolling on a 390 px phone screen; calendar unreadable on mobile; date picker missing in a browser |

## Severity scale (impact on the system)

| Severity | Definition | EduFlow example |
|---|---|---|
| **Critical** | Blocks a core function or exposes data; no workaround | No user can log in; students can see other students' submissions |
| **Major** | A main feature fails or gives wrong results; a workaround may exist | Students cannot submit assignments; grades are not saved |
| **Minor** | Limited feature or validation problem; the main flow still works | Wrong error text; a filter ignores one option |
| **Trivial** | Cosmetic issue with no functional effect | Spelling mistake; misaligned icon |

## Priority scale (urgency of fixing)

| Priority | Definition | Typical pairing |
|---|---|---|
| **P1 — Immediate** | Fix before any further testing or release | Critical bugs |
| **P2 — High** | Fix in the current release | Major bugs, visible Minor bugs on main flows |
| **P3 — Medium** | Fix when time allows in this or the next release | Minor bugs |
| **P4 — Low** | Fix if convenient; may be deferred | Trivial bugs |

Severity describes **how bad** the defect is; priority describes **how soon** it must be fixed.
They usually match, but not always: a spelling mistake in the login page title is Trivial but
could be P2 because every user sees it.

## Bug status lifecycle

`New → Open → Fixed → Retested → Closed`, with `Reopened` if the retest fails, and `Deferred` or
`Rejected` (not a bug / works as designed) when applicable.
