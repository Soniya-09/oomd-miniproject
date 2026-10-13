/* Teacher pages. */
(function () {
	"use strict";
	const { api, esc, html, raw, fmt, toast, confirm, formModal, modalShell, Table, badge, priorityBadge, statusBadge, loading, empty, failed, on, showErrors, clearErrors } = window.EF;
	const page = document.querySelector("[data-page]");
	if (!page) return;

	const EXTENSIONS = ["pdf", "doc", "docx", "ppt", "pptx", "xls", "xlsx", "txt", "csv", "png", "jpg", "jpeg", "zip"];
	const classLabel = (r) => `${r.class_name}${r.division ? " " + r.division : ""}`;
	let teachingCache;
	const teaching = () => (teachingCache ??= api("/api/teacher/classes").then((r) => r.data));

	// ================================================================ assignment form
	async function assignmentForm(a, onSaved) {
		const rows = await teaching();
		if (!rows.length) {
			toast("You are not assigned to any class yet. Ask your administrator.", "warning");
			return;
		}
		const editingPublished = a && a.status !== "draft";
		const fields = [
			{ name: "title", label: "Title", required: true, maxlength: 200 },
			{ name: "description", label: "Instructions", type: "textarea", required: true, rows: 6 },
			...(editingPublished ? [] : [{
				name: "teaching", label: "Class & subject", type: "select", required: true, placeholder: "Choose…", col: "col-md-8",
				options: rows.map((r) => ({ value: `${r.class_id}:${r.subject_id}`, label: `${classLabel(r)} · ${r.subject_name}` })),
			}]),
			{ name: "priority", label: "Priority", type: "select", col: editingPublished ? "col-md-6" : "col-md-4", options: [{ value: "low", label: "Low" }, { value: "medium", label: "Medium" }, { value: "high", label: "High" }] },
			{ name: "due_at", label: "Due date & time", type: "datetime", col: "col-md-6" },
			{ name: "max_marks", label: "Maximum marks", type: "number", min: 0, max: 1000, step: "0.5", col: "col-md-3" },
			{ name: "max_upload_mb", label: "Max file size (MB)", type: "number", min: 1, max: 50, col: "col-md-3" },
			{ name: "allowed_extensions", label: "Allowed file types", type: "multiselect", options: EXTENSIONS.map((e) => ({ value: e, label: e })), col: "col-md-6", help: "Ctrl/Cmd-click to pick several." },
			{ name: "requires_submission", label: "Students must submit files", type: "checkbox", col: "col-md-6" },
			{ name: "allow_late", label: "Accept late submissions (marked late)", type: "checkbox", col: "col-md-6" },
			{ name: "allow_resubmission", label: "Allow resubmission after review", type: "checkbox", col: "col-md-6" },
			{ name: "target_all", label: "Whole class", type: "checkbox", col: "col-md-6", help: "Turn off to pick specific students." },
			{ name: "student_ids", label: "Students", type: "multiselect", options: [], col: "col-12" },
		];
		const values = a
			? { ...a, teaching: `${a.class_id}:${a.subject_id}`, student_ids: a.target_student_ids, requires_submission: +a.requires_submission, allow_late: +a.allow_late, allow_resubmission: +a.allow_resubmission, target_all: +a.target_all }
			: { priority: "medium", max_upload_mb: 10, allowed_extensions: ["pdf", "docx"], requires_submission: 1, allow_late: 1, allow_resubmission: 1, target_all: 1 };

		const { el, form } = formModal({
			title: a ? `Edit "${a.title}"` : "New assignment",
			size: "modal-lg",
			fields,
			values,
			submitText: a ? "Save changes" : "Save as draft",
			submit: async (d) => {
				const body = { ...d };
				if (body.teaching) {
					const [c, s] = body.teaching.split(":");
					body.class_id = +c;
					body.subject_id = +s;
				}
				delete body.teaching;
				if (body.target_all) delete body.student_ids;
				body.max_marks = body.max_marks === null ? null : +body.max_marks;
				if (body.max_upload_mb !== null) body.max_upload_mb = +body.max_upload_mb;
				if (!a && el.dataset.publish === "1") body.publish = true;
				let id = a?.id;
				if (a) await api(`/api/teacher/assignments/${a.id}`, { method: "PATCH", body });
				else id = (await api("/api/teacher/assignments", { method: "POST", body })).data.id;
				toast(a ? "Assignment updated." : body.publish ? "Assignment published. Students have been notified." : "Draft saved.");
				onSaved?.(id);
			},
		});

		// "Save & publish" for new assignments.
		if (!a) {
			const pub = document.createElement("button");
			pub.type = "button";
			pub.className = "btn btn-success";
			pub.textContent = "Save & publish";
			pub.addEventListener("click", () => {
				el.dataset.publish = "1";
				el.querySelector("[data-submit]").click();
				setTimeout(() => (el.dataset.publish = "0"));
			});
			el.querySelector(".modal-footer").appendChild(pub);
		}

		// Load class students for targeting when the class changes or "whole class" is turned off.
		const studentsSel = form.elements.student_ids;
		const studentsWrap = studentsSel.closest("div");
		const targetAll = form.elements.target_all;
		const classOf = () => (form.elements.teaching ? form.elements.teaching.value.split(":")[0] : a?.class_id);
		async function refreshStudents() {
			studentsWrap.classList.toggle("d-none", targetAll.checked);
			const cid = classOf();
			if (targetAll.checked || !cid) return;
			const selected = new Set([...studentsSel.selectedOptions].map((o) => o.value).concat((values.student_ids || []).map(String)));
			const { data } = await api(`/api/teacher/classes/${cid}/students`);
			studentsSel.innerHTML = data.map((s) => html`<option value="${s.id}"${raw(selected.has(String(s.id)) ? " selected" : "")}>${s.roll_no ? s.roll_no + ". " : ""}${s.full_name}</option>`).join("");
			studentsSel.size = Math.min(8, Math.max(4, data.length));
		}
		targetAll.addEventListener("change", refreshStudents);
		form.elements.teaching?.addEventListener("change", refreshStudents);
		refreshStudents();
	}

	// ================================================================ review modal
	async function reviewModal(submissionId, onChange) {
		const { el, modal } = modalShell("Review submission", '<div data-body><div class="loading-state">Loading…</div></div>', "", "modal-lg");
		modal.show();
		const body = el.querySelector("[data-body]");

		function render(s) {
			const a = s.assignment;
			const files = s.files.length
				? `<ul class="file-list">${s.files.map((f) => html`<li><i class="bi bi-file-earmark" aria-hidden="true"></i><a href="/api/files/submissions/${f.id}">${f.original_name}</a> <span class="text-muted small">${fmt.bytes(f.size_bytes)} · attempt ${f.attempt}</span></li>`).join("")}</ul>`
				: '<p class="text-muted">No files.</p>';
			const feedback = s.feedback.length
				? s.feedback.map((f) => html`<div class="border rounded p-2 mb-2"><div class="small text-muted">${f.author} · ${fmt.datetime(f.created_at)} · attempt ${f.attempt}</div><div class="prose">${f.comment}</div></div>`).join("")
				: '<p class="text-muted small">No comments yet.</p>';
			const history = s.history.map((h) => html`<li class="small">${fmt.datetime(h.created_at)} — ${h.by_name}: ${h.old_status && h.old_status !== h.new_status ? EF.statusLabel(h.old_status) + " → " : ""}${EF.statusLabel(h.new_status)}${h.new_marks !== null && h.new_marks !== h.old_marks ? ` (marks ${h.new_marks})` : ""}${h.note ? " · " + h.note : ""}</li>`).join("");
			const canGrade = ["submitted", "resubmitted", "under_review", "graded"].includes(s.status);
			const maxAttr = a.max_marks !== null ? raw(` max="${esc(a.max_marks)}"`) : "";
			body.innerHTML = html`
				<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
					<div class="flex-grow-1"><div class="fw-semibold">${s.student.full_name}</div><div class="small text-muted">${s.student.admission_no} · ${s.student.email}</div></div>
					${raw(statusBadge(s.status))} ${raw(s.is_late ? badge("Late", "warning") : badge("On time", "success"))}
					${raw(s.resubmission_requested ? badge("Resubmission requested", "warning") : "")}
				</div>
				<dl class="row small mb-2">
					<dt class="col-sm-3">Assignment</dt><dd class="col-sm-9">${a.title}</dd>
					<dt class="col-sm-3">Submitted</dt><dd class="col-sm-9">${fmt.datetime(s.submitted_at)} (attempt ${s.attempt})</dd>
					<dt class="col-sm-3">Marks</dt><dd class="col-sm-9">${s.marks ?? "—"}${a.max_marks !== null ? " / " + a.max_marks : ""} ${raw(s.status === "graded" && !s.grade_released ? badge("Not released", "muted") : "")}</dd>
					${raw(s.student_note ? html`<dt class="col-sm-3">Student note</dt><dd class="col-sm-9 prose">${s.student_note}</dd>` : "")}
				</dl>
				<h3 class="h6">Files</h3>${raw(files)}
				<h3 class="h6 mt-3">Feedback</h3>${raw(feedback)}
				${raw(canGrade ? html`
				<form class="border-top pt-3 mt-3" data-grade novalidate>
					<div class="alert alert-danger d-none" data-form-error role="alert"></div>
					<div class="row g-2">
						<div class="col-sm-4"><label class="form-label" for="g-marks">Marks${a.max_marks !== null ? ` (of ${a.max_marks})` : ""}</label>
							<input class="form-control" id="g-marks" name="marks" type="number" min="0" step="0.5"${maxAttr} value="${s.marks ?? ""}"><div class="invalid-feedback" data-error-for="marks"></div></div>
						<div class="col-sm-8"><label class="form-label" for="g-fb">Feedback</label>
							<textarea class="form-control" id="g-fb" name="feedback" rows="3"></textarea><div class="invalid-feedback" data-error-for="feedback"></div></div>
						<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="g-release" name="release" checked><label class="form-check-label" for="g-release">Release grade and feedback to the student now</label></div></div>
					</div>
					<div class="d-flex flex-wrap gap-2 mt-3">
						<button class="btn btn-primary" type="button" data-do="grade"><i class="bi bi-check2-circle me-1"></i>Save grade</button>
						<button class="btn btn-outline-warning" type="button" data-do="return"><i class="bi bi-arrow-counterclockwise me-1"></i>Return for revision</button>
						${raw(["submitted", "resubmitted"].includes(s.status) ? '<button class="btn btn-light" type="button" data-do="review">Mark under review</button>' : "")}
						${raw(s.status === "graded" && !s.grade_released ? '<button class="btn btn-success" type="button" data-do="release">Release grade</button>' : "")}
					</div>
				</form>` : html`<p class="text-muted small mt-3 mb-0">${s.status === "returned" ? "Returned to the student. You can review again after they resubmit." : ""}</p>`)}
				<details class="mt-3"><summary class="small">History</summary><ul class="mt-2">${raw(history)}</ul></details>`;

			const form = body.querySelector("[data-grade]");
			form?.querySelectorAll("[data-do]").forEach((b) => b.addEventListener("click", async () => {
				const action = b.dataset.do;
				clearErrors(form);
				if (action === "return" && !(await confirm("Return this work to the student for revision? They will be able to submit again.", { confirmText: "Return", danger: false }))) return;
				b.disabled = true;
				try {
					const payload = action === "grade" ? { marks: form.marks.value === "" ? null : +form.marks.value, feedback: form.feedback.value, release: form.release.checked }
						: action === "return" ? { feedback: form.feedback.value } : {};
					const r = await api(`/api/teacher/submissions/${s.id}/${action}`, { method: "POST", body: payload });
					toast({ grade: "Grade saved.", return: "Returned for revision.", review: "Marked under review.", release: "Grade released." }[action]);
					r.data.assignment = a;
					render(r.data);
					onChange?.();
				} catch (e) {
					showErrors(form, e);
				} finally {
					b.disabled = false;
				}
			}));
		}

		try {
			const { data } = await api(`/api/teacher/submissions/${submissionId}`);
			const { data: a } = await api(`/api/teacher/assignments/${data.assignment_id}`);
			data.assignment = a;
			render(data);
		} catch (e) {
			failed(body, e);
		}
	}

	// ================================================================ assignments list
	if (page.dataset.page === "teacher-assignments") {
		const root = document.getElementById("assignments-table");
		const urlStatus = new URLSearchParams(location.search).get("status");
		const table = new Table(root, {
			url: "/api/teacher/assignments",
			filters: urlStatus ? { status: urlStatus } : {},
			empty: "No assignments yet. Create your first one.",
			columns: [
				{ label: "Title", sort: "title", render: (a) => html`<a class="fw-semibold" href="/teacher/assignments/${a.id}">${a.title}</a><div class="small text-muted">${classLabel(a)} · ${a.subject_name}</div>` },
				{ label: "Status", render: (a) => statusBadge(a.status) },
				{ label: "Priority", render: (a) => priorityBadge(a.priority) },
				{ label: "Due", sort: "due", render: (a) => esc(a.due_at ? fmt.datetime(a.due_at) : "—") },
				{ label: "Submitted", className: "text-end", render: (a) => (+a.requires_submission ? esc(`${a.submitted} / ${a.recipients}`) : '<span class="text-muted">n/a</span>') },
				{ label: "To review", className: "text-end", render: (a) => (+a.to_review ? badge(a.to_review, "info") : "0") },
			],
		});
		const sel = document.getElementById("a-class");
		teaching().then((rows) => rows.forEach((r) => sel.insertAdjacentHTML("beforeend", html`<option value="${r.class_id}:${r.subject_id}">${classLabel(r)} · ${r.subject_name}</option>`)));
		sel.addEventListener("change", () => {
			const [c, s] = sel.value ? sel.value.split(":") : ["", ""];
			Object.assign(table.state, { class_id: c, subject_id: s, page: 1 });
			table.load();
		});
		on(page, "[data-action=create]", () => assignmentForm(null, (id) => (location.href = `/teacher/assignments/${id}`)));
	}

	// ================================================================ assignment detail
	if (page.dataset.page === "teacher-assignment") {
		const id = page.dataset.id;
		const box = document.getElementById("assignment-detail");
		let a;

		async function load() {
			try {
				({ data: a } = await api(`/api/teacher/assignments/${id}`));
			} catch (e) {
				return failed(box, e, load);
			}
			document.title = `${a.title} · EduFlow`;
			const counts = a.roster.reduce((m, r) => ((m[r.status] = (m[r.status] || 0) + 1), m), {});
			const submitted = a.roster.filter((r) => r.submission_id).length;
			const actions = { draft: ["publish", "cancel"], published: ["close", "cancel"], closed: ["reopen"], cancelled: [] }[a.status];
			const actionBtn = { publish: ["btn-success", "send", "Publish"], close: ["btn-outline-secondary", "lock", "Close submissions"], reopen: ["btn-outline-primary", "unlock", "Reopen"], cancel: ["btn-outline-danger", "x-circle", "Cancel assignment"] };
			const editable = a.status !== "cancelled";

			box.innerHTML = html`
			<div class="row g-3">
				<div class="col-lg-8">
					<div class="card">
						<div class="card-header">
							<span>${a.title}</span> ${raw(statusBadge(a.status))} ${raw(priorityBadge(a.priority))}
							<div class="card-actions">
								${raw(editable ? '<button class="btn btn-sm btn-light" data-action="edit"><i class="bi bi-pencil me-1"></i>Edit</button>' : "")}
								${raw(actions.map((k) => `<button class="btn btn-sm ${actionBtn[k][0]}" data-transition="${k}"><i class="bi bi-${actionBtn[k][1]} me-1"></i>${actionBtn[k][2]}</button>`).join(""))}
							</div>
						</div>
						<div class="card-body">
							<dl class="row small mb-3">
								<dt class="col-sm-3">Class · Subject</dt><dd class="col-sm-9">${classLabel(a)} · ${a.subject_name} (${a.subject_code})</dd>
								<dt class="col-sm-3">Due</dt><dd class="col-sm-9">${a.due_at ? fmt.datetime(a.due_at) + " (" + fmt.remaining(a.due_at) + ")" : "No deadline"}</dd>
								<dt class="col-sm-3">Marks</dt><dd class="col-sm-9">${a.max_marks ?? "Not graded with marks"}</dd>
								<dt class="col-sm-3">Submission</dt><dd class="col-sm-9">${+a.requires_submission ? `${a.allowed_extensions.join(", ")} · up to ${a.max_upload_mb} MB · late ${+a.allow_late ? "accepted" : "not accepted"} · resubmission ${+a.allow_resubmission ? "allowed" : "not allowed"}` : "No file submission"}</dd>
								<dt class="col-sm-3">Audience</dt><dd class="col-sm-9">${+a.target_all ? "Whole class" : a.target_student_ids.length + " selected student(s)"}</dd>
							</dl>
							<div class="prose">${a.description}</div>
						</div>
					</div>
				</div>
				<div class="col-lg-4">
					<div class="card mb-3">
						<div class="card-header">Learning materials</div>
						<div class="card-body">
							${raw(a.attachments.length ? `<ul class="file-list">${a.attachments.map((f) => html`<li><i class="bi bi-paperclip" aria-hidden="true"></i><a href="/api/files/attachments/${f.id}">${f.original_name}</a><span class="text-muted small">${fmt.bytes(f.size_bytes)}</span>
								${raw(editable ? html`<button class="btn btn-sm btn-link text-danger ms-auto" data-remove-attachment="${f.id}" aria-label="Remove ${f.original_name}"><i class="bi bi-trash"></i></button>` : "")}</li>`).join("")}</ul>` : '<p class="text-muted small">No files attached.</p>')}
							${raw(editable ? `<form data-attach class="mt-2" novalidate><label class="form-label small" for="att-files">Add files</label><input class="form-control form-control-sm" id="att-files" type="file" name="files" multiple><div class="invalid-feedback" data-error-for="files"></div><button class="btn btn-sm btn-outline-primary mt-2" type="submit">Upload</button></form>` : "")}
						</div>
					</div>
					<div class="stat-grid mb-0">
						<div class="stat-card"><div class="stat-label">Submitted</div><div class="stat-value">${submitted} / ${a.roster.length}</div></div>
						<div class="stat-card is-danger"><div class="stat-label">Missing</div><div class="stat-value">${counts.missing || 0}</div></div>
					</div>
				</div>
			</div>
			<div class="card mt-3">
				<div class="card-header">Students
					<div class="card-actions">
						<label class="visually-hidden" for="roster-filter">Filter students</label>
						<select class="form-select form-select-sm" id="roster-filter">
							<option value="">Everyone</option><option value="submitted,resubmitted,under_review">Awaiting review</option>
							<option value="graded">Graded</option><option value="returned">Returned</option><option value="missing,not_submitted">Not submitted</option>
						</select>
					</div>
				</div>
				<div class="table-responsive"><table class="table align-middle">
					<thead><tr><th>Student</th><th>Status</th><th>Submitted</th><th class="text-end">Files</th><th class="text-end">Marks</th><th class="text-end">Review</th></tr></thead>
					<tbody data-roster></tbody>
				</table></div>
			</div>`;
			renderRoster("");
			box.querySelector("#roster-filter").addEventListener("change", (e) => renderRoster(e.target.value));
		}

		function renderRoster(filter) {
			const set = filter ? filter.split(",") : null;
			const rows = a.roster.filter((r) => !set || set.includes(r.status));
			box.querySelector("[data-roster]").innerHTML = rows.length ? rows.map((r) => html`<tr>
				<td><div class="fw-semibold">${r.full_name}</div><div class="small text-muted">${r.admission_no}${r.roll_no ? " · roll " + r.roll_no : ""}</div></td>
				<td>${raw(statusBadge(r.status))} ${raw(+r.is_late ? badge("Late", "warning") : "")} ${raw(+r.resubmission_requested ? badge("Asked to resubmit", "warning") : "")}</td>
				<td>${r.submitted_at ? fmt.datetime(r.submitted_at) : "—"}</td>
				<td class="text-end">${r.submission_id ? r.file_count : "—"}</td>
				<td class="text-end">${r.marks ?? "—"}${raw(r.status === "graded" && !+r.grade_released ? ' <i class="bi bi-eye-slash text-muted" title="Not released"></i>' : "")}</td>
				<td class="text-end">${raw(r.submission_id ? html`<button class="btn btn-sm btn-outline-primary" data-review="${r.submission_id}">Open</button>` : "")}</td>
			</tr>`).join("") : `<tr><td colspan="6"><div class="empty-state">No students in this view.</div></td></tr>`;
		}

		on(box, "[data-review]", (b) => reviewModal(b.dataset.review, load));
		on(box, "[data-action=edit]", () => assignmentForm(a, load));
		on(box, "[data-transition]", async (b) => {
			const t = b.dataset.transition;
			const msg = {
				publish: "Publish this assignment? Every student in the audience will be notified.",
				close: "Close submissions? Students will no longer be able to submit.",
				reopen: "Reopen this assignment for submissions?",
				cancel: "Cancel this assignment? Students will no longer see it. This cannot be undone.",
			}[t];
			if (!(await confirm(msg, { confirmText: b.textContent.trim(), danger: t === "cancel" }))) return;
			try {
				await api(`/api/teacher/assignments/${id}/${t}`, { method: "POST" });
				toast("Status updated.");
				load();
			} catch (e) {
				toast(e.errors ? Object.values(e.errors).join(" ") : e.message, "danger");
			}
		});
		on(box, "[data-remove-attachment]", async (b) => {
			if (!(await confirm("Remove this file?", { confirmText: "Remove" }))) return;
			await api(`/api/teacher/assignments/${id}/attachments/${b.dataset.removeAttachment}`, { method: "DELETE" });
			load();
		});
		box.addEventListener("submit", async (ev) => {
			const form = ev.target.closest("[data-attach]");
			if (!form) return;
			ev.preventDefault();
			clearErrors(form);
			const fd = new FormData();
			[...form.elements.files.files].forEach((f) => fd.append("files[]", f));
			try {
				await api(`/api/teacher/assignments/${id}/attachments`, { method: "POST", form: fd });
				toast("Uploaded.");
				load();
			} catch (e) {
				showErrors(form, e);
			}
		});
		load();
	}

	// ================================================================ submissions queue
	if (page.dataset.page === "teacher-submissions") {
		const table = new Table(document.getElementById("queue-table"), {
			url: "/api/teacher/submissions",
			filters: { status: new URLSearchParams(location.search).get("status") || "to_review" },
			sort: "submitted",
			empty: "Nothing waiting for review.",
			columns: [
				{ label: "Student", sort: "student", render: (s) => html`<div class="fw-semibold">${s.student_name}</div><div class="small text-muted">${s.admission_no}</div>` },
				{ label: "Assignment", render: (s) => html`<a href="/teacher/assignments/${s.assignment_id}">${s.title}</a><div class="small text-muted">${s.class_name}${s.division ? " " + s.division : ""} · ${s.subject_name}</div>` },
				{ label: "Submitted", sort: "submitted", render: (s) => html`${fmt.datetime(s.submitted_at)} ${raw(+s.is_late ? badge("Late", "warning") : "")}` },
				{ label: "Status", render: (s) => statusBadge(s.status) + (+s.attempt > 1 ? ` <span class="small text-muted">attempt ${esc(s.attempt)}</span>` : "") },
				{ label: "Marks", className: "text-end", render: (s) => esc(s.marks !== null ? `${s.marks}${s.max_marks !== null ? " / " + s.max_marks : ""}` : "—") },
				{ label: "", className: "text-end", render: (s) => html`<button class="btn btn-sm btn-outline-primary" data-review="${s.id}">Review</button>` },
			],
		});
		on(page, "[data-review]", (b) => reviewModal(b.dataset.review, () => table.load()));
	}

	// ================================================================ classes
	if (page.dataset.page === "teacher-classes") {
		const grid = document.getElementById("teacher-classes");
		teaching().then((rows) => {
			if (!rows.length) return empty(grid, "You have not been assigned to any classes yet.", "collection");
			grid.innerHTML = rows.map((r) => html`<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body">
				<div class="small text-muted">${r.subject_code}</div>
				<h2 class="h6 mb-1">${r.subject_name}</h2>
				<div class="mb-3">${classLabel(r)}</div>
				<div class="d-flex gap-3 small mb-3"><span><i class="bi bi-people me-1" aria-hidden="true"></i>${r.student_count} students</span><span><i class="bi bi-journal-text me-1" aria-hidden="true"></i>${r.active_assignments} active</span></div>
				<div class="d-flex flex-wrap gap-2">
					<button class="btn btn-sm btn-outline-primary" data-roster="${r.class_id}" data-label="${classLabel(r)}">View students</button>
					<button class="btn btn-sm btn-light" data-exam="${r.class_id}:${r.subject_id}" data-label="${classLabel(r)} · ${r.subject_name}"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Schedule exam</button>
				</div>
			</div></div></div>`).join("");
		}).catch((e) => failed(grid, e));

		on(grid, "[data-exam]", (b) => {
			const [classId, subjectId] = b.dataset.exam.split(":").map(Number);
			formModal({
				title: `Schedule exam · ${b.dataset.label}`,
				fields: [
					{ name: "title", label: "Title", required: true, placeholder: "Unit test 1" },
					{ name: "room", label: "Room" },
					{ name: "starts_at", label: "Starts", type: "datetime", required: true, col: "col-md-6" },
					{ name: "ends_at", label: "Ends", type: "datetime", required: true, col: "col-md-6" },
				],
				submit: async (d) => {
					await api("/api/exams", { method: "POST", body: { ...d, class_id: classId, subject_id: subjectId } });
					toast("Exam scheduled. Students have been notified and it appears on their calendar.");
				},
			});
		});

		on(grid, "[data-roster]", async (b) => {
			const card = document.getElementById("class-roster-card");
			const box = document.getElementById("class-roster");
			card.classList.remove("d-none");
			document.getElementById("class-roster-title").textContent = `Students · ${b.dataset.label}`;
			loading(box);
			try {
				const { data } = await api(`/api/teacher/classes/${b.dataset.roster}/students`);
				box.innerHTML = data.length ? `<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Roll</th><th>Name</th><th>Admission no.</th><th class="text-end">Missing submissions</th></tr></thead><tbody>` +
					data.map((s) => html`<tr><td>${s.roll_no || "—"}</td><td>${s.full_name}</td><td>${s.admission_no}</td><td class="text-end">${raw(+s.missing >= 2 ? badge(s.missing, "danger") : esc(s.missing))}</td></tr>`).join("") + "</tbody></table></div>"
					: '<div class="empty-state">No students enrolled.</div>';
				card.scrollIntoView({ behavior: "smooth", block: "start" });
			} catch (e) {
				failed(box, e);
			}
		});
	}

	window.EFTeacher = { reviewModal, assignmentForm };
})();
