"""Exploratory testing session (Step 9). Probes beyond the scripted cases and records what
actually happens, with screenshots in screenshots/EXP_*.png. Results go to
exploratory/observations.json; the write-up is exploratory_testing.md.

Run against the app after the main suite:  python exploratory/explore.py
"""

import json
import sys
import time
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "tests"))
from helpers import ADMIN, PASSWORD, STUDENT, TEACHER, App  # noqa: E402

BASE = "http://127.0.0.1:8000"
OUT = Path(__file__).parent / "observations.json"
FILES = Path(__file__).resolve().parent.parent / "tests" / "files"
notes: list[dict] = []


def new_app(width=1366, height=900) -> App:
    opts = webdriver.ChromeOptions()
    opts.add_argument("--headless=new")
    opts.add_argument(f"--window-size={width},{height}")
    return App(webdriver.Chrome(options=opts), BASE)


def note(probe: str, observed: str, verdict: str) -> None:
    notes.append({"probe": probe, "observed": observed, "verdict": verdict})
    print(f"[{verdict}] {probe}: {observed}")


def task_titles(app: App, query: str) -> list[str]:
    app.open("/student/tasks?q=" + query)
    app.wait.until(EC.invisibility_of_element_located((By.CSS_SELECTOR, "#task-list .loading-state")))
    return [e.text for e in app.driver.find_elements(By.CSS_SELECTOR, "#task-list .task-title")]


def probe(fn):
    try:
        fn()
    except Exception as e:  # keep exploring even if one probe breaks
        note(fn.__name__, f"probe error: {type(e).__name__}: {str(e).splitlines()[0][:200]}", "ERROR")


# ---------------------------------------------------------------------------- probes

def special_characters_in_task_title():
    app = new_app()
    try:
        app.login_as(STUDENT, "/student")
        app.open("/student/tasks")
        payload = "<script>alert('x')</script> Café & \"quotes\" 😀"
        box = app.css("#smart-input")
        box.send_keys(payload)
        box.send_keys(Keys.ENTER)
        app.toast()
        titles = task_titles(app, "Caf")
        alert_opened = False
        try:
            WebDriverWait(app.driver, 1).until(EC.alert_is_present())
            alert_opened = True
        except Exception:
            pass
        app.shot("EXP01_special_chars")
        shown = titles[0] if titles else "(not found)"
        ok = "<script>" in shown and not alert_opened
        note("Special characters / HTML in task title", f"Saved and shown literally as '{shown}'; script executed: {alert_opened}", "OK" if ok else "BUG")
    finally:
        app.driver.quit()


def very_long_description():
    app = new_app()
    try:
        app.login_as(STUDENT, "/student")
        app.open("/student/tasks")
        app.click("[data-action=new-task]")
        app.modal()
        app.modal_type("title", "Long description probe")
        desc = app.modal_field("description")
        app.driver.execute_script("arguments[0].value = 'x'.repeat(6000);", desc)
        app.modal_submit()
        err = app.modal_error("description")
        app.shot("EXP02_long_description")
        note("6000-character task notes (limit 5000)", f"Rejected with field error '{err}'", "OK" if "5000" in err else "BUG")
    finally:
        app.driver.quit()


def double_submit_quick_add():
    app = new_app()
    try:
        app.login_as(STUDENT, "/student")
        app.open("/student/tasks")
        box = app.css("#smart-input")
        title = f"Double click probe {int(time.time())}"
        box.send_keys(title)
        button = app.css("#smart-add button[type=submit]")
        ActionChains(app.driver).double_click(button).perform()
        app.toast()
        app.wait.until(lambda d: d.find_element(By.ID, "smart-input").get_attribute("value") == "")
        count = app.driver.execute_script(
            "return fetch('/api/student/tasks?q=' + encodeURIComponent(arguments[0])).then(r => r.json()).then(j => j.meta.total);", title)
        titles = [title] * count
        app.shot("EXP03_double_submit")
        note("Double-click the quick-add Add button", f"{len(titles)} task(s) created", "BUG" if len(titles) > 1 else "OK")
    finally:
        app.driver.quit()


def double_submit_new_assignment():
    app = new_app()
    try:
        app.login_as(TEACHER, "/teacher")
        app.open("/teacher/assignments")
        app.table_rows()
        app.click("[data-action=create]")
        app.modal()
        title = f"Double save probe {int(time.time())}"
        app.modal_type("title", title)
        app.modal_type("description", "x")
        from selenium.webdriver.support.ui import Select
        Select(app.modal_field("teaching")).select_by_visible_text("FY BSc CS A · Programming Fundamentals")
        save = app.css(".modal.show [data-submit]")
        ActionChains(app.driver).double_click(save).perform()
        app.wait.until(EC.url_matches(r"/teacher/assignments/\d+$"))  # app redirects to the new assignment
        app.css("#assignment-detail .card-header")
        count = app.driver.execute_script(
            "return fetch('/api/teacher/assignments?q=' + encodeURIComponent(arguments[0])).then(r => r.json()).then(j => j.meta.total);", title)
        note("Double-click 'Save as draft' on a new assignment", f"{count} assignment(s) created", "BUG" if count > 1 else "OK")
    finally:
        app.driver.quit()


