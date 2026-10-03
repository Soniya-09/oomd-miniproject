# Copyright (c) 2026, Soniya Pawar and contributors
# For license information, please see license.txt

import frappe
from frappe.model.document import Document


class NovaTask(Document):
	def validate(self):
		if self.duration_min is not None and self.duration_min <= 0:
			frappe.throw("Duration must be a positive number of minutes.")
