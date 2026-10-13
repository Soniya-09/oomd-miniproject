"""TC15–TC21: admin user and class management."""

from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC

from helpers import ADMIN, PASSWORD


def open_add_student(app):
    app.login_as(ADMIN, "/admin")
    app.open("/admin/students")
    app.table_rows()
    app.click("[data-action=create]")
    app.modal()


def fill_student(app, name, email, password, admission_no):
    app.modal_type("full_name", name)
    app.modal_type("email", email)
    app.modal_type("password", password)
    app.modal_type("admission_no", admission_no)


def search_users(app, path, term):
    app.open(path)
    app.table_rows()
    box = app.css("[data-filter=q]")
    box.send_keys(term)
    app.wait.until(lambda d: all(term.lower() in r.text.lower() for r in d.find_elements(By.CSS_SELECTOR, "[data-table-body] tr"))
                   or d.find_elements(By.CSS_SELECTOR, "[data-table-body] .empty-state"))
    return app.table_rows()


def test_tc15_admin_creates_student(app, actual):
    open_add_student(app)
    fill_student(app, "Test Student", "test.student.stqa@eduflow.test", "Secure123", "STQA001")
    app.shot("TC15_add_student_form")
    app.modal_submit()
    toast = app.toast()
    app.wait_modal_closed()
    rows = search_users(app, "/admin/students", "STQA001")
    assert toast == "Account created."
    assert len(rows) == 1 and "Test Student" in rows[0].text
    actual(f"Toast '{toast}'; student found by admission number")


def test_tc16_duplicate_email(app, actual):
    open_add_student(app)
    fill_student(app, "Duplicate", "priya.student@eduflow.test", "Secure123", "STQA002")
    app.modal_submit()
    error = app.modal_error("email")
    assert error == "An account with this email already exists."
    actual(f"Field error: '{error}'")


def test_tc17_password_seven_characters(app, actual):
    open_add_student(app)
    fill_student(app, "Short Pass", "short.pass@eduflow.test", "Abcde12", "STQA003")
    app.modal_submit()
    error = app.modal_error("password")
    assert error == "Password must be at least 8 characters."
    actual(f"Field error: '{error}'")


def test_tc18_password_eight_characters(app, actual):
    open_add_student(app)
    fill_student(app, "Eight Chars", "eight.chars@eduflow.test", "Abcdef12", "STQA004")
    app.modal_submit()
    toast = app.toast()
    assert toast == "Account created."
    actual(f"8-character password accepted; toast '{toast}'")


def test_tc19_admin_creates_class(app, actual):
    app.login_as(ADMIN, "/admin")
    app.open("/admin/classes")
    app.css("#classes-body a")  # list loaded
    app.click("[data-action=create]")
    app.modal()
    app.modal_type("name", "SY BSc CS")
    app.modal_type("division", "C")
    app.modal_submit()
    toast = app.toast()
    app.wait.until(EC.text_to_be_present_in_element((By.CSS_SELECTOR, "#classes-body"), "SY BSc CS C"))
    app.shot("TC19_class_created")
    assert toast == "Class created."
    actual(f"Toast '{toast}'; 'SY BSc CS C' listed")


def test_tc20_admin_searches_users(app, actual):
    app.login_as(ADMIN, "/admin")
    rows = search_users(app, "/admin/users", "Kulkarni")
    names = [r.find_element(By.CSS_SELECTOR, ".fw-semibold").text for r in rows]
    assert names == ["Meera Kulkarni"]
    actual(f"Results: {names}")


def test_tc21_deactivate_user_blocks_login(app, actual):
    app.login_as(ADMIN, "/admin")
    rows = search_users(app, "/admin/students", "Dev Patil")
    rows[0].find_element(By.CSS_SELECTOR, "[data-action=toggle]").click()
    app.modal()
    app.confirm()
    assert "deactivated" in app.toast().lower()
    app.wait.until(EC.text_to_be_present_in_element((By.CSS_SELECTOR, "[data-table-body]"), "Deactivated"))
    app.shot("TC21_user_deactivated")
    app.logout()
    app.login("dev.student@eduflow.test", PASSWORD)
    error = app.login_error()
    assert "deactivated" in error
    actual(f"Status 'Deactivated' shown; login message: '{error}'")
