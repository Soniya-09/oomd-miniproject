# Metric definitions

Every number on every dashboard is computed by `app/Services/AnalyticsService.php` from the
unified work list in `app/Services/StudentWork.php`. Nothing is hard-coded or sampled.

## Work items

A student's work is one list containing two kinds of item:

| Kind | Source | Included when |
|---|---|---|
| Academic | `assignments` | Published or closed, addressed to the student (whole class, or listed in `assignment_targets`), **and** requires a submission |
| Personal | `personal_tasks` | Owned by the student, not deleted, not cancelled |

Assignments that need no submission (e.g. "read chapter 3") appear on the calendar and in the
list as **info** items but are never counted in any metric. Draft and cancelled assignments are
invisible to students and excluded everywhere.

## Unified status

| Status | Academic assignment | Personal task |
|---|---|---|
| **completed** | A submission exists and is not "returned for revision" (submitted, resubmitted, under review, graded) | `status = completed` |
| **in_progress** | Returned for revision and still open | `status = in_progress` |
| **overdue** | No submission and the deadline has passed (or returned and the assignment is closed after its deadline) | Not completed and `due_at` has passed |
| **pending** | Everything else that is still open | Everything else that is still open |

"Active" / "open" = pending + in_progress + overdue.

## Formulas

| Metric | Definition |
|---|---|
| **Completion rate** | completed eligible items ÷ all eligible items × 100 |
| **On-time submission rate** | assignments submitted on or before the deadline ÷ assignments that require a submission and whose deadline has passed × 100. Late and missing both count against it. |
| **Weekly consistency** | Number of days in the current week (Monday–Sunday, institution time zone) with at least one completion |
| **Pending workload** | Count of open items; overdue items are also reported separately |
| **Submission rate** (teacher / admin) | submissions ÷ expected submissions, where expected = every recipient of every assignment that requires a submission and is already past its deadline |
| **Average marks** | Mean of `marks` over submissions in status *graded* (released or not) for that assignment |

When the denominator is zero the rate is `null` and shown as "—", never as 0 %.

## Time

* "Completed at" for academic work is the **first** submission time (`first_submitted_at`), so a
  resubmission does not move earlier productivity into a later week.
* "Completed at" for personal tasks is `completed_at`, set when the status becomes *completed* and
  cleared when the task is reopened. Reopened tasks therefore drop out of past completion counts.
* All timestamps are stored in UTC. Periods (today, this week, this month, custom) are computed in
  the institution's time zone, then converted to UTC ranges.

## Which items a period includes

For the status breakdown, subject progress and completion rate on **My Progress**, an item belongs
to the selected period if any of these fall inside it:

* its deadline, or
* its completion time, or
* (for items without a deadline) its creation time.

The **trend** charts count completions by completion time only. The **submission performance**
chart only uses assignments whose deadline is inside the period *and* already passed (future
deadlines cannot be on time, late or missing yet).

## Edge cases

| Case | Handling |
|---|---|
| Cancelled personal task | Excluded from every count |
| Cancelled assignment | Hidden from students; excluded |
| Task without a deadline | Never overdue; counted by creation date for period scoping |
| Late submission | Counts as **completed** for completion rate and productivity, but **late** for on-time rate |
| Resubmission | Same item; status moves from *in_progress* back to *completed*; on-time uses the `is_late` flag of the latest attempt |
| Grade not released | Student sees "under review"; the work still counts as completed |
| Student added to a class later | Becomes a recipient of that class's assignments from then on |

## Insights

Insights are plain facts derived from the same data: completions this week vs last week, active
days, items due in the next 7 days, overdue count, the subject with the most open assignments and a
suggested next item (earliest deadline, then highest priority). They make no judgement about the
student and are not a substitute for academic assessment.
