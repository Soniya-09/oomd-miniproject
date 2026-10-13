/* EduFlow shared front-end helpers (no build step). Exposes window.EF. */
(function () {
	"use strict";

	const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content || "";
	const TZ = meta("app-timezone") || Intl.DateTimeFormat().resolvedOptions().timeZone;

	// ---------------------------------------------------------------- escaping
	const esc = (v) =>
		String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

	/** Tagged template that escapes interpolations unless wrapped with EF.raw(). */
	class Raw { constructor(s) { this.s = s; } }
	const raw = (s) => new Raw(s);
	function html(strings, ...values) {
		return strings.reduce((out, s, i) => {
			if (i === 0) return s;
			const v = values[i - 1];
			const str = v instanceof Raw ? v.s : Array.isArray(v) ? v.map((x) => (x instanceof Raw ? x.s : esc(x))).join("") : esc(v);
			return out + str + s;
		}, "");
	}

	// ---------------------------------------------------------------- API
	class ApiError extends Error {
		constructor(message, status, errors) {
			super(message);
			this.status = status;
			this.errors = errors || {};
		}
	}

	async function api(path, { method = "GET", body, form } = {}) {
		const headers = { Accept: "application/json" };
		if (method !== "GET") headers["X-CSRF-Token"] = meta("csrf-token");
		let payload;
		if (form) payload = form;
		else if (body !== undefined) {
			headers["Content-Type"] = "application/json";
			payload = JSON.stringify(body);
		}
		let res;
		try {
			res = await fetch(path, { method, headers, body: payload, credentials: "same-origin" });
		} catch {
			throw new ApiError("Cannot reach the server. Check your connection.", 0);
		}
		if (res.status === 401) {
			window.location.href = "/login?next=" + encodeURIComponent(location.pathname);
			throw new ApiError("Your session ended. Please log in again.", 401);
		}
		const type = res.headers.get("Content-Type") || "";
		const data = type.includes("application/json") ? await res.json().catch(() => null) : null;
		if (!res.ok) throw new ApiError(data?.error || `Request failed (${res.status}).`, res.status, data?.errors);
		return data;
	}

	const qs = (obj) => {
		const p = new URLSearchParams();
		Object.entries(obj).forEach(([k, v]) => {
			if (v !== undefined && v !== null && v !== "") p.set(k, v);
		});
		const s = p.toString();
		return s ? "?" + s : "";
	};

	// ---------------------------------------------------------------- dates (institution time zone)
	function tzOffsetMs(date, tz) {
		const parts = Object.fromEntries(
			new Intl.DateTimeFormat("en-US", { timeZone: tz, hourCycle: "h23", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", second: "2-digit" })
				.formatToParts(date)
				.map((p) => [p.type, p.value]),
		);
		const asUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour % 24, +parts.minute, +parts.second);
		return asUtc - Math.floor(date.getTime() / 1000) * 1000;
	}

	/** Wall-clock parts in the institution zone. */
	function zoned(iso) {
		const d = iso instanceof Date ? iso : new Date(iso);
		const shifted = new Date(d.getTime() + tzOffsetMs(d, TZ));
		return {
			y: shifted.getUTCFullYear(), m: shifted.getUTCMonth(), d: shifted.getUTCDate(),
			h: shifted.getUTCHours(), mi: shifted.getUTCMinutes(), dow: shifted.getUTCDay(),
		};
	}

	/** "YYYY-MM-DDTHH:mm" in institution zone -> ISO UTC string. */
	function zonedInputToIso(value) {
		if (!value) return null;
		const [date, time = "00:00"] = value.split("T");
		const [y, m, d] = date.split("-").map(Number);
		const [h, mi] = time.split(":").map(Number);
		const guess = Date.UTC(y, m - 1, d, h, mi);
		let ts = guess - tzOffsetMs(new Date(guess), TZ);
		ts = guess - tzOffsetMs(new Date(ts), TZ); // second pass handles DST edges
		return new Date(ts).toISOString();
	}

	const pad = (n) => String(n).padStart(2, "0");
	function isoToZonedInput(iso) {
		if (!iso) return "";
		const z = zoned(iso);
		return `${z.y}-${pad(z.m + 1)}-${pad(z.d)}T${pad(z.h)}:${pad(z.mi)}`;
	}

	/** Date key "YYYY-MM-DD" in institution zone. */
	function dayKey(iso) {
		const z = zoned(iso);
		return `${z.y}-${pad(z.m + 1)}-${pad(z.d)}`;
	}

	const fmt = {
		datetime(iso) {
			if (!iso) return "—";
			return new Intl.DateTimeFormat(undefined, { timeZone: TZ, dateStyle: "medium", timeStyle: "short" }).format(new Date(iso));
		},
		date(iso) {
			if (!iso) return "—";
			return new Intl.DateTimeFormat(undefined, { timeZone: TZ, dateStyle: "medium" }).format(new Date(iso));
		},
		time(iso) {
			return new Intl.DateTimeFormat(undefined, { timeZone: TZ, timeStyle: "short" }).format(new Date(iso));
		},
		/** "in 3 h", "2 d overdue" */
		remaining(iso, now = Date.now()) {
			if (!iso) return "No deadline";
			const diff = new Date(iso).getTime() - now;
			const abs = Math.abs(diff);
			const mins = Math.round(abs / 60000);
			let s;
			if (mins < 60) s = `${mins} min`;
			else if (mins < 60 * 48) s = `${Math.round(mins / 60)} h`;
			else s = `${Math.round(mins / 1440)} d`;
			return diff >= 0 ? `in ${s}` : `${s} overdue`;
		},
		bytes(n) {
			if (n < 1024) return `${n} B`;
			if (n < 1048576) return `${(n / 1024).toFixed(0)} KB`;
			return `${(n / 1048576).toFixed(1)} MB`;
		},
		pct(n) {
			return n === null || n === undefined ? "—" : `${Math.round(n)}%`;
		},
	};

	// ---------------------------------------------------------------- UI bits
	function toast(message, type = "success") {
		const wrap = document.getElementById("toasts");
		if (!wrap) return alert(message);
		const el = document.createElement("div");
		const color = { success: "text-bg-success", danger: "text-bg-danger", info: "text-bg-primary", warning: "text-bg-warning" }[type] || "text-bg-dark";
		el.className = `toast align-items-center border-0 ${color}`;
		el.setAttribute("role", type === "danger" ? "alert" : "status");
		el.innerHTML = html`<div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
		wrap.appendChild(el);
		const t = new bootstrap.Toast(el, { delay: 4500 });
		el.addEventListener("hidden.bs.toast", () => el.remove());
		t.show();
	}

	function modalShell(title, bodyHtml, footerHtml, size = "") {
		const el = document.createElement("div");
		el.className = "modal fade";
		el.tabIndex = -1;
		el.setAttribute("aria-hidden", "true");
		const id = "m" + Math.random().toString(36).slice(2);
		el.setAttribute("aria-labelledby", id);
		el.innerHTML = `<div class="modal-dialog modal-dialog-scrollable ${size}"><div class="modal-content">
			<div class="modal-header"><h2 class="modal-title h5" id="${id}">${esc(title)}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
			<div class="modal-body">${bodyHtml}</div>
			${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ""}
		</div></div>`;
		document.body.appendChild(el);
		const modal = new bootstrap.Modal(el);
		el.addEventListener("hidden.bs.modal", () => el.remove());
		return { el, modal };
	}

	function confirmDialog(message, { title = "Please confirm", confirmText = "Confirm", danger = true } = {}) {
		return new Promise((resolve) => {
			const { el, modal } = modalShell(
				title,
				`<p class="mb-0">${esc(message)}</p>`,
				`<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
				 <button type="button" class="btn ${danger ? "btn-danger" : "btn-primary"}" data-ok>${esc(confirmText)}</button>`,
			);
			let ok = false;
			el.querySelector("[data-ok]").addEventListener("click", () => { ok = true; modal.hide(); });
			el.addEventListener("hidden.bs.modal", () => resolve(ok));
			modal.show();
		});
	}

	/**
	 * Build a modal form.
	 * fields: [{name, label, type: text|email|password|number|textarea|select|multiselect|checkbox|datetime|date|time|file,
	 *           options: [{value,label}], required, help, min, max, step, placeholder, multiple, accept, col}]
	 * submit(data, formEl) -> Promise; throw ApiError to show field errors.
	 */
	function formModal({ title, fields, values = {}, submitText = "Save", submit, size = "" }) {
		const fieldHtml = fields.map((f) => fieldMarkup(f, values[f.name])).join("");
		const { el, modal } = modalShell(
			title,
			`<form novalidate><div class="alert alert-danger d-none" data-form-error role="alert"></div><div class="row g-3">${fieldHtml}</div></form>`,
			`<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
			 <button type="button" class="btn btn-primary" data-submit>${esc(submitText)}</button>`,
			size,
		);
		const form = el.querySelector("form");
		const btn = el.querySelector("[data-submit]");
		const run = async () => {
			clearErrors(form);
			btn.disabled = true;
			btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving…`;
			try {
				await submit(readForm(form, fields), form);
				modal.hide();
			} catch (e) {
				showErrors(form, e);
			} finally {
				btn.disabled = false;
				btn.textContent = submitText;
			}
		};
		btn.addEventListener("click", run);
		form.addEventListener("submit", (ev) => { ev.preventDefault(); run(); });
		el.addEventListener("shown.bs.modal", () => form.querySelector("input:not([type=hidden]),select,textarea")?.focus());
		modal.show();
		return { el, modal, form };
	}

	function fieldMarkup(f, value) {
		const id = "f_" + f.name + "_" + Math.random().toString(36).slice(2, 7);
		const req = f.required ? " required" : "";
		const col = f.col || "col-12";
		const help = f.help ? `<div class="form-text">${esc(f.help)}</div>` : "";
		const label = `<label class="form-label" for="${id}">${esc(f.label)}${f.required ? ' <span class="text-danger" aria-hidden="true">*</span>' : ""}</label>`;
		const fb = `<div class="invalid-feedback" data-error-for="${esc(f.name)}"></div>`;
		const v = value ?? f.default ?? "";
		switch (f.type) {
			case "textarea":
				return `<div class="${col}">${label}<textarea class="form-control" id="${id}" name="${esc(f.name)}" rows="${f.rows || 4}"${req}>${esc(v)}</textarea>${help}${fb}</div>`;
			case "select":
			case "multiselect": {
				const multiple = f.type === "multiselect";
				const selected = new Set((Array.isArray(v) ? v : [v]).map(String));
				const opts = (f.placeholder && !multiple ? [`<option value="">${esc(f.placeholder)}</option>`] : [])
					.concat((f.options || []).map((o) => `<option value="${esc(o.value)}"${selected.has(String(o.value)) ? " selected" : ""}>${esc(o.label)}</option>`))
					.join("");
				return `<div class="${col}">${label}<select class="form-select" id="${id}" name="${esc(f.name)}"${multiple ? ` multiple size="${Math.min(8, Math.max(4, (f.options || []).length))}"` : ""}${req}>${opts}</select>${help}${fb}</div>`;
			}
			case "checkbox":
				return `<div class="${col}"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="${id}" name="${esc(f.name)}"${v === true || v === 1 || v === "1" ? " checked" : ""}><label class="form-check-label" for="${id}">${esc(f.label)}</label></div>${help}${fb}</div>`;
			case "datetime":
				return `<div class="${col}">${label}<input class="form-control" type="datetime-local" id="${id}" name="${esc(f.name)}" value="${esc(isoToZonedInput(v))}"${req}>${help || `<div class="form-text">Time zone: ${esc(TZ)}</div>`}${fb}</div>`;
			case "file":
				return `<div class="${col}">${label}<input class="form-control" type="file" id="${id}" name="${esc(f.name)}"${f.multiple ? " multiple" : ""}${f.accept ? ` accept="${esc(f.accept)}"` : ""}${req}>${help}${fb}</div>`;
			default: {
				const attrs = ["min", "max", "step", "placeholder", "maxlength", "autocomplete"].filter((a) => f[a] !== undefined).map((a) => ` ${a}="${esc(f[a])}"`).join("");
				return `<div class="${col}">${label}<input class="form-control" type="${esc(f.type || "text")}" id="${id}" name="${esc(f.name)}" value="${esc(v)}"${attrs}${req}>${help}${fb}</div>`;
			}
		}
	}

	function readForm(form, fields) {
		const out = {};
		for (const f of fields) {
			const el = form.elements[f.name];
			if (!el) continue;
			if (f.type === "checkbox") out[f.name] = el.checked;
			else if (f.type === "multiselect") out[f.name] = [...el.selectedOptions].map((o) => o.value);
			else if (f.type === "datetime") out[f.name] = el.value ? zonedInputToIso(el.value) : null;
			else if (f.type === "file") out[f.name] = el.files;
			else if (f.type === "number") out[f.name] = el.value === "" ? null : el.value;
			else out[f.name] = el.value;
		}
		return out;
	}

	function clearErrors(form) {
		form.querySelectorAll(".is-invalid").forEach((el) => el.classList.remove("is-invalid"));
		form.querySelectorAll("[data-error-for]").forEach((el) => (el.textContent = ""));
		const box = form.querySelector("[data-form-error]");
		if (box) box.classList.add("d-none");
	}

	function showErrors(form, e) {
		const errors = e.errors || {};
		let placed = false;
		for (const [name, msg] of Object.entries(errors)) {
			const fb = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
			const input = form.elements[name];
			if (fb && input) {
				(input.classList ? input : input[0]).classList.add("is-invalid");
				fb.textContent = msg;
				placed = true;
			}
		}
		const box = form.querySelector("[data-form-error]");
		const unplaced = Object.entries(errors).filter(([n]) => !form.querySelector(`[data-error-for="${CSS.escape(n)}"]`));
		if (box && (!placed || unplaced.length)) {
			box.textContent = unplaced.length ? unplaced.map(([, m]) => m).join(" ") : e.message;
			box.classList.remove("d-none");
		}
		form.querySelector(".is-invalid")?.focus();
	}

	// ---------------------------------------------------------------- server-side table
	/**
	 * new EF.Table(container, {
	 *   url, columns: [{key, label, sort, render(row)}], filters: {name: value}, perPage,
	 *   empty: "No users yet", onLoad(rows)
	 * })
	 * Filter inputs inside container with [data-filter=name] are wired automatically.
	 */
	class Table {
		constructor(root, opts) {
			this.root = root;
			this.o = Object.assign({ perPage: 20, empty: "Nothing to show yet." }, opts);
			this.state = Object.assign({ page: 1, sort: opts.sort || "" }, opts.filters || {});
			this.body = root.querySelector("[data-table-body]");
			this.footer = root.querySelector("[data-table-footer]");
			this.head = root.querySelector("[data-table-head]");
			this.renderHead();
			let timer;
			root.querySelectorAll("[data-filter]").forEach((el) => {
				const name = el.dataset.filter;
				if (this.state[name] !== undefined) el.value = this.state[name];
				const handler = () => {
					clearTimeout(timer);
					timer = setTimeout(() => { this.state[name] = el.value; this.state.page = 1; this.load(); }, el.tagName === "INPUT" ? 300 : 0);
				};
				el.addEventListener(el.tagName === "INPUT" ? "input" : "change", handler);
			});
			this.load();
		}

		renderHead() {
			this.head.innerHTML = "<tr>" + this.o.columns.map((c) => {
				if (!c.sort) return `<th scope="col"${c.className ? ` class="${c.className}"` : ""}>${esc(c.label)}</th>`;
				const active = this.state.sort.replace("-", "") === c.sort;
				const desc = this.state.sort.startsWith("-");
				const icon = active ? (desc ? "bi-sort-down" : "bi-sort-up") : "bi-arrow-down-up opacity-50";
				const aria = active ? (desc ? "descending" : "ascending") : "none";
				return `<th scope="col" class="sortable" aria-sort="${aria}"><button type="button" data-sort="${esc(c.sort)}">${esc(c.label)} <i class="bi ${icon}" aria-hidden="true"></i></button></th>`;
			}).join("") + "</tr>";
			this.head.querySelectorAll("[data-sort]").forEach((b) => b.addEventListener("click", () => {
				const key = b.dataset.sort;
				this.state.sort = this.state.sort === key ? "-" + key : key;
				this.renderHead();
				this.load();
			}));
		}

		async load() {
			const cols = this.o.columns.length;
			this.body.innerHTML = `<tr><td colspan="${cols}"><div class="loading-state"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div></td></tr>`;
			try {
				const res = await api(this.o.url + qs({ ...this.state, per_page: this.o.perPage }));
				this.rows = res.data;
				if (!res.data.length) {
					this.body.innerHTML = `<tr><td colspan="${cols}"><div class="empty-state"><i class="bi bi-inbox" aria-hidden="true"></i>${esc(this.o.empty)}</div></td></tr>`;
				} else {
					this.body.innerHTML = res.data.map((r) => "<tr>" + this.o.columns.map((c) => `<td${c.className ? ` class="${c.className}"` : ""}>${c.render ? c.render(r) : esc(r[c.key])}</td>`).join("") + "</tr>").join("");
				}
				this.renderFooter(res.meta);
				this.o.onLoad?.(res.data, this);
			} catch (e) {
				this.body.innerHTML = `<tr><td colspan="${cols}"><div class="error-state"><i class="bi bi-exclamation-triangle me-1"></i>${esc(e.message)} <button class="btn btn-sm btn-link" data-retry>Retry</button></div></td></tr>`;
				this.body.querySelector("[data-retry]").addEventListener("click", () => this.load());
			}
		}

		renderFooter(meta) {
			if (!this.footer || !meta) return;
			const from = meta.total ? (meta.page - 1) * meta.per_page + 1 : 0;
			const to = Math.min(meta.total, meta.page * meta.per_page);
			this.footer.innerHTML = `<span>${from}–${to} of ${meta.total}</span>
				<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">
					<li class="page-item${meta.page <= 1 ? " disabled" : ""}"><button class="page-link" data-page="${meta.page - 1}" aria-label="Previous page">‹</button></li>
					<li class="page-item disabled"><span class="page-link">${meta.page} / ${meta.pages}</span></li>
					<li class="page-item${meta.page >= meta.pages ? " disabled" : ""}"><button class="page-link" data-page="${meta.page + 1}" aria-label="Next page">›</button></li>
				</ul></nav>`;
			this.footer.querySelectorAll("[data-page]").forEach((b) => b.addEventListener("click", () => {
				this.state.page = +b.dataset.page;
				this.load();
			}));
		}

		row(id) {
			return (this.rows || []).find((r) => String(r.id) === String(id));
		}
	}

	// ---------------------------------------------------------------- small render helpers
	const badge = (text, kind = "muted") => `<span class="badge-soft badge-${esc(kind)}">${esc(text)}</span>`;
	const priorityBadge = (p) => badge(p ? p[0].toUpperCase() + p.slice(1) : "—", p || "muted");
	const STATUS_KIND = {
		completed: "success", graded: "success", submitted: "info", resubmitted: "info", under_review: "info",
		pending: "muted", in_progress: "warning", overdue: "danger", returned: "warning", missing: "danger",
		draft: "muted", published: "success", closed: "muted", cancelled: "muted", late: "warning",
	};
	const statusLabel = (s) => String(s || "").replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase());
	const statusBadge = (s) => badge(statusLabel(s), STATUS_KIND[s] || "muted");

	const loading = (el, text = "Loading…") => (el.innerHTML = `<div class="loading-state"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${esc(text)}</div>`);
	const empty = (el, text, icon = "inbox") => (el.innerHTML = `<div class="empty-state"><i class="bi bi-${esc(icon)}" aria-hidden="true"></i>${esc(text)}</div>`);
	const failed = (el, e, retry) => {
		el.innerHTML = `<div class="error-state"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>${esc(e.message || e)} ${retry ? '<button class="btn btn-sm btn-link" data-retry>Retry</button>' : ""}</div>`;
		if (retry) el.querySelector("[data-retry]").addEventListener("click", retry);
	};

	/** Delegated click handler: on(root, "[data-action=edit]", (el, ev) => …) */
	function on(root, selector, handler, type = "click") {
		root.addEventListener(type, (ev) => {
			const el = ev.target.closest(selector);
			if (el && root.contains(el)) handler(el, ev);
		});
	}

	// ---------------------------------------------------------------- notification badge
	async function refreshBell() {
		const el = document.getElementById("notif-count");
		if (!el) return;
		try {
			const r = await api("/api/notifications/unread-count");
			el.textContent = r.count > 99 ? "99+" : r.count;
			el.classList.toggle("d-none", !r.count);
			document.getElementById("notif-bell")?.setAttribute("aria-label", r.count ? `Notifications, ${r.count} unread` : "Notifications");
		} catch {
			/* badge is best-effort */
		}
	}
	if (document.getElementById("notif-count")) {
		refreshBell();
		setInterval(refreshBell, 60000);
	}

	window.EF = {
		TZ, api, ApiError, qs, esc, html, raw, fmt, zoned, dayKey, zonedInputToIso, isoToZonedInput,
		toast, confirm: confirmDialog, formModal, modalShell, showErrors, clearErrors, Table,
		badge, priorityBadge, statusBadge, statusLabel, loading, empty, failed, on, refreshBell,
	};
})();