def back_button_after_logout():
    app = new_app()
    try:
        app.login_as(STUDENT, "/student")
        app.css("#student-stats .stat-card")
        app.logout()
        app.driver.back()
        WebDriverWait(app.driver, 10).until(lambda d: "/login" in d.current_url or d.find_elements(By.CSS_SELECTOR, "#student-stats"))
        # If a cached dashboard appears, its API calls should send the user back to login.
        try:
            WebDriverWait(app.driver, 8).until(EC.url_contains("/login"))
        except Exception:
            pass
        app.shot("EXP04_back_after_logout")
        where = app.path()
        note("Browser Back after logout", f"Ended on {where}", "OK" if where.startswith("/login") else "BUG")
    finally:
        app.driver.quit()


def refresh_after_submission():
    app = new_app()
    try:
        app.login_as("arjun.student@eduflow.test", "/student")
        app.open("/student/assignments?status=pending")
        app.table_rows()
        links = app.driver.find_elements(By.CSS_SELECTOR, "[data-table-body] a")
        if not links:
            note("Refresh after submitting", "No pending assignment available for this student", "SKIPPED")
            return
        links[0].click()
        app.css("#sub-files").send_keys(str(FILES / "answer.pdf"))
        app.click("[data-submit-form] button[type=submit]")
        app.toast()
        app.driver.refresh()
        app.css("#assignment-detail .col-lg-5 .card-header")
        files = app.driver.find_elements(By.XPATH, "//a[normalize-space()='answer.pdf']")
        note("Refresh after submitting an assignment", f"No resubmission prompt; {len(files)} file listed after refresh", "OK" if len(files) == 1 else "BUG")
    finally:
        app.driver.quit()


def direct_urls_without_login():
    app = new_app()
    try:
        results = []
        for path in ["/teacher/assignments/1", "/student/tasks", "/admin/settings", "/profile"]:
            app.open(path)
            app.wait_url_contains("/login")
            results.append(f"{path} -> {app.path()}")
        app.open("/api/admin/users")
        body = app.page_text()
        results.append(f"/api/admin/users -> {body[:60]}")
        app.open("/api/files/submissions/1")
        results.append(f"/api/files/submissions/1 -> {app.page_text()[:60]}")
        ok = all("/login" in r for r in results[:4]) and "log in" in results[4].lower()
        note("Direct URLs without logging in", "; ".join(results), "OK" if ok else "BUG")
    finally:
        app.driver.quit()


def other_users_ids():
    app = new_app()
    try:
        app.login_as("neha.student@eduflow.test", "/student")  # class B student
        app.open("/api/student/assignments/1")  # class A assignment
        body = app.page_text()
        app.open("/api/files/submissions/1")  # class A student's file
        body2 = app.page_text()
        ok = "not found" in body.lower() and "not found" in body2.lower()
        note("Change ids in the URL to another class's assignment / another student's file",
             f"assignment: {body[:50]}; file: {body2[:50]}", "OK" if ok else "BUG")
    finally:
        app.driver.quit()


def sql_like_search():
    app = new_app()
    try:
        app.login_as(ADMIN, "/admin")
        app.open("/admin/users")
        app.table_rows()
        app.css("[data-filter=q]").send_keys("' OR 1=1 --")
        app.wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, "[data-table-body] .empty-state, [data-table-body] .error-state")))
        text = app.css("[data-table-body]").text
        note("SQL-like text in user search", f"Table shows: '{text.strip()[:60]}'", "OK" if "No users match" in text else "BUG")
    finally:
        app.driver.quit()


def unicode_profile_name():
    app = new_app()
    try:
        app.login_as("kabir.student@eduflow.test", "/student")
        app.open("/profile")
        name = app.css("#p-name")
        name.clear()
        name.send_keys("कबीर सिंह")
        app.click("#profile-form button[type=submit]")
        app.toast()
        app.driver.refresh()
        menu = app.css(".btn-profile").text
        note("Unicode (Devanagari) name in profile", f"Saved; header shows '{menu}'", "OK" if "कबीर" in menu else "BUG")
    finally:
        app.driver.quit()


def whitespace_only_task():
    app = new_app()
    try:
        app.login_as(STUDENT, "/student")
        app.open("/student/tasks")
        box = app.css("#smart-input")
        box.send_keys("     ")
        box.send_keys(Keys.ENTER)
        msg = app.css("#smart-preview").text
        note("Whitespace-only quick-add", f"Message: '{msg}'", "OK" if "Type what you need" in msg else "BUG")
    finally:
        app.driver.quit()


def email_with_spaces():
    app = new_app()
    try:
        app.login("  " + STUDENT + "  ")
        WebDriverWait(app.driver, 10).until(lambda d: "/student" in d.current_url or d.find_elements(By.CSS_SELECTOR, ".alert-danger"))
        note("Login email with leading/trailing spaces", f"Ended on {app.path()}", "OK" if app.path() == "/student" else "BUG")
    finally:
        app.driver.quit()


