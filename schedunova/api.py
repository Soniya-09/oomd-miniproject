# Copyright (c) 2026, Soniya Pawar and contributors
# For license information, please see license.txt

import frappe
from frappe import _
from frappe.rate_limiter import rate_limit
from frappe.utils.password import update_password


@frappe.whitelist(allow_guest=True, methods=["POST"])
@rate_limit(limit=5, seconds=60 * 60)
def sign_up(email: str, full_name: str, password: str):
	"""Create a Website User with a usable password (no email verification
	round-trip). Called from the ScheduNova signup page."""
	email = (email or "").strip().lower()
	full_name = (full_name or "").strip()

	if not email or not full_name or not password:
		frappe.throw(_("Email, name and password are all required."))

	from frappe.utils import validate_email_address
	validate_email_address(email, throw=True)

	if frappe.db.exists("User", email):
		frappe.throw(_("An account with this email already exists. Try logging in."))

	user = frappe.get_doc(
		{
			"doctype": "User",
			"email": email,
			"first_name": full_name,
			"enabled": 1,
			"user_type": "Website User",
			"send_welcome_email": 0,
		}
	)
	user.flags.ignore_permissions = True
	user.flags.no_welcome_mail = True
	user.insert(ignore_permissions=True)

	# frappe validates password strength here and throws if too weak
	update_password(user.name, password)

	return {"ok": True, "user": user.name}


@frappe.whitelist()
def me():
	"""Current session user, or 401 for guests."""
	if frappe.session.user == "Guest":
		frappe.throw(_("Not logged in"), frappe.AuthenticationError)
	user = frappe.get_doc("User", frappe.session.user)
	return {"email": user.name, "full_name": user.full_name}


@frappe.whitelist()
def get_csrf_token():
	"""Frontend fetches this once after login and sends it back as
	X-Frappe-CSRF-Token on every write request."""
	return {"csrf_token": frappe.sessions.get_csrf_token()}
