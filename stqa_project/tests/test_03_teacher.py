"""TC22–TC26: teacher assignment management and grading."""

from datetime import datetime, timedelta

from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC

from helpers import TEACHER

CLASS_SUBJECT = "FY BSc CS A · Programming Fundamentals"


def new_assignment_form(app):
    app.login_as(TEACHER, "/teacher")
    app.open("/teacher/assignments")
    app.table_rows()
    app.click("[data-action=create]")
    app.modal()


def save_and_publish(app):
    app.clickable(By.XPATH, "//div[contains(@class,'modal') and contains(@class,'show')]//button[normalize-space()='Save & publish']").click()


def open_ervdiagram_submission(app, student="Sara Khan"):
    """Teacher → Assignments → 'ER diagram for a library' → review the student's submission."""
    app.login_as(TEACHER, "/teacher")
    app.open("/teacher/assignments")
    app.table_rows()
    app.css("[data-filter=q]").send_keys("ER diagram")
    app.clickable(By.LINK_TEXT, "ER diagram for a library").click()
    row = app.visible(By.XPATH, f"//tbody[@data-roster]//tr[contains(., '{student}')]")
    app.scroll_click(row.find_element(By.CSS_SELECTOR, "[data-review]"))
    app.css("#g-marks")


def test_tc22_create_and_publish_assignment(app, actual):
    new_assignment_form(app)
    due = (datetime.now() + timedelta(days=5)).strftime("%Y-%m-%dT17:00")
    app.modal_type("title", "Recursion practice set")
    app.modal_type("description", "Solve the five recursion problems and upload a PDF.")
    app.modal_select("teaching", text=CLASS_SUBJECT)
    app.modal_set_value("due_at", due)
    app.modal_type("max_marks", "10")
    save_and_publish(app)
    app.wait_url_contains("/teacher/assignments/")
    header = app.css("#assignment-detail .card-header").text
    app.shot("TC22_assignment_published")
    assert "Recursion practice set" in header and "Published" in header
    actual(f"Assignment page opened: '{header.splitlines()[0]}' with Published badge")


def test_tc23_assignment_required_fields(app, actual):
    new_assignment_form(app)
    app.modal_submit()  # "Save as draft" with nothing filled in
    app.modal_error("title")  # wait for the validation response
    errors = {
        f: app.driver.find_element(By.CSS_SELECTOR, f".modal.show [data-error-for='{f}']").text
        for f in ("title", "description", "teaching")
    }
    banner = app.driver.find_element(By.CSS_SELECTOR, ".modal.show [data-form-error]")
    banner_text = banner.text if banner.is_displayed() else ""
    app.shot("TC23_required_fields")
    observed = (f"Title: '{errors['title']}', Instructions: '{errors['description']}', "
                f"Class & subject: '{errors['teaching'] or 'no message'}', top banner: '{banner_text or 'none'}'")
    actual(observed)
    assert all(e == "This field is required." for e in errors.values()), observed


def test_tc24_publish_without_due_date(app, actual):
    new_assignment_form(app)
    app.modal_type("title", "No deadline task")
    app.modal_type("description", "Anything.")
    app.modal_select("teaching", text=CLASS_SUBJECT)
    save_and_publish(app)
    error = app.modal_error("due_at")
    assert "Set a due date before publishing" in error
    actual(f"Field error: '{error}'")


def test_tc25_marks_above_maximum(app, actual):
    open_ervdiagram_submission(app)
    app.css("#g-marks").send_keys("21")
    app.click("[data-do=grade]")
    error = app.field_error("marks")
    assert error == "Marks cannot exceed 20."
    actual(f"Field error: '{error}'")


def test_tc26_grade_submission(app, actual):
    open_ervdiagram_submission(app)
    marks = app.css("#g-marks")
    marks.clear()
    marks.send_keys("18")
    app.css("#g-fb").send_keys("Clear entities and relationships.")
    app.click("[data-do=grade]")
    toast = app.toast()
    app.wait.until(EC.text_to_be_present_in_element((By.CSS_SELECTOR, ".modal.show [data-body]"), "Graded"))
    app.shot("TC26_graded")
    assert toast == "Grade saved."
    actual(f"Toast '{toast}'; status badge 'Graded', marks 18 / 20")
