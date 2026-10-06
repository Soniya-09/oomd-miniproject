import { useCallback, useEffect, useState } from "react";
import { auth, refreshCsrf } from "./api";
import AuthScreen from "./components/AuthScreen.jsx";
import Dashboard from "./components/Dashboard.jsx";
import Logo from "./components/Logo.jsx";

export default function App() {
  // undefined = still checking the session, null = signed out
  const [user, setUser] = useState(undefined);

  useEffect(() => {
    auth
      .me()
      .then(async (me) => {
        await refreshCsrf();
        setUser(me);
      })
      .catch(() => setUser(null));
  }, []);

  const signOut = useCallback(async () => {
    try {
      await auth.logout();
    } catch {
      /* already signed out on the server */
    }
    setUser(null);
  }, []);

  const sessionExpired = useCallback(() => setUser(null), []);

  if (user === undefined) {
    return (
      <div className="splash" role="status">
        <Logo size={40} />
        <span>Loading ScheduNova…</span>
      </div>
    );
  }

  if (!user) return <AuthScreen onAuthed={setUser} />;

  return <Dashboard user={user} onLogout={signOut} onSessionExpired={sessionExpired} />;
}
