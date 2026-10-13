# Exploratory Testing — EduFlow

**Date:** 13 October 2026 · **Build:** EduFlow local, demo seed · **Browser:** Chrome 154 ·
**Testers:** Sakshi Jagadale, Priti Merurkar · **Time box:** about 60 minutes

## Charter

Explore EduFlow beyond the scripted test cases to find defects in input handling, double
submission, browser navigation (Back, Refresh, multiple tabs), access without logging in or with
another user's ids, and layout on small screens.

## Approach

Each idea was tried in the running application and the outcome recorded. To make the session
repeatable, the probes were also written as a script (`exploratory/explore.py`) that saves
screenshots (`screenshots/EXP*.png`) and the observations (`exploratory/observations.json`).
Three probe scripts initially broke because of our own selectors (they did not wait for the
redirect after saving, used a stale element, and clicked a hidden close button). These were fixed
and re-run, and are not counted as application bugs.

## Findings

| # | Area | What we tried | What happened | Result |
|---|---|---|---|---|
| 1 | Special characters | Task title `<script>alert('x')</script> Café & "quotes" 😀` | Saved and displayed literally; no script ran | OK |
| 2 | Very long input | 6,000 characters in task notes (limit 5,000) | Rejected: "Must be at most 5000 characters." | OK |
| 3 | Long input (boundary) | 250 characters typed into the 200-character task title | Field keeps 200 characters (scripted TC31) | OK |
| 4 | Whitespace only | Quick-add with only spaces | "Type what you need to do first." | OK |
| 5 | Double submit | Double-click **Add** in quick-add | **Two identical tasks created (2/2 attempts)** | **BUG-02** |
| 6 | Double submit | Double-click **Save as draft** on a new assignment | One assignment created (button disabled while saving) | OK |
| 7 | Back button | Log out, then press browser Back | Returned to `/login?next=%2Fstudent`, not the dashboard | OK |
| 8 | Refresh | Submit an assignment, then refresh the page | No resubmission prompt; one file listed | OK |
| 9 | Multiple tabs | Log out in tab 2, then add a task in tab 1 | Tab 1 is sent to the login page | OK |
| 10 | Direct URL, no login | `/teacher/assignments/1`, `/student/tasks`, `/admin/settings`, `/profile` | All redirect to `/login?next=…` | OK |
| 11 | Direct API, no login | `/api/admin/users`, `/api/files/submissions/1` | `{"error":"Please log in to continue."}` | OK |
| 12 | Id tampering | Class B student opens a class A assignment and another student's file by id | "Assignment not found." / "Not found." | OK |
| 13 | Injection-like input | `' OR 1=1 --` in admin user search | "No users match these filters." | OK |
| 14 | Unicode | Devanagari profile name `कबीर सिंह` | Saved and shown in the header | OK |
| 15 | Input normalisation | Login email with leading/trailing spaces | Logged in normally | OK |
| 16 | Invalid number | Marks `-5` when grading | "Must be at least 0." | OK |
| 17 | File name case | Upload `ANSWER_UPPER.PDF` where `pdf` is allowed | Accepted | OK |
| 18 | Logged-in user opens /login | Teacher visits `/login` | Redirected to the teacher dashboard | OK |
| 19 | Responsive (390 px phone) | Dashboard, tasks, calendar, progress, assignments | No horizontal scrolling; sidebar collapses to a menu button that opens it | OK |
| 20 | Responsive (768 px tablet) | Same pages | No horizontal scrolling; menu works | OK |

## Summary

20 ideas explored, 19 behaved correctly, 1 defect found (BUG-02, duplicate task on double-click).
Access control held up well: every direct URL, API call and id change outside the user's scope was
refused. Input handling escaped HTML, enforced length limits and accepted Unicode.

## Screenshots

* `EXP01_special_chars.png` — HTML shown as text in the task list
* `EXP02_long_description.png` — 5,000-character limit message
* `EXP03_double_submit.png` — duplicate tasks after double-click (BUG-02)
* `EXP04_back_after_logout.png` — login page after Back
* `EXP_phone_dashboard.png`, `EXP_phone_menu.png`, `EXP_tablet_dashboard.png`, `EXP_tablet_menu.png` — responsive layouts
