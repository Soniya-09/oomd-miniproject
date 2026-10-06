import { useEffect, useRef, useState } from "react";
import { fromInput, toInput } from "../dates";

function initialForm(task) {
  return {
    title: task?.title ?? "",
    priority: task?.priority ?? "medium",
    duration_min: String(task?.duration_min ?? 45),
    fixed_start: toInput(task?.fixed_start),
    deadline: toInput(task?.deadline),
    context: task?.context ?? "",
    raw: task?.raw ?? "",
  };
}

export default function TaskForm({ task, onSave, onClose }) {
  const editing = Boolean(task?.name);
  const [form, setForm] = useState(() => initialForm(task));
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const titleRef = useRef(null);

  useEffect(() => {
    titleRef.current?.focus();
    const onKey = (e) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  async function submit(e) {
    e.preventDefault();
    const duration = Number(form.duration_min);
    // Same rules the server enforces in NovaTask.validate() and the DocType's reqd flag.
    if (!form.title.trim()) return setError("Give the task a title.");
    if (!Number.isFinite(duration) || duration <= 0) {
      return setError("Duration must be a positive number of minutes.");
    }

    const payload = {
      title: form.title.trim(),
      priority: form.priority,
      duration_min: Math.round(duration),
      fixed_start: fromInput(form.fixed_start),
      deadline: fromInput(form.deadline),
      context: form.context.trim(),
    };
    if (!editing && form.raw) payload.raw = form.raw;

    setBusy(true);
    setError("");
    const err = await onSave(payload);
    setBusy(false);
    if (err) setError(err);
  }

  return (
    <div
      className="overlay"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="modal card" role="dialog" aria-modal="true" aria-labelledby="form-title">
        <div className="modal-head">
          <h2 id="form-title">{editing ? "Edit task" : "New task"}</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Close">
            ×
          </button>
        </div>

        {form.raw && (
          <p className="raw-note">
            From: <q>{form.raw}</q>
          </p>
        )}

        <form onSubmit={submit} className="stack">
          <label className="field">
            <span>Title</span>
            <input id="task-title" ref={titleRef} value={form.title} onChange={set("title")} required />
          </label>

          <div className="row-2">
            <label className="field">
              <span>Priority</span>
              <select id="task-priority" value={form.priority} onChange={set("priority")}>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
              </select>
            </label>
            <label className="field">
              <span>Duration (minutes)</span>
              <input
                id="task-duration"
                type="number"
                min="1"
                step="5"
                value={form.duration_min}
                onChange={set("duration_min")}
                required
              />
            </label>
          </div>

          <div className="row-2">
            <label className="field">
              <span>Fixed start</span>
              <input id="task-start" type="datetime-local" value={form.fixed_start} onChange={set("fixed_start")} />
              <small className="muted">Leave empty to let the day plan find a slot.</small>
            </label>
            <label className="field">
              <span>Deadline</span>
              <input id="task-deadline" type="datetime-local" value={form.deadline} onChange={set("deadline")} />
            </label>
          </div>

          <label className="field">
            <span>Context / location</span>
            <input id="task-context" value={form.context} onChange={set("context")} placeholder="e.g. gym, college, home" />
          </label>

          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}

          <div className="modal-actions">
            <button type="button" className="btn ghost" onClick={onClose}>
              Cancel
            </button>
            <button className="btn primary" disabled={busy}>
              {busy ? "Saving…" : editing ? "Save changes" : "Add task"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
