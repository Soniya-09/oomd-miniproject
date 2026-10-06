import { useEffect, useMemo, useState } from "react";
import { addDays, fmtDay, fmtDuration, fmtTime, startOfDay } from "../dates";
import { planDay } from "../planDay";

const PX_PER_MIN = 1; // 60px per hour
const MIN_BLOCK_PX = 26;

export default function DayPlan({ tasks, onEdit }) {
  const [day, setDay] = useState(() => startOfDay(new Date()));
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 60_000);
    return () => clearInterval(id);
  }, []);

  const plan = useMemo(() => planDay(tasks, day, now), [tasks, day, now]);
  const hours = [];
  for (let h = plan.startHour; h <= plan.endHour; h++) hours.push(h);
  const height = (plan.endHour - plan.startHour) * 60 * PX_PER_MIN;
  const offset = (d) => ((d - plan.windowStart) / 60000) * PX_PER_MIN;

  const fixedCount = plan.blocks.filter((b) => b.kind === "fixed").length;
  const autoCount = plan.blocks.length - fixedCount;
  const showNow = plan.isToday && now >= plan.windowStart && now <= plan.windowEnd;

  return (
    <section className="plan card" aria-labelledby="plan-title">
      <div className="section-head plan-head">
        <div>
          <h2 id="plan-title">Day plan</h2>
          <p className="muted">
            {fmtDay(day, now)} · {day.toLocaleDateString([], { day: "numeric", month: "long" })}
          </p>
        </div>
        <div className="day-nav">
          <button type="button" className="icon-btn" onClick={() => setDay((d) => addDays(d, -1))} aria-label="Previous day">
            ‹
          </button>
          <button
            type="button"
            className="btn ghost sm"
            onClick={() => setDay(startOfDay(new Date()))}
            disabled={plan.isToday}
          >
            Today
          </button>
          <button type="button" className="icon-btn" onClick={() => setDay((d) => addDays(d, 1))} aria-label="Next day">
            ›
          </button>
        </div>
      </div>

      <p className="plan-summary">
        <span>
          <b>{fixedCount}</b> fixed
        </span>
        <span>
          <b>{autoCount}</b> auto-planned
        </span>
        {plan.leftovers.length > 0 && (
          <span className="warn">
            <b>{plan.leftovers.length}</b> don't fit
          </span>
        )}
      </p>

      {plan.isPast && <p className="muted small">This day is over, so only tasks with fixed times are shown.</p>}

      <div className="timeline-scroll">
        <div className="timeline" style={{ height }}>
          {hours.map((h) => (
            <div key={h} className="hour" style={{ top: (h - plan.startHour) * 60 * PX_PER_MIN }}>
              <span>{new Date(2000, 0, 1, h % 24).toLocaleTimeString([], { hour: "numeric" })}</span>
            </div>
          ))}

          {plan.blocks.map((b) => {
            const top = offset(b.start);
            const blockHeight = Math.max(MIN_BLOCK_PX, offset(b.end) - top - 2);
            const compact = blockHeight < 42;
            return (
              <button
                type="button"
                key={`${b.task.name}-${b.kind}`}
                className={`tl-block k-${b.kind} pri-${b.task.priority}${b.late ? " is-late" : ""}${
                  compact ? " compact" : ""
                }`}
                style={{ top, height: blockHeight }}
                onClick={() => onEdit(b.task)}
                title={`${b.task.title}, ${fmtTime(b.start)} to ${fmtTime(b.end)}`}
              >
                <span className="block-time">
                  {compact ? fmtTime(b.start) : `${fmtTime(b.start)} – ${fmtTime(b.end)}`}
                </span>
                <span className="block-title">{b.task.title}</span>
                {blockHeight > 44 && (
                  <span className="block-tag">
                    {b.kind === "fixed" ? "Fixed time" : "Auto-planned"}
                    {b.late && " · ends after deadline"}
                  </span>
                )}
              </button>
            );
          })}

          {showNow && (
            <div className="now-line" style={{ top: offset(now) }} aria-label={`Now, ${fmtTime(now)}`}>
              <span>Now</span>
            </div>
          )}

          {plan.blocks.length === 0 && (
            <p className="timeline-empty">
              {plan.isPast ? "Nothing was scheduled on this day." : "Free day. Add a task and it will be slotted in here."}
            </p>
          )}
        </div>
      </div>

      {plan.leftovers.length > 0 && (
        <div className="leftovers">
          <h3>Doesn't fit on this day</h3>
          <ul>
            {plan.leftovers.map((t) => (
              <li key={t.name}>
                <button type="button" className="link" onClick={() => onEdit(t)}>
                  {t.title}
                </button>
                <span className="muted"> · {fmtDuration(t.duration_min)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <p className="muted small plan-legend">
        Tasks with a start time keep their slot. Tasks without one fill the free time, earliest deadline first, then by
        priority. Click a block to edit it.
      </p>
    </section>
  );
}
