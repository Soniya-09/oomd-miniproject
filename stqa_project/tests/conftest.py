"""pytest fixtures: app server, database reset, Chrome driver, screenshots and result capture."""

import base64
import json
import os
import subprocess
import time
import urllib.request
from pathlib import Path

import pytest
from selenium import webdriver

from helpers import SCREENSHOTS, App

PROJECT = Path(__file__).resolve().parent.parent
APP_ROOT = Path(os.environ.get("EDUFLOW_ROOT", PROJECT.parent))
RESULTS: dict[str, dict] = {}


def pytest_addoption(parser):
    parser.addoption("--base-url", default=os.environ.get("EDUFLOW_URL", "http://127.0.0.1:8000"))
    parser.addoption("--headed", action="store_true", help="show the browser window")
    parser.addoption("--no-reset", action="store_true", help="do not reset the database to the demo seed")


def _up(url: str) -> bool:
    try:
        with urllib.request.urlopen(url + "/login", timeout=3) as r:
            return r.status == 200
    except Exception:
        return False


@pytest.fixture(scope="session")
def base_url(request):
    url = request.config.getoption("--base-url").rstrip("/")
    server = None
    if not _up(url):
        # Start PHP's built-in server for the run if nothing is listening.
        host = url.split("//", 1)[1]
        server = subprocess.Popen(
            ["php", "-S", host, "-t", "public", "public/index.php"],
            cwd=APP_ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        for _ in range(50):
            if _up(url):
                break
            time.sleep(0.2)  # waiting for a process to start, not for the UI
        else:
            server.terminate()
            pytest.exit(f"EduFlow is not reachable at {url}", returncode=3)
    yield url
    if server:
        server.terminate()


@pytest.fixture(scope="session", autouse=True)
def fresh_database(request, base_url):
    """Reset to the demo seed so every full run starts from the same data."""
    if request.config.getoption("--no-reset"):
        return
    for cmd in (["php", "bin/migrate.php", "--fresh"], ["php", "bin/seed.php"]):
        result = subprocess.run(cmd, cwd=APP_ROOT, capture_output=True, text=True)
        if result.returncode != 0:
            pytest.exit(f"Database reset failed: {' '.join(cmd)}\n{result.stdout}{result.stderr}", returncode=3)


@pytest.fixture
def driver(request):
    opts = webdriver.ChromeOptions()
    if not request.config.getoption("--headed"):
        opts.add_argument("--headless=new")
    opts.add_argument("--window-size=1366,900")
    opts.add_argument("--disable-search-engine-choice-screen")
    drv = webdriver.Chrome(options=opts)
    yield drv
    drv.quit()


@pytest.fixture
def app(driver, base_url):
    return App(driver, base_url)


@pytest.fixture
def actual(request):
    """Call actual("what happened") to record the observed result for test_cases.md."""
    def record(text: str) -> None:
        request.node.user_properties.append(("actual", text))
    return record


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item, call):
    outcome = yield
    report = outcome.get_result()
    if report.when != "call" and not (report.when == "setup" and report.failed):
        return
    extras = getattr(report, "extras", [])
    tc = item.name.split("_")[1].upper() if item.name.startswith("test_tc") else item.name
    if report.failed and "driver" in item.fixturenames:
        drv = item.funcargs.get("driver")
        if drv is not None:
            SCREENSHOTS.mkdir(exist_ok=True)
            path = SCREENSHOTS / f"FAIL_{tc}.png"
            drv.save_screenshot(str(path))
            try:
                from pytest_html import extras as html_extras
                extras.append(html_extras.png(base64.b64encode(path.read_bytes()).decode()))
            except ImportError:
                pass
    report.extras = extras
    actual = dict(report.user_properties).get("actual") if report.passed else None
    message = None
    if report.failed:
        message = str(call.excinfo.value).splitlines()[0][:300] if call.excinfo else "failed"
    RESULTS[tc] = {
        "test": item.nodeid,
        "status": "Pass" if report.passed else "Fail",
        "actual": actual or message,
        "duration_s": round(report.duration, 2),
    }


def pytest_sessionfinish(session, exitstatus):
    if not RESULTS:
        return
    out = PROJECT / "reports" / "results.json"
    out.parent.mkdir(exist_ok=True)
    out.write_text(json.dumps(dict(sorted(RESULTS.items())), indent=2), encoding="utf-8")


def pytest_terminal_summary(terminalreporter):
    if not RESULTS:
        return
    passed = sum(1 for r in RESULTS.values() if r["status"] == "Pass")
    total = len(RESULTS)
    terminalreporter.section("EduFlow STQA summary")
    terminalreporter.write_line(f"Total: {total}   Passed: {passed}   Failed: {total - passed}   Pass rate: {passed / total * 100:.1f}%")
    for tc, r in sorted(RESULTS.items()):
        terminalreporter.write_line(f"  {tc}: {r['status']}")
