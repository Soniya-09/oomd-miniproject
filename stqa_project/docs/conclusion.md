# Conclusion

In this mini project we applied the software testing life cycle to EduFlow, a complete web
application with three user roles. We prepared a test plan with scope, risks, entry/exit criteria
and a schedule; defined a bug taxonomy with categories, severity and priority; designed 36 test
cases covering valid and invalid logins, empty fields, boundary values, each module's main flow,
navigation, logout and session handling; and automated all of them with Selenium WebDriver and
pytest, using explicit waits, stable locators and automatic failure screenshots. We also recorded
three flows in Selenium IDE and explored the application manually.

The final regression run executed all 36 test cases with 35 passing (97.2 %), which meets the
exit criteria of a 100 % run rate and at least an 80 % pass rate. Two Minor defects were found and
reported: a missing field-level validation message on the new-assignment form (BUG-01) and
duplicate tasks created by a double-click in quick-add (BUG-02). Exploratory testing showed that
access control, input escaping, length limits and responsive layouts work correctly.

The project showed us the value of automation for regression testing: the whole suite runs
unattended in about eight minutes from a clean database, so it can be repeated after every change.
It also showed that a failing test must be investigated before it is reported: two of our three
first-run failures were caused by our own scripts, not by the application. Selenium WebDriver with
pytest proved suitable for testing a modern JavaScript-driven web application, and the same suite
can be extended with Selenium Grid for cross-browser testing in the future.
