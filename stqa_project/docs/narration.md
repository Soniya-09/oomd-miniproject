# Narration of Regression Scripts

The regression suite lives in `stqa_project/tests/` and is run with a single command, `pytest`.
Every run starts from the same data, so the same 36 checks can be repeated after any change to
the application to confirm that nothing that worked before has broken.

## How a run works

1. **Start-up (`conftest.py`).** pytest loads the fixtures. The `base_url` fixture checks that
   EduFlow answers on `http://127.0.0.1:8000`; if it does not, it starts PHP's built-in server.
   The `fresh_database` fixture runs `php bin/migrate.php --fresh` and `php bin/seed.php`, which
   rebuild the database with the fictional demo institution. This makes every regression run
   independent of earlier runs.
2. **A new browser per test.** The `driver` fixture opens a fresh headless Chrome window
   (1366 × 900) through Selenium Manager, which downloads a ChromeDriver that matches the
   installed Chrome. Each test therefore starts logged out with no cookies, and is closed at the
   end even if it fails.
3. **Page actions (`helpers.py`).** The `App` class wraps the driver. `login()` types the email
   and password into `#email` and `#password` and clicks the submit button. `visible()`,
   `clickable()` and `toast()` use `WebDriverWait` with `expected_conditions`, waiting up to 10
   seconds for the element instead of sleeping. Modal forms are filled by field name
   (`modal_type`, `modal_select`), and validation messages are read from the
   `[data-error-for=…]` element under each field.
4. **Checks and evidence.** Each test asserts the expected outcome (URL, heading, toast message,
   field error, list contents) and calls `actual(...)` to record what it observed. Key passing
   steps save screenshots such as `TC22_assignment_published.png`. When an assertion fails, a hook
   saves `screenshots/FAIL_TCxx.png` and embeds it in the HTML report.
5. **Reporting.** pytest-html writes `reports/test_report.html`. A custom summary prints totals and
   each test case's status in the console, and `reports/results.json` stores the observed results,
   which `fill_results.py` copies into `test_cases.md`.

## Scripts and what they cover

| Script | Test cases | Narration |
|---|---|---|
| `test_01_auth.py` | TC01–TC14 | Logs in as each role and checks the landing page; tries a wrong password, an unknown email, empty fields and an upper-case email; fails five times on one account to trigger the lockout; logs out and checks that protected pages redirect to login; opens admin pages without logging in and as a student (403); requests a password reset with valid and invalid emails; opens a reset link with a bad token. |
| `test_02_admin.py` | TC15–TC21 | Logs in as admin, opens **Add student**, and creates a valid student, then tries a duplicate email and passwords of 7 and 8 characters (boundary values). Creates a class, searches users by name, and deactivates a student before checking that the student can no longer log in. |
| `test_03_teacher.py` | TC22–TC26 | Logs in as a teacher and creates an assignment with a due date, then uses **Save & publish** and checks the Published badge. Submits the empty form to check required-field messages, and publishes without a due date to check that rule. Opens a student's submission from the roster and enters marks above the maximum (boundary), then valid marks with feedback. |
| `test_04_student.py` | TC27–TC36 | Logs in as students, uploads a valid PDF and a disallowed `.txt` file, quick-adds a task from a sentence, ticks a task complete, types 250 characters into a 200-character title, clicks the Overdue dashboard card, marks all notifications as read, switches calendar views, checks that the five progress charts render and respond to the period filter, and updates the profile and tries a wrong current password. |

## Design choices

* **Selenium 4 syntax only:** `driver.find_element(By.CSS_SELECTOR, …)`, never the removed
  `find_element_by_*` methods.
* **Stable locators:** element ids (`#email`), form field names (`[name='title']`) and the
  application's `data-*` attributes, rather than layout-dependent XPath.
* **Explicit waits only:** no `time.sleep` in any test. The one short pause in `conftest.py` waits
  for the PHP server process to start, not for the user interface.
* **Independent tests:** each test logs in itself and uses its own data (for example a dedicated
  account for the lockout test), so tests can run alone (`pytest -k tc22`) or in any order.

## Fixing scripts vs reporting bugs

The first run had three failures. TC25 and TC26 failed because the roster's **Open** button was
below the visible part of the page, so Chrome refused the click. That was a script problem; the
script now scrolls the button into view (`scroll_click`). TC23 failed because the application does
not show the **Class & subject** required-field message under the field. That is a real defect,
so the script was left strict and the defect is reported as BUG-01.
