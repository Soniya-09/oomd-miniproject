/* Admin pages. Each block runs only on its own page (data-page). */
(function () {
	"use strict";
	const { api, esc, html, raw, fmt, toast, confirm, formModal, Table, badge, statusBadge, loading, empty, failed, on } = window.EF;
	const page = document.querySelector("[data-page]");
	if (!page) return;

	const activeBadge = (on) => (on ? badge("Active", "success") : badge("Deactivated", "muted"));
	const classLabel = (c) => (c.division ? `${c.name} ${c.division}` : c.name);

	// Shared lookups, fetched once per page.
	const cache = {};
	const lookup = (key, url) => (cache[key] ??= api(url).then((r) => r.data));
	const departments = () => lookup("departments", "/api/admin/departments");
	const sessions = () => lookup("sessions", "/api/admin/sessions");
	const classes = () => lookup("classes", "/api/admin/classes");
	const subjects = () => lookup("subjects", "/api/admin/subjects");

	// ================================================================ overview
	if (page.dataset.page === "admin-overview") {
		const stats = document.getElementById("admin-stats");
		api("/api/admin/overview")
			.then(({ data: d }) => {
				const card = (href, icon, label, value, sub = "", cls = "") =>
					html`<a class="stat-card ${cls}" href="${href}"><div class="stat-label"><i class="bi bi-${icon}" aria-hidden="true"></i>${label}</div><div class="stat-value">${value}</div><div class="stat-sub">${sub}</div></a>`;
				stats.innerHTML = [
					card("/admin/students", "mortarboard", "Students", d.students, "active accounts"),
					card("/admin/teachers", "person-workspace", "Teachers", d.teachers, "active accounts"),
					card("/admin/classes", "collection", "Classes", d.classes),
					card("/admin/subjects", "book", "Subjects", d.subjects),
					card("/admin/reports", "journal-text", "Active assignments", d.active_assignments, "published"),
					card("/admin/reports", "check2-circle", "Submission rate", fmt.pct(d.submission_rate), "due assignments, all classes", "is-success"),
				].join("");
			})
			.catch((e) => failed(stats, e));
		window.EFCharts?.adminOverview();
	}

	// ================================================================ users
	if (page.dataset.page === "admin-users") {
		const fixedRole = page.dataset.role;
		const root = document.getElementById("users-table");
		if (fixedRole === "student") {
			classes().then((list) => {
				const sel = document.getElementById("user-class");
				list.forEach((c) => sel.insertAdjacentHTML("beforeend", html`<option value="${c.id}">${classLabel(c)} (${c.session_name})</option>`));
			});
		}
		const table = new Table(root, {
			url: "/api/admin/users",
			filters: fixedRole ? { role: fixedRole } : {},
			sort: "name",
			empty: "No users match these filters.",
			columns: [
				{ label: "Name", sort: "name", render: (u) => html`<div class="fw-semibold">${u.full_name}</div><div class="small text-muted">${u.email}</div>` },
				{ label: "Role", sort: "role", render: (u) => badge(u.role, u.role === "admin" ? "high" : u.role === "teacher" ? "info" : "low") },
				{ label: "ID", render: (u) => esc(u.admission_no || u.employee_no || "—") },
				{ label: fixedRole === "teacher" ? "Designation" : "Classes", render: (u) => esc((fixedRole === "teacher" ? u.designation : u.classes) || "—") },
				{ label: "Status", render: (u) => activeBadge(u.is_active) },
				{ label: "Last login", sort: "last_login", render: (u) => esc(u.last_login_at ? fmt.datetime(u.last_login_at) : "Never") },
				{
					label: "Actions", className: "text-end",
					render: (u) => html`<div class="btn-group btn-group-sm">
						<button class="btn btn-light" data-action="edit" data-id="${u.id}" aria-label="Edit ${u.full_name}"><i class="bi bi-pencil"></i></button>
						<button class="btn btn-light" data-action="toggle" data-id="${u.id}" aria-label="${u.is_active ? "Deactivate" : "Activate"} ${u.full_name}">
							<i class="bi ${u.is_active ? "bi-person-dash" : "bi-person-check"}"></i></button></div>`,
				},
			],
		});

		async function userFields(role, editing) {
			const [deps, cls] = await Promise.all([departments(), classes()]);
			const f = [];
			if (!editing && !fixedRole) {
				f.push({ name: "role", label: "Role", type: "select", required: true, options: [{ value: "student", label: "Student" }, { value: "teacher", label: "Teacher" }, { value: "admin", label: "Admin" }], col: "col-md-4" });
			}
			f.push(
				{ name: "full_name", label: "Full name", required: true, col: editing || fixedRole ? "col-md-6" : "col-md-8", maxlength: 150 },
				{ name: "email", label: "Email", type: "email", required: true, col: "col-md-6", autocomplete: "off" },
				{ name: "phone", label: "Phone", col: "col-md-6" },
				{ name: "password", label: editing ? "New password (leave blank to keep)" : "Initial password", type: "password", required: !editing, col: "col-md-6", autocomplete: "new-password", help: "At least 8 characters with a letter and a number." },
			);
			if (role === "student" || (!editing && !fixedRole)) {
				f.push(
					{ name: "admission_no", label: "Admission no. (students)", col: "col-md-4", required: role === "student" },
					{ name: "roll_no", label: "Roll no.", col: "col-md-4" },
					{ name: "guardian_name", label: "Guardian", col: "col-md-4" },
				);
				if (!editing) f.push({ name: "class_ids", label: "Enroll in classes", type: "multiselect", options: cls.filter((c) => +c.is_active).map((c) => ({ value: c.id, label: `${classLabel(c)} · ${c.session_name}` })), help: "Ctrl/Cmd-click to pick several." });
			}
			if (role === "teacher" || (!editing && !fixedRole)) {
				f.push(
					{ name: "employee_no", label: "Employee no. (teachers)", col: "col-md-4", required: role === "teacher" },
					{ name: "designation", label: "Designation", col: "col-md-4" },
					{ name: "department_id", label: "Department", type: "select", placeholder: "None", options: deps.map((d) => ({ value: d.id, label: d.name })), col: "col-md-4" },
				);
			}
			if (editing) f.push({ name: "is_active", label: "Account active", type: "checkbox" });
			return f;
		}

		on(page, "[data-action=create]", async () => {
			const role = fixedRole || "student";
			formModal({
				title: `Add ${fixedRole || "user"}`,
				size: "modal-lg",
				fields: await userFields(fixedRole, false),
				values: { role },
				submit: async (d) => {
					if (fixedRole) d.role = fixedRole;
					await api("/api/admin/users", { method: "POST", body: d });
					toast("Account created.");
					cache.users = null;
					table.load();
				},
			});
		});

		on(page, "[data-action=edit]", async (b) => {
			const { data: u } = await api(`/api/admin/users/${b.dataset.id}`);
			formModal({
				title: `Edit ${u.full_name}`,
				size: "modal-lg",
				fields: await userFields(u.role, true),
				values: u,
				submit: async (d) => {
					if (!d.password) delete d.password;
					await api(`/api/admin/users/${u.id}`, { method: "PATCH", body: d });
					toast("Changes saved.");
					table.load();
				},
			});
		});

		on(page, "[data-action=toggle]", async (b) => {
			const u = table.row(b.dataset.id);
			const deactivate = u.is_active;
			const ok = await confirm(
				deactivate ? `Deactivate ${u.full_name}? They will be signed out and cannot log in until reactivated.` : `Reactivate ${u.full_name}?`,
				{ confirmText: deactivate ? "Deactivate" : "Activate", danger: deactivate },
			);
			if (!ok) return;
			try {
				await api(`/api/admin/users/${u.id}`, { method: "PATCH", body: { is_active: !deactivate } });
				toast(deactivate ? "Account deactivated." : "Account activated.");
				table.load();
			} catch (e) {
				toast(e.errors?.is_active || e.message, "danger");
			}
		});

		on(page, "[data-action=import]", () => {
			formModal({
				title: "Import users from CSV",
				submitText: "Import",
				fields: [{
					name: "file", label: "CSV file", type: "file", accept: ".csv,text/csv", required: true,
					help: "Columns: role, full_name, email, password, admission_no, roll_no, employee_no, designation, class. Nothing is imported if any row has an error.",
				}],
				submit: async (d, form) => {
					const fd = new FormData();
					fd.append("file", d.file[0] || "");
					try {
						const r = await api("/api/admin/users/import", { method: "POST", form: fd });
						toast(`Imported ${r.created} account(s).`);
						table.load();
					} catch (e) {
						if (e.status === 422 && !e.errors?.file) {
							const box = form.querySelector("[data-form-error]");
							box.innerHTML = "<strong>No accounts were imported.</strong><ul class='mb-0 mt-1'>" + (e.errors && Object.keys(e.errors).length ? Object.values(e.errors) : []).map((m) => `<li>${esc(m)}</li>`).join("") + "</ul>";
						}
						throw e;
					}
				},
			});
		});
	}

	// ================================================================ classes
	if (page.dataset.page === "admin-classes") {
		const body = document.getElementById("classes-body");
		const sessionSel = document.getElementById("class-session");

		sessions().then((list) => list.forEach((s) => sessionSel.insertAdjacentHTML("beforeend", html`<option value="${s.id}"${+s.is_current ? " selected" : ""}>${s.name}${+s.is_current ? " (current)" : ""}</option>`))).then(load);
		sessionSel.addEventListener("change", load);

		async function load() {
			body.innerHTML = `<tr><td colspan="7">${'<div class="loading-state">Loading…</div>'}</td></tr>`;
			try {
				const { data } = await api("/api/admin/classes" + EF.qs({ session_id: sessionSel.value }));
				cache.classList = data;
				if (!data.length) {
					body.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="bi bi-collection"></i>No classes in this session yet.</div></td></tr>`;
					return;
				}
				body.innerHTML = data.map((c) => html`<tr>
					<td><a class="fw-semibold" href="/admin/classes/${c.id}">${classLabel(c)}</a></td>
					<td>${c.session_name}</td><td>${c.department_name || "—"}</td>
					<td class="text-end">${c.student_count}</td><td class="text-end">${c.teaching_count}</td>
					<td>${raw(activeBadge(+c.is_active))}</td>
					<td class="text-end"><div class="btn-group btn-group-sm">
						<a class="btn btn-light" href="/admin/classes/${c.id}" aria-label="Manage ${classLabel(c)}"><i class="bi bi-people"></i></a>
						<button class="btn btn-light" data-action="edit" data-id="${c.id}" aria-label="Edit ${classLabel(c)}"><i class="bi bi-pencil"></i></button></div></td>
				</tr>`).join("");
			} catch (e) {
				body.innerHTML = `<tr><td colspan="7"></td></tr>`;
				failed(body.querySelector("td"), e, load);
			}
		}

		async function classForm(c) {
			const [ses, deps] = await Promise.all([sessions(), departments()]);
			formModal({
				title: c ? `Edit ${classLabel(c)}` : "Add class",
				fields: [
					{ name: "name", label: "Class name", required: true, col: "col-md-8", placeholder: "e.g. FY BSc Computer Science / Grade 10" },
					{ name: "division", label: "Division", col: "col-md-4", placeholder: "A" },
					{ name: "academic_session_id", label: "Academic session", type: "select", required: true, options: ses.map((s) => ({ value: s.id, label: s.name })), col: "col-md-6" },
					{ name: "department_id", label: "Department", type: "select", placeholder: "None", options: deps.map((d) => ({ value: d.id, label: d.name })), col: "col-md-6" },
					...(c ? [{ name: "is_active", label: "Active", type: "checkbox" }] : []),
				],
				values: c ? { ...c, is_active: +c.is_active } : { academic_session_id: sessionSel.value || ses.find((s) => +s.is_current)?.id },
				submit: async (d) => {
					d.department_id = d.department_id || null;
					await api(c ? `/api/admin/classes/${c.id}` : "/api/admin/classes", { method: c ? "PATCH" : "POST", body: d });
					toast(c ? "Class updated." : "Class created.");
					load();
				},
			});
		}
		on(page, "[data-action=create]", () => classForm(null));
		on(page, "[data-action=edit]", (b) => classForm(cache.classList.find((c) => String(c.id) === b.dataset.id)));
	}

	// ================================================================ single class
	if (page.dataset.page === "admin-class") {
		const id = page.dataset.classId;
		const studentsEl = document.getElementById("class-students");
		const teachingEl = document.getElementById("class-teaching");
		let detail;

		async function load() {
			loading(studentsEl);
			loading(teachingEl);
			try {
				({ data: detail } = await api(`/api/admin/classes/${id}`));
			} catch (e) {
				failed(studentsEl, e, load);
				teachingEl.innerHTML = "";
				return;
			}
			const c = detail.class;
			document.getElementById("class-heading").textContent = `${classLabel(c)} · ${c.session_name}`;
			document.title = `${classLabel(c)} · EduFlow`;

			if (!detail.students.length) empty(studentsEl, "No students enrolled yet.", "people");
			else studentsEl.innerHTML = `<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Roll</th><th>Name</th><th>Admission no.</th><th class="text-end">Remove</th></tr></thead><tbody>` +
				detail.students.map((s) => html`<tr><td>${s.roll_no || "—"}</td><td><div class="fw-semibold">${s.full_name}</div><div class="small text-muted">${s.email}</div></td><td>${s.admission_no}</td>
					<td class="text-end"><button class="btn btn-sm btn-light" data-action="unenroll" data-id="${s.id}" aria-label="Remove ${s.full_name} from class"><i class="bi bi-x-lg"></i></button></td></tr>`).join("") +
				"</tbody></table></div>";

			if (!detail.teaching.length) empty(teachingEl, "No teachers assigned yet.", "person-workspace");
			else teachingEl.innerHTML = `<ul class="list-group list-group-flush">` +
				detail.teaching.map((t) => html`<li class="list-group-item d-flex align-items-center gap-2"><div class="flex-grow-1"><div class="fw-semibold">${t.subject_name} <span class="text-muted small">${t.subject_code}</span></div><div class="small text-muted">${t.teacher_name}</div></div>
					<button class="btn btn-sm btn-light" data-action="unassign" data-id="${t.id}" aria-label="Remove ${t.teacher_name} from ${t.subject_name}"><i class="bi bi-x-lg"></i></button></li>`).join("") + "</ul>";
		}
		load();

		on(page, "[data-action=enroll]", async () => {
			const { data: students } = await api("/api/admin/users?role=student&active=1&per_page=100&sort=name");
			const enrolled = new Set(detail.students.map((s) => s.id));
			const options = students.filter((s) => !enrolled.has(s.id)).map((s) => ({ value: s.id, label: `${s.full_name} (${s.admission_no || s.email})` }));
			formModal({
				title: "Enroll students",
				submitText: "Enroll",
				fields: [{ name: "student_ids", label: "Students", type: "multiselect", options, required: true, help: options.length ? "Ctrl/Cmd-click to pick several. Showing the first 100 active students not in this class." : "Every active student is already enrolled." }],
				submit: async (d) => {
					const r = await api(`/api/admin/classes/${id}/students`, { method: "POST", body: d });
					toast(`Enrolled ${r.enrolled} student(s).`);
					load();
				},
			});
		});

		on(page, "[data-action=unenroll]", async (b) => {
			const s = detail.students.find((x) => String(x.id) === b.dataset.id);
			if (!(await confirm(`Remove ${s.full_name} from this class? Their past submissions are kept.`, { confirmText: "Remove" }))) return;
			await api(`/api/admin/classes/${id}/students/${s.id}`, { method: "DELETE" });
			toast("Student removed from class.");
			load();
		});

		on(page, "[data-action=assign]", async () => {
			const [subs, teachers] = await Promise.all([subjects(), api("/api/admin/users?role=teacher&active=1&per_page=100&sort=name").then((r) => r.data)]);
			formModal({
				title: "Assign teacher to subject",
				fields: [
					{ name: "subject_id", label: "Subject", type: "select", required: true, placeholder: "Choose…", options: subs.filter((s) => +s.is_active).map((s) => ({ value: s.id, label: `${s.name} (${s.code})` })) },
					{ name: "teacher_id", label: "Teacher", type: "select", required: true, placeholder: "Choose…", options: teachers.map((t) => ({ value: t.id, label: `${t.full_name} (${t.employee_no})` })) },
				],
				submit: async (d) => {
					await api("/api/admin/teaching", { method: "POST", body: { ...d, class_id: id } });
					toast("Teacher assigned.");
					load();
				},
			});
		});

		on(page, "[data-action=unassign]", async (b) => {
			if (!(await confirm("Remove this teacher from the subject for this class? Existing assignments stay.", { confirmText: "Remove" }))) return;
			await api(`/api/admin/teaching/${b.dataset.id}`, { method: "DELETE" });
			toast("Assignment removed.");
			load();
		});

		// ---- timetable & exams
		const DAYS = ["", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
		const ttEl = document.getElementById("class-timetable");
		const exEl = document.getElementById("class-exams");
		const subjectOptions = () => (detail?.teaching || []).filter((t, i, all) => all.findIndex((x) => x.subject_id === t.subject_id) === i).map((t) => ({ value: t.subject_id, label: t.subject_name }));

		async function loadSchedule() {
			loading(ttEl);
			loading(exEl);
			try {
				const [{ data: tt }, { data: ex }] = await Promise.all([api(`/api/admin/classes/${id}/timetable`), api(`/api/exams?class_id=${id}`)]);
				ttEl.innerHTML = tt.length
					? `<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th><th></th></tr></thead><tbody>${tt.map((t) => html`<tr><td>${DAYS[t.weekday]}</td><td>${t.start_time.slice(0, 5)}–${t.end_time.slice(0, 5)}</td><td>${t.subject_name}</td><td>${t.teacher_name || "—"}</td><td>${t.room || "—"}</td>
						<td class="text-end"><button class="btn btn-sm btn-light" data-del-period="${t.id}" aria-label="Remove period"><i class="bi bi-x-lg"></i></button></td></tr>`).join("")}</tbody></table></div>`
					: '<div class="empty-state">No periods yet.</div>';
				exEl.innerHTML = ex.length
					? `<ul class="list-group list-group-flush">${ex.map((e) => html`<li class="list-group-item d-flex gap-2 align-items-center"><div class="flex-grow-1"><div class="fw-semibold">${e.title}</div><div class="small text-muted">${e.subject_name} · ${fmt.datetime(e.starts_at)}${e.room ? " · " + e.room : ""}</div></div>
						<button class="btn btn-sm btn-light" data-del-exam="${e.id}" aria-label="Delete exam ${e.title}"><i class="bi bi-trash"></i></button></li>`).join("")}</ul>`
					: '<div class="empty-state">No exams scheduled.</div>';
			} catch (e) {
				failed(ttEl, e, loadSchedule);
				exEl.innerHTML = "";
			}
		}
		loadSchedule();

		on(page, "[data-action=add-period]", () => {
			if (!subjectOptions().length) return toast("Assign a teacher and subject to this class first.", "warning");
			formModal({
				title: "Add timetable period",
				fields: [
					{ name: "weekday", label: "Day", type: "select", required: true, options: DAYS.slice(1).map((d, i) => ({ value: i + 1, label: d })), col: "col-md-6" },
					{ name: "subject_id", label: "Subject", type: "select", required: true, options: subjectOptions(), col: "col-md-6" },
					{ name: "start_time", label: "Starts", type: "time", required: true, col: "col-md-4" },
					{ name: "end_time", label: "Ends", type: "time", required: true, col: "col-md-4" },
					{ name: "room", label: "Room", col: "col-md-4" },
				],
				submit: async (d) => {
					const teacher = detail.teaching.find((t) => String(t.subject_id) === String(d.subject_id));
					await api(`/api/admin/classes/${id}/timetable`, { method: "POST", body: { ...d, teacher_id: teacher?.teacher_id || null } });
					toast("Period added.");
					loadSchedule();
				},
			});
		});
		on(page, "[data-action=add-exam]", () => {
			if (!subjectOptions().length) return toast("Assign a teacher and subject to this class first.", "warning");
			formModal({
				title: "Schedule exam",
				fields: [
					{ name: "title", label: "Title", required: true, placeholder: "Mid-term exam" },
					{ name: "subject_id", label: "Subject", type: "select", required: true, options: subjectOptions(), col: "col-md-6" },
					{ name: "room", label: "Room", col: "col-md-6" },
					{ name: "starts_at", label: "Starts", type: "datetime", required: true, col: "col-md-6" },
					{ name: "ends_at", label: "Ends", type: "datetime", required: true, col: "col-md-6" },
				],
				submit: async (d) => {
					await api("/api/exams", { method: "POST", body: { ...d, class_id: +id } });
					toast("Exam scheduled. Students have been notified.");
					loadSchedule();
				},
			});
		});
		on(page, "[data-del-period]", async (b) => {
			await api(`/api/admin/timetable/${b.dataset.delPeriod}`, { method: "DELETE" });
			loadSchedule();
		});
		on(page, "[data-del-exam]", async (b) => {
			if (!(await confirm("Delete this exam?", { confirmText: "Delete" }))) return;
			await api(`/api/exams/${b.dataset.delExam}`, { method: "DELETE" });
			loadSchedule();
		});
	}

	// ================================================================ settings
	if (page.dataset.page === "admin-settings") {
		const form = document.getElementById("settings-form");
		api("/api/admin/settings").then(({ data: s }) => {
			form.name.value = s.name;
			form.kind.value = s.kind;
			form.timezone.innerHTML = s.timezones.map((z) => html`<option${raw(z === s.timezone ? " selected" : "")}>${z}</option>`).join("");
			form.due_soon_hours.value = s.settings.due_soon_hours ?? 24;
			document.getElementById("st-code").textContent = s.code;
		}).catch((e) => toast(e.message, "danger"));
		form.addEventListener("submit", async (ev) => {
			ev.preventDefault();
			EF.clearErrors(form);
			try {
				await api("/api/admin/settings", { method: "PATCH", body: { name: form.name.value, kind: form.kind.value, timezone: form.timezone.value, due_soon_hours: +form.due_soon_hours.value } });
				toast("Settings saved. Reload to see times in the new zone.");
			} catch (e) {
				EF.showErrors(form, e);
			}
		});
	}

	// ================================================================ reports
	if (page.dataset.page === "admin-reports") {
		classes().then((list) => page.querySelectorAll("[data-report-class]").forEach((sel) =>
			list.forEach((c) => sel.insertAdjacentHTML("beforeend", html`<option value="${c.id}">${classLabel(c)} · ${c.session_name}</option>`)),
		));
		page.querySelectorAll("[data-report-class]").forEach((sel) => sel.addEventListener("change", () => {
			const a = sel.closest(".card-body").querySelector("[data-report]");
			a.href = `/api/reports/${a.dataset.report}.csv` + EF.qs({ class_id: sel.value });
		}));
	}

	// ================================================================ subjects
	if (page.dataset.page === "admin-subjects") {
		const body = document.getElementById("subjects-body");
		const search = document.getElementById("subject-search");
		let list = [];
		const render = () => {
			const q = search.value.trim().toLowerCase();
			const rows = list.filter((s) => !q || `${s.name} ${s.code}`.toLowerCase().includes(q));
			body.innerHTML = rows.length ? rows.map((s) => html`<tr><td class="fw-semibold">${s.code}</td><td>${s.name}</td><td>${s.department_name || "—"}</td><td>${raw(activeBadge(+s.is_active))}</td>
				<td class="text-end"><button class="btn btn-sm btn-light" data-action="edit" data-id="${s.id}" aria-label="Edit ${s.name}"><i class="bi bi-pencil"></i></button></td></tr>`).join("")
				: `<tr><td colspan="5"><div class="empty-state"><i class="bi bi-book"></i>${q ? "No subjects match." : "No subjects yet."}</div></td></tr>`;
		};
		const load = async () => {
			body.innerHTML = `<tr><td colspan="5"><div class="loading-state">Loading…</div></td></tr>`;
			cache.subjects = null;
			list = await subjects();
			render();
		};
		search.addEventListener("input", render);
		load();

		async function subjectForm(s) {
			const deps = await departments();
			formModal({
				title: s ? `Edit ${s.name}` : "Add subject",
				fields: [
					{ name: "code", label: "Code", required: true, col: "col-md-4", placeholder: "CS101" },
					{ name: "name", label: "Name", required: true, col: "col-md-8" },
					{ name: "department_id", label: "Department", type: "select", placeholder: "None", options: deps.map((d) => ({ value: d.id, label: d.name })) },
					...(s ? [{ name: "is_active", label: "Active", type: "checkbox" }] : []),
				],
				values: s ? { ...s, is_active: +s.is_active } : {},
				submit: async (d) => {
					d.department_id = d.department_id || null;
					await api(s ? `/api/admin/subjects/${s.id}` : "/api/admin/subjects", { method: s ? "PATCH" : "POST", body: d });
					toast("Subject saved.");
					load();
				},
			});
		}
		on(page, "[data-action=create]", () => subjectForm(null));
		on(page, "[data-action=edit]", (b) => subjectForm(list.find((s) => String(s.id) === b.dataset.id)));
	}

	// ================================================================ sessions & departments
	if (page.dataset.page === "admin-sessions") {
		const sBody = document.getElementById("sessions-body");
		const dBody = document.getElementById("departments-body");
		let sList = [], dList = [];

		const loadSessions = async () => {
			cache.sessions = null;
			sList = await sessions();
			sBody.innerHTML = sList.length ? sList.map((s) => html`<tr><td class="fw-semibold">${s.name}</td><td>${s.starts_on}</td><td>${s.ends_on}</td><td class="text-end">${s.class_count}</td>
				<td>${raw(+s.is_current ? badge("Current", "success") : "")}</td>
				<td class="text-end"><button class="btn btn-sm btn-light" data-action="edit-session" data-id="${s.id}" aria-label="Edit ${s.name}"><i class="bi bi-pencil"></i></button></td></tr>`).join("")
				: `<tr><td colspan="6"><div class="empty-state">No academic sessions yet.</div></td></tr>`;
		};
		const loadDeps = async () => {
			cache.departments = null;
			dList = await departments();
			dBody.innerHTML = dList.length ? dList.map((d) => html`<tr><td class="fw-semibold">${d.code}</td><td>${d.name}</td>
				<td class="text-end"><button class="btn btn-sm btn-light" data-action="edit-department" data-id="${d.id}" aria-label="Edit ${d.name}"><i class="bi bi-pencil"></i></button></td></tr>`).join("")
				: `<tr><td colspan="3"><div class="empty-state">No departments yet.</div></td></tr>`;
		};
		loadSessions();
		loadDeps();

		const sessionForm = (s) => formModal({
			title: s ? `Edit ${s.name}` : "Add academic session",
			fields: [
				{ name: "name", label: "Name", required: true, placeholder: "2026-27" },
				{ name: "starts_on", label: "Starts on", type: "date", required: true, col: "col-md-6" },
				{ name: "ends_on", label: "Ends on", type: "date", required: true, col: "col-md-6" },
				{ name: "is_current", label: "This is the current session", type: "checkbox" },
			],
			values: s ? { ...s, is_current: +s.is_current } : {},
			submit: async (d) => {
				await api(s ? `/api/admin/sessions/${s.id}` : "/api/admin/sessions", { method: s ? "PATCH" : "POST", body: d });
				toast("Session saved.");
				loadSessions();
			},
		});
		const depForm = (d0) => formModal({
			title: d0 ? `Edit ${d0.name}` : "Add department",
			fields: [
				{ name: "code", label: "Code", required: true, col: "col-md-4", placeholder: "CS" },
				{ name: "name", label: "Name", required: true, col: "col-md-8" },
			],
			values: d0 || {},
			submit: async (d) => {
				await api(d0 ? `/api/admin/departments/${d0.id}` : "/api/admin/departments", { method: d0 ? "PATCH" : "POST", body: d });
				toast("Department saved.");
				loadDeps();
			},
		});
		on(page, "[data-action=create-session]", () => sessionForm(null));
		on(page, "[data-action=edit-session]", (b) => sessionForm(sList.find((s) => String(s.id) === b.dataset.id)));
		on(page, "[data-action=create-department]", () => depForm(null));
		on(page, "[data-action=edit-department]", (b) => depForm(dList.find((d) => String(d.id) === b.dataset.id)));
	}

	// ================================================================ audit
	if (page.dataset.page === "admin-audit") {
		new Table(document.getElementById("audit-table"), {
			url: "/api/admin/audit-logs",
			perPage: 50,
			empty: "No audit entries.",
			columns: [
				{ label: "When", render: (r) => esc(fmt.datetime(r.created_at)) },
				{ label: "User", render: (r) => (r.user_name ? html`<div>${r.user_name}</div><div class="small text-muted">${r.user_email}</div>` : '<span class="text-muted">System</span>') },
				{ label: "Action", render: (r) => html`<code>${r.action}</code>` },
				{ label: "Record", render: (r) => esc(r.entity_type ? `${r.entity_type}${r.entity_id ? " #" + r.entity_id : ""}` : "—") },
				{ label: "Details", render: (r) => (r.details ? html`<code class="small text-wrap">${JSON.stringify(r.details).slice(0, 160)}</code>` : "—") },
				{ label: "IP", render: (r) => esc(r.ip_address || "—") },
			],
		});
	}
})();
