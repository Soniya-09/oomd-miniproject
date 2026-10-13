"""Replay EduFlow.side with Selenium WebDriver to check the recorded flows still work.

Supports the commands used in the file: open, setWindowSize, click, type, assertText,
assertTitle, assertElementPresent, waitForElementVisible, waitForElementPresent.

    python selenium_ide/replay_side.py [--headed]
"""

import json
import sys
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


def locator(target: str):
    kind, _, value = target.partition("=")
    return {
        "id": (By.ID, value),
        "css": (By.CSS_SELECTOR, value),
        "xpath": (By.XPATH, value),
        "linkText": (By.LINK_TEXT, value),
        "name": (By.NAME, value),
    }[kind]


def run_test(driver, base, test) -> list[str]:
    log = []
    for c in test["commands"]:
        cmd, target, value = c["command"], c["target"], c["value"]
        wait = WebDriverWait(driver, int(value) / 1000 if cmd.startswith("waitFor") and value else 10)
        if cmd == "open":
            driver.get(base + target)
        elif cmd == "setWindowSize":
            w, h = target.split("x")
            driver.set_window_size(int(w), int(h))
        elif cmd == "click":
            wait.until(EC.element_to_be_clickable(locator(target))).click()
        elif cmd == "type":
            el = wait.until(EC.visibility_of_element_located(locator(target)))
            el.clear()
            el.send_keys(value)
        elif cmd == "waitForElementVisible":
            wait.until(EC.visibility_of_element_located(locator(target)))
        elif cmd == "waitForElementPresent":
            wait.until(EC.presence_of_element_located(locator(target)))
        elif cmd == "assertText":
            text = wait.until(EC.visibility_of_element_located(locator(target))).text
            assert text == value, f"{target}: expected {value!r}, got {text!r}"
        elif cmd == "assertTitle":
            assert driver.title == target, f"title: expected {target!r}, got {driver.title!r}"
        elif cmd == "assertElementPresent":
            assert driver.find_elements(*locator(target)), f"{target} not present"
        else:
            raise ValueError(f"Unsupported command {cmd}")
        log.append(f"    ok  {cmd} {target} {value}".rstrip())
    return log


def main() -> int:
    side = json.loads((Path(__file__).parent / "EduFlow.side").read_text(encoding="utf-8"))
    base = side["url"].rstrip("/")
    failed = 0
    for test in side["tests"]:
        opts = webdriver.ChromeOptions()
        if "--headed" not in sys.argv:
            opts.add_argument("--headless=new")
        driver = webdriver.Chrome(options=opts)  # fresh session per test, like persistSession: false
        try:
            log = run_test(driver, base, test)
            print(f"PASS  {test['name']} ({len(log)} commands)")
        except Exception as e:  # report and continue with the next flow
            failed += 1
            print(f"FAIL  {test['name']}: {e}")
        finally:
            driver.quit()
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
