/* Student dashboard, teacher dashboard and teacher analytics. */
(function () {
	"use strict";
	const { api, esc, html, raw, fmt, badge, priorityBadge, failed, empty, toast } = window.EF;
	const page = document.querySelector("[data-page]");
	if (!page) return;

	const card = (href, icon, label, value, sub = "", cls = "") =>
		html`<a class="stat-card ${cls}" href="${href}"><div class="stat-label"><i class="bi bi-${icon}" aria-hidden="true"></i>${label}</div><div class="stat-value">${value}</div><div class="stat-sub">${sub}</div></a>`;

	// ================================================================ student dashboard
	if (page.dataset.page === "student-dashboard") {
		const stats = document.getElementById("student-stats");
		const upcoming = document.getElementById("upcoming");
		const suggestion = document.getElementById("suggestion");

		async function load() {
			let d;
			EFCharts.state("chart-status", "loading");
			try {
				({ data: d } = await api("/api/student/dashboard"));
			} catch (e) {
				failed(stats, e, load);
				EFCharts.state("chart-status", "error", e.message, load);
				return;
			}
			const s = d.summary;
			stats.innerHTML = [
				card("/student/tasks?status=open", "list-task", "Active tasks", s.active),
				card("/student/tasks?status=completed", "check2-circle", "Completed", s.completed, "", "is-success"),
				card("/student/tasks?status=pending", "hourglass", "Pending", s.pending),
				card("/student/tasks?status=overdue", "exclamation-circle", "Overdue", s.overdue, "", s.overdue ? "is-danger" : ""),
				card("/student/tasks?status=due_soon", "alarm", "Assignments due soon", s.due_soon, "next 72 hours", s.due_soon ? "is-warning" : ""),
				card("/student/progress", "pie-chart", "Completion rate", fmt.pct(s.completion_rate), "all eligible tasks"),
				card("/student/assignments?status=done", "clock-history", "On-time submissions", fmt.pct(s.on_time_rate), "assignments past deadline"),
			].join("");

			if (!d.upcoming.length) empty(upcoming, "No upcoming deadlines. Enjoy the breathing room.", "calendar-check");
			else upcoming.innerHTML = d.upcoming.map(EFTasks.renderItem).join("");

			const n = d.suggestion;
			suggestion.innerHTML = n
				? html`<div class="small text-muted mb-1">${n.kind === "academic" ? "Assignment" : "Personal task"} · ${n.group}</div>
					<a class="fw-semibold d-block mb-2" href="${n.url}">${n.title}</a>
					<div class="d-flex flex-wrap gap-2 small">${raw(priorityBadge(n.priority))} ${raw(n.due_at ? html`<span class="${n.status === "overdue" ? "text-danger fw-semibold" : ""}">${fmt.datetime(n.due_at)} · ${fmt.remaining(n.due_at)}</span>` : "<span>No deadline</span>")}</div>
					<p class="small text-muted mt-2 mb-0">Picked by earliest deadline, then priority.</p>`
				: '<p class="text-muted mb-0">Nothing open right now.</p>';

			EFCharts.insightsList(document.getElementById("insights"), d.insights);
			EFCharts.statusDoughnut("chart-status", { completed: s.completed, pending: s.pending - s.in_progress, in_progress: s.in_progress, overdue: s.overdue }, (k) => `/student/tasks?status=${k}`);
		}
		EFTasks.smartAdd(load);
		EFTasks.bindList(upcoming, load);
		page.querySelector("[data-action=new-task]").addEventListener("click", () => EFTasks.taskForm(null, load));
		load();
	}

	// ================================================================ teacher dashboard
	if (page.dataset.page === "teacher-dashboard") {
		const stats = document.getElementById("teacher-stats");
		const list = document.getElementById("teacher-upcoming");
		(async function load() {
			EFCharts.state("chart-rate", "loading");
			try {
				const [{ data: d }, { data: an }] = await Promise.all([api("/api/teacher/dashboard"), api("/api/teacher/analytics")]);
				const s = d.summary;
				stats.innerHTML = [
					card("/teacher/classes", "collection", "Classes", s.classes),
					card("/teacher/classes", "book", "Subjects", s.subjects),
					card("/teacher/assignments?status=published", "journal-text", "Active assignments", s.active_assignments),
					card("/teacher/submissions?status=graded", "inbox", "Submissions received", s.submissions_received),
					card("/teacher/submissions", "hourglass-split", "Awaiting review", s.awaiting_review, "", s.awaiting_review ? "is-warning" : ""),
					card("/teacher/assignments?status=published", "alarm", "Due in 72 h", s.due_soon),
				].join("");
				list.innerHTML = d.upcoming.length
					? d.upcoming.map((a) => html`<li class="task-item kind-academic"><span class="kind-bar" aria-hidden="true"></span><div class="task-main">
						<a class="task-title" href="/teacher/assignments/${a.id}">${a.title}</a>
						<div class="task-meta"><span>${a.class_name}${a.division ? " " + a.division : ""} · ${a.subject_name}</span><span class="due">${fmt.datetime(a.due_at)} · ${fmt.remaining(a.due_at)}</span><span>${a.submitted}/${a.recipients} submitted</span></div>
					</div></li>`).join("")
					: '<li class="empty-state">No upcoming deadlines.</li>';
				EFCharts.teacherRates("chart-rate", an.assignments.slice(-8));
			} catch (e) {
				failed(stats, e, load);
				EFCharts.state("chart-rate", "error", e.message, load);
			}
		})();
	}

	// ================================================================ teacher analytics
	if (page.dataset.page === "teacher-analytics") {
		const sel = document.getElementById("ta-class");
		const ids = ["chart-assignments", "chart-timeliness", "chart-marks", "chart-classes"];
		api("/api/teacher/classes").then(({ data }) => {
			const seen = new Set();
			data.forEach((r) => {
				if (seen.has(r.class_id)) return;
				seen.add(r.class_id);
				sel.insertAdjacentHTML("beforeend", html`<option value="${r.class_id}">${r.class_name}${r.division ? " " + r.division : ""}</option>`);
			});
		});
		async function load() {
			ids.forEach((id) => EFCharts.state(id, "loading"));
			const repeat = document.getElementById("repeat-missing");
			try {
				const { data } = await api("/api/teacher/analytics" + EF.qs({ class_id: sel.value }));
				EFCharts.teacherAnalytics(data);
				repeat.innerHTML = data.repeated_missing.length
					? `<div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Student</th><th>Class</th><th class="text-end">Missing</th></tr></thead><tbody>${data.repeated_missing.map((s) => html`<tr><td>${s.full_name}</td><td>${s.class}</td><td class="text-end">${raw(badge(s.missing, "danger"))}</td></tr>`).join("")}</tbody></table></div>`
					: '<div class="empty-state">No student has two or more missing submissions.</div>';
			} catch (e) {
				ids.forEach((id) => EFCharts.state(id, "error", e.message, load));
			}
			page.querySelectorAll("[data-export]").forEach((a) => (a.href = `/api/reports/${a.dataset.export}.csv` + EF.qs({ class_id: sel.value })));
		}
		sel.addEventListener("change", load);
		load();
	}
})();
