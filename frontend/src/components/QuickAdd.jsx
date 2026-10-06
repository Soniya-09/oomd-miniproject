import { useMemo, useState } from "react";
import { parseTask } from "../parseTask";
import { fmtDuration, fmtWhen, fromFrappe } from "../dates";

const EXAMPLES = [
  "Gym tomorrow 7am for 1 hour high priority @gym",
  "Submit DBMS assignment by friday 5pm urgent",
  "Read chapter 4 for 30 min tonight",
  "Revise notes 1.5h low priority due tomorrow",
];

export default function QuickAdd({ onAdd, onOpenForm }) {
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const parsed = useMemo(() => (text.trim() ? parseTask(text) : null), [text]);
  const task = parsed?.task;

  async function submit(e) {
    e.preventDefault();
    if (!task?.title || busy) return;
    setBusy(true);
    const ok = await onAdd(task);
    setBusy(false);
    if (ok) setText("");
  }

  return (
    <section className="quick card" aria-labelledby="quick-title">
      <div className="section-head">
        <h2 id="quick-title">Add a task</h2>
        <p className="muted">Type it the way you'd say it. Check the fields below, then press Enter.</p>
      </div>

      <form onSubmit={submit} className="quick-form">
        <input
          id="quick-input"
          className="quick-input"
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="e.g. Gym tomorrow 7am for 1 hour high priority @gym"
          autoComplete="off"
          aria-label="Describe your task"
          aria-describedby="quick-preview"
        />
        <div className="quick-actions">
          <button className="btn primary" disabled={!task?.title || busy}>
            {busy ? "Adding…" : "Add task"}
          </button>
          <button type="button" className="btn ghost" onClick={() => onOpenForm(task ?? null)}>
            {task ? "Edit details" : "Use form"}
          </button>
        </div>
      </form>

      <div id="quick-preview" aria-live="polite">
        {task ? (
          <dl className="parsed">
            <div>
              <dt>Title</dt>
              <dd>{task.title || <span className="warn">Add a few words for the title</span>}</dd>
            </div>
            <div>
              <dt>Priority</dt>
              <dd>
                <span className={`pill pri-${task.priority}`}>{task.priority}</span>
              </dd>
            </div>
            <div>
              <dt>Duration</dt>
              <dd>
                {fmtDuration(task.duration_min)}
                {!parsed.found.duration && <span className="muted"> (default)</span>}
              </dd>
            </div>
            <div>
              <dt>Starts</dt>
              <dd>{task.fixed_start ? fmtWhen(fromFrappe(task.fixed_start)) : <span className="muted">Flexible</span>}</dd>
            </div>
            <div>
              <dt>Due</dt>
              <dd>{task.deadline ? fmtWhen(fromFrappe(task.deadline)) : <span className="muted">No deadline</span>}</dd>
            </div>
            {task.context && (
              <div>
                <dt>Where</dt>
                <dd>@{task.context}</dd>
              </div>
            )}
          </dl>
        ) : (
          <div className="examples">
            <span className="muted">Try one:</span>
            {EXAMPLES.map((ex) => (
              <button type="button" className="example" key={ex} onClick={() => setText(ex)}>
                {ex}
              </button>
            ))}
          </div>
        )}
      </div>
    </section>
  );
}
