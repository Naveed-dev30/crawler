# Chats Tab — Design

**Date:** 2026-07-22
**Status:** Approved

## Goal

Give dashboard admins visibility into the mobile-chat system built in the
mobile-chat feature (PR #19): every thread, who it is assigned to, the full
conversation, and the assignment/escalation history. Read-only — the mobile
app remains the only write path.

## Decisions

- **Read-only.** No reassign, block, or send actions from the dashboard.
- **Admin only.** Routes live in the existing `admin` middleware group,
  same gate as Users and Filters.
- **Slide-over detail.** List page plus a right offcanvas loaded via AJAX,
  the same pattern as the Bids page detail panels.
- **Per-thread history only.** No global activity feed.
- **Server-rendered.** Blade pagination like the Bid Insights page; no
  polling, no JS-rendered table.

## Routes

| Method | Path | Purpose |
|---|---|---|
| GET | `/chats` | List page (Blade, paginated 20/page) |
| GET | `/chats/{thread}/detail` | Offcanvas partial (AJAX) |

Both admin-gated. New `ChatController` with `index` and `detail`.
Menu: "Chats" entry (`bx bx-chat`) between Bid Insights and Filters in
`resources/menu/verticalMenu.json`.

## List page

Table ordered by latest activity (`last_client_message_at` desc, nulls
last), 20 per page, bootstrap-5 pagination in a separate card (users-page
pattern). Columns:

- **Project** — `project_id` plus proposal title (truncated)
- **Assigned To** — user name, or muted "Unassigned"
- **Status** — badge for `status` (fresh/replied); additional red
  "Blocked" badge when `blocked`
- **Messages** — message count
- **Escalations** — count of `activity_logs` rows of type `escalation`
- **Last Client Message** — relative time, "—" when null
- **View** — opens the slide-over

Filters above the table:

- Search input (debounced): matches `project_id`, proposal title, or
  assignee name
- Status dropdown: All / Fresh / Replied / Blocked

Query: `Thread::with(['assignedUser', 'proposal'])->withCount(['messages'])`
plus an escalation-count aggregate; search joins stay in the controller.

## Detail slide-over

Fetched into the offcanvas (`X-Requested-With` AJAX partial, like
`_partials/not-qualified-detail.blade.php`). Three sections:

1. **Header** — proposal title (fallback "Project {id}"), project id,
   status + blocked badges, assigned user with ladder position
   ("Sara — ladder 2"), created / last client message / last escalated
   timestamps.
2. **Assignment timeline** — `activity_logs` for the thread with
   `fromUser`/`toUser`, oldest first. Row format:
   - `escalation` → up-arrow icon, "Escalated: {from} → {to}", timestamp
   - `manual_assign` → "Reassigned: {from} → {to}", timestamp
   - AI matches are not logged; when the thread has an assignee, prepend a
     synthetic first row "AI matched to {first known assignee}" derived
     from thread state (use the earliest log's from-user when logs exist,
     else the current assignee).
3. **Conversation** — `thread_messages` chronological with attachments:
   `received` bubbles left (client), `sent` bubbles right with sender name.
   Attachment rows render as links. Empty state: "No messages yet."

## Error handling

- Detail route model-binds `{thread}` — unknown id 404s.
- Missing proposal (nullable FK) falls back to "Project {project_id}".
- Unassigned threads render everywhere with "Unassigned" instead of a
  user name.

## Testing

Feature tests (`ChatsPageTest`):

- `/chats` requires auth; non-admin forbidden
- List renders thread rows: project, assignee name, status + blocked
  badges, message and escalation counts
- Search by project id and by assignee name narrows results
- Status filter (including Blocked) narrows results
- Pagination at 20
- Detail partial: messages both directions, sender names, attachment
  links, escalation timeline rows with from → to users, synthetic AI row
- Detail 404 for unknown thread; unassigned thread renders

## Out of scope

- Any write action (reassign, block, reply) — future iteration can add
  `ThreadAssigner::TYPE_MANUAL` reassign safely since the write path
  already exists.
- Global activity feed.
- Live updates/polling.
