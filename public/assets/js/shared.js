/* Pages used by several roles: announcements, notifications, calendar. */
(function () {
	"use strict";
	const { api, esc, html, raw, fmt, toast, confirm, formModal, badge, loading, empty, failed, on, zoned, dayKey } = window.EF;
	const page = document.querySelector("[data-page]");
	if (!page) return;
	const role = document.querySelector('meta[name="app-role"]')?.content;

	function pager(el, meta, go) {
		el.innerHTML = `<span>${meta.total} total</span><nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">
			<li class="page-item${meta.page <= 1 ? " disabled" : ""}"><button class="page-link" data-page="${meta.page - 1}" aria-label="Previous page">‹</button></li>
			<li class="page-item disabled"><span class="page-link">${meta.page} / ${meta.pages}</span></li>
			<li class="page-item${meta.page >= meta.pages ? " disabled" : ""}"><button class="page-link" data-page="${meta.page + 1}" aria-label="Next page">›</button></li></ul></nav>`;
		el.querySelectorAll("[data-page]").forEach((b) => b.addEventListener("click", () => go(+b.dataset.page)));
	}

	// ================================================================ announcements
	if (page.dataset.page === "announcements") {
		const list = document.getElementById("announcement-list");
		const footer = document.getElementById("announcement-footer");
		const search = document.getElementById("an-search");
		let current = 1;

		async function load(p = current) {
			current = p;
			loading(list);
			try {
				const res = await api("/api/announcements" + EF.qs({ page: p, q: search.value.trim() }));
				if (!res.data.length) {
					empty(list, "No announcements right now.", "megaphone");
				} else {
					list.innerHTML = res.data.map((a) => html`<article class="notif-item${raw(a.is_read ? "" : " is-unread")}" id="a${a.id}" data-id="${a.id}">
						<span class="notif-dot" aria-hidden="true"></span>
						<div class="flex-grow-1">
							<h2 class="h6 mb-1 notif-title">${a.title}</h2>
							<div class="small text-muted mb-2">${a.author_name} · ${fmt.datetime(a.published_at)} · ${a.audience === "institution" ? "Everyone" : a.classes || "Selected classes"}${a.expires_at ? " · until " + fmt.date(a.expires_at) : ""}</div>
							<div class="prose">${a.body}</div>
							${raw(a.attachments.length ? `<ul class="file-list mt-2">${a.attachments.map((f) => html`<li><i class="bi bi-paperclip" aria-hidden="true"></i><a href="/api/files/announcements/${f.id}">${f.original_name}</a><span class="small text-muted">${fmt.bytes(f.size_bytes)}</span></li>`).join("")}</ul>` : "")}
						</div>
						${raw(a.can_delete ? html`<button class="btn btn-sm btn-light align-self-start" data-delete="${a.id}" aria-label="Delete ${a.title}"><i class="bi bi-trash"></i></button>` : "")}
					</article>`).join("");
					// Mark visible unread announcements as read.
					res.data.filter((a) => !a.is_read).forEach((a) => api(`/api/announcements/${a.id}/read`, { method: "POST" }).catch(() => {}));
					if (location.hash) document.querySelector(location.hash)?.scrollIntoView({ block: "center" });
				}
				pager(footer, res.meta, load);
			} catch (e) {
				failed(list, e, () => load());
			}
		}
		let t;
		search.addEventListener("input", () => { clearTimeout(t); t = setTimeout(() => load(1), 300); });

		on(list, "[data-delete]", async (b) => {
			if (!(await confirm("Delete this announcement for everyone?", { confirmText: "Delete" }))) return;
			await api(`/api/announcements/${b.dataset.delete}`, { method: "DELETE" });
			toast("Announcement deleted.");
			load();
		});

		on(page, "[data-action=create]", async () => {
			const classes = role === "admin"
				? (await api("/api/admin/classes")).data.filter((c) => +c.is_active).map((c) => ({ value: c.id, label: `${c.name}${c.division ? " " + c.division : ""} · ${c.session_name}` }))
				: [...new Map((await api("/api/teacher/classes")).data.map((r) => [r.class_id, { value: r.class_id, label: `${r.class_name}${r.division ? " " + r.division : ""}` }])).values()];
			const fields = [
				{ name: "title", label: "Title", required: true, maxlength: 200 },
				{ name: "body", label: "Message", type: "textarea", required: true, rows: 5 },
				...(role === "admin" ? [{ name: "audience", label: "Audience", type: "select", options: [{ value: "institution", label: "Everyone in the institution" }, { value: "classes", label: "Selected classes" }], col: "col-md-6" }] : []),
				{ name: "class_ids", label: "Classes", type: "multiselect", options: classes, col: role === "admin" ? "col-md-6" : "col-12", help: "Ctrl/Cmd-click to pick several." },
				{ name: "published_at", label: "Publish at (leave empty for now)", type: "datetime", col: "col-md-6" },
				{ name: "expires_at", label: "Expires (optional)", type: "datetime", col: "col-md-6" },
				{ name: "files", label: "Attachments (optional, up to 5)", type: "file", multiple: true },
			];
			formModal({
				title: "New announcement",
				size: "modal-lg",
				fields,
				values: { audience: "institution" },
				submitText: "Publish",
				submit: async (d) => {
					const fd = new FormData();
					fd.append("title", d.title);
					fd.append("body", d.body);
					fd.append("audience", d.audience || "classes");
					fd.append("class_ids", (d.class_ids || []).join(","));
					if (d.published_at) fd.append("published_at", d.published_at);
					if (d.expires_at) fd.append("expires_at", d.expires_at);
					[...(d.files || [])].forEach((f) => fd.append("files[]", f));
					await api("/api/announcements", { method: "POST", form: fd });
					toast("Announcement published.");
					load(1);
				},
			});
		});
		load();
	}

	// ================================================================ notifications
	if (page.dataset.page === "notifications") {
		const list = document.getElementById("notification-list");
		const footer = document.getElementById("notification-footer");
		const ICON = { assignment_new: "journal-plus", deadline_changed: "calendar-event", deadline_soon: "alarm", overdue: "exclamation-circle", submission_received: "check2-circle", feedback: "chat-left-text", returned: "arrow-counterclockwise", announcement: "megaphone", task_reminder: "bell", exam: "pencil-square", resubmission_requested: "arrow-repeat", assignment_cancelled: "x-circle" };
		let unread = "";

		async function load(p = 1) {
			loading(list);
			try {
				const res = await api("/api/notifications" + EF.qs({ page: p, unread }));
				if (!res.data.length) empty(list, unread ? "You're all caught up." : "No notifications yet.", "bell");
				else list.innerHTML = res.data.map((n) => html`<div class="notif-item${raw(n.read_at ? "" : " is-unread")}">
					<span class="notif-dot" aria-hidden="true"></span>
					<i class="bi bi-${ICON[n.type] || "bell"} text-primary mt-1" aria-hidden="true"></i>
					<div class="flex-grow-1">
						<div class="notif-title">${raw(n.action_url ? html`<a href="${n.action_url}" data-open="${n.id}">${n.title}</a>` : esc(n.title))}</div>
						<div class="small">${n.message}</div>
						<div class="small text-muted">${fmt.datetime(n.created_at)}${n.read_at ? "" : " · unread"}</div>
					</div>
					${raw(n.read_at ? "" : html`<button class="btn btn-sm btn-light align-self-start" data-read="${n.id}" aria-label="Mark as read"><i class="bi bi-check2"></i></button>`)}
				</div>`).join("");
				pager(footer, res.meta, load);
			} catch (e) {
				failed(list, e, () => load(p));
			}
		}
		page.querySelectorAll("input[name=nf]").forEach((r) => r.addEventListener("change", () => { unread = r.value; load(1); }));
		on(list, "[data-read]", async (b) => { await api(`/api/notifications/${b.dataset.read}/read`, { method: "POST" }); EF.refreshBell(); load(); });
		on(list, "[data-open]", (a) => { api(`/api/notifications/${a.dataset.open}/read`, { method: "POST" }).catch(() => {}); });
		on(page, "[data-action=read-all]", async () => { const r = await api("/api/notifications/read-all", { method: "POST" }); toast(`Marked ${r.updated} as read.`); EF.refreshBell(); load(); });
		load();
	}

	// ================================================================ calendar
	if (page.dataset.page === "calendar") {
		const body = document.getElementById("calendar-body");
		const title = document.getElementById("cal-title");
		const z = zoned(new Date());
		let view = "month";
		let cursor = new Date(Date.UTC(z.y, z.m, z.d)); // wall-clock date in institution zone
		const pad = (n) => String(n).padStart(2, "0");
		const key = (d) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
		const addDays = (d, n) => new Date(d.getTime() + n * 86400000);
		const mondayOf = (d) => addDays(d, -((d.getUTCDay() + 6) % 7));
		const todayKey = key(new Date(Date.UTC(z.y, z.m, z.d)));
		const toIso = (d) => EF.zonedInputToIso(`${key(d)}T00:00`);
		const DOW = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

		function range() {
			if (view === "month") {
				const first = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth(), 1));
				const start = mondayOf(first);
				return [start, addDays(start, 42)];
			}
			if (view === "week") {
				const s = mondayOf(cursor);
				return [s, addDays(s, 7)];
			}
			return [cursor, addDays(cursor, 30)];
		}

		const evLink = (e) => {
			const label = html`${e.start && e.type !== "announcement" ? fmt.time(e.start) + " " : ""}${e.title}`;
			const tip = `${e.title}${e.subtitle ? " · " + e.subtitle : ""}`;
			return e.url
				? html`<a class="cal-event ev-${e.type}" href="${e.url}" title="${tip}">${raw(label)}</a>`
				: html`<span class="cal-event ev-${e.type}" title="${tip}">${raw(label)}</span>`;
		};

		async function load() {
			const [s, e] = range();
			title.textContent = view === "month"
				? new Intl.DateTimeFormat(undefined, { month: "long", year: "numeric", timeZone: "UTC" }).format(cursor)
				: `${new Intl.DateTimeFormat(undefined, { day: "numeric", month: "short", timeZone: "UTC" }).format(s)} – ${new Intl.DateTimeFormat(undefined, { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }).format(addDays(e, -1))}`;
			loading(body);
			let events;
			try {
				({ data: events } = await api("/api/calendar" + EF.qs({ from: toIso(s), to: toIso(e) })));
			} catch (err) {
				return failed(body, err, load);
			}
			const byDay = {};
			events.forEach((ev) => (byDay[dayKey(ev.start)] ??= []).push(ev));

			if (view === "agenda") {
				const days = Object.keys(byDay).sort();
				body.innerHTML = days.length
					? days.map((d) => html`<div class="agenda-day">${new Intl.DateTimeFormat(undefined, { weekday: "long", day: "numeric", month: "long", timeZone: "UTC" }).format(new Date(d + "T00:00:00Z"))}</div>
						<ul class="list-unstyled px-3 py-2 mb-0">${raw(byDay[d].map((ev) => html`<li class="py-1">${raw(evLink(ev))} <span class="small text-muted">${ev.subtitle || ""}</span></li>`).join(""))}</ul>`).join("")
					: '<div class="empty-state"><i class="bi bi-calendar3" aria-hidden="true"></i>Nothing scheduled in the next 30 days.</div>';
				return;
			}
			const cells = [];
			for (let d = s; d < e; d = addDays(d, 1)) {
				const k = key(d);
				const evs = byDay[k] || [];
				const other = view === "month" && d.getUTCMonth() !== cursor.getUTCMonth();
				const max = view === "month" ? 3 : 12;
				cells.push(html`<div class="cal-cell${raw(other ? " is-other" : "")}${raw(k === todayKey ? " is-today" : "")}" role="gridcell" aria-label="${k}${evs.length ? ", " + evs.length + " events" : ""}">
					<span class="cal-day">${d.getUTCDate()}</span>
					${raw(evs.slice(0, max).map(evLink).join(""))}
					${raw(evs.length > max ? html`<button class="cal-event btn btn-link p-0" data-day="${k}">+${evs.length - max} more</button>` : "")}
				</div>`);
			}
			body.innerHTML = `<div class="cal-grid" role="grid">${DOW.map((d) => `<div class="cal-head" role="columnheader">${d}</div>`).join("")}${cells.join("")}</div>`;
			body.querySelectorAll("[data-day]").forEach((b) => b.addEventListener("click", () => {
				cursor = new Date(b.dataset.day + "T00:00:00Z");
				view = "week";
				document.getElementById("cv-week").checked = true;
				load();
			}));
		}

		page.querySelectorAll("[data-nav]").forEach((b) => b.addEventListener("click", () => {
			const n = +b.dataset.nav;
			if (n === 0) cursor = new Date(Date.UTC(z.y, z.m, z.d));
			else if (view === "month") cursor = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + n, 1));
			else cursor = addDays(cursor, n * (view === "week" ? 7 : 30));
			load();
		}));
		page.querySelectorAll("input[name=cv]").forEach((r) => r.addEventListener("change", () => { view = r.value; load(); }));
		if (matchMedia("(max-width: 575.98px)").matches) {
			view = "agenda";
			document.getElementById("cv-agenda").checked = true;
		}
		load();
	}
})();
