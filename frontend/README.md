# ScheduNova Frontend

Prototype React frontend for **ScheduNova**, an AI personal scheduling assistant. It lives in the
`frontend/` folder of the `schedunova` Frappe app, which is the backend.

## Features

- **Sign up, log in, log out**: `schedunova.api.sign_up`, Frappe `/api/method/login`, `schedunova.api.me`.
- **Smart add**: type "Gym tomorrow 7am for 1 hour high priority @gym" and see the parsed title, start time,
  duration, deadline, priority and place before saving. The original sentence is stored in the task's `raw` field.
- **Task list**: Pending / Scheduled / Overdue / Done filters, mark done, edit, delete.
- **Day plan**: a timeline for any day. Tasks with a fixed start keep their slot. Tasks without one are slotted
  into the free time, earliest deadline first, then by priority. Tasks that don't fit are listed separately.
- Light and dark mode, and a layout that works on phones.

## Tech

| Part | Choice |
|---|---|
| UI | React 19 (JavaScript + JSX) |
| Build / dev server | Vite |
| Styling | Plain CSS with design tokens (`src/styles.css`) |
| Backend calls | `fetch`, session cookie + `X-Frappe-CSRF-Token` (`src/api.js`) |

## Project structure

```
src/
  api.js            Backend client: auth, CSRF token, Nova Task CRUD
  parseTask.js      Sentence -> task fields (the "Task Parser")
  planDay.js        Builds the day timeline (the "Day Planner")
  status.js         Pending / Scheduled / Overdue / Done rules
  dates.js          Frappe datetime <-> JS Date helpers
  App.jsx           Session check, switches between login and dashboard
  components/
    AuthScreen.jsx  Log in / create account
    Dashboard.jsx   Main screen, owns the task list state
    QuickAdd.jsx    Smart add box with live preview
    TaskForm.jsx    Create / edit dialog
    TaskList.jsx    Filterable task list
    DayPlan.jsx     Timeline view
```

## Run it locally

You need the backend running first. In WSL:

```bash
# Terminal 1: Frappe services (redis, workers, socketio)
cd ~/frappe/frappe-v16-bench
bench start

# Terminal 2: the ScheduNova site on port 8002
cd ~/frappe/frappe-v16-bench
bench --site schedunova.localhost serve --port 8002

# Terminal 3: this frontend
cd ~/frappe/frappe-v16-bench/apps/schedunova/frontend
npm install        # first time only
npm run dev
```

Open **http://localhost:5173**.

Vite forwards every `/api/...` request to the backend at `http://127.0.0.1:8002`, so the browser sees one
origin and login cookies work without any CORS setup. To use a different backend address:

```bash
SCHEDUNOVA_BACKEND=http://127.0.0.1:8001 npm run dev
```

## Production build

```bash
npm run build      # outputs static files to dist/
```

Serve `dist/` behind the same domain as the Frappe site (for example from nginx, with `/api` proxied to
Frappe), so cookies and CSRF keep working.

## Smart add cheat sheet

| You type | Becomes |
|---|---|
| `tomorrow 7am`, `friday 4:30pm`, `tonight` | Fixed start time |
| `by friday 5pm`, `before 6pm`, `due tomorrow` | Deadline |
| `on monday` (a day with no time) | Deadline at the end of that day |
| `for 1 hour`, `30 min`, `1.5h`, `half an hour` | Duration |
| `urgent`, `high priority`, `!!` / `low priority`, `someday` | Priority |
| `@gym`, `at office`, `in college` | Context / location |
