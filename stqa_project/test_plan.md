# Test Plan — EduFlow Academic Management & Productivity Portal

| | |
|---|---|
| Project | STQA Mini Project, BE Computer Engineering |
| Application under test | EduFlow (PHP 8.4, MySQL 8, Bootstrap 5, Chart.js) |
| URL under test | http://127.0.0.1:8000 (local) |
| Prepared by | Soniya Pawar, Sakshi Jagadale, Priti Merurkar |
| Version / date | 1.0 / 12 October 2026 |

## Introduction

**Purpose.** This plan describes how the team will test EduFlow, a web portal that combines
school/college administration, assignment distribution and grading, and a student productivity
dashboard. It defines what will be tested, how, by whom, when, and how we decide that testing is
complete.

**Scope.** Black-box functional testing of the web user interface for the three user roles
(Admin, Teacher, Student), automated with Selenium WebDriver (Python + pytest) and supported by
Selenium IDE recordings and exploratory testing. The application source code is not modified.

**About the application.** EduFlow has three roles:

* **Admin** manages users, classes, subjects, academic sessions, reports and settings.
* **Teacher** creates and publishes assignments, reviews submissions, grades and returns work.
* **Student** views and submits assignments, manages personal tasks, and sees a calendar,
  notifications and progress charts.

## 1 Test Strategy

### 1.1 Scope

**Features to be tested**

| Module | Roles | Description |
|---|---|---|
| Login & authentication | All | Valid/invalid login, empty fields, case-insensitive email, lockout after repeated failures, role-based redirect |
| Password recovery | All | Forgot-password request, email format validation, invalid reset link |
| Session & access control | All | Logout, back-navigation after logout, direct URL access without login, role restrictions (403) |
| User management | Admin | Create student, duplicate email, password boundary values, search, deactivate account |
| Class management | Admin | Create class |
| Assignment management | Teacher | Create and publish assignment, required-field validation, "due date required to publish" rule |
| Grading | Teacher | Grade a submission, marks above maximum rejected |
| Assignment submission | Student | Upload valid PDF, reject disallowed file type |
| Personal tasks | Student | Quick-add from a sentence, complete with checkbox, title length boundary |
| Dashboard & navigation | Student | Summary cards, card drill-down navigation |
| Notifications | Student | Unread badge, "Mark all as read" |
| Calendar | Student | Month view rendering, switch to agenda view |
| Progress analytics | Student | Charts render from data, period filter |
| Profile | All | Update name, change password with wrong current password |

**Features not to be tested**

| Feature | Reason |
|---|---|
| Email delivery of password-reset links | Mail is written to a log file in the test environment; no mail server |
| Scheduled reminder job (`bin/reminders.php`) | Command-line job, not part of the web UI; covered by the developers' unit tests |
| CSV report contents and CSV user import | File download/upload contents are outside the UI scope of this project |
| Performance and load testing | No load-testing tool in scope; a single local server is not representative |
| Cross-browser testing beyond Chrome | Only Google Chrome is available on the test machine |
| Attendance, parent access | Not implemented in the application |

### 1.2 Test types

| Type | What we will do |
|---|---|
| **Integration testing** | Verify that UI, server and database work together, e.g. a student submission appears in the teacher's review queue, publishing creates notifications |
| **System testing** | End-to-end tests of complete user journeys for every role on the running application |
| **Regression testing** | The automated Selenium suite is re-run after every fix or script change to confirm nothing else broke |
| **Exploratory testing** | Time-boxed sessions probing special characters, long strings, double submits, back/refresh, direct URLs, and small screen sizes |

### 1.3 Risks and mitigation

| Risk | Impact | Mitigation |
|---|---|---|
| Tests change data (new users, submissions), so a second run behaves differently | Flaky or failing re-runs | Reset the database to the demo seed before every full run (`php bin/migrate.php --fresh` + `php bin/seed.php`) |
| Login lockout (5 failed attempts in 15 min) blocks accounts used by other tests | Unrelated tests fail | Lockout test uses a dedicated account not used elsewhere; database reset clears counters |
| Timing issues with JavaScript-loaded content | False failures | Explicit waits (`WebDriverWait`) on visible elements; no fixed sleeps |
| ChromeDriver / Chrome version mismatch | Suite cannot start | Selenium Manager downloads the matching driver automatically |
| Team members unfamiliar with Selenium | Slow script writing | Shared helper module (login, waits, screenshots) and Selenium IDE recordings as examples |
| Limited time before submission | Incomplete testing | Prioritise login, access control and the assignment workflow first |

