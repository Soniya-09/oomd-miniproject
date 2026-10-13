"""Reusable Selenium helpers: waits, login/logout, modals, toasts and screenshots.

Everything uses explicit waits (WebDriverWait + expected_conditions); there is no time.sleep.
"""

from pathlib import Path

from selenium.webdriver.common.by import By
from selenium.webdriver.remote.webdriver import WebDriver
from selenium.webdriver.remote.webelement import WebElement
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import Select, WebDriverWait

PASSWORD = "Demo@1234"
ADMIN = "admin@eduflow.test"
TEACHER = "meera.teacher@eduflow.test"
STUDENT = "priya.student@eduflow.test"

TIMEOUT = 10
SCREENSHOTS = Path(__file__).resolve().parent.parent / "screenshots"


class App:
    """Thin wrapper around the driver with the base URL and common actions."""

    def __init__(self, driver: WebDriver, base_url: str):
        self.driver = driver
        self.base = base_url.rstrip("/")
        self.wait = WebDriverWait(driver, TIMEOUT)

    # ------------------------------------------------------------- navigation
    def open(self, path: str) -> None:
        self.driver.get(self.base + path)

    def path(self) -> str:
        return self.driver.current_url.replace(self.base, "") or "/"

    def wait_url_contains(self, fragment: str) -> None:
        self.wait.until(EC.url_contains(fragment))

    # ------------------------------------------------------------- finding
    def visible(self, by: str, value: str) -> WebElement:
        return self.wait.until(EC.visibility_of_element_located((by, value)))

    def clickable(self, by: str, value: str) -> WebElement:
        return self.wait.until(EC.element_to_be_clickable((by, value)))

    def css(self, selector: str) -> WebElement:
        return self.visible(By.CSS_SELECTOR, selector)

    def click(self, selector: str) -> None:
        self.clickable(By.CSS_SELECTOR, selector).click()

    def scroll_click(self, element: WebElement) -> None:
        """Scroll an element to the middle of the viewport, wait until clickable, then click."""
        self.driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", element)
        self.wait.until(EC.element_to_be_clickable(element)).click()

    def text_present(self, selector: str, text: str) -> bool:
        return self.wait.until(EC.text_to_be_present_in_element((By.CSS_SELECTOR, selector), text))

    def page_text(self) -> str:
        return self.driver.find_element(By.TAG_NAME, "body").text

    # ------------------------------------------------------------- auth
    def login(self, email: str, password: str = PASSWORD) -> None:
        self.open("/login")
        self.css("#email").clear()
        self.css("#email").send_keys(email)
        self.css("#password").clear()
        self.css("#password").send_keys(password)
        self.click("form button[type=submit]")

    def login_as(self, email: str, landing: str) -> None:
        self.login(email)
        self.wait_url_contains(landing)
        self.css("h1.page-title")

    def logout(self) -> None:
        self.click(".btn-profile")
        self.clickable(By.XPATH, "//button[normalize-space()='Log out']").click()
        self.wait_url_contains("/login")

    def login_error(self) -> str:
        return self.css(".alert-danger").text

    # ------------------------------------------------------------- page widgets
    def toast(self) -> str:
        """Text of the most recent toast notification."""
        el = self.wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, "#toasts .toast:last-child .toast-body")))
        return el.text

    def modal(self) -> WebElement:
        return self.wait.until(EC.visibility_of_element_located((By.CSS_SELECTOR, ".modal.show")))

    def modal_field(self, name: str) -> WebElement:
        return self.visible(By.CSS_SELECTOR, f".modal.show [name='{name}']")

    def modal_type(self, name: str, value: str) -> None:
        field = self.modal_field(name)
        field.clear()
        field.send_keys(value)

    def modal_select(self, name: str, *, text: str | None = None, value: str | None = None) -> None:
        sel = Select(self.modal_field(name))
        if text is not None:
            sel.select_by_visible_text(text)
        else:
            sel.select_by_value(value)

    def modal_set_value(self, name: str, value: str) -> None:
        """Set inputs like datetime-local whose typing format depends on the OS locale."""
        field = self.modal_field(name)
        self.driver.execute_script(
            "arguments[0].value = arguments[1]; arguments[0].dispatchEvent(new Event('change', {bubbles: true}));",
            field, value,
        )

    def modal_error(self, field: str) -> str:
        el = self.visible(By.CSS_SELECTOR, f".modal.show [data-error-for='{field}']")
        return el.text

    def field_error(self, field: str) -> str:
        return self.visible(By.CSS_SELECTOR, f"[data-error-for='{field}']").text

    def modal_submit(self) -> None:
        self.click(".modal.show [data-submit]")

    def wait_modal_closed(self) -> None:
        self.wait.until(EC.invisibility_of_element_located((By.CSS_SELECTOR, ".modal.show")))

    def confirm(self) -> None:
        self.click(".modal.show [data-ok]")

    def table_rows(self, root: str = "[data-table-body]") -> list[WebElement]:
        """Rows of a server-paginated table once loading has finished."""
        self.wait.until(EC.invisibility_of_element_located((By.CSS_SELECTOR, f"{root} .loading-state")))
        return self.driver.find_elements(By.CSS_SELECTOR, f"{root} tr")

    # ------------------------------------------------------------- evidence
    def shot(self, name: str) -> Path:
        SCREENSHOTS.mkdir(exist_ok=True)
        path = SCREENSHOTS / f"{name}.png"
        self.driver.save_screenshot(str(path))
        return path
