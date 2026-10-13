"""TC27–TC36: student submissions, personal tasks, dashboard, notifications, calendar, progress, profile."""

from pathlib import Path

from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select

from helpers import STUDENT

FILES = Path(__file__).resolve().parent / "files"


def open_assignment(app, email, title):
    app.login_as(email, "/student")
    app.open("/student/assignments")
    app.table_rows()
    app.clickable(By.LINK_TEXT, title).click()
    app.wait_url_contains("/student/assignments/")


def test_tc27_submit_valid_pdf(app, actual):
    open_assignment(app, STUDENT, "Normalization case study")
    app.css("#sub-files").send_keys(str(FILES / "answer.pdf"))
    app.css("#sub-note").send_keys("Submitted by automated test.")
    app.click("[data-submit-form] button[type=submit]")
    toast = app.toast()
    app.wait.until(EC.text_to_be_present_in_element((By.CSS_SELECTOR, "#assignment-detail"), "answer.pdf"))
    status = app.css("#assignment-detail .col-lg-5 .card-header").text
    app.shot("TC27_submitted")
    assert toast.startswith("Submitted")
    assert "Submitted" in status
    actual(f"Toast '{toast}'; card shows '{status}' and answer.pdf")


def test_tc28_reject_txt_file(app, actual):
    open_assignment(app, "sara.student@eduflow.test", "Normalization case study")
    app.css("#sub-files").send_keys(str(FILES / "notes.txt"))
    app.click("[data-submit-form] button[type=submit]")
    error = app.field_error("files")
    assert "is not an allowed file type" in error
    assert "Not submitted" in app.css("#assignment-detail .col-lg-5 .card-header").text
    actual(f"Field error: '{error}'; status still 'Not submitted'")


def test_tc29_quick_add_task(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/student/tasks")
    app.css("#task-list li")
    box = app.css("#smart-input")
    box.send_keys("Revise OS notes tomorrow 6pm high priority")
    preview = app.css("#smart-preview .chip").text
    box.send_keys(Keys.ENTER)
    toast = app.toast()
    item = app.visible(By.XPATH, "//ul[@id='task-list']//li[.//a[normalize-space()='Revise OS notes']]")
    app.shot("TC29_quick_add")
    assert toast == 'Added "Revise OS notes".'
    assert "High" in item.text
    actual(f"Preview '{preview}'; toast '{toast}'; listed with High priority")


def test_tc30_complete_personal_task(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/student/tasks?kind=personal&status=pending")
    item = app.visible(By.CSS_SELECTOR, "#task-list li.kind-personal")
    title = item.find_element(By.CSS_SELECTOR, ".task-title").text
    item.find_element(By.CSS_SELECTOR, "[data-complete]").click()
    toast = app.toast()
    app.open("/student/tasks?kind=personal&status=completed&q=" + title.replace(" ", "+"))
    done = app.visible(By.XPATH, f"//ul[@id='task-list']//li[.//a[normalize-space()='{title}']]")
    assert "marked complete" in toast
    assert "is-done" in done.get_attribute("class")
    actual(f"'{title}' ticked; toast '{toast}'; now in Completed list")


def test_tc31_task_title_max_length(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/student/tasks")
    app.click("[data-action=new-task]")
    app.modal()
    app.modal_field("title").send_keys("A" * 250)
    length = len(app.modal_field("title").get_attribute("value"))
    app.modal_submit()
    toast = app.toast()
    assert length == 200
    assert toast == "Task added."
    actual(f"Typed 250 characters, field kept {length}; toast '{toast}'")


def test_tc32_dashboard_card_navigation(app, actual):
    app.login_as(STUDENT, "/student")
    card = app.clickable(By.CSS_SELECTOR, "#student-stats a[href='/student/tasks?status=overdue']")
    count = card.find_element(By.CSS_SELECTOR, ".stat-value").text
    card.click()
    app.wait_url_contains("/student/tasks")
    selected = Select(app.css("#t-status")).first_selected_option.text
    app.css("#task-list li")
    assert "status=overdue" in app.path()
    assert selected == "Overdue"
    actual(f"Overdue card ({count}) opened {app.path()} with filter '{selected}'")


def test_tc33_mark_all_notifications_read(app, actual):
    app.login_as(STUDENT, "/student")
    badge = app.css("#notif-count").text
    app.open("/notifications")
    app.css("#notification-list .notif-item")
    app.click("[data-action=read-all]")
    toast = app.toast()
    app.wait.until(lambda d: "d-none" in d.find_element(By.ID, "notif-count").get_attribute("class"))
    unread = app.driver.find_elements(By.CSS_SELECTOR, "#notification-list .notif-item.is-unread")
    assert toast.startswith("Marked") and int(badge) > 0
    assert not unread
    actual(f"Badge was {badge}; toast '{toast}'; badge hidden, no unread items")


def test_tc34_calendar_views(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/student/calendar")
    app.css(".cal-grid")
    cells = len(app.driver.find_elements(By.CSS_SELECTOR, ".cal-cell"))
    events = len(app.driver.find_elements(By.CSS_SELECTOR, ".cal-event"))
    app.shot("TC34_calendar_month")
    app.click("label[for=cv-agenda]")
    app.css("#calendar-body .agenda-day, #calendar-body .empty-state")
    agenda_days = len(app.driver.find_elements(By.CSS_SELECTOR, ".agenda-day"))
    assert cells == 42
    actual(f"Month grid with {cells} days and {events} events; agenda view shows {agenda_days} days")


def test_tc35_progress_charts_and_filter(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/student/progress")
    rng = app.css("#pf-range")
    app.wait.until(lambda d: rng.text.strip() != "")
    before = rng.text
    app.wait.until(EC.invisibility_of_element_located((By.CSS_SELECTOR, ".chart-state .spinner-border")))
    canvases = len(app.driver.find_elements(By.CSS_SELECTOR, ".chart-box canvas"))
    errors = app.driver.find_elements(By.CSS_SELECTOR, ".chart-state .text-danger")
    app.shot("TC35_progress")
    Select(app.css("#pf-period")).select_by_visible_text("This week")
    app.wait.until(lambda d: d.find_element(By.ID, "pf-range").text != before)
    after = app.css("#pf-range").text
    assert canvases == 5 and not errors
    actual(f"{canvases} charts rendered, no errors; range '{before}' changed to '{after}'")


def test_tc36_profile_update_and_wrong_password(app, actual):
    app.login_as(STUDENT, "/student")
    app.open("/profile")
    name = app.css("#p-name")
    name.clear()
    name.send_keys("Priya S. Sharma")
    app.click("#profile-form button[type=submit]")
    toast = app.toast()
    app.css("#pw-current").send_keys("NotMyPassword1")
    app.css("#pw-new").send_keys("NewSecure123")
    app.css("#pw-confirm").send_keys("NewSecure123")
    app.click("#password-form button[type=submit]")
    error = app.field_error("current_password")
    app.driver.refresh()
    saved = app.css("#p-name").get_attribute("value")
    assert toast == "Profile saved."
    assert error == "Current password is incorrect."
    assert saved == "Priya S. Sharma"
    actual(f"Toast '{toast}'; name persisted after refresh; password error '{error}'")
