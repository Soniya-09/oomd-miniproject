# Final Test Report — EduFlow

| | |
|---|---|
| Application | EduFlow — Academic Management & Productivity Portal |
| Environment | Windows 11, PHP 8.4.5 built-in server, MySQL 8.0, Chrome 154 (headless) |
| Tools | Selenium WebDriver 4.27.1 (Python 3.13), pytest 8.3.4, pytest-html 4.1.1, Selenium IDE |
| Test run | 13 October 2026, full regression suite, database reset to demo seed before the run |
| Prepared by | Soniya Pawar, Sakshi Jagadale, Priti Merurkar |

## Execution summary

| Metric | Value |
|---|---|
| Test cases designed | 36 |
| Test cases executed | 36 |
| Passed | 35 |
| Failed | 1 (TC23) |
| Blocked / not run | 0 |
| **Run rate** | **100 %** (36 / 36) |
| **Pass rate** | **97.2 %** (35 / 36) |
| Execution time | 8 min 18 s (497.6 s) |
| Selenium IDE flows | 3 / 3 passed (login, quick-add task, logout) |

## Results by module

| Module | Test cases | Passed | Failed |
|---|---|---|---|
| Login, session, access control, password recovery | TC01–TC14 | 14 | 0 |
| Admin: users and classes | TC15–TC21 | 7 | 0 |
| Teacher: assignments and grading | TC22–TC26 | 4 | 1 (TC23) |
| Student: submissions, tasks, dashboard, notifications, calendar, progress, profile | TC27–TC36 | 10 | 0 |
| **Total** | **36** | **35** | **1** |

## Exit criteria

| Criterion | Target | Achieved | Met? |
|---|---|---|---|
| Run rate | 100 % | 100 % | Yes |
| Pass rate | ≥ 80 % | 97.2 % | Yes |
| Open Critical defects | 0 | 0 | Yes |
| Suspension threshold | < 40 % failing | 2.8 % failing | Not triggered |

All exit criteria are met.

## Bug summary

| Bug ID | Title | Category | Severity | Priority | Status |
|---|---|---|---|---|---|
| BUG-01 | "Class & subject" required error not shown under the field; vague duplicate banner instead | Validation / UI | Minor | P3 | Open |
| BUG-02 | Double-clicking "Add" in quick-add creates duplicate tasks | Functional | Minor | P3 | Open |

| Severity | Count |
|---|---|
| Critical | 0 |
| Major | 0 |
| Minor | 2 |
| Trivial | 0 |

## Script failures vs application defects

The first execution of the suite had three failures. Each was investigated:

| Test case | First-run failure | Cause | Action |
|---|---|---|---|
| TC25, TC26 | "element click intercepted" on the roster's **Open** button | Script: the button was below the visible area | Script fixed to scroll the button into view; both now pass |
| TC23 | No error message under **Class & subject** | Application defect | Kept as a failing test; reported as BUG-01 |

## Exploratory testing findings

20 exploratory ideas were tried (special characters, very long strings, whitespace-only input,
double submits, Back/Refresh, two tabs, direct URLs and API calls without login, id tampering,
injection-like search text, Unicode, negative marks, upper-case file extensions, and phone/tablet
layouts). 19 behaved correctly and 1 defect was found (BUG-02). Notable positives:

* Every protected page, API endpoint and file download refused access without login or outside
  the user's own scope.
* HTML typed into inputs is displayed as text, never executed.
* Length limits and number ranges are enforced with clear messages.
* No horizontal scrolling at 390 px and 768 px widths.

## Conclusion

EduFlow meets the exit criteria: 100 % of the 36 test cases were executed, 97.2 % passed, and no
Critical or Major defects are open. The two open defects are Minor (an unclear validation message
and duplicate tasks on a double-click) and do not block the main workflows. We recommend fixing
both in the next release (priority P3) and re-running the regression suite, where TC23 is
expected to pass once BUG-01 is fixed.
