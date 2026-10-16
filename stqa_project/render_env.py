"""Create the environment-setup screenshot (ENV_versions.png) from real tool version output.

    python render_env.py
"""

import subprocess
import sys

from selenium import webdriver

from render_evidence import SHOTS, colour_console, page, snap

COMMANDS = [
    ([sys.executable, "--version"], "python --version"),
    ([sys.executable, "-m", "pip", "show", "selenium", "pytest", "pytest-html"], "pip show selenium pytest pytest-html"),
    (["php", "-v"], "php -v"),
    (["mysql", "--version"], "mysql --version"),
]


def main():
    out = []
    for cmd, shown in COMMANDS:
        res = subprocess.run(cmd, capture_output=True, text=True)
        text = (res.stdout or res.stderr).strip()
        if shown.startswith("pip show"):
            text = "\n".join(l for l in text.splitlines() if l.startswith(("Name:", "Version:", "---")))
        out.append(f"PS D:\\oomd-miniproject\\stqa_project> {shown}\n{text}\n")
    opts = webdriver.ChromeOptions()
    opts.add_argument("--headless=new")
    driver = webdriver.Chrome(options=opts)
    try:
        snap(driver, page("Windows PowerShell — tool versions", colour_console("\n".join(out))), "ENV_versions.png", 900)
    finally:
        driver.quit()
    print("Wrote", SHOTS / "ENV_versions.png")


if __name__ == "__main__":
    main()
