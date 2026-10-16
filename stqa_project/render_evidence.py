"""Create the code/output screenshots for the Word report with Selenium.

Renders source files and the saved console output as terminal-style HTML pages, opens the
pytest-html report, and saves PNGs in screenshots/ (CODE_*.png, OUTPUT_*.png).

    python render_evidence.py
"""

import html
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait

ROOT = Path(__file__).parent
SHOTS = ROOT / "screenshots"

PY_KEYWORDS = r"\b(def|class|return|import|from|as|for|in|if|else|elif|with|assert|try|except|finally|yield|lambda|not|and|or|is|None|True|False|raise|pass)\b"


TOKEN = re.compile(r'(?P<c>#.*$)|(?P<s>[rf]?"[^"\n]*"|[rf]?\'[^\'\n]*\')|(?P<d>@[\w.]+)|(?P<k>' + PY_KEYWORDS + r")", re.M)


def highlight(code: str) -> str:
    """Single pass over the raw source, escaping each piece, so markup is never re-matched."""
    out, pos = [], 0
    for m in TOKEN.finditer(code):
        out.append(html.escape(code[pos:m.start()]))
        out.append(f'<span class="{m.lastgroup}">{html.escape(m.group())}</span>')
        pos = m.end()
    out.append(html.escape(code[pos:]))
    return "".join(out)


def page(title: str, body: str) -> str:
    return f"""<!doctype html><meta charset="utf-8"><style>
body{{margin:0;background:#1e1e1e;font:13px/1.45 Consolas,monospace;color:#d4d4d4}}
.bar{{padding:8px 14px;background:#2d2d2d;color:#ccc;font-family:Segoe UI,sans-serif;border-bottom:1px solid #3c3c3c}}
pre{{margin:0;padding:14px 18px;white-space:pre-wrap}}
.k{{color:#c586c0}}.s{{color:#ce9178}}.c{{color:#6a9955;font-style:italic}}.d{{color:#dcdcaa}}
.pass{{color:#4ec9b0}}.fail{{color:#f48771;font-weight:bold}}.hdr{{color:#569cd6;font-weight:bold}}
</style><div class="bar"><b>{html.escape(title)}</b></div><pre>{body}</pre>"""


def snap(driver, html_text: str, name: str, width=1100):
    with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False, encoding="utf-8") as f:
        f.write(html_text)
    driver.set_window_size(width, 900)
    driver.get(Path(f.name).as_uri())
    height = driver.execute_script("return document.body.scrollHeight")
    driver.set_window_size(width, min(height + 20, 3000))
    driver.save_screenshot(str(SHOTS / name))
    Path(f.name).unlink()


def code_excerpt(path: Path, start: str, lines: int) -> str:
    text = path.read_text(encoding="utf-8").splitlines()
    idx = next(i for i, l in enumerate(text) if start in l)
    return "\n".join(text[idx: idx + lines])


def colour_console(text: str) -> str:
    out = []
    for line in html.escape(text).splitlines():
        if "PASSED" in line or line.strip().endswith(": Pass"):
            line = f'<span class="pass">{line}</span>'
        elif "FAILED" in line or line.strip().endswith(": Fail") or line.startswith("E "):
            line = f'<span class="fail">{line}</span>'
        elif line.startswith("=") or "Total:" in line:
            line = f'<span class="hdr">{line}</span>'
        out.append(line)
    return "\n".join(out)


def main():
    SHOTS.mkdir(exist_ok=True)
    opts = webdriver.ChromeOptions()
    opts.add_argument("--headless=new")
    driver = webdriver.Chrome(options=opts)
    try:
        t = ROOT / "tests"
        snap(driver, page("tests/helpers.py", highlight(code_excerpt(t / "helpers.py", "class App:", 62))), "CODE_helpers.png")
        snap(driver, page("tests/conftest.py", highlight(code_excerpt(t / "conftest.py", "@pytest.fixture(scope=\"session\", autouse=True)", 30))), "CODE_conftest.png")
        snap(driver, page("tests/test_01_auth.py", highlight(code_excerpt(t / "test_01_auth.py", "def test_tc04_wrong_password", 62))), "CODE_test_auth.png")
        snap(driver, page("tests/test_03_teacher.py", highlight(code_excerpt(t / "test_03_teacher.py", "def test_tc22_create_and_publish_assignment", 40))), "CODE_test_teacher.png")

        console = (ROOT / "reports" / "console_output.txt").read_text(encoding="utf-8", errors="replace")
        lines = console.splitlines()
        keep = [l for l in lines if "PASSED" in l or "FAILED" in l or l.startswith(("=", "Total", "  TC", "platform", "rootdir", "collected", "- Generated"))]
        snap(driver, page("PS D:\\oomd-miniproject\\stqa_project> pytest", colour_console("\n".join(keep))), "OUTPUT_console.png", 1250)

        if "--skip-side" in sys.argv:  # replay needs the EduFlow server running
            side = None
        else:
            side = subprocess.run([sys.executable, str(ROOT / "selenium_ide" / "replay_side.py")], capture_output=True, text=True, cwd=ROOT)
        if side and side.stdout.strip():
            snap(driver, page("PS D:\\oomd-miniproject\\stqa_project> python selenium_ide\\replay_side.py",
                              colour_console(side.stdout.replace("PASS ", "PASSED ").replace("FAIL ", "FAILED "))), "OUTPUT_side_replay.png", 900)

        driver.set_window_size(1300, 1000)
        driver.get((ROOT / "reports" / "test_report.html").as_uri())
        WebDriverWait(driver, 10).until(EC.presence_of_element_located((By.ID, "results-table")))
        driver.save_screenshot(str(SHOTS / "OUTPUT_html_report.png"))
    finally:
        driver.quit()
    print("Evidence screenshots written to", SHOTS)


if __name__ == "__main__":
    main()
