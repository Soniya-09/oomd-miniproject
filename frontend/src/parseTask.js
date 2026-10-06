// Turns a plain-English sentence into Nova Task fields, e.g.
//   "Gym tomorrow 7am for 1 hour high priority @gym"
//   -> title "Gym", fixed_start tomorrow 07:00, duration 60, priority high, context "gym"
//
// Rules, applied in order (each match is removed from the text):
//   priority  "high priority", "urgent", "important", "asap", "!high", "!!" / "low priority", "someday", "!low"
//   context   "@place" or "at/in home|office|work|gym|college|school|library|class"
//   duration  "for 1 hour", "30 min", "1.5h", "an hour", "half an hour"
//   deadline  "by|before|due <day> [time]" or "by <time> [day]"
//   start     "<day>" and/or "<time>". A time sets fixed_start; a day alone becomes an end-of-day deadline.
// Whatever text is left becomes the title.

import { addDays, startOfDay, toFrappe } from "./dates";

const DATE =
  "(?:day after tomorrow|today|tonight|tomorrow|tmrw|(?:next\\s+|this\\s+|on\\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tues|tue|wed|thurs|thu|fri|sat|sun))";
const TIME =
  "(?:at\\s+)?(?:\\d{1,2}(?::\\d{2})?\\s*(?:am|pm)|\\d{1,2}:\\d{2}|noon|morning|afternoon|evening)";
const PLACES = "the gym|the office|home|office|work|gym|college|school|library|class";
const WEEKDAY_PREFIX = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];

function resolveDate(phrase, now) {
  const w = phrase.toLowerCase().replace(/^(next|this|on)\s+/, "");
  const today = startOfDay(now);
  if (w === "today" || w === "tonight") return today;
  if (w === "tomorrow" || w === "tmrw") return addDays(today, 1);
  if (w === "day after tomorrow") return addDays(today, 2);
  const idx = WEEKDAY_PREFIX.findIndex((p) => w.startsWith(p));
  if (idx === -1) return null;
  // Always the upcoming one: "friday" said on a Friday means next week.
  const diff = (idx - today.getDay() + 7) % 7 || 7;
  return addDays(today, diff);
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

const at = (day, [h, m]) => new Date(day.getFullYear(), day.getMonth(), day.getDate(), h, m);

export function parseTask(input, now = new Date()) {
  const raw = input.trim();
  let text = ` ${raw} `;
  const take = (re) => {
    const m = text.match(re);
    if (m) text = `${text.slice(0, m.index)} ${text.slice(m.index + m[0].length)}`;
    return m;
  };

  const task = {
    title: "",
    priority: "medium",
    duration_min: 45,
    fixed_start: null,
    deadline: null,
    context: "",
    raw,
  };
  const found = { priority: false, duration: false };

  // Priority
  if (take(/(?<=\s)(?:high priority|urgent|important|asap|!high|!!)(?=\s)/i)) {
    task.priority = "high";
    found.priority = true;
  } else if (take(/(?<=\s)(?:low priority|someday|!low)(?=\s)/i)) {
    task.priority = "low";
    found.priority = true;
  } else if (take(/(?<=\s)(?:medium priority|normal priority|!medium)(?=\s)/i)) {
    found.priority = true;
  }

  // Context / location
  let m =
    take(/(?<=\s)@([\w-]+)(?=\s)/) || take(new RegExp(`(?<=\\s)(?:at|in)\\s+(${PLACES})(?=\\s)`, "i"));
  if (m) task.context = m[1].replace(/^the\s+/i, "").toLowerCase();

  // Duration
  if (take(/(?<=\s)(?:for\s+)?half\s+an?\s+hour(?=\s)/i)) {
    task.duration_min = 30;
    found.duration = true;
  } else if (take(/(?<=\s)(?:for\s+)?(?:an|one)\s+hour(?=\s)/i)) {
    task.duration_min = 60;
    found.duration = true;
  } else if ((m = take(/(?<=\s)(?:for\s+)?(\d+(?:\.\d+)?)\s*(hours?|hrs?|h|minutes?|mins?|m)(?=\s)/i))) {
    const n = parseFloat(m[1]);
    task.duration_min = Math.max(1, Math.round(/^h/i.test(m[2]) ? n * 60 : n));
    found.duration = true;
  }

  // Deadline: "by friday 5pm", "before 6pm", "due tomorrow"
  const deadlineRe = new RegExp(
    `(?<=\\s)(?:by|before|due(?:\\s+on)?)\\s+((?:${DATE})(?:\\s+${TIME})?|${TIME}(?:\\s+${DATE})?)(?=\\s)`,
    "i"
  );
  if ((m = take(deadlineRe))) {
    const d = m[1].match(new RegExp(DATE, "i"));
    const t = m[1].match(new RegExp(TIME, "i"));
    const day = (d && resolveDate(d[0], now)) || startOfDay(now);
    task.deadline = toFrappe(at(day, (t && resolveTime(t[0])) || [23, 59]));
  }

  // Start: "tomorrow 7am", "at 6:30pm", "on monday"
  const dateM = take(new RegExp(`(?<=\\s)${DATE}(?=\\s)`, "i"));
  const timeM = take(new RegExp(`(?<=\\s)${TIME}(?=\\s)`, "i"));
  if (dateM || timeM) {
    const day = (dateM && resolveDate(dateM[0], now)) || startOfDay(now);
    let time = timeM ? resolveTime(timeM[0]) : null;
    if (!time && dateM && /tonight/i.test(dateM[0])) time = [20, 0];
    if (time) {
      let start = at(day, time);
      // "7am" with no day, said after 7am, means tomorrow.
      if (!dateM && start < now) start = addDays(start, 1);
      task.fixed_start = toFrappe(start);
    } else if (!task.deadline) {
      task.deadline = toFrappe(at(day, [23, 59]));
    }
  }

  // Title: what's left, minus filler.
  let title = text.replace(/\s+/g, " ").trim();
  title = title.replace(/^(?:remind me to|i need to|i have to|need to|have to)\s+/i, "");
  let prev;
  do {
    prev = title;
    title = title.replace(/[\s,.;-]+$/, "").replace(/\s+(?:on|at|for|by|in|from|and|to)$/i, "");
  } while (title !== prev);
  task.title = title ? title[0].toUpperCase() + title.slice(1) : "";

  return { task, found };
}
