import { useCallback, useEffect, useRef, useState } from "react";
import { auth, tasks as tasksApi } from "../api";
import DayPlan from "./DayPlan.jsx";
import Logo from "./Logo.jsx";
import QuickAdd from "./QuickAdd.jsx";
import TaskForm from "./TaskForm.jsx";
import TaskList from "./TaskList.jsx";

function greeting(now = new Date()) {
  const h = now.getHours();
  if (h < 12) return "Good morning";
  if (h < 17) return "Good afternoon";
  return "Good evening";
}

export default function Dashboard({ user, onLogout, onSessionExpired }) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [toast, setToast] = useState("");
  // undefined = form closed, null = new empty task, object = prefilled or existing task
  const [formTask, setFormTask] = useState(undefined);
  const toastTimer = useRef(null);

  const flash = useCallback((msg) => {
    setToast(msg);
    clearTimeout(toastTimer.current);
    toastTimer.current = setTimeout(() => setToast(""), 2500);
  }, []);

  useEffect(() => () => clearTimeout(toastTimer.current), []);

  // A 401/403 may just be a permission problem, so confirm the session is really gone first.
  const handleError = useCallback(
    (err) => {
      if (err.status === 401 || err.status === 403) {
        auth.me().catch(() => onSessionExpired());
      }
      setError(err.message);
      return err.message;
    },
    [onSessionExpired]
  );

  const load = useCallback(async () => {
    try {
      setItems(await tasksApi.list());
      setError("");
    } catch (err) {
      handleError(err);
    } finally {
      setLoading(false);
    }
  }, [handleError]);

  useEffect(() => {
    load();
  }, [load]);

  async function addTask(task) {
    try {
      await tasksApi.create(task);
      flash(`Added “${task.title}”`);
      await load();
      return true;
    } catch (err) {
      handleError(err);
      return false;
    }
  }

  const closeForm = useCallback(() => setFormTask(undefined), []);

  async function saveForm(payload) {
    const existing = formTask?.name;
    try {
      if (existing) await tasksApi.update(existing, payload);
      else await tasksApi.create(payload);
      setFormTask(undefined);
      flash(existing ? "Task updated" : `Added “${payload.title}”`);
      await load();
      return null;
    } catch (err) {
      if (err.status === 401 || err.status === 403) auth.me().catch(() => onSessionExpired());
      return err.message;
    }
  }

  async function toggleDone(task) {
    const done = Number(task.done) ? 0 : 1;
    setItems((xs) => xs.map((x) => (x.name === task.name ? { ...x, done } : x)));
    try {
      await tasksApi.update(task.name, { done });
      if (done) flash(`Done: “${task.title}”`);
    } catch (err) {
      handleError(err);
    }
    load();
  }

  async function removeTask(task) {
    try {
      await tasksApi.remove(task.name);
      flash("Task deleted");
      await load();
    } catch (err) {
      handleError(err);
    }
  }

  const firstName = (user.full_name || user.email).split(" ")[0];
  const openCount = items.filter((t) => !Number(t.done)).length;

  return (
    <div className="app">
      <header className="topbar">
        <div className="brand">
          <Logo />
          <span>ScheduNova</span>
        </div>
        <div className="topbar-right">
          <span className="who" title={user.email}>
            {user.full_name || user.email}
          </span>
          <button type="button" className="btn ghost sm" onClick={onLogout}>
            Log out
          </button>
        </div>
      </header>

      <main className="container">
        <div className="hello">
          <h1>
            {greeting()}, {firstName}
          </h1>
          <p className="muted">
            {new Date().toLocaleDateString([], { weekday: "long", day: "numeric", month: "long" })} ·{" "}
            {openCount === 0 ? "No open tasks" : `${openCount} open task${openCount === 1 ? "" : "s"}`}
          </p>
        </div>

        {error && (
          <div className="banner" role="alert">
            <span>{error}</span>
            <button type="button" className="btn ghost sm" onClick={() => { setError(""); load(); }}>
              Retry
            </button>
          </div>
        )}

        <QuickAdd onAdd={addTask} onOpenForm={(t) => setFormTask(t)} />

        <div className="grid">
          <DayPlan tasks={items} onEdit={(t) => setFormTask(t)} />
          <TaskList
            tasks={items}
            loading={loading}
            onToggle={toggleDone}
            onEdit={(t) => setFormTask(t)}
            onDelete={removeTask}
          />
        </div>
      </main>

      {formTask !== undefined && <TaskForm task={formTask} onSave={saveForm} onClose={closeForm} />}

      <div className="toast-region" aria-live="polite">
        {toast && <div className="toast">{toast}</div>}
      </div>
    </div>
  );
}
