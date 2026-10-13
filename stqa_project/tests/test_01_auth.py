"""TC01–TC14: login, logout, session, access control and password recovery."""

from selenium.webdriver.common.by import By

from helpers import ADMIN, PASSWORD, STUDENT, TEACHER


def test_tc01_valid_login_admin(app, actual):
    app.login(ADMIN)
    app.wait_url_contains("/admin")
    title = app.css("h1.page-title").text
    app.shot("TC01_admin_overview")
    assert app.path() == "/admin"
    assert title == "Overview"
    actual(f"Redirected to {app.path()}; heading '{title}'")


def test_tc02_valid_login_teacher(app, actual):
    app.login(TEACHER)
    app.wait_url_contains("/teacher")
    title = app.css("h1.page-title").text
    app.shot("TC02_teacher_dashboard")
    assert app.path() == "/teacher" and title == "Dashboard"
    actual(f"Redirected to {app.path()}; heading '{title}'")


def test_tc03_valid_login_student(app, actual):
    app.login(STUDENT)
    app.wait_url_contains("/student")
    cards = app.css("#student-stats .stat-card")
    count = len(app.driver.find_elements(By.CSS_SELECTOR, "#student-stats .stat-card"))
    app.shot("TC03_student_dashboard")
    assert app.path() == "/student"
    assert cards.is_displayed() and count == 7
    actual(f"Redirected to /student; {count} summary cards shown")


def test_tc04_wrong_password(app, actual):
    app.login(STUDENT, "WrongPass1")
    error = app.login_error()
    assert app.path() == "/login"
    assert "Incorrect email or password" in error
    actual(f"Stayed on /login; message: '{error}'")


def test_tc05_unregistered_email(app, actual):
    app.login("nobody@eduflow.test", "Whatever123")
    error = app.login_error()
    assert app.path() == "/login"
    assert "Incorrect email or password" in error
    actual(f"Stayed on /login; same generic message: '{error}'")


def test_tc06_empty_fields(app, actual):
    app.login("", "")
    error = app.login_error()
    assert app.path() == "/login"
    assert error
    actual(f"Not logged in; message: '{error}'")


def test_tc07_email_case_insensitive(app, actual):
    app.login(STUDENT.upper())
    app.wait_url_contains("/student")
    assert app.path() == "/student"
    actual("Upper-case email accepted; redirected to /student")


def test_tc08_lockout_after_five_failures(app, actual):
    email = "isha.student@eduflow.test"  # used only by this test
    for _ in range(5):
        app.login(email, "WrongPass1")
        assert "Incorrect email or password" in app.login_error()
    app.login(email, PASSWORD)
    error = app.login_error()
    app.shot("TC08_lockout")
    assert "Too many login attempts" in error
    actual(f"5 failures accepted as errors; 6th (correct password) refused: '{error}'")


def test_tc09_logout_ends_session(app, actual):
    app.login_as(STUDENT, "/student")
    app.logout()
    after_logout = app.path()
    app.open("/student")
    app.wait_url_contains("/login")
    assert after_logout.startswith("/login")
    assert app.path().startswith("/login")
    actual(f"After logout at {after_logout}; reopening /student redirected to {app.path()}")


def test_tc10_direct_url_without_login(app, actual):
    app.open("/admin/users")
    app.wait_url_contains("/login")
    assert app.path() == "/login?next=%2Fadmin%2Fusers"
    actual(f"Redirected to {app.path()}")


def test_tc11_student_cannot_open_admin_page(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/admin/users")
    heading = app.css("h1").text
    app.shot("TC11_access_denied")
    assert "403" in heading and "Access denied" in heading
    actual(f"Page shows '{heading}'")


def test_tc12_forgot_password_valid_email(app, actual):
    app.open("/forgot-password")
    app.css("#email").send_keys(STUDENT)
    app.click("form button[type=submit]")
    app.wait_url_contains("/login")
    notice = app.css(".alert-success").text
    assert "If an account exists" in notice
    actual(f"Redirected to /login with notice: '{notice}'")


def test_tc13_forgot_password_invalid_email(app, actual):
    app.open("/forgot-password")
    app.css("#email").send_keys("not-an-email")
    app.click("form button[type=submit]")
    error = app.css(".alert-danger").text
    assert error == "Enter a valid email address."
    actual(f"Message: '{error}'")


def test_tc14_reset_with_invalid_token(app, actual):
    app.open("/reset-password?token=abc123")
    warning = app.css(".alert-warning").text
    link = app.css("a[href='/forgot-password']").text
    assert "invalid or has expired" in warning
    actual(f"Message: '{warning}'; link offered: '{link}'")
