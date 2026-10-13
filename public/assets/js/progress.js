/* Student "My Progress" page. */
(function () {
	"use strict";
	const { api, html, fmt } = window.EF;
	const page = document.querySelector("[data-page=student-progress]");
	if (!page) return;

	const $ = (id) => document.getElementById(id);
	const CHARTS = ["chart-trend", "chart-status", "chart-kind", "chart-subject", "chart-submission"];
	const today = new Date().toISOString().slice(0, 10);
	$("pf-from").value = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);
	$("pf-to").value = today;

	const filters = () => ({
		period: $("pf-period").value,
		kind: $("pf-kind").value,
		granularity: page.querySelector("input[name=gran]:checked").value,
		from: $("pf-period").value === "custom" ? $("pf-from").value : "",
		to: $("pf-period").value === "custom" ? $("pf-to").value : "",
	});

	/** Link into the unified task list with matching filters (chart drill-down). */
	function linkFor(extra) {
		const f = filters();
		const q = { ...extra };
		if (f.kind !== "all" && !q.kind) q.kind = f.kind;
		return "/student/tasks" + EF.qs(q);
	}

	async function load() {
		CHARTS.forEach((id) => EFCharts.state(id, "loading"));
		const metrics = $("progress-metrics");
		try {
			const { data: p } = await api("/api/student/progress" + EF.qs(filters()));
			$("pf-range").textContent = `${fmt.date(p.range.from)} – ${fmt.date(new Date(new Date(p.range.to) - 1).toISOString())}`;
			const m = p.metrics;
			metrics.innerHTML = [
				["check2-circle", "Completed", m.completed, "/student/tasks?status=completed", "is-success"],
				["pie-chart", "Completion rate", fmt.pct(m.completion_rate), "#chart-status", ""],
				["clock-history", "On-time submissions", fmt.pct(m.on_time_rate), "#chart-submission", ""],
				["hourglass-split", "Pending workload", m.pending_workload, "/student/tasks?status=open", ""],
				["exclamation-circle", "Overdue", m.overdue, "/student/tasks?status=overdue", m.overdue ? "is-danger" : ""],
			].map(([icon, label, value, href, cls]) => html`<a class="stat-card ${cls}" href="${href}"><div class="stat-label"><i class="bi bi-${icon}" aria-hidden="true"></i>${label}</div><div class="stat-value">${value}</div></a>`).join("");
			EFCharts.studentProgress(p, { linkFor });
			EFCharts.insightsList($("insights"), p.insights);
		} catch (e) {
			CHARTS.forEach((id) => EFCharts.state(id, "error", e.errors ? Object.values(e.errors).join(" ") : e.message, load));
			metrics.innerHTML = "";
		}
	}

	$("pf-period").addEventListener("change", () => {
		const custom = $("pf-period").value === "custom";
		page.querySelectorAll("[data-custom]").forEach((el) => el.classList.toggle("d-none", !custom));
		// Sensible default granularity per period.
		const g = $("pf-period").value === "month" || $("pf-period").value === "week" || $("pf-period").value === "today" ? "day" : "week";
		page.querySelector(`input[name=gran][value=${g}]`).checked = true;
		load();
	});
	["pf-kind", "pf-from", "pf-to"].forEach((id) => $(id).addEventListener("change", load));
	page.querySelectorAll("input[name=gran]").forEach((r) => r.addEventListener("change", load));
	load();
})();