### 1.4 Test logistics

| Activity | Who | When |
|---|---|---|
| Requirement study, test plan, bug taxonomy | Soniya Pawar | Day 1 |
| Test case design | Sakshi Jagadale, Priti Merurkar | Day 1–2 |
| Selenium WebDriver scripts | Soniya Pawar, Sakshi Jagadale | Day 2–3 |
| Selenium IDE recordings | Priti Merurkar | Day 3 |
| Test execution and regression runs | All | Day 3–4 |
| Exploratory testing and bug reporting | Priti Merurkar, Sakshi Jagadale | Day 4 |
| Final test report and documentation | Soniya Pawar | Day 5 |

## 2 Test Objective

* Verify that every feature in scope works as intended for each role.
* Verify input validation: required fields, formats, and boundary values.
* Verify that access control prevents unauthenticated and unauthorised access.
* Find and document defects with clear reproduction steps, severity and priority.
* Provide an automated regression suite that can be re-run after changes.
* Decide, using the criteria below, whether the application is ready for release.

## 3 Test Criteria

### Suspension criteria

Testing is suspended if **40 % or more of the executed test cases fail**, or if a blocker
(the application does not start, login is impossible for all roles) is found. Testing resumes
after the development team fixes the blocking defects.

### Exit criteria

| Criterion | Target |
|---|---|
| Run rate (test cases executed ÷ total) | **100 %** |
| Pass rate (test cases passed ÷ executed) | **≥ 80 %** |
| Open Critical defects | 0 |

## 4 Resource Planning

### System resources

| Resource | Details |
|---|---|
| Server / test machine | Windows 11, Intel/AMD x64, 8 GB RAM or more |
| Application stack | PHP 8.4 built-in server, MySQL 8.0 |
| Browser | Google Chrome 154 (headless and normal) |
| Automation | Python 3.13, Selenium 4.27, pytest 8.3, pytest-html 4.1 |
| Recording tool | Selenium IDE browser extension |
| Documentation | Markdown, Microsoft Word |
| Version control | Git, GitHub |

### Human resources

| Member | Role | Responsibilities |
|---|---|---|
| Soniya Pawar | Test lead | Plan, automation framework, final report |
| Sakshi Jagadale | Test engineer | Test cases, automation scripts, exploratory testing |
| Priti Merurkar | Test engineer | Test cases, Selenium IDE, bug reports |

## 5 Schedule and Estimation

| Task | Members | Man-hours |
|---|---|---|
| Understand the application and requirements | All | 6 |
| Write test plan and bug taxonomy | Soniya Pawar | 4 |
| Design test cases | Sakshi Jagadale, Priti Merurkar | 6 |
| Build automation framework and scripts | Soniya Pawar, Sakshi Jagadale | 12 |
| Record Selenium IDE flows | Priti Merurkar | 2 |
| Execute tests and regression runs | All | 4 |
| Exploratory testing and bug reports | Sakshi Jagadale, Priti Merurkar | 5 |
| Final test report and Word document | Soniya Pawar | 4 |
| **Total** | | **43** |

## 6 Test Deliverables

| Stage | Deliverables |
|---|---|
| Before testing | Test plan (`test_plan.md`), bug taxonomy (`bug_taxonomy.md`), test cases (`test_cases.md`) |
| During testing | Selenium WebDriver scripts (`tests/`), Selenium IDE file (`selenium_ide/`), screenshots (`screenshots/`), execution logs |
| After testing | Executed test cases with results, HTML test report (`reports/`), bug report (`bug_report.md`), exploratory testing notes (`exploratory_testing.md`), final report (`reports/final_report.md`), Word report (`STQA_Mini_Project_Report.docx`) |
