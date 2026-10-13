# EduFlow — STQA Mini Project

Software Testing and Quality Assurance mini project for **EduFlow**, an academic management and
student productivity web portal (PHP 8 + MySQL 8). Prepared by Soniya Pawar, Sakshi Jagadale and
Priti Merurkar (BE Computer Engineering).

## Contents

| Path | What it is |
|---|---|
| `test_plan.md` | Test plan (strategy, scope, risks, criteria, resources, schedule, deliverables) |
| `bug_taxonomy.md` | Bug categories, severity and priority scales |
| `test_cases.md` | 36 test cases with actual results and status from the latest run |
| `tests/` | Selenium WebDriver + pytest scripts (one test function per test case) |
| `selenium_ide/EduFlow.side` | Selenium IDE project: login, quick-add task, logout |
| `selenium_ide/replay_side.py` | Replays the `.side` flows with WebDriver to check they still pass |
| `exploratory_testing.md` | Exploratory testing sessions and findings |
| `bug_report.md` | Confirmed defects |
| `screenshots/` | Key screenshots from passing tests, and `FAIL_TCxx.png` for failures |
| `reports/test_report.html` | pytest-html report (self-contained) |
| `reports/results.json` | Machine-readable results used to fill `test_cases.md` |
| `reports/final_report.md` | Final test report |
| `STQA_Mini_Project_Report.docx` | Everything assembled into one Word document |

## Prerequisites

* EduFlow set up and seeded (see the main project `README.md`): PHP 8.2+, MySQL 8
* Python 3.11+ and Google Chrome (ChromeDriver is downloaded automatically by Selenium Manager)

## Setup

```bash
cd stqa_project
python -m venv .venv
.venv\Scripts\activate          # Windows  (Linux/macOS: source .venv/bin/activate)
pip install -r requirements.txt
```

## Running the tests

```bash
# from stqa_project/
pytest                      # headless Chrome, HTML report in reports/test_report.html
pytest --headed             # watch the browser
pytest -k tc22              # a single test case
pytest --no-reset           # keep the current database instead of resetting it
python fill_results.py      # copy the results into test_cases.md
python selenium_ide/replay_side.py   # replay the Selenium IDE flows
```

What happens on `pytest`:

1. If nothing is listening on `http://127.0.0.1:8000`, the suite starts PHP's built-in server.
2. The EduFlow database is reset to the fictional demo seed (`php bin/migrate.php --fresh` and
   `php bin/seed.php`) so every run starts from identical data. **This erases any other data in
   the local EduFlow database.** Use `--no-reset` to skip it.
3. Each test opens a fresh Chrome session, logs in as needed and uses explicit waits
   (`WebDriverWait`) only.
4. On failure a screenshot is saved as `screenshots/FAIL_TCxx.png` and embedded in the HTML report.
5. A pass/fail summary is printed at the end and `reports/results.json` is written.

Options: `--base-url URL` (or env `EDUFLOW_URL`), env `EDUFLOW_ROOT` = path to the EduFlow
project if `stqa_project/` is not inside it.

## Selenium IDE

Install the Selenium IDE extension in Chrome or Firefox, choose **Open an existing project** and
select `selenium_ide/EduFlow.side`. Run the suite "EduFlow smoke flows" with the app running on
`http://127.0.0.1:8000`.

## Demo accounts

Password for every account: `Demo@1234`

| Role | Email |
|---|---|
| Admin | admin@eduflow.test |
| Teacher | meera.teacher@eduflow.test |
| Student | priya.student@eduflow.test |
