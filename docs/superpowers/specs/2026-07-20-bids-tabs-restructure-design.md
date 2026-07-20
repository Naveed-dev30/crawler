# Bids Tabs Restructure — Design

**Date:** 2026-07-20
**Status:** Approved (pending spec review)
**Depends on:** `2026-07-20-not-qualified-tab-design.md` (implemented)

## Goal

Replace the Bids page tabs (Placed / Completed / Failed / Not Qualified) with exactly four: **Completed · Not Qualified · Skill Not Matched · Failed**. Completed becomes the default tab and absorbs pending bids (user decision — nothing becomes invisible).

## Tab semantics

| Tab | `tab` param | Query |
|---|---|---|
| Completed (default) | `completed` | `bids.bid_status IN ('pending', 'completed')` |
| Not Qualified | `not-qualified` | existing Proposal branch, unchanged |
| Skill Not Matched | `skill-not-matched` | `bids.bid_status IN ('failed', 'expired') AND bids.error_message LIKE '%skill%'` |
| Failed | `failed` | `bids.bid_status IN ('failed', 'expired') AND (bids.error_message NOT LIKE '%skill%' OR bids.error_message IS NULL)` |

Unknown/absent `tab` falls back to `completed`. The skill match uses SQL `LIKE` (case-insensitive on MySQL default collation and sqlite ASCII), consistent with the row indicator's `str_contains(strtolower(error_message), 'skill')` signal.

## Backend — `BidController@data`

- Whitelist: `in_array($request->query('tab'), ['failed', 'skill-not-matched', 'not-qualified'], true) ? ... : 'completed'` (not-qualified handled by its early branch as today).
- `match` arms per the table above; `$isCompleted = $tab === 'completed'` still drives the awarded columns (they now also render for pending rows — empty cells, accepted).
- `cards` / `statusCounts` computation unchanged (stat cards keep Total/Placed/Failed aggregates).
- Empty-state colspan: 9 for completed (awarded cols visible), 7 for skill-not-matched/failed — same rule as today (`$isCompleted ? 9 : 7`).

## Front end — `home.blade.php`

- Tab nav becomes:
  1. `data-tab="completed"` "Completed" — active by default
  2. `data-tab="not-qualified"` "Not Qualified"
  3. `data-tab="skill-not-matched"` "Skill Not Matched"
  4. `data-tab="failed"` "Failed"
- JS `currentTab` initial value `'completed'`.
- CSS: completed keeps its green active rule; not-qualified keeps orange; failed keeps red; new amber rule for skill-not-matched: `color:#ffab00; background-color:rgba(255,171,0,.12); border-bottom-color:#ffab00;`. The generic `.nav-link.active` (indigo) remains as base.
- No changes to thead swap, filter hiding, cards JS, pagination, offcanvas.

## Testing

- `BidsDataTest`: update — `tab=placed` no longer special (falls back to completed); default tab returns pending + completed bids; `tab=completed` same; failed tab excludes skill-error bids.
- New tests (extend BidsDataTest or new class `SkillNotMatchedTabTest`):
  1. `tab=skill-not-matched` returns only failed/expired bids whose error_message contains "skill" (case via lowercase data).
  2. `tab=failed` excludes those and includes null-error failures.
  3. Unknown `tab=placed` falls back to completed set.
- Bids page HTML: four tab buttons in the given order, no `data-tab="placed"`.

## Out of scope

- Stat cards redesign, statuses themselves, Not Qualified branch, mobile API.
