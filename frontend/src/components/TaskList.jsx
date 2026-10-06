import { useState } from "react";
import { fmtDuration, fmtWhen, fromFrappe } from "../dates";
import { PRIORITY_RANK, STATUS_LABEL, taskStatus } from "../status";

const FILTERS = ["all", "pending", "scheduled", "overdue", "done"];
const STATUS_ORDER = { overdue: 0, scheduled: 1, pending: 2, done: 3 };

const EMPTY = {
  all: "No tasks yet. Add one above to get started.",
  pending: "Nothing pending. Tasks without a start time show up here.",
  scheduled: "No tasks with a fixed start time.",
  overdue: "Nothing overdue. Nice.",
  done: "No finished tasks yet.",
};

function compare(a, b) {
  const s = STATUS_ORDER[a.status] - STATUS_ORDER[b.status];
  if (s) return s;
  if (a.status === "scheduled") return fromFrappe(a.task.fixed_start) - fromFrappe(b.task.fixed_start);
  const p = (PRIORITY_RANK[a.task.priority] ?? 1) - (PRIORITY_RANK[b.task.priority] ?? 1);
  if (p) return p;
  const da = fromFrappe(a.task.deadline);
  const db = fromFrappe(b.task.deadline);
  if (da && db) return da - db;
  return da ? -1 : db ? 1 : 0;
}

export default function TaskList({ tasks, loading, onToggle, onEdit, onDelete }) {
  const [filter, setFilter] = useState("all");
  const rows = tasks.map((task) => ({ task, status: taskStatus(task) }));
  const counts = rows.reduce(
    (acc, r) => ({ ...acc, [r.status]: acc[r.status] + 1 }),
    { all: rows.length, pending: 0, scheduled: 0, overdue: 0, done: 0 }
  );
  const shown = rows.filter((r) => filter === "all" || r.status === filter).sort(compare);

  return (
    <section className="tasks card" aria-labelledby="tasks-title">
      <div className="section-head">
        <h2 id="tasks-title">Your tasks</h2>
      </div>

      <div className="filters" role="tablist" aria-label="Filter tasks">
        {FILTERS.map((f) => (
          <button
            key={f}
            type="button"
            role="tab"
            aria-selected={filter === f}
            className={`filter f-${f}${filter === f ? " active" : ""}`}
            onClick={() => setFilter(f)}
          >
            {f === "all" ? "All" : STATUS_LABEL[f]}
            <span className="count">{counts[f]}</span>
          </button>
        ))}
      </div>

      {loading ? (
        <p className="empty">Loading your tasks…</p>
      ) : shown.length === 0 ? (
        <p className="empty">{EMPTY[filter]}</p>
      ) : (
        <ul className="task-list">
          {shown.map(({ task, status }) => (
            <TaskItem
              key={task.name}
              task={task}
              status={status}
              onToggle={onToggle}
              onEdit={onEdit}
              onDelete={onDelete}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

function TaskItem({ task, status, onToggle, onEdit, onDelete }) {
  const [confirming, setConfirming] = useState(false);
  const start = fromFrappe(task.fixed_start);
  const due = fromFrappe(task.deadline);
  const checkId = `done-${task.name}`;

  return (
    <li className={`task s-${status}`}>
      <input
        id={checkId}
        type="checkbox"
        className="check"
        checked={Boolean(Number(task.done))}
        onChange={() => onToggle(task)}
        aria-label={Number(task.done) ? `Mark “${task.title}” as not done` : `Mark “${task.title}” as done`}
      />
      <div className="task-body">
        <label htmlFor={checkId} className="task-title">
          {task.title}
        </label>
        <div className="task-meta">
          <span className={`pill pri-${task.priority}`}>{task.priority}</span>
          <span>{fmtDuration(task.duration_min)}</span>
          {start && <span>Starts {fmtWhen(start)}</span>}
          {due && <span className={status === "overdue" ? "late" : ""}>Due {fmtWhen(due)}</span>}
          {task.context && <span>@{task.context}</span>}
        </div>
        {task.raw && <div className="task-raw">“{task.raw}”</div>}
      </div>
      <span className={`status st-${status}`}>{STATUS_LABEL[status]}</span>
      <div className="task-actions">
        {confirming ? (
          <>
            <button type="button" className="btn danger sm" onClick={() => onDelete(task)}>
              Delete
            </button>
            <button type="button" className="btn ghost sm" onClick={() => setConfirming(false)}>
              Keep
            </button>
          </>
        ) : (
          <>
            <button type="button" className="btn ghost sm" onClick={() => onEdit(task)}>
              Edit
            </button>
            <button
              type="button"
              className="btn ghost sm"
              onClick={() => setConfirming(true)}
              aria-label={`Delete “${task.title}”`}
            >
              Delete
            </button>
          </>
        )}
      </div>
    </li>
  );
}
