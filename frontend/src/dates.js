// Frappe stores Datetime as "YYYY-MM-DD HH:mm:ss" in the site's time zone.
// The app treats those strings as local time.

const pad = (n) => String(n).padStart(2, "0");

export function toFrappe(d) {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(
    d.getMinutes()
  )}:00`;
}

export function fromFrappe(s) {
  if (!s) return null;
  const [date, time = "00:00:00"] = s.split(/[ T]/);
  const [y, mo, da] = date.split("-").map(Number);
  const [h = 0, mi = 0, se = 0] = time.split(".")[0].split(":").map(Number);
  return new Date(y, mo - 1, da, h, mi, se);
}

// <input type="datetime-local"> uses "YYYY-MM-DDTHH:mm".
export function toInput(s) {
  const d = fromFrappe(s);
  if (!d) return "";
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(
    d.getMinutes()
  )}`;
}

export const fromInput = (v) => (v ? `${v.replace("T", " ")}:00` : null);

export const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

export const addDays = (d, n) =>
  new Date(d.getFullYear(), d.getMonth(), d.getDate() + n, d.getHours(), d.getMinutes());

export const addMinutes = (d, n) => new Date(d.getTime() + n * 60000);

export const sameDay = (a, b) =>
  a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

export const fmtTime = (d) => d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });

export function fmtDay(d, now = new Date()) {
  const today = startOfDay(now);
  if (sameDay(d, today)) return "Today";
  if (sameDay(d, addDays(today, 1))) return "Tomorrow";
  if (sameDay(d, addDays(today, -1))) return "Yesterday";
  return d.toLocaleDateString([], { weekday: "short", day: "numeric", month: "short" });
}

export const fmtWhen = (d, now = new Date()) => `${fmtDay(d, now)}, ${fmtTime(d)}`;

export function fmtDuration(min) {
  const n = Number(min) || 0;
  const h = Math.floor(n / 60);
  const m = n % 60;
  if (!h) return `${m} min`;
  return m ? `${h} h ${m} min` : `${h} h`;
}
