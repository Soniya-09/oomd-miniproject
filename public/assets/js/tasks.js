/* Personal tasks: smart-add parser, task form, unified academic + personal list. Exposes window.EFTasks. */
(function () {
	"use strict";
	const { api, esc, html, raw, fmt, toast, confirm, formModal, modalShell, badge, priorityBadge, loading, empty, failed, on, zoned, zonedInputToIso, isoToZonedInput } = window.EF;

	// ================================================================ smart-add parser
	// Ported from the ScheduNova prototype. Works on "wall clock" dates in the institution
	// time zone (a Date whose UTC fields hold local wall time), converted to UTC at the end.
	//   "Gym tomorrow 7am for 1 hour high priority @gym"
	//   -> title "Gym", starts tomorrow 07:00, 60 min, high priority, context "gym"
	const DATE = "(?:day after tomorrow|today|tonight|tomorrow|tmrw|(?:next\\s+|this\\s+|on\\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tues|tue|wed|thurs|thu|fri|sat|sun))";
	const TIME = "(?:at\\s+)?(?:\\d{1,2}(?::\\d{2})?\\s*(?:am|pm)|\\d{1,2}:\\d{2}|noon|morning|afternoon|evening)";
	const PLACES = "the gym|the office|home|office|work|gym|college|school|library|class|lab|canteen";
	const WEEKDAY_PREFIX = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];

	const wallNow = () => { const z = zoned(new Date()); return new Date(Date.UTC(z.y, z.m, z.d, z.h, z.mi)); };
	const startOfDay = (d) => new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()));
	const addDays = (d, n) => new Date(d.getTime() + n * 86400000);
	const at = (day, [h, m]) => new Date(Date.UTC(day.getUTCFullYear(), day.getUTCMonth(), day.getUTCDate(), h, m));
	const pad = (n) => String(n).padStart(2, "0");
	const wallToIso = (d) => zonedInputToIso(`${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`);

	function resolveDate(phrase, now) {
		const w = phrase.toLowerCase().replace(/^(next|this|on)\s+/, "");
		const today = startOfDay(now);
		if (w === "today" || w === "tonight") return today;
		if (w === "tomorrow" || w === "tmrw") return addDays(today, 1);
		if (w === "day after tomorrow") return addDays(today, 2);
		const idx = WEEKDAY_PREFIX.findIndex((p) => w.startsWith(p));
		if (idx === -1) return null;
		// Always the upcoming one: "friday" said on a Friday means next week.
		return addDays(today, (idx - today.getUTCDay() + 7) % 7 || 7);
	}

	function resolveTime(phrase) {
		const p = phrase.toLowerCase().replace(/^at\s+/, "").trim();
		const named = { noon: [12, 0], morning: [9, 0], afternoon: [14, 0], evening: [18, 0] };
		if (named[p]) return named[p];
		const m = p.match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/);
		if (!m) return null;
		let h = Number(m[1]);
		const min = Number(m[2] || 0);
		if (m[3] === "pm" && h < 12) h += 12;
		if (m[3] === "am" && h === 12) h = 0;
		if (h > 23 || min > 59) return null;
		return [h, min];
	}

	function parseTask(input, now = wallNow()) {
		const rawInput = input.trim();
		let text = ` ${rawInput} `;
		const take = (re) => {
			const m = text.match(re);
			if (m) text = `${text.slice(0, m.index)} ${text.slice(m.index + m[0].length)}`;
			return m;
		};
		const task = { title: "", priority: "medium", duration_min: 45, starts_at: null, due_at: null, context: "", raw_input: rawInput };
		const found = { priority: false, duration: false };

		if (take(/(?<=\s)(?:high priority|urgent|important|asap|!high|!!)(?=\s)/i)) { task.priority = "high"; found.priority = true; }
		else if (take(/(?<=\s)(?:low priority|someday|!low)(?=\s)/i)) { task.priority = "low"; found.priority = true; }
		else if (take(/(?<=\s)(?:medium priority|normal priority|!medium)(?=\s)/i)) found.priority = true;

		let m = take(/(?<=\s)@([\w-]+)(?=\s)/) || take(new RegExp(`(?<=\\s)(?:at|in)\\s+(${PLACES})(?=\\s)`, "i"));
		if (m) task.context = m[1].replace(/^the\s+/i, "").toLowerCase();

		if (take(/(?<=\s)(?:for\s+)?half\s+an?\s+hour(?=\s)/i)) { task.duration_min = 30; found.duration = true; }
		else if (take(/(?<=\s)(?:for\s+)?(?:an|one)\s+hour(?=\s)/i)) { task.duration_min = 60; found.duration = true; }
		else if ((m = take(/(?<=\s)(?:for\s+)?(\d+(?:\.\d+)?)\s*(hours?|hrs?|h|minutes?|mins?|m)(?=\s)/i))) {
			const n = parseFloat(m[1]);
			task.duration_min = Math.min(1440, Math.max(5, Math.round(/^h/i.test(m[2]) ? n * 60 : n)));
			found.duration = true;
		}

		const deadlineRe = new RegExp(`(?<=\\s)(?:by|before|due(?:\\s+on)?)\\s+((?:${DATE})(?:\\s+${TIME})?|${TIME}(?:\\s+${DATE})?)(?=\\s)`, "i");
		if ((m = take(deadlineRe))) {
			const d = m[1].match(new RegExp(DATE, "i"));
			const t = m[1].match(new RegExp(TIME, "i"));
			const day = (d && resolveDate(d[0], now)) || startOfDay(now);
			task.due_at = wallToIso(at(day, (t && resolveTime(t[0])) || [23, 59]));
		}

		const dateM = take(new RegExp(`(?<=\\s)${DATE}(?=\\s)`, "i"));
		const timeM = take(new RegExp(`(?<=\\s)${TIME}(?=\\s)`, "i"));
		if (dateM || timeM) {
			const day = (dateM && resolveDate(dateM[0], now)) || startOfDay(now);
			let time = timeM ? resolveTime(timeM[0]) : null;
			if (!time && dateM && /tonight/i.test(dateM[0])) time = [20, 0];
			if (time) {
				let start = at(day, time);
				if (!dateM && start < now) start = addDays(start, 1); // "7am" said after 7am means tomorrow
				task.starts_at = wallToIso(start);
				if (!task.due_at) task.due_at = wallToIso(new Date(start.getTime() + task.duration_min * 60000));
			} else if (!task.due_at) {
				task.due_at = wallToIso(at(day, [23, 59]));
			}
		}

		let title = text.replace(/\s+/g, " ").trim().replace(/^(?:remind me to|i need to|i have to|need to|have to)\s+/i, "");
		let prev;
		do {
			prev = title;
			title = title.replace(/[\s,.;-]+$/, "").replace(/\s+(?:on|at|for|by|in|from|and|to)$/i, "");
		} while (title !== prev);
		task.title = title ? title[0].toUpperCase() + title.slice(1) : "";
		return { task, found };
	}

	function previewChips(task) {
		if (!task.title) return "";
		const chips = [html`<span class="chip">${task.title}</span>`];
		if (task.starts_at) chips.push(html`<span class="chip"><i class="bi bi-clock"></i> ${fmt.datetime(task.starts_at)}</span>`);
		if (task.due_at) chips.push(html`<span class="chip"><i class="bi bi-flag"></i> due ${fmt.datetime(task.due_at)}</span>`);
		chips.push(html`<span class="chip">${task.duration_min} min</span>`, html`<span class="chip">${task.priority}</span>`);
		if (task.context) chips.push(html`<span class="chip">@${task.context}</span>`);
		return chips.join("");
	}

	/** Wire a smart-add form (#smart-add, #smart-input, #smart-preview). */
	function smartAdd(onAdded) {
		const form = document.getElementById("smart-add");
		if (!form) return;
		const input = document.getElementById("smart-input");
		const preview = document.getElementById("smart-preview");
		input.addEventListener("input", () => (preview.innerHTML = input.value.trim() ? previewChips(parseTask(input.value).task) : ""));
		form.addEventListener("submit", async (ev) => {
			ev.preventDefault();
			const { task } = parseTask(input.value);
			if (!task.title) {
				preview.innerHTML = '<span class="text-danger">Type what you need to do first.</span>';
				return input.focus();
			}
			try {
				await api("/api/student/tasks", { method: "POST", body: task });
				toast(`Added "${task.title}".`);
				input.value = "";
				preview.innerHTML = "";
				onAdded?.();
			} catch (e) {
				preview.innerHTML = `<span class="text-danger">${esc(e.errors ? Object.values(e.errors).join(" ") : e.message)}</span>`;
			}
		});
	}

	// ================================================================ task form
	let categoriesCache;
	const categories = (fresh) => (fresh || !categoriesCache ? (categoriesCache = api("/api/student/categories").then((r) => r.data)) : categoriesCache);

	async function taskForm(task, onSaved) {
		const cats = await categories();
		formModal({
			title: task ? "Edit task" : "New personal task",
			size: "modal-lg",
			fields: [
				{ name: "title", label: "Title", required: true, maxlength: 200 },
				{ name: "description", label: "Notes", type: "textarea", rows: 3 },
				{ name: "category_id", label: "Category", type: "select", placeholder: "None", options: cats.map((c) => ({ value: c.id, label: c.name })), col: "col-md-4" },
				{ name: "priority", label: "Priority", type: "select", options: [{ value: "low", label: "Low" }, { value: "medium", label: "Medium" }, { value: "high", label: "High" }], col: "col-md-4" },
				{ name: "status", label: "Status", type: "select", options: [{ value: "pending", label: "Pending" }, { value: "in_progress", label: "In progress" }, { value: "completed", label: "Completed" }, { value: "cancelled", label: "Cancelled" }], col: "col-md-4" },
				{ name: "starts_at", label: "Start (optional)", type: "datetime", col: "col-md-6" },
				{ name: "due_at", label: "Due", type: "datetime", col: "col-md-6" },
				{ name: "duration_min", label: "Duration (minutes)", type: "number", min: 5, max: 1440, col: "col-md-4" },
				{ name: "remind_at", label: "Remind me at", type: "datetime", col: "col-md-4", help: "Shown in your notifications." },
				{ name: "context", label: "Place / context", col: "col-md-4", maxlength: 100 },
			],
			values: task || { priority: "medium", status: "pending", duration_min: 45 },
			submit: async (d) => {
				d.category_id = d.category_id || null;
				d.duration_min = d.duration_min ? +d.duration_min : 45;
				const r = await api(task ? `/api/student/tasks/${task.id}` : "/api/student/tasks", { method: task ? "PATCH" : "POST", body: d });
				toast(task ? "Task updated." : "Task added.");
				onSaved?.(r.data);
			},
		});
	}

	async function openTask(id, onChange) {
		const { data } = await api(`/api/student/tasks/${id}`);
		taskForm(data, onChange);
	}

	async function toggleComplete(id, done, onChange) {
		await api(`/api/student/tasks/${id}`, { method: "PATCH", body: { status: done ? "completed" : "pending" } });
		toast(done ? "Nice — marked complete." : "Marked as not done.");
		onChange?.();
	}

	async function deleteTask(id, title, onChange) {
		if (!(await confirm(`Delete "${title}"? This cannot be undone.`, { confirmText: "Delete" }))) return;
		await api(`/api/student/tasks/${id}`, { method: "DELETE" });
		toast("Task deleted.");
		onChange?.();
	}

	// ================================================================ list rendering
	const STATUS_TEXT = { pending: "Pending", in_progress: "In progress", completed: "Completed", overdue: "Overdue", info: "No submission" };
	const STATUS_KIND = { pending: "muted", in_progress: "warning", completed: "success", overdue: "danger", info: "muted" };

	function renderItem(i) {
		const now = Date.now();
		const due = i.due_at ? new Date(i.due_at).getTime() : null;
		const urgent = due && i.status !== "completed" && due > now && due - now < 24 * 3600000;
		const cls = ["task-item", `kind-${i.kind}`, i.status === "completed" ? "is-done" : "", i.status === "overdue" ? "is-overdue" : "", urgent ? "is-urgent" : ""].join(" ");
		const check = i.kind === "personal"
			? html`<input class="form-check-input mt-1" type="checkbox" data-complete="${i.id}" ${raw(i.status === "completed" ? "checked" : "")} aria-label="${i.status === "completed" ? "Mark not done" : "Mark done"}: ${i.title}">`
			: '<i class="bi bi-journal-text mt-1 text-primary" aria-hidden="true"></i>';
		const actions = i.kind === "personal"
			? html`<button class="btn btn-sm btn-light" data-edit="${i.id}" aria-label="Edit ${i.title}"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-light" data-delete="${i.id}" data-title="${i.title}" aria-label="Delete ${i.title}"><i class="bi bi-trash"></i></button>`
			: html`<a class="btn btn-sm btn-outline-primary" href="${i.url}">${i.status === "completed" ? "View" : i.status === "in_progress" ? "Revise" : "Open"}</a>`;
		const title = i.kind === "personal" ? html`<a class="task-title" href="#" data-edit="${i.id}">${i.title}</a>` : html`<a class="task-title" href="${i.url}">${i.title}</a>`;
		return html`<li class="${cls}">
			<span class="kind-bar" aria-hidden="true"></span>
			${raw(check)}
			<div class="task-main">
				${raw(title)}
				<div class="task-meta">
					${raw(badge(i.kind === "academic" ? "Academic" : "Personal", i.kind))}
					<span>${i.group}</span>
					${raw(priorityBadge(i.priority))}
					${raw(badge(STATUS_TEXT[i.status] || i.status, STATUS_KIND[i.status] || "muted"))}
					${raw(i.due_at ? html`<span class="due"><i class="bi bi-flag" aria-hidden="true"></i> ${fmt.datetime(i.due_at)} · ${i.status === "completed" ? "done" : fmt.remaining(i.due_at)}</span>` : '<span>No deadline</span>')}
				</div>
			</div>
			<div class="task-actions">${raw(actions)}</div>
		</li>`;
	}

	/** Delegated handlers for a list containing renderItem() rows. */
	function bindList(root, reload) {
		on(root, "[data-complete]", (el) => toggleComplete(el.dataset.complete, el.checked, reload).catch((e) => { toast(e.message, "danger"); el.checked = !el.checked; }), "change");
		on(root, "[data-edit]", (el, ev) => { ev.preventDefault(); openTask(el.dataset.edit, reload).catch((e) => toast(e.message, "danger")); });
		on(root, "[data-delete]", (el) => deleteTask(el.dataset.delete, el.dataset.title, reload).catch((e) => toast(e.message, "danger")));
	}

	// ================================================================ categories manager
	async function manageCategories(onChange) {
		const { el, modal } = modalShell("Task categories", '<div data-cats></div><form class="d-flex gap-2 mt-3" data-new novalidate><label class="visually-hidden" for="cat-name">New category</label><input class="form-control" id="cat-name" placeholder="New category, e.g. Health" maxlength="60" required><label class="visually-hidden" for="cat-color">Colour</label><input type="color" class="form-control form-control-color" id="cat-color" value="#0d6efd"><button class="btn btn-primary" type="submit">Add</button></form><div class="small text-danger mt-1" data-err></div>', "");
		const box = el.querySelector("[data-cats]");
		const err = el.querySelector("[data-err]");
		async function render() {
			const cats = await categories(true);
			box.innerHTML = cats.length ? `<ul class="list-group">${cats.map((c) => html`<li class="list-group-item d-flex align-items-center gap-2"><span class="d-inline-block rounded-circle cat-dot" data-color="${c.color}"></span><span class="flex-grow-1">${c.name}</span><span class="small text-muted">${c.open_count} open</span><button class="btn btn-sm btn-light" data-del="${c.id}" aria-label="Delete ${c.name}"><i class="bi bi-trash"></i></button></li>`).join("")}</ul>` : '<p class="text-muted">No categories yet.</p>';
			box.querySelectorAll(".cat-dot").forEach((d) => { d.style.width = d.style.height = "12px"; d.style.background = d.dataset.color; });
		}
		el.querySelector("[data-new]").addEventListener("submit", async (ev) => {
			ev.preventDefault();
			err.textContent = "";
			try {
				await api("/api/student/categories", { method: "POST", body: { name: el.querySelector("#cat-name").value, color: el.querySelector("#cat-color").value } });
				el.querySelector("#cat-name").value = "";
				render();
				onChange?.();
			} catch (e) {
				err.textContent = e.errors ? Object.values(e.errors).join(" ") : e.message;
			}
		});
		on(box, "[data-del]", async (b) => {
			if (!(await confirm("Delete this category? Its tasks stay, without a category.", { confirmText: "Delete" }))) return;
			await api(`/api/student/categories/${b.dataset.del}`, { method: "DELETE" });
			render();
			onChange?.();
		});
		modal.show();
		render();
	}

	// ================================================================ tasks page
	const page = document.querySelector("[data-page=student-tasks]");
	if (page) {
		const list = document.getElementById("task-list");
		const footer = document.getElementById("task-footer");
		const params = new URLSearchParams(location.search);
		const state = { page: 1 };
		["kind", "status", "priority", "subject_id", "q", "from", "to"].forEach((k) => params.get(k) && (state[k] = params.get(k)));

		// Reflect URL filters in the controls.
		page.querySelectorAll("[data-f]").forEach((el) => { if (state[el.dataset.f]) el.value = state[el.dataset.f]; });
		page.querySelectorAll("input[name=kind]").forEach((r) => (r.checked = (state.kind || "") === r.value));
		if (state.from || state.to) {
			page.querySelector(".table-toolbar").insertAdjacentHTML("beforeend", html`<span class="badge-soft badge-info">Due ${state.from ? fmt.date(state.from) : "…"} – ${state.to ? fmt.date(new Date(new Date(state.to) - 1).toISOString()) : "…"} <a href="/student/tasks" class="ms-1" aria-label="Clear date filter">×</a></span>`);
		}

		async function load() {
			loading(list);
			try {
				const res = await api("/api/student/items" + EF.qs({ ...state, per_page: 25 }));
				if (!res.data.length) empty(list, state.status === "completed" ? "Nothing completed in this view yet." : "Nothing here. Add a task above, or change the filters.", "check2-square");
				else list.innerHTML = res.data.map(renderItem).join("");
				const m = res.meta;
				footer.innerHTML = `<span>${m.total} item${m.total === 1 ? "" : "s"}</span><nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">
					<li class="page-item${m.page <= 1 ? " disabled" : ""}"><button class="page-link" data-page="${m.page - 1}" aria-label="Previous page">‹</button></li>
					<li class="page-item disabled"><span class="page-link">${m.page} / ${m.pages}</span></li>
					<li class="page-item${m.page >= m.pages ? " disabled" : ""}"><button class="page-link" data-page="${m.page + 1}" aria-label="Next page">›</button></li></ul></nav>`;
				// Keep the URL in sync so filters survive back/forward navigation.
				const q = EF.qs(Object.fromEntries(Object.entries(state).filter(([k]) => k !== "page")));
				history.replaceState(null, "", "/student/tasks" + q);
			} catch (e) {
				failed(list, e, load);
			}
		}
		const set = (k, v) => { state[k] = v; state.page = 1; load(); };
		let timer;
		page.querySelectorAll("[data-f]").forEach((el) => el.addEventListener(el.tagName === "INPUT" ? "input" : "change", () => {
			clearTimeout(timer);
			timer = setTimeout(() => set(el.dataset.f, el.value), el.tagName === "INPUT" ? 300 : 0);
		}));
		page.querySelectorAll("input[name=kind]").forEach((r) => r.addEventListener("change", () => set("kind", r.value)));
		on(footer, "[data-page]", (b) => { state.page = +b.dataset.page; load(); });
		on(page, "[data-action=new-task]", () => taskForm(null, load));
		on(page, "[data-action=categories]", () => manageCategories(load));
		bindList(list, load);
		smartAdd(load);

		api("/api/student/subjects").then(({ data }) => {
			const sel = document.getElementById("t-subject");
			data.forEach((s) => sel.insertAdjacentHTML("beforeend", html`<option value="${s.id}">${s.name}</option>`));
			if (state.subject_id) sel.value = state.subject_id;
		}).catch(() => {});

		load();
		if (params.get("task")) openTask(params.get("task"), load).catch(() => toast("That task could not be found.", "warning"));
	}

	window.EFTasks = { parseTask, smartAdd, taskForm, renderItem, bindList, openTask };
})();
