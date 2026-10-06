import { useState } from "react";
import { auth } from "../api";
import Logo from "./Logo.jsx";

export default function AuthScreen({ onAuthed }) {
  const [mode, setMode] = useState("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const isSignup = mode === "signup";

  function switchMode(next) {
    setMode(next);
    setError("");
  }

  async function submit(e) {
    e.preventDefault();
    setError("");
    setBusy(true);
    try {
      if (isSignup) await auth.signUp(name.trim(), email.trim(), password);
      await auth.login(email.trim(), password);
      onAuthed(await auth.me());
    } catch (err) {
      setError(err.message);
      setBusy(false);
    }
  }

  return (
    <main className="auth">
      <section className="auth-intro">
        <div className="brand">
          <Logo size={36} />
          <span>ScheduNova</span>
        </div>
        <h1>Say what you need to do. Get a plan for your day.</h1>
        <p>
          Type a task the way you would say it, like <em>“Gym tomorrow 7am for an hour”</em>. ScheduNova picks out
          the time, duration and priority, then fits your open tasks around the ones with fixed times.
        </p>
        <ul className="auth-points">
          <li>Plain-English task entry</li>
          <li>Automatic day plan by deadline and priority</li>
          <li>Pending, scheduled, overdue and done at a glance</li>
        </ul>
      </section>

      <section className="auth-card card" aria-labelledby="auth-title">
        <div className="tabs" role="tablist" aria-label="Account">
          <button
            type="button"
            role="tab"
            aria-selected={!isSignup}
            className={!isSignup ? "tab active" : "tab"}
            onClick={() => switchMode("login")}
          >
            Log in
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={isSignup}
            className={isSignup ? "tab active" : "tab"}
            onClick={() => switchMode("signup")}
          >
            Create account
          </button>
        </div>

        <h2 id="auth-title">{isSignup ? "Create your account" : "Welcome back"}</h2>

        <form onSubmit={submit} className="stack">
          {isSignup && (
            <label className="field">
              <span>Full name</span>
              <input
                id="auth-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                autoComplete="name"
                required
              />
            </label>
          )}
          <label className="field">
            <span>Email</span>
            <input
              id="auth-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="email"
              required
            />
          </label>
          <label className="field">
            <span>Password</span>
            <input
              id="auth-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete={isSignup ? "new-password" : "current-password"}
              minLength={isSignup ? 8 : undefined}
              required
            />
          </label>

          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}

          <button className="btn primary block" disabled={busy}>
            {busy ? "Please wait…" : isSignup ? "Create account" : "Log in"}
          </button>
        </form>
      </section>
    </main>
  );
}
