// Day Planner: lays out one day as time blocks.
//   1. Tasks with a fixed_start on that day keep their time ("fixed").
//   2. Open tasks without a start time are slotted into the free gaps ("auto"),
//      earliest deadline first, then by priority, then shortest first.
//   3. Anything that does not fit before the end of the day is returned as leftovers.

import { addMinutes, fromFrappe, sameDay, startOfDay } from "./dates";
import { PRIORITY_RANK } from "./status";

const BUFFER_MIN = 5;
const durationOf = (t) => Math.max(5, Number(t.duration_min) || 45);

const roundUp = (d, minutes) => {
  const step = minutes * 60000;
  return new Date(Math.ceil(d.getTime() / step) * step);
};

function byUrgency(a, b) {
  const da = fromFrappe(a.deadline);
  const db = fromFrappe(b.deadline);
  if (da && db && da - db !== 0) return da - db;
  if (da && !db) return -1;
  if (!da && db) return 1;
  const pr = (PRIORITY_RANK[a.priority] ?? 1) - (PRIORITY_RANK[b.priority] ?? 1);
  if (pr) return pr;
  return durationOf(a) - durationOf(b);
}

export function planDay(tasks, day, now = new Date(), { startHour = 8, endHour = 22 } = {}) {
  const base = startOfDay(day);
  const open = tasks.filter((t) => !Number(t.done));

  const fixed = [];
  for (const t of open) {
    const start = fromFrappe(t.fixed_start);
    if (start && sameDay(start, base)) {
      fixed.push({ task: t, start, end: addMinutes(start, durationOf(t)), kind: "fixed" });
    }
  }
  fixed.sort((a, b) => a.start - b.start);

  // Stretch the visible window so early or late fixed tasks still show.
  for (const b of fixed) {
    startHour = Math.min(startHour, b.start.getHours());
    endHour = sameDay(b.end, base)
      ? Math.max(endHour, b.end.getHours() + (b.end.getMinutes() ? 1 : 0))
      : 24;
  }
  const windowStart = new Date(base);
  windowStart.setHours(startHour, 0, 0, 0);
  const windowEnd = new Date(base);
  windowEnd.setHours(endHour, 0, 0, 0);

  const isPast = windowEnd <= now;
  const placed = [];
  const leftovers = [];

  if (!isPast) {
    const cursor = now > windowStart ? roundUp(now, 5) : windowStart;
    const gaps = [];
    let c = cursor;
    for (const b of fixed) {
      if (b.start > c) gaps.push([c, b.start]);
      if (b.end > c) c = b.end;
    }
    if (windowEnd > c) gaps.push([c, windowEnd]);

    const flexible = open.filter((t) => !t.fixed_start).sort(byUrgency);
    for (const t of flexible) {
      const need = durationOf(t);
      const i = gaps.findIndex(([s, e]) => (e - s) / 60000 >= need);
      if (i === -1) {
        leftovers.push(t);
        continue;
      }
      const [s, e] = gaps[i];
      const end = addMinutes(s, need);
      placed.push({ task: t, start: s, end, kind: "auto" });
      const next = addMinutes(end, BUFFER_MIN);
      if (next < e) gaps[i] = [next, e];
      else gaps.splice(i, 1);
    }
  }

  const blocks = [...fixed, ...placed]
    .sort((a, b) => a.start - b.start)
    .map((b) => {
      const due = fromFrappe(b.task.deadline);
      return { ...b, late: Boolean(due && b.end > due) };
    });

  return {
    blocks,
    leftovers,
    windowStart,
    windowEnd,
    startHour,
    endHour,
    isPast,
    isToday: sameDay(base, now),
  };
}
