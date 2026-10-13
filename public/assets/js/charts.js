/* Chart.js helpers shared by every dashboard. Exposes window.EFCharts. */
(function () {
	"use strict";
	const { api, esc, fmt, html, failed } = window.EF;

	const C = {
		navy: "#1e3a8a", blue: "#3b82f6", teal: "#0d9488", amber: "#d97706", red: "#dc2626", purple: "#9333ea", slate: "#94a3b8",
	};
	if (window.Chart) {
		Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
		Chart.defaults.color = "#475569";
		Chart.defaults.plugins.legend.labels.usePointStyle = true;
		Chart.defaults.maintainAspectRatio = false;
		Chart.defaults.animation.duration = matchMedia("(prefers-reduced-motion: reduce)").matches ? 0 : 400;
	}

	const charts = new Map();

	/** Overlay for loading / empty / error inside a .chart-box. */
	function state(boxId, kind, message, retry) {
		const box = document.getElementById(boxId);
		if (!box) return;
		box.querySelector(".chart-state")?.remove();
		if (!kind) return;
		const el = document.createElement("div");
		el.className = "chart-state";
		el.setAttribute("role", "status");
		el.innerHTML = kind === "loading"
			? '<span><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</span>'
			: kind === "error"
				? `<span class="text-danger"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${esc(message)}${retry ? ' <button class="btn btn-sm btn-link" data-retry>Retry</button>' : ""}</span>`
				: `<span><i class="bi bi-bar-chart d-block fs-3 mb-1" aria-hidden="true"></i>${esc(message || "No data for this period yet.")}</span>`;
		box.appendChild(el);
		if (retry) el.querySelector("[data-retry]")?.addEventListener("click", retry);
	}

	/** Create or replace a chart. onClick(index, datasetIndex) for drill-down. */
	function draw(boxId, config, { onClick, emptyWhen } = {}) {
		const box = document.getElementById(boxId);
		if (!box || !window.Chart) return null;
		charts.get(boxId)?.destroy();
		const isEmpty = emptyWhen ? emptyWhen() : config.data.datasets.every((d) => d.data.every((v) => !v));
		state(boxId, isEmpty ? "empty" : null);
		config.options = config.options || {};
		if (onClick) {
			config.options.onClick = (evt, els) => {
				if (els.length) onClick(els[0].index, els[0].datasetIndex);
			};
			config.options.onHover = (evt, els) => (evt.native.target.style.cursor = els.length ? "pointer" : "default");
		}
		const chart = new Chart(box.querySelector("canvas"), config);
		charts.set(boxId, chart);
		return chart;
	}

	const pctTooltip = {
		callbacks: {
			label(ctx) {
				const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
				const v = ctx.parsed;
				return ` ${ctx.label}: ${v} (${total ? Math.round((v / total) * 100) : 0}%)`;
			},
		},
	};

	// ------------------------------------------------------------ student charts
	const STATUS_KEYS = ["completed", "pending", "in_progress", "overdue"];
	const STATUS_LABELS = ["Completed", "Pending", "In progress", "Overdue"];
	const STATUS_COLORS = [C.teal, C.slate, C.amber, C.red];

	function statusDoughnut(boxId, status, link) {
		const data = STATUS_KEYS.map((k) => status[k] || 0);
		return draw(boxId, {
			type: "doughnut",
			data: { labels: STATUS_LABELS.map((l, i) => `${l} (${data[i]})`), datasets: [{ data, backgroundColor: STATUS_COLORS, borderWidth: 2 }] },
			options: { cutout: "62%", plugins: { legend: { position: "bottom" }, tooltip: pctTooltip } },
		}, { onClick: (i) => link && (location.href = link(STATUS_KEYS[i])) });
	}

	function studentProgress(p, { linkFor }) {
		draw("chart-trend", {
			type: "line",
			data: { labels: p.labels, datasets: [{ label: "Completed", data: p.trend, borderColor: C.navy, backgroundColor: "rgba(30,58,138,.12)", fill: true, tension: 0, pointRadius: 3 }] },
			options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
		}, { onClick: (i) => (location.href = linkFor({ status: "completed", from: p.bucket_starts[i], to: p.bucket_ends[i] }, true)) });

		statusDoughnut("chart-status", p.status, (s) => linkFor({ status: s }));

		draw("chart-kind", {
			type: "bar",
			data: { labels: p.labels, datasets: [
				{ label: "Academic", data: p.by_kind.academic, backgroundColor: C.blue },
				{ label: "Personal", data: p.by_kind.personal, backgroundColor: C.purple },
			] },
			options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
		}, { onClick: (i, ds) => (location.href = linkFor({ status: "completed", kind: ds === 0 ? "academic" : "personal", from: p.bucket_starts[i], to: p.bucket_ends[i] }, true)) });

		const subj = p.subjects;
		draw("chart-subject", {
			type: "bar",
			data: { labels: subj.map((s) => s.subject), datasets: [{ label: "Completion %", data: subj.map((s) => s.rate ?? 0), backgroundColor: C.teal }] },
			options: {
				indexAxis: "y",
				scales: { x: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + "%" } } },
				plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => { const s = subj[ctx.dataIndex]; return ` ${s.rate ?? 0}% · ${s.completed} of ${s.assigned} done, ${s.outstanding} outstanding`; } } } },
			},
		}, { onClick: (i) => (location.href = linkFor({ kind: "academic", subject_id: subj[i].subject_id })), emptyWhen: () => !subj.length });

		const sub = p.submissions;
		draw("chart-submission", {
			type: "doughnut",
			data: { labels: [`On time (${sub.on_time})`, `Late (${sub.late})`, `Missing (${sub.missing})`], datasets: [{ data: [sub.on_time, sub.late, sub.missing], backgroundColor: [C.teal, C.amber, C.red], borderWidth: 2 }] },
			options: { cutout: "62%", plugins: { legend: { position: "bottom" }, tooltip: pctTooltip } },
		}, { onClick: (i) => (location.href = i === 2 ? "/student/assignments?status=missing" : "/student/assignments?status=done"), emptyWhen: () => !(sub.on_time + sub.late + sub.missing) });
	}

	/** Plain, factual sentences. Each entry is [icon, already-escaped HTML]. */
	function insightsList(el, ins) {
		const s = (n, word) => `${n} ${word}${n === 1 ? "" : "s"}`;
		const diff = ins.change_vs_last_week;
		const vs = diff ? ` (${diff > 0 ? "+" : ""}${diff} vs last week)` : ins.completed_last_week ? " (same as last week)" : "";
		const items = [
			["check2-circle", esc(`${s(ins.completed_this_week, "task")} completed this week${vs}`)],
			["calendar-check", esc(`Completed something on ${ins.active_days_this_week} of 7 days this week`)],
			["hourglass-split", esc(`${s(ins.due_next_7_days, "open item")} due in the next 7 days`)],
		];
		if (ins.overdue) items.push(["exclamation-circle", esc(`${s(ins.overdue, "overdue item")}`)]);
		if (ins.most_outstanding_subject) items.push(["journal-text", esc(`Most outstanding: ${ins.most_outstanding_subject.subject} (${ins.most_outstanding_subject.count})`)]);
		if (ins.suggested_next) items.push(["arrow-right-circle", html`Suggested next: <a href="${ins.suggested_next.url}">${ins.suggested_next.title}</a>`]);
		el.innerHTML = items.map(([icon, text]) => `<li><i class="bi bi-${icon}" aria-hidden="true"></i><span>${text}</span></li>`).join("");
	}

	// ------------------------------------------------------------ teacher charts
	function teacherAnalytics(d) {
		const as = d.assignments;
		const short = (t) => (t.length > 22 ? t.slice(0, 21) + "…" : t);
		draw("chart-assignments", {
			type: "bar",
			data: { labels: as.map((a) => short(a.title)), datasets: [
				{ label: "Submitted", data: as.map((a) => a.submitted), backgroundColor: C.teal, stack: "s" },
				{ label: "Missing", data: as.map((a) => a.missing), backgroundColor: C.red, stack: "s" },
				{ label: "Not yet due", data: as.map((a) => a.pending), backgroundColor: C.slate, stack: "s" },
			] },
			options: {
				scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
				plugins: { tooltip: { callbacks: { title: (items) => as[items[0].dataIndex].title, afterBody: (items) => `Submission rate: ${fmt.pct(as[items[0].dataIndex].submission_rate)}` } } },
			},
		}, { onClick: (i) => (location.href = `/teacher/assignments/${as[i].id}`), emptyWhen: () => !as.length });

		const t = d.totals;
		draw("chart-timeliness", {
			type: "doughnut",
			data: { labels: [`On time (${t.on_time})`, `Late (${t.late})`, `Missing (${t.missing})`], datasets: [{ data: [t.on_time, t.late, t.missing], backgroundColor: [C.teal, C.amber, C.red], borderWidth: 2 }] },
			options: { cutout: "62%", plugins: { legend: { position: "bottom" }, tooltip: pctTooltip } },
		}, { onClick: () => (location.href = "/teacher/submissions"), emptyWhen: () => !(t.on_time + t.late + t.missing) });

		const graded = as.filter((a) => a.average_marks !== null);
		draw("chart-marks", {
			type: "bar",
			data: { labels: graded.map((a) => short(a.title)), datasets: [{ label: "Average %", data: graded.map((a) => (a.max_marks ? Math.round((a.average_marks / a.max_marks) * 1000) / 10 : a.average_marks)), backgroundColor: C.navy }] },
			options: {
				plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => { const a = graded[ctx.dataIndex]; return ` Average ${a.average_marks}${a.max_marks ? " / " + a.max_marks : ""} over ${a.graded} graded`; } } } },
				scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + "%" } } },
			},
		}, { onClick: (i) => (location.href = `/teacher/assignments/${graded[i].id}`), emptyWhen: () => !graded.length });

		draw("chart-classes", {
			type: "bar",
			data: { labels: d.classes.map((c) => c.class), datasets: [{ label: "Completion %", data: d.classes.map((c) => c.completion_rate ?? 0), backgroundColor: C.teal }] },
			options: { indexAxis: "y", plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => { const c = d.classes[ctx.dataIndex]; return ` ${fmt.pct(c.completion_rate)} · ${c.submitted} of ${c.expected} expected`; } } } }, scales: { x: { beginAtZero: true, max: 100 } } },
		}, { emptyWhen: () => !d.classes.length });
	}

	function teacherRates(boxId, as) {
		draw(boxId, {
			type: "bar",
			data: { labels: as.map((a) => (a.title.length > 18 ? a.title.slice(0, 17) + "…" : a.title)), datasets: [{ label: "Submission rate %", data: as.map((a) => a.submission_rate ?? 0), backgroundColor: C.teal }] },
			options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + "%" } } } },
		}, { onClick: (i) => (location.href = `/teacher/assignments/${as[i].id}`), emptyWhen: () => !as.length });
	}

	// ------------------------------------------------------------ admin charts
	async function adminOverview() {
		["chart-trend", "chart-enrolment", "chart-class", "chart-subject"].forEach((id) => state(id, "loading"));
		let d;
		try {
			({ data: d } = await api("/api/admin/analytics"));
		} catch (e) {
			["chart-trend", "chart-enrolment", "chart-class", "chart-subject"].forEach((id) => state(id, "error", e.message, adminOverview));
			return;
		}
		draw("chart-trend", {
			type: "bar",
			data: { labels: d.trend.labels, datasets: [
				{ label: "On time", data: d.trend.on_time, backgroundColor: C.teal, stack: "s" },
				{ label: "Late", data: d.trend.late, backgroundColor: C.amber, stack: "s" },
			] },
			options: { scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } },
		});
		draw("chart-enrolment", {
			type: "doughnut",
			data: { labels: d.enrolment.map((e) => `${e.class} (${e.students})`), datasets: [{ data: d.enrolment.map((e) => +e.students), backgroundColor: [C.navy, C.teal, C.blue, C.purple, C.amber, C.slate, C.red] }] },
			options: { cutout: "55%", plugins: { legend: { position: "bottom" }, tooltip: pctTooltip } },
		}, { onClick: () => (location.href = "/admin/classes") });
		draw("chart-class", {
			type: "bar",
			data: { labels: d.classes.map((c) => c.class), datasets: [{ label: "Completion %", data: d.classes.map((c) => c.completion_rate ?? 0), backgroundColor: C.teal }] },
			options: { indexAxis: "y", plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => { const c = d.classes[ctx.dataIndex]; return ` ${fmt.pct(c.completion_rate)} · ${c.submitted} of ${c.expected} expected`; } } } }, scales: { x: { beginAtZero: true, max: 100 } } },
		}, { onClick: (i) => (location.href = `/admin/classes/${d.classes[i].id}`), emptyWhen: () => d.classes.every((c) => +c.expected === 0) });
		draw("chart-subject", {
			type: "bar",
			data: { labels: d.subjects.map((s) => s.subject), datasets: [
				{ label: "Assignments", data: d.subjects.map((s) => +s.assignments), backgroundColor: C.navy },
				{ label: "Submissions", data: d.subjects.map((s) => +s.submissions), backgroundColor: C.teal },
			] },
			options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
		});
	}

	window.EFCharts = { state, draw, statusDoughnut, studentProgress, insightsList, teacherAnalytics, teacherRates, adminOverview, colors: C };
})();