def negative_marks():
    app = new_app()
    try:
        app.login_as(TEACHER, "/teacher")
        app.open("/teacher/submissions")
        rows = app.table_rows()
        app.scroll_click(rows[0].find_element(By.CSS_SELECTOR, "[data-review]"))
        marks = app.css("#g-marks")
        marks.clear()
        marks.send_keys("-5")
        app.click("[data-do=grade]")
        err = app.field_error("marks")
        note("Negative marks", f"Field error '{err}'", "OK" if "at least 0" in err else "BUG")
    finally:
        app.driver.quit()


def uppercase_extension_upload():
    app = new_app()
    try:
        app.login_as("kabir.student@eduflow.test", "/student")
        app.open("/student/assignments?status=pending")
        app.table_rows()
        links = app.driver.find_elements(By.CSS_SELECTOR, "[data-table-body] a")
        if not links:
            note("Upload with upper-case extension (ANSWER.PDF)", "No pending assignment for this student", "SKIPPED")
            return
        links[0].click()
        upper = FILES.parent / "files" / "ANSWER_UPPER.PDF"
        upper.write_bytes((FILES / "answer.pdf").read_bytes())
        app.css("#sub-files").send_keys(str(upper))
        app.click("[data-submit-form] button[type=submit]")
        toast = app.toast()
        note("Upload with upper-case extension (ANSWER_UPPER.PDF)", f"Toast '{toast}'", "OK" if toast.startswith("Submitted") else "BUG")
    finally:
        app.driver.quit()


def login_page_when_logged_in():
    app = new_app()
    try:
        app.login_as(TEACHER, "/teacher")
        app.open("/login")
        app.wait_url_contains("/teacher")
        note("Open /login while already logged in", f"Redirected to {app.path()}", "OK")
    finally:
        app.driver.quit()


def responsive_layouts():
    for width, height, label in [(390, 844, "phone"), (768, 1024, "tablet")]:
        app = new_app(width, height)
        try:
            app.login_as(STUDENT, "/student")
            app.css("#student-stats .stat-card")
            pages = {}
            for path in ["/student", "/student/tasks", "/student/calendar", "/student/progress", "/student/assignments"]:
                app.open(path)
                app.css("h1.page-title")
                app.wait.until(lambda d: not d.find_elements(By.CSS_SELECTOR, ".loading-state"))
                overflow = app.driver.execute_script("return document.documentElement.scrollWidth - window.innerWidth")
                pages[path] = overflow
            menu_hidden = not app.driver.find_element(By.ID, "sidebar").is_displayed()
            app.click("button[data-bs-toggle='offcanvas'][data-bs-target='#sidebar']")
            app.visible(By.CSS_SELECTOR, "#sidebar .nav-link")
            app.shot(f"EXP_{label}_menu")
            app.open("/student")
            app.css("#student-stats .stat-card")
            app.shot(f"EXP_{label}_dashboard")
            bad = {p: o for p, o in pages.items() if o > 1}
            note(f"Responsive layout at {width}px ({label})",
                 f"Horizontal overflow: {bad or 'none'}; sidebar collapsed: {menu_hidden}; menu button opens it",
                 "OK" if not bad else "BUG")
        finally:
            app.driver.quit()


def tab_logout_then_action():
    app = new_app()
    try:
        app.login_as(STUDENT, "/student")
        app.open("/student/tasks")
        app.css("#smart-input")
        app.driver.switch_to.new_window("tab")
        app.open("/student")
        app.logout()
        app.driver.switch_to.window(app.driver.window_handles[0])
        app.css("#smart-input").send_keys("After logout in other tab" + Keys.ENTER)
        WebDriverWait(app.driver, 10).until(lambda d: "/login" in d.current_url or d.find_element(By.ID, "smart-preview").text)
        where = app.path()
        detail = "" if "/login" in where else f"; message '{app.driver.find_element(By.ID, 'smart-preview').text}'"
        note("Logout in one tab, then add a task in another", f"Ended on {where}{detail}", "OK" if "/login" in where else "CHECK")
    finally:
        app.driver.quit()


if __name__ == "__main__":
    only = set(sys.argv[1:])
    for fn in [special_characters_in_task_title, very_long_description, double_submit_quick_add, double_submit_new_assignment,
               back_button_after_logout, refresh_after_submission, direct_urls_without_login, other_users_ids, sql_like_search,
               unicode_profile_name, whitespace_only_task, email_with_spaces, negative_marks, uppercase_extension_upload,
               login_page_when_logged_in, responsive_layouts, tab_logout_then_action]:
        if not only or fn.__name__ in only:
            probe(fn)
    if only and OUT.exists():  # re-running selected probes: replace their earlier entries
        names = {n["probe"] for n in notes}
        previous = [n for n in json.loads(OUT.read_text(encoding="utf-8")) if n["probe"] not in names and n["verdict"] != "ERROR"]
        notes[:0] = previous
    OUT.write_text(json.dumps(notes, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\n{len(notes)} observations written to {OUT}")
