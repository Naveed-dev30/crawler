# Manual Thread Assignment from Chats Dashboard

Date: 2026-07-23
Status: approved

## Goal

Admin can assign a chat thread to a mobile user from the Chats thread-detail
slide-over. The chosen user gets an in-app notification and an FCM push.

## Context

`App\Services\ThreadAssigner` is already the single write-path for
assignments and supports `TYPE_MANUAL`: it sets `assigned_user_id`, writes
an `ActivityLog` row, creates `MobileNotification` rows, and dispatches
`SendFcmPushJob` for both the new and previous assignee. Only the admin UI
and route are missing.

## Design

### Route

`POST /chats/{thread}/assign` (name `chats.assign`) inside the existing
admin-middleware group in `routes/web.php`.

### Controller

`ChatController::assign(Request, Thread)`:

- Validate `user_id`: required, must be a user with `role = mobile`
  (422 otherwise).
- If `user_id` equals the current `assigned_user_id`, no-op — return
  success without logging or notifying.
- Otherwise call
  `app(ThreadAssigner::class)->assign($thread, $to, ThreadAssigner::TYPE_MANUAL, $thread->assignedUser)`.
- Return JSON `{success: true}`.

### UI (`_partials/chat-thread-detail.blade.php`)

The "Assigned to" row gains an inline control: a select of mobile users
(current assignee preselected) and an "Assign" button. Controller `detail()`
passes `$mobileUsers = User::mobile()->orderBy('name')->get(['id', 'name', 'escalation_ladder'])`.

On click, JS in `chats.blade.php` POSTs to `chats.assign` with CSRF token,
then re-fetches the detail partial into the open slide-over and reloads the
page table (existing sync pattern).

### Notifications (no new code)

`ThreadAssigner` already produces, for manual assigns:

- `MobileNotification` + FCM push to the new assignee (last client message
  as body).
- "Thread reassigned: {title}" notification to the previous assignee.
- `ActivityLog` row (`type = manual_assign`) which the existing assignment
  timeline renders as "Reassigned: X → Y".

## Tests (`ChatsPageTest` or new `ChatAssignTest`)

- Non-admin cannot POST assign (403/redirect).
- Admin assign updates `assigned_user_id`, creates ActivityLog +
  MobileNotification, pushes `SendFcmPushJob` (Queue::fake).
- Assigning the already-assigned user is a no-op (no log, no notification).
- Invalid/non-mobile `user_id` → 422.
- Detail view shows the assign select for mobile users.

## Out of scope

- Assign control in the table rows.
- Unassigning a thread.
