import { fromFrappe } from "./dates";

// Mirrors the Nova Task state machine: only `done` is stored,
// the other states are worked out from fixed_start and deadline.
export function taskStatus(task, now = new Date()) {
  if (Number(task.done)) return "done";
  const due = fromFrappe(task.deadline);
  if (due && due < now) return "overdue";
  if (task.fixed_start) return "scheduled";
  return "pending";
}

export const STATUS_LABEL = {
  pending: "Pending",
  scheduled: "Scheduled",
  overdue: "Overdue",
  done: "Done",
};

export const PRIORITY_RANK = { high: 0, medium: 1, low: 2 };
