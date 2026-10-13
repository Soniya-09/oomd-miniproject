(function () {
	"use strict";
	const { api, toast, showErrors, clearErrors, ApiError } = window.EF;

	const profile = document.getElementById("profile-form");
	profile?.addEventListener("submit", async (ev) => {
		ev.preventDefault();
		clearErrors(profile);
		try {
			await api("/api/me", { method: "PATCH", body: { full_name: profile.full_name.value, phone: profile.phone.value } });
			toast("Profile saved.");
		} catch (e) {
			showErrors(profile, e);
		}
	});

	const pw = document.getElementById("password-form");
	pw?.addEventListener("submit", async (ev) => {
		ev.preventDefault();
		clearErrors(pw);
		try {
			if (pw.new_password.value !== pw.confirm_password.value) {
				throw new ApiError("The passwords do not match.", 422, { confirm_password: "The passwords do not match." });
			}
			await api("/api/me/password", { method: "POST", body: { current_password: pw.current_password.value, new_password: pw.new_password.value } });
			pw.reset();
			toast("Password updated.");
		} catch (e) {
			showErrors(pw, e);
		}
	});
})();
