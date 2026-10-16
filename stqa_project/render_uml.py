"""Render the EduFlow UML SVGs (docs/uml/images/*.svg) to high-resolution PNGs for the OOMD report.

    python render_uml.py

Writes screenshots/UML_<name>.png at 2.5x scale using headless Chrome.
"""

import re
from pathlib import Path

from selenium import webdriver

ROOT = Path(__file__).parent
SRC = ROOT.parent / "docs" / "uml" / "images"
OUT = ROOT / "screenshots"
SCALE = 2.5


def main():
    opts = webdriver.ChromeOptions()
    opts.add_argument("--headless=new")
    opts.add_argument("--hide-scrollbars")
    driver = webdriver.Chrome(options=opts)
    try:
        for svg in sorted(SRC.glob("*.svg")):
            head = svg.read_text(encoding="utf-8")[:2000]
            w = float(re.search(r'width="([\d.]+)px"', head).group(1))
            h = float(re.search(r'height="([\d.]+)px"', head).group(1))
            driver.execute_cdp_cmd("Emulation.setDeviceMetricsOverride",
                                   {"width": int(w) + 1, "height": int(h) + 1, "deviceScaleFactor": SCALE, "mobile": False})
            driver.get(svg.as_uri())
            out = OUT / f"UML_{svg.stem}.png"
            driver.save_screenshot(str(out))
            print("Wrote", out)
    finally:
        driver.quit()


if __name__ == "__main__":
    main()
