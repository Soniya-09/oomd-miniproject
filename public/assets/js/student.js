/* Student assignment pages. */
(function () {
	"use strict";
	const { api, esc, html, raw, fmt, toast, confirm, formModal, Table, badge, priorityBadge, statusBadge, failed, on, showErrors, clearErrors } = window.EF;
	const page = document.querySelector("[data-page]");
	if (!page) return;

	const STUDENT_LABEL = { pending: "Not submitted", missing: "Missing", no_submission: "No submission needed" };
	const studentBadge = (s) => (STUDENT_LABEL[s] ? badge(STUDENT_LABEL[s], s === "missing" ? "danger" : "muted") : statusBadge(s));

	// ================================================================ list
	if (page.dataset.page === "student-assignments") {
		const params = new URLSearchParams(location.search);
		const filters = {};
		["status", "subject_id", "q"].forEach((k) => params.get(k) && (filters[k] = params.get(k)));
		const table = new Table(document.getElementById("student-assignments-table"), {
			url: "/api/student/assignments",
			filters,
			sort: "due",
			empty: "No assignments match these filters.",
			columns: [
				{ label: "Assignment", sort: "title", render: (a) => html`<a class="fw-semibold" href="/student/assignments/${a.id}">${a.title}</a><div class="small text-muted">${a.subject_name}</div>` },
				{ label: "Due", sort: "due", render: (a) => (a.due_at ? html`${fmt.datetime(a.due_at)}<div class="small ${new Date(a.due_at) < new Date() ? "text-danger" : "text-muted"}">${fmt.remaining(a.due_at)}</div>` : '<span class="text-muted">No deadline</span>') },
				{ label: "Priority", sort: "priority", render: (a) => priorityBadge(a.priority) },
				{ label: "Status", render: (a) => studentBadge(a.student_status) + (+a.is_late ? " " + badge("Late", "warning") : "") + (a.assignment_status === "closed" ? " " + badge("Closed", "muted") : "") },
				{ label: "Marks", className: "text-end", render: (a) => esc(a.marks !== null ? `${a.marks}${a.max_marks !== null ? " / " + a.max_marks : ""}` : "—") },
			],
		});
		// Subject filter options from the student's own assignments.
		api("/api/student/subjects").then(({ data }) => {
			const sel = document.getElementById("sa-subject");
			data.forEach((s) => sel.insertAdjacentHTML("beforeend", html`<option value="${s.id}">${s.name}</option>`));
			if (filters.subject_id) sel.value = filters.subject_id;
		}).catch(() => {});
		window.EFStudentTable = table;
	}

	// ================================================================ detail
	if (page.dataset.page === "student-assignment") {
		const id = page.dataset.id;
		const box = document.getElementById("assignment-detail");

		async function load() {
			let a;
			try {
				({ data: a } = await api(`/api/student/assignments/${id}`));
			} catch (e) {
				return failed(box, e, load);
			}
			document.title = `${a.title} · EduFlow`;
			const s = a.submission;
			const overdue = a.due_at && new Date(a.due_at) < new Date();

			const submissionCard = !a.requires_submission
				? '<div class="card"><div class="card-body text-muted">This assignment does not need a file submission.</div></div>'
				: html`<div class="card">
					<div class="card-header">Your submission ${raw(studentBadge(s ? s.status : a.student_status))} ${raw(s?.is_late ? badge("Late", "warning") : "")}</div>
					<div class="card-body">
						${raw(s ? html`
							<dl class="row small">
								<dt class="col-5">Last submitted</dt><dd class="col-7">${fmt.datetime(s.submitted_at)}</dd>
								${raw(s.attempt > 1 ? html`<dt class="col-5">First submitted</dt><dd class="col-7">${fmt.datetime(s.first_submitted_at)}</dd><dt class="col-5">Attempt</dt><dd class="col-7">${s.attempt}</dd>` : "")}
								${raw(s.marks !== null ? html`<dt class="col-5">Marks</dt><dd class="col-7 fw-semibold">${s.marks}${a.max_marks !== null ? " / " + a.max_marks : ""}</dd>` : "")}
							</dl>
							<ul class="file-list mb-3">${raw(s.files.map((f) => html`<li><i class="bi bi-file-earmark" aria-hidden="true"></i><a href="/api/files/submissions/${f.id}">${f.original_name}</a><span class="small text-muted">attempt ${f.attempt} · ${fmt.bytes(f.size_bytes)}</span></li>`).join(""))}</ul>` : "")}
						${raw(a.can_submit ? html`
							<form data-submit-form novalidate>
								<div class="alert alert-danger d-none" data-form-error role="alert"></div>
								${raw(overdue ? '<div class="alert alert-warning py-2 small">The deadline has passed. Your submission will be marked late.</div>' : "")}
								${raw(s && ["submitted", "resubmitted"].includes(s.status) ? '<div class="alert alert-info py-2 small">Uploading again replaces your current files. You can do this until your teacher starts reviewing.</div>' : "")}
								<label class="form-label" for="sub-files">${s?.status === "returned" ? "Upload revised work" : "Upload your work"}</label>
								<input class="form-control" id="sub-files" type="file" name="files" multiple accept="${a.allowed_extensions.map((e) => "." + e).join(",")}" required>
								<div class="form-text">Allowed: ${a.allowed_extensions.join(", ")} · up to ${a.max_upload_mb} MB each</div>
								<div class="invalid-feedback" data-error-for="files"></div>
								<label class="form-label mt-2" for="sub-note">Note to teacher (optional)</label>
								<textarea class="form-control" id="sub-note" name="note" rows="2" maxlength="2000"></textarea>
								<button class="btn btn-primary mt-3" type="submit"><i class="bi bi-upload me-1"></i>${s ? (s.status === "returned" ? "Resubmit" : "Replace submission") : "Submit"}</button>
							</form>` : "")}
						${raw(!a.can_submit && !s ? `<p class="text-muted mb-0">${a.status === "closed" ? "Submissions are closed." : overdue && !a.allow_late ? "The deadline has passed and late work is not accepted." : ""}</p>` : "")}
						${raw(s?.status === "graded" && a.allow_resubmission && !s.resubmission_requested ? '<button class="btn btn-sm btn-outline-secondary mt-2" data-request-resubmit>Request resubmission</button>' : "")}
						${raw(s?.resubmission_requested ? '<p class="small text-muted mt-2 mb-0">Resubmission requested. Your teacher will reopen the work if they agree.</p>' : "")}
					</div>
				</div>`;

			const feedback = s?.feedback?.length
				? html`<div class="card mt-3"><div class="card-header">Feedback</div><div class="card-body">${raw(s.feedback.map((f) => html`<div class="mb-3"><div class="small text-muted">${f.author} · ${fmt.datetime(f.created_at)} · attempt ${f.attempt}</div><div class="prose">${f.comment}</div></div>`).join(""))}</div></div>`
				: "";

			box.innerHTML = html`
			<div class="row g-3">
				<div class="col-lg-7">
					<div class="card">
						<div class="card-header">${a.title} ${raw(priorityBadge(a.priority))} ${raw(a.status === "closed" ? badge("Closed", "muted") : "")}</div>
						<div class="card-body">
							<dl class="row small">
								<dt class="col-sm-3">Subject</dt><dd class="col-sm-9">${a.subject_name} (${a.subject_code}) · ${a.teacher_name}</dd>
								<dt class="col-sm-3">Due</dt><dd class="col-sm-9 ${overdue ? "text-danger fw-semibold" : ""}">${a.due_at ? `${fmt.datetime(a.due_at)} · ${fmt.remaining(a.due_at)}` : "No deadline"}</dd>
								${raw(a.max_marks !== null ? html`<dt class="col-sm-3">Max marks</dt><dd class="col-sm-9">${a.max_marks}</dd>` : "")}
							</dl>
							<h2 class="h6">Instructions</h2>
							<div class="prose mb-3">${a.description}</div>
							${raw(a.attachments.length ? html`<h2 class="h6">Materials</h2><ul class="file-list">${raw(a.attachments.map((f) => html`<li><i class="bi bi-paperclip" aria-hidden="true"></i><a href="/api/files/attachments/${f.id}">${f.original_name}</a><span class="small text-muted">${fmt.bytes(f.size_bytes)}</span></li>`).join(""))}</ul>` : "")}
						</div>
					</div>
				</div>
				<div class="col-lg-5">${raw(submissionCard)}${raw(feedback)}</div>
			</div>`;

			const form = box.querySelector("[data-submit-form]");
			form?.addEventListener("submit", async (ev) => {
				ev.preventDefault();
				clearErrors(form);
				const files = form.elements.files.files;
				if (!files.length) return showErrors(form, { errors: { files: "Choose at least one file." } });
				const fd = new FormData();
				[...files].forEach((f) => fd.append("files[]", f));
				fd.append("note", form.elements.note.value);
				const btn = form.querySelector("[type=submit]");
				btn.disabled = true;
				btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Uploading…';
				try {
					await api(`/api/student/assignments/${id}/submission`, { method: "POST", form: fd });
					toast("Submitted. Your teacher can see it now.");
					EF.refreshBell();
					load();
				} catch (e) {
					showErrors(form, e);
					btn.disabled = false;
					btn.textContent = "Submit";
				}
			});
			box.querySelector("[data-request-resubmit]")?.addEventListener("click", () => formModal({
				title: "Request resubmission",
				submitText: "Send request",
				fields: [{ name: "reason", label: "Why do you need to resubmit?", type: "textarea", required: true, rows: 3 }],
				submit: async (d) => {
					await api(`/api/student/assignments/${id}/resubmission-request`, { method: "POST", body: d });
					toast("Request sent to your teacher.");
					load();
				},
			}));
		}
		load();
	}

	window.EFStudent = { studentBadge };
})();
