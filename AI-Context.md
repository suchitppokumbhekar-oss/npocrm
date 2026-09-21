# NPO CRM — Canonical AI Context / Production Handoff
## Current authoritative state — 2026-09-19

This file is the single canonical AI context for the NPO CRM. It supersedes the separate root `AI-Context.md`, root `AI_CONTEXT.md`, and `app/AI-Context.md` context files.

### Production status
- **Production baseline: v72 Final — Complete Task Restored.**
- The user explicitly confirmed this release is deployed/live on 2026-09-19.
- v71 is retained only as the rollback/reference baseline.
- The uploaded `fullproject-19092026-1952.zip` is treated as the current live-server/source snapshot for alignment work.
- Future CRM changes must start from this live-aligned state, not from an older v70/v71/v72 package assumption.

### Critical Complete Task / Done → Log Now contract
- The established Complete Task transaction workflow is protected.
- “How did you contact them?” is an **activity-type dropdown** and must remain a dropdown.
- Existing configured outcome catalogue/filtering must be preserved.
- Manual/custom next-action date and time override must remain available.
- Existing visit date/time, booking, lost-reason, WhatsApp, notes, validation, and completion-processing behavior must not be casually redesigned.
- Check-back tasks retain their forced system activity behavior.
- `Report from Site` is available as a normal completion activity for users who are authorized to work the lead, while server-side `AccessService::canWorkLead()` remains the authority.
- The Complete Task restoration in the live snapshot matches the restored `resources/views/modals/complete-task.blade.php` baseline package file.

### Core UX contract
- Dashboard = landing/overview.
- My Work = personal operational execution queue.
- My Leads / Lead Directory = find/manage the lead book.
- Lead Workbench / Lead page = canonical customer-work execution surface.
- Timeline/History = understand documented history.
- Team Status / Super Admin Work = management and oversight.
- Do not create duplicate customer-contact workflows.
- Work loop: **See priority → open work → do work → complete → return to exact originating queue/context → continue.**
- Work status (Overdue, Today, Upcoming, Completed) is distinct from lead/customer status (New, Working/Attempted/Contacted, Booked, Lost, All).
- Agents use personal operational work.
- Team Managers have management/oversight plus their own personal agent work when applicable.
- Admin remains management/oversight-first rather than being flattened into a generic agent dashboard.
- Overdue work must remain prominent.
- `return_to` is the canonical mechanism for returning users to their originating work context.
- Mobile navigation/z-index is sensitive; do not disturb established mobile stacking without an explicit requirement.
- No automatic WhatsApp/Recent Calls lead creation is to be introduced merely because device-import UI exists.

### Live snapshot alignment — important deltas from the prepared v72 package
The uploaded live snapshot is not byte-for-byte identical to the prepared v72 package. The differences are treated as **live production additions/changes** and must be preserved unless deliberately changed in a future release.

1. `app/Http/Controllers/TaskController.php`
   - Live includes `DelegatedAccessService` and `TeamService`.
   - My Work scope now gives delegated-access profiles their permitted agent IDs first.
   - Team Managers use `TeamService::agentIdsForManager()`.
   - Agents use their own Agent record.
   - This is an important live authorization/work-scope rule and must not be removed when future TaskController changes are made.

2. `resources/views/components/task-card.blade.php`
   - Live omits `data-return-to` when the return target is `/`; non-root return contexts are still carried.
   - Preserve this live behavior unless a regression is demonstrated.

3. `routes/web.php`
   - Live includes `/device-share/native`.
   - Live includes notification push-status and push-test routes.
   - These are part of the current live route surface.

4. `app/Services/WebPushService.php`
   - Live includes `sendNotification(PushSubscription $subscription, AppNotification $notification)` for explicit/manual delivery.
   - It bypasses the per-device cursor used by the background worker so manual tests cannot be swallowed merely because cron already processed the notification.

5. `app/Http/Controllers/DeviceLeadImportController.php`
   - Live includes the native Android bridge fallback.
   - vCard parsing was hardened through `parseVcard()`, including folded lines and escaped values.
   - Shared names are cleaned/limited through `cleanSharedName()`.
   - `/device-share/native` stores an `android_native` import payload for review before lead creation.

6. `resources/views/modals/add-lead.blade.php`
   - Live labels the WhatsApp device option **“From WhatsApp Contact”**.
   - The live implementation does not automatically create a lead merely from WhatsApp/Recent Calls selection; the user still reviews/saves through the lead form.

7. `app/Http/Controllers/SuperAdminWorkSummaryController.php`
   - Live routes the reconciliation action to the dedicated `admin.super-admin-work-reconciliation` view.

8. `resources/views/reports/index.blade.php`
   - Live uses the shared `partials.reports-tabs` include rather than duplicating report navigation markup.

9. `app/Http/Controllers/ExternalShareController.php`
   - Live returns a dedicated `completion_url` for completed external-share task flows, targeting the Lead Workbench completion focus.

10. `resources/views/modals/task-share-lead.blade.php`
    - Live Site WhatsApp flow uses the established v5 approach: commit the share first, keep the CRM tab as the work surface, open WhatsApp separately, and then return to the CRM completion focus through the completion URL.
    - Do not reintroduce the old visibility-change/intent-based return mechanism without a specific regression fix.

### Live-vs-prepared baseline rule
The live snapshot is now authoritative for production behavior. The prepared v72 package remains useful as release history and rollback/reference material, but if a live file differs, the difference must be investigated and recorded before replacing it.

Do not silently overwrite live-only functionality with an older package file.



## Stack
- Laravel 13.31, PHP 8.3.33, MySQL 8 / MariaDB
- Production env, cPanel hosting
- Path: `/home/u748336076/domains/propertypoint.online/public_html/npocrm`
- Public: `.../npocrm/public`
- Live: https://npocrm.propertypoint.online
- Blade + vanilla JS + fetch (no Livewire / Vue / React)
- Custom session auth (`user_id`, `user_name`, `user_role`). NOT Laravel auth.
- Custom role checks (admin / team_manager / agent). No Spatie.

---

## ⚠️ Environment gotchas (read first)

### MySQL timezone vs Laravel timezone
- MySQL session tz = UTC
- Laravel `config('app.timezone')` = Asia/Kolkata (IST)
- Timestamps written by `now()` round-trip fine
- Timestamps written via **raw SQL use UTC** and appear 5.5 h early in Laravel
- **Fix for raw SQL:** write `UTC_TIMESTAMP() + INTERVAL 330 MINUTE` for "now IST", or a plain IST string like `'2026-09-12 15:00:00'`
- **Never use `NOW()`** in raw SQL for values Laravel will read

### SettingsService caching
`SettingsService` caches statuses, transitions, activity types, outcomes, sources, and settings KV.
After **any** raw SQL update to these tables, hit `/admin/flush-settings?key=DEV_ROUTE_KEY`:

- `lead_statuses`
- `lead_status_transitions`
- `call_outcomes`
- `activity_types`
- `followup_action_types`
- `lead_sources`
- `lead_tags`
- `lead_labels`
- `settings`

Symptom of forgetting: "Invalid transition" errors after you just added the transition.

### PHP 8.3 fatals on undefined variables in closures
`Undefined variable $x` used to be a warning (PHP 7) — the transaction completed with null.
Now it is a **fatal error** — the transaction rolls back and the whole action fails silently if wrapped in `try/catch (\DomainException)`.
**Always list every variable used inside a closure in its `use (...)` clause.**

Known recent examples:
- `LeadStatusService::change()` was missing `$newIsFinal`
- `ExternalShareController::store()` (v1 draft) referenced `$sharerUserId` inside the closure without declaring it in `use (...)` — the `Log::warning()` path only, so it hadn't fired yet. Now fixed.
### WhatsApp WABA pipeline — VERIFIED WORKING (2026-09-13)

**All branches tested end-to-end:**

1. Form → CI → waba → welcome template ✅
2. Welcome template → customer reply → verification buttons ✅
3. "Yes I did" → 3 action buttons ✅
4. "Get Call Back" / "Book Site Visit" / "Get Brochure" → npocrm activity ✅
5. "Yes I did" reply → npocrm activity ✅
6. Substantive text → npocrm activity ✅
7. "Did not enquire" → npocrm marks lead Lost + cancels follow-ups ✅ (fix applied, retest on fresh number pending)
8. Direct WhatsApp button → 3 action buttons immediately ✅

**Critical bug fixes applied:**
- `normalize()` in LeadIntakeService MUST include `'action' => $input['action']` — else the mark_dead flag is dropped
- `sendTemplateMessage()` argument order: (name, language, phone, components, vendorId)
- `processReplyBot()` uses `return` (not `break`) after a bot fires — else all matching bots fire
- CI `Lead_model::push_to_waba()` config keys: `waba_api_url`, `waba_api_key`
- `$messageType = null;` at top of waba's processWebhookRequest — avoids fatal on status webhooks
### Blade `@json()` directive
Breaks on arrow functions and function calls with nested arrays.

**Bad:**
@json(
x
−
>
m
a
p
(
f
n
(
x−>map(fn(y) => ['a' => $y->a]))

text

**Good:** precompute in `@php`, then output raw:
```php
@php $jsonVar = $x->map(fn($y) => ['a' => $y->a])->values()->all(); @endphp
{{ $jsonVar }}
Or use data-* attributes with {{ json_encode($var) }} — that's what the settings page does for the project list.

Blade @if directly after a word character
Blade's directive parser uses \B (non-word-boundary) before @. When @if immediately follows a letter (e.g. Filters@if), the compiler skips it and leaves a dangling @endif.
Always put a space before @if:

Bad:

text
🔍 Filters@if ($x) ...
Good:

text
🔍 Filters @if ($x) ...
WhatsApp share links must use api.whatsapp.com/send, NOT wa.me
wa.me is a shortlink alias that 302-redirects to api.whatsapp.com/send. That redirect hop decodes the query string on some devices/WhatsApp versions using a non-UTF-8 charset, corrupting emoji bytes into � (U+FFFD).

Symptom: modal preview shows correct emojis, but the WhatsApp message arrives with � in place of every emoji. encodeURIComponent() output is correct (verifiable in DevTools console — the emoji bytes are %F0%9F...), yet the recipient sees garbage.

Fix: use the canonical endpoint directly.

Bad:

text
https://wa.me/91XXXXXXXXXX?text=...
Good:

text
https://api.whatsapp.com/send?phone=91XXXXXXXXXX&text=...
Note the ? → & when a phone number precedes text.

Applies to resources/views/modals/share-lead.blade.php and any future WhatsApp share link. Not a WhatsApp Business vs normal issue — both affected. If you ever grep for wa.me in the codebase, any hit is the same latent bug.

Cache-Control on authenticated pages
Auth pages (/login, /forgot-password, /reset-password*, /logout) get no-store, no-cache, must-revalidate, max-age=0, private — never bfcached

Authenticated pages get no-cache, must-revalidate, max-age=0, private — allowed in bfcache, revalidated on fetch

A pageshow handler in app.js pings /api/session-check on bfcache restore. If 401, force reload → server redirects to /login. Prevents back-button showing a stale dashboard.

Permissions-Policy
Currently: geolocation=(self), microphone=(), camera=(). geolocation=(self) is required for the future attendance check-in. Do NOT set geolocation=() — it blocks the feature.

Admin has no Agent record by default (⚠️ data gotcha)
Any service that resolves the acting user to an agents row will silently no-op when the user has no Agent record.
Known example: ExternalShareController::store() bails out of scheduling the check-back task if $sharer is null.

Fix applied in prod: an agents row was created for Admin (users.id = 2 → agents.id = 14, status = 'active').

If the DB ever gets reset, re-run this insert or Admin-created shares won't schedule check-backs.

sql
INSERT INTO agents (user_id, phone, max_daily_leads, current_load, status, created_at, updated_at)
VALUES (2, NULL, 10, 0, 'active',
        UTC_TIMESTAMP() + INTERVAL 330 MINUTE,
        UTC_TIMESTAMP() + INTERVAL 330 MINUTE);
Facebook Lead Ads pipeline gotchas
settings.meta_system_user_token — legacy key. Older code stored a single Page token here. New code reads from meta_page_tokens (a JSON map) instead.

settings.meta_system_user_token_raw — the raw System User token (used for refreshing page tokens)

settings.meta_page_tokens — JSON map {page_id: page_token} — this is what the job reads now

settings.meta_pages_cache — JSON array of {id, name} for the UI dropdown

settings.meta_forms_cache_all — JSON array of all forms, each tagged with page_id + page_name

settings.meta_form_project_map — JSON map {form_id: project_id} — drives per-form project routing

settings.meta_forms_synced_at_all, settings.meta_pages_synced_at — timestamps

Column settings.value MUST be LONGTEXT — the 233-form JSON exceeds TEXT

subscribed_apps and leadgen_forms require a Page token. me/accounts, me/permissions accept the System User token. Don't mix them up.

Generating a System User token: click leads_retrieval first — clicking any other permission first silently drops leads_retrieval from the issued token.

Queue cron path: /home/u748336076/domains/propertypoint.online/public_html/npocrm/artisan. The path /home/u748336076/public_html/npocrm/ is the old CodeIgniter app — do NOT use it.

Meta retains leads for 90 days. Older leads are gone.

Lead Access Manager is per-page. The API can subscribe a page to leadgen events, but each page's admin must still grant your app access via its Lead Access Manager. No API bypasses this.

Form filter behaviour
The forms table uses:

Page-first selector — pick a page, then work with its forms only

Search box — filters within the selected page

Min leads — hides forms below the threshold (default 0 = show all)

Non-ACTIVE forms are always hidden, regardless of filter settings

Counter shows X of Y forms (visible vs total on the selected page)

Domain
Real-estate CRM for propertypoint.online. Lead → visit → booking → brokerage workflow.
Multi-project. Projects are routed to teams + agents. Leads auto-assign to the least-loaded eligible agent.

Modules built
Core
Auth: custom login (RateLimiter 5/60s), POST logout with confirm, password reset (SMTP)

Dashboard: role-scoped, priority buckets (OVERDUE / DUE SOON / REST OF TODAY / TOMORROW), site visits today, recent leads, quick actions, greeting

Leads: index, show, store, assign project, assign/share agent, unshare, revive, update status, update booking

Lead routing: per-project direct agents + teams, strict mode flag (blocks global fallback)

Projects: CRUD, search API for autocomplete, project picker component

Activities: store, timeline on lead page

Followups: store, mark done, complete with activity (full outcome engine)

Tasks: dedicated page with type chips

Agents, Teams, Team Managers, My Team

Contacts: index, show, promote to lead, CSV import

Dialer: call queue, log call, recent calls sidebar

Telecalling Report: per-agent dials, connects, talk time, outcome breakdown

Universal search: /search — role-scoped across leads / contacts / projects / agents / teams + tags / labels

Tags & Labels
lead_tags table — single-select per lead (Hot / Warm / Cold / Dead / VIP / Watch)

lead_labels table — multi-select, grouped: Property Type / Buyer Type / Payment / Property Status / Special

lead_label_pivot — pivot with created_at + updated_at

Managed via 🏷️ Tags modal on lead detail and header-chip-btn in the header

Chips appear on lead detail, leads list, search results, dashboard recent leads, dashboard site visits

Clickable chips → /leads?tag_id=X or /leads?label_id=Y

Filters on /leads: Tag dropdown, Label dropdown, quick tag pill row

Full-text search matches tag keys, tag labels, label keys, label labels

External Share
Record sharing a lead with someone outside the CRM — WhatsApp group, site team, outside agent, etc.

Structured storage: lead_external_shares table (lead_id, shared_by_user_id, group_name, reason_key, reason_label, extra_notes)

Timeline: external_share activity type (id 15, is_system=1, requires_outcome=0, sort_order=200) — written only by the controller, filtered from all manual dropdowns

Modal: resources/views/modals/external-share.blade.php

Group / team name — optional, free-text with autocomplete from past shares (max 150)

Reason — preset dropdown, defaulted to follow_up, never blocks save

Extra notes — optional free text (max 2000)

"Close pending tasks" checkbox — appears only when the lead has ≥1 pending followup, defaulted checked. Marks all pending followups on the lead as done before logging.

Info banner: "check-back task in 2 days"

Check-back: 2 days out at 10:00 IST, assigned to the sharer (not the lead's primary agent), reuses the existing check_site_team followup action type

On check-back completion: modal force-locks activity type to site_team_report and shows the existing 6 site-team outcomes

Entry points: 📤 Share Externally button inserted after each Share on WhatsApp button in leads/show.blade.php (3 places: Lost / Won / Active action bars)

Route: POST /leads/external-share → ExternalShareController::store (name leads.externalShare)

Reason presets (keys → labels):

follow_up → 📤 Shared for follow-up (DEFAULT)

site_visit → 🏠 Customer visiting site — site team to receive

outside_agent → 👔 Outside agent to contact directly

closing → 🤝 Handing over for negotiation / closing

reassignment → 💼 Reassignment — they now own it

other → 📝 Other

Reports: group by group_name for "which groups get the most leads", group by reason_label for "why are we sharing"

Facebook Lead Ads intake (multi-page, native pipeline)
MetaWebhookController at /webhooks/meta/lead (GET verify + POST with HMAC signature check)

Dispatches FetchMetaLead → queue → cron worker (every minute)

Auth: System User token (100090522382027) → per-page tokens stored in settings.meta_page_tokens

Pages: 4 currently subscribed (New Projects Online, Property Point - Mumbai, Kharghar Property Deals, New Commercial Projects Navi Mumbai)

Forms: 233 across all pages, ~19 with active leads. Mapping stored in settings.meta_form_project_map

Bypasses Advanced Access for leads_retrieval via System User token route

UI: Settings → Facebook Leads tab — token + pages + subscription + page-first form mapping

Job reads token from meta_page_tokens[$pageId], not a single global token

Job now applies phone normalization (strip 91 / 0 prefix like LeadIntakeService does)

Job uses $assignment->assign($lead) (project-aware routing), not assignLeastLoaded

Facebook Lead Ads settings keys:

meta_system_user_token_raw — raw System User token (for refreshing page tokens)

meta_page_tokens — JSON map {page_id: page_token} — what the job reads

meta_pages_cache — JSON array of {id, name} for the UI dropdown

meta_forms_cache_all — JSON array of all forms, each tagged with page_id + page_name

meta_form_project_map — JSON map {form_id: project_id} — drives per-form project routing

meta_forms_synced_at_all, meta_pages_synced_at — timestamps

Contacts access gating
users.is_telecaller boolean

settings.contacts_visibility = telecallers_only (default) or everyone

Admins and team managers always have access

Non-telecaller agents get 404 on /contacts* and /contacts/dialer

Nav links hidden when access denied

Toggle per-agent via Agent edit form → 📞 Telecaller checkbox

Global override via Settings → General → "Contacts & Dialer Access" radio

Attendance (schema + roster in place, UI not built)
users.is_on_payroll boolean — flag on each user

office_locations table — one row: Head Office — CBD Belapur, lat 19.017296, lng 73.037238, radius 150m

attendances table — one row per agent per day, tracks checked_in_at/lat/lng/accuracy/distance/ip, checked_out_*, tasks_completed, tasks_overdue_at_exit, override fields

Routes: POST /attendance/check-in, POST /attendance/check-out, POST /attendance/force-check-out

AttendanceController NOT YET BUILT — the routes reference a class that doesn't exist yet

Roster (marked is_on_payroll = 1): Alifiya Shaikh, Anuj Jadhav, Aviraj Damle, Gitanjali Kshirsagar, Mrudula, Simran Jaiswal, Taufeeq Shaikh, Yakub Shaikh

Off-payroll: Ajay Jain Agent Role, Khalid Ansari, Dilip Prajapati, Kaushal Sharma, Suchit Kumbhekar

To activate: build AttendanceController (Haversine + radius block), partials/attendance-banner.blade.php (with geolocation JS), dashboard include

Settings
Tabs: General · Website Intake · Facebook Leads · Teams · Managers · Agents · Projects · Routing · Outcomes · Statuses · Activity Types · Action Types · Sources · Call Outcomes.

General tab also has:

Contacts & Dialer Access radio (telecallers_only / everyone)

WhatsApp Initial Message template with placeholders {name}, {agent}, {company}, {project}

Notifications
Desktop + mobile bells, panel with unread-only items

Sound chime (Web Audio API, synthesised, no audio file)

Vibration on Android

Tab title flash when hidden

Mark-all-read clears badges (optimistic UI + server)

Cache-bust on unread-count endpoint (LiteSpeed was caching stale counts)

Reports
/reports — hub: conversion funnel, sources, agents, projects, brokerage

/reports/brokerage — paginated

/reports/team — team performance (role-scoped)

/reports/scorecard — agent scorecard (paginated, full stats)

/reports/crm-health — admin only

/reports/telecalling — dial activity per agent

Dashboard — Stats & Pipeline
Three metric groups, every tile clickable:

🎯 Leads — Total, Today, This Week, Active

🏠 Site Visits — Done (all), This Week, Next 7 Days, Today

🎉 Bookings & Closing Pipeline — Booked (all), This Month, Negotiation, Visit Done

Footer: 👥 active agents · ⏳ pending tasks · 🚨 overdue

Links use ?preset= param handled by LeadIndexController

LeadIndexController preset mapping:

text
new_today, new_week, active,
visits_done, visits_done_week, visits_next_7d, visits_today,
bookings_month
Intake channels
Website form: POST /api/leads (rate-limited, optional API key, honeypot)

Meta Lead Ads webhook: GET/POST /webhooks/meta/lead (multi-page, System User token pipeline)

Manual: New Lead modal

CSV import: /import

Contact import: /contacts/import

Contact promote: /contacts/{id}/promote

Google Sheets migration tool — NOT YET BUILT

Admin tools
/admin/delete-lead — permanent delete (2-step: preview + name confirmation + acknowledge)

Uses DeleteLeadController + LeadDeletionService

Old closure route /admin/delete-lead/{id} was removed — do NOT re-add

/admin/paste-lead — paste lead from external text (PasteLeadController)

Key services
App\Services\SettingsService
DB-driven config.

statuses(), statusLabel(), statusColor(), statusIsFinal(), statusIsWon(), statusIsLost()

allowedTransitionsFrom($statusKey)

activityTypes() — note: called with no args in log-activity.blade.php, with (true, true) in complete-task.blade.php. The latter is required so the forced-type path (check-backs) can find site_team_report / shared_agent_report in the collection.

activityTypeByKey()

actionTypes(), actionTypeByKey()

callOutcomes(), callOutcomeByKey(), callOutcomesForContext()

sources(), sourceByKey()

get($key, $default), set($key, $value), flush()

Cache must be flushed after direct DB edits

App\Services\FacebookLeadService
Multi-page Facebook Lead Ads pipeline.

getSystemUserToken() / saveSystemUserToken() — verifies leads_retrieval before storing

refreshPageTokens() — pulls /me/accounts, stores meta_page_tokens + meta_pages_cache

getPages(), getPageToken($pageId), getConnectedPageId()

fetchFormsForPage($pageId) — paginates correctly (only first call passes params; subsequent use paging.next URL as-is)

fetchFormsForAllPages() — loops all pages, tags each form with page_id + page_name

fetchAndCacheAllForms() — writes meta_forms_cache_all

getFormsCachedAll(), getFormsSyncedAtAll()

isPageSubscribed($pageId) / subscribePageToWebhook($pageId)

subscribeAllPages() — batch subscribe

getAllPagesSubscriptionStatus()

getFormProjectMap() / setFormProject($formId, $projectId)

getDiagnostics()

App\Services\AccessService
Central answer to "can this user see Contacts?"

php
canAccessContacts(): bool
Rules: admin/team_manager always yes; contacts_visibility = everyone yes; is_telecaller = 1 yes; otherwise no.

App\Services\LeadTagService
tags() — active tags, ordered

labelsGrouped() — labels grouped by group_key

apply(Lead, tagId, labelIds) — transaction that writes leads.tag_id + syncs pivot

App\Services\LeadAssignmentService
assign(Lead) — routes by project → direct agents → team members → global (if strict OFF)

assignTo(Lead, Agent, ...) — explicit primary assignment, bumps load

shareWith(Lead, Agent, ..., $shareType) — co-agent share, requires note if reassigning. Also schedules a check-back reminder:

$shareType = 'internal' → reminder to the primary agent, 24h out

$shareType = 'site_team' → reminder to the sharer, 2d out

unshare(Lead, AgentId) — deactivates pivot row, re-homes pending followups to primary (or cancels if no primary)

releaseForFinalStatus(Lead) — decrements agent load

Strict mode via settings.project_routing_strict

eligibleAgentIdsBySource() on Project model splits candidates into direct and team tiers. candidateAgentIds() now enforces direct-agent priority: direct agents get first pick; teams only if no direct agent is eligible.

App\Services\LeadStatusService
change(Lead, $newStatus, $lostReason, $visitScheduledAt, $revivalReason, $skipActivityCheck, $bookingDetails, $brokerageDetails)

Requires activity within 15 min unless $skipActivityCheck = true

Auto-advance logic lives in FollowupController::completeWithActivity — no checkbox, advances on outcome

⚠️ Every variable used inside the transaction closure MUST be in its use (...) list.

App\Services\FollowupService
autoCreateForStatusChange(Lead, $newStatusKey):

Cancels all prior auto-created pending followups first

visit_scheduled → 3 reminders (2 h before, 3 h after, next day 10 AM)

visit_done → +1 day

negotiation → +2 days

booking → +3 days

lost → +90 days

createVisitFollowups(Lead) — the 3 visit reminders

schedule(Lead, $when, $actionTypeKey, $priority) — session-based agent

scheduleForAgent(Lead, $agentId, $when, $type, $priority, $autoCreated) — target a specific agent

Used by ExternalShareController to schedule the 2-day check-back to the sharer

markDone(Followup) — sets status = done

App\Services\SmartFollowupService
scheduleForOutcome(Lead, Activity, CallOutcome) — falls back to the outcome's next_action_delay_hours

ensureAllLeadsHaveNextAction() — safety net, runs every 5 min via cron

App\Services\ContactService
normalizePhone(), importBatch(), promoteToLead(), assignTo()

App\Services\CallService
log(agentId, contactId, leadId, outcomeKey, durationSeconds, notes, source)

agentCallStats(), outcomeBreakdown()

App\Services\ReportScopeService
teamIds(), agentIds(), canSeeReports(), canSeeCrmHealth()

App\Services\ComplianceService
agentScorecard(), teamScorecard(), crmHealth()

App\Services\LeadDeletionService
preview($leadId), delete($leadId)

App\Services\LeadIntakeService
Website / Facebook / Google / Manual / Import intake.

Step 7: $this->assignment->assign($lead) (correct path)

Step 8: creates first-contact followup (delay from first_contact_delay_minutes, default 15)

Step 10: logs intake activity + updates last_activity_at

App\Jobs\FetchMetaLead
dispatch($leadgenId, $pageId)

Reads $settings.meta_page_tokens[$pageId] for the token, fallback to meta_system_user_token for legacy

Calls Graph API v23.0/{leadgenId}?fields=id,created_time,form_id,field_data

Normalizes phone (strips 91 / 0 prefix)

Duplicate-check by phone

Resolves project from meta_form_project_map[$formId], falls back to first active project

Uses $assignment->assign($lead) — project-aware routing

Schedules first-contact followup

Logs Meta Ads lead activity with form + page context

Lead status flow
Statuses (ids)
id	key
1	new
2	contacted
3	visit_scheduled
4	visit_done
5	negotiation
6	booking (final)
7	lost (final)
Transitions (all allowed)
text
new              → contacted, visit_scheduled, negotiation, booking, lost
contacted        → visit_scheduled, negotiation, booking, lost
visit_scheduled  → visit_done, negotiation, booking, lost
visit_done       → negotiation, booking, lost
negotiation      → booking, lost
lost             → new, contacted   (revival)
Outcome → status auto-advance mapping
Outcome	Status becomes
interested	contacted
asked_details	contacted
wa_sent_details	contacted
wa_delivered_awaiting	contacted
wa_read_no_reply	contacted
wa_replied_positive	contacted
site_visit_scheduled	visit_scheduled
negotiating	negotiation
visit_wants_negotiate	negotiation
visit_booked_spot	booking
booking_confirmed	booking
not_interested	lost
already_bought	lost
wrong_number	lost
booking_backed_out	lost
visit_not_interested	lost
already_visited_project	contacted
All others NULL — they log the activity and schedule the next followup but do NOT advance the pipeline.

Auto-advance behaviour
When an agent picks an outcome with a suggested status that is a legal transition:

ActivityController::store() and FollowupController::completeWithActivity advance automatically

Green info line in the modal: "💡 Status will update to Contacted"

If the transition is illegal, LeadStatusService::change() throws → caught → $advanced = false → falls through to SmartFollowupService::scheduleForOutcome()

Both controllers share the same logic. ActivityController previously had a bug where advance_status was required in the input — fixed.

Activity types & Followup action types
Activity types (in activity_types)
Call / WhatsApp / Email / Meeting / Note / Site Visit / Status Change / Lead Shared / Lead Reassigned / Shared Agent Report / Site Team Report / External Share

System-only types MUST NOT be pickable by agents — filtered out in log-activity.blade.php and complete-task.blade.php via $systemTypes reject list:

text
lead_shared, lead_reassigned, status_change,
shared_agent_report, site_team_report,
external_share
Note: in complete-task.blade.php the reject only runs in the non-check-back branch. Check-back tasks force the type to shared_agent_report / site_team_report and skip filtering entirely.

Followup action types (in followup_action_types)
Existing: post_visit_call, negotiation_followup, thank_you_call, reactivation_call, retry_call, send_details, send_brochure, confirm_site_visit, verify_contact, send_budget_options, send_location_opts, followup_call, call, whatsapp_followup, visit_reminder, visit_feedback_call, visit_outcome_call, booking_confirmation, brokerage_followup

Check-back types: check_shared_agent, check_site_team

check_shared_agent → forces activity type shared_agent_report

check_site_team → forces activity type site_team_report (also used by External Share check-backs)

Tags & labels reference
Tags (lead_tags)
Key	Icon	Label	Color
hot	🔥	Hot	red
warm	☀️	Warm	orange
cold	❄️	Cold	blue
dead	💀	Dead	slate
vip	⭐	VIP	purple
watch	👀	Watch	teal
Labels (lead_labels), grouped
Property Type: 1bhk, 2bhk, 3bhk, 4bhk_plus, penthouse, row_house, villa, plot, shop, office_space, commercial, studio

Buyer Type: investor, self_use, resale, rental

Payment: full_down_payment, half_down_payment, twenty_percent, regular_payment, loan_required, cash_buyer

Property Status: ready_to_move, under_construction, new_launch, possession_soon

Special: nri, first_time_buyer, repeat_customer, referral_lead

Outcome → next action delay table
Call
Outcome	Delay	Next action
interested	1 h	send_details
asked_details	1 h	whatsapp_followup
busy	1 h	retry_call
call_later	4 h	retry_call
not_answered	2 h	retry_call
not_reachable	4 h	retry_call
switched_off	8 h	retry_call
site_visit_scheduled	1 h	confirm_site_visit
negotiating	24 h	negotiation_followup
not_interested	2160 h	reactivation_call
wrong_number	24 h	verify_contact
budget_issue	48 h	send_budget_options
location_issue	72 h	send_location_opts
already_bought	4320 h	reactivation_call
already_visited_project	24 h	followup_call (about other projects)
WhatsApp
Outcome	Delay
wa_sent_details	4 h
wa_delivered_awaiting	4 h
wa_read_no_reply	4 h
wa_replied_positive	1 h
Site visit (context: visit_feedback_call)
Outcome	Delay
visit_booked_spot	4 h → booking_confirmation
visit_interested	4 h
visit_needs_family	24 h
visit_wants_negotiate	4 h → negotiation_followup
visit_wants_other_project	24 h → send_details
visit_not_interested	2160 h → reactivation
visit_no_show	2 h
Booking
Outcome	Delay
booking_confirmed	24 h → thank_you_call
booking_postponed	24 h → retry_call
booking_payment_issue	24 h → retry_call
booking_backed_out	2160 h → reactivation_call
booking_needs_more_time	72 h → retry_call
Settings keys
first_contact_delay_minutes = 15

project_routing_strict = 1

contacts_visibility = telecallers_only

followup_escalation_hours

activity_log_window_minutes

company_name

whatsapp_initial_message

unassigned_alert_min_age_minutes = 5

unassigned_alert_repeat_minutes = 15

website_intake_*

default_brokerage_percentage

Complete Task modal
resources/views/modals/complete-task.blade.php

Fields:

Activity type dropdown — normal tasks get Call / WhatsApp / Email / Meeting / Note / Site Visit (system types filtered out). Check-back tasks force shared_agent_report / site_team_report and skip the filter.

Outcome dropdown — searchable combobox (.op-picker) driven by a hidden <select>; supports comma-separated activity_type_filter

Stage-relevance filter — outcomes are filtered by UNIVERSAL list + STATUS_OUTCOMES[lead_status]; a "Show all outcomes" toggle reveals hidden ones

📅 Visit Date & Time — reveals when target status has requires_datetime = 1

⏰ Custom next action time — always visible

📤 Also send WhatsApp — checkbox, reveals for outcomes with prompts_whatsapp_send = 1

💡 Status auto-advance info line — green

Booking fields — reveal when target status has requires_booking_details = 1

Notes textarea

Note warning — when activity type = note and the task is a contact-type action, an amber banner warns that notes don't advance the lead

Check-back tasks (check_shared_agent, check_site_team) force the activity type and hide other options.

Client-side validation
Every [required] field is checked before submit

Empty → red banner + red border + shake + scroll + focus, no POST

Red asterisks auto-wrapped by a MutationObserver in app.js

Searchable outcome picker
Progressive enhancement over the hidden native <select>:

Add a .op-picker div with search input, clear button, results panel

JS .op-item clicks set select.value and fire change

The hidden select stays the source of truth — existing JS keeps working

Supports keyboard nav (↑ ↓ Enter Esc)

Placeholder: 🔍 Search or select outcome…

Applied in both log-activity.blade.php and complete-task.blade.php

Lead Activity logging — one path only
Design principle: agents must complete a task to log an interaction. Free-form logging is a fallback.

The 📞 Log Activity button was removed from the lead action bar

The lead detail page shows:

Header (with tags)
⏰ Pending Tasks card — prominent, at the top. Each task has a big green ✅ Done button
Site Visit card (if any)
Lost reason / Booking card (if applicable)
Manage the lead label + action bar
Pipeline stepper
Activity timeline
The Pending Tasks card has a small "📝 Customer reached out outside the task queue? Log it here" link at the bottom

When there are no pending tasks, the fallback link reads: "📝 Log something that happened (call, WhatsApp, meeting)"

Both ActivityController::store() (fallback) and FollowupController::completeWithActivity() (Done button) route through the same logic.

External Share is a third way to log an interaction, but it's a handoff, not a completion:

Opens from the action bar (not the Pending Tasks card)

Optionally closes pending tasks (checkbox)

Writes external_share activity + creates a check-back

Lead detail page layout (final)
text
[← Back to dashboard]
[Header card: name + status + tag + labels + contact buttons + meta grid]
[⏰ Pending Tasks card — primary work surface]
[🏠 Site Visit card if scheduled]
[🚫 Lost Reason card if lost]
[🎉 Booking card if booked]
"Manage the lead" label
[Action bar — Schedule(admin) / Assign Project / Assign Share /
               Share on WhatsApp / Share Externally / Tags]
[📊 Pipeline stepper]
[📜 Activity Timeline]
The Pending Tasks card is styled with a colored left border:

Amber (#f39c12) when there are pending tasks

Red (#e74c3c) when any are overdue

Neutral gray when empty

The Share Externally button uses class="btn-small" with inline background:#0ea5e9;color:#fff; (sky blue). It sits immediately after the Share on WhatsApp button in all three action bars (Lost / Won / Active).

Dev / debug routes (all gated by ?key=DEV_ROUTE_KEY)
/clear-config — clears all caches

/admin/flush-settings — flushes SettingsService cache

/admin/backfill-visits — runs leads:backfill-visit-followups

/admin/sync-manager-agents — backfill Agent records for team_managers (one-time)

/admin/fix-stuck-visits — interactive CLI-style, or ?apply=1&lead=X&when=...

/admin/backfill-customers — groups leads by phone, creates Customer records

/test-fix-stuck-statuses — advance leads based on activity history (dry-run or ?apply=1)

/test-outcomes, /test-config, /test-time, /test-smtp, /test-social-config

/test-run-command, /test-ensure-now, /test-reminder, /test-escalate, /test-schedule-list

/debug-access — JSON: session role + is_telecaller + canAccessContacts

/debug-attendance — JSON: session role + is_on_payroll + requiresAttendance + offices + today_attendance

Artisan commands
leads:alert-unassigned — every 5 min via scheduler

leads:backfill-visit-followups

leads:ensure-next-actions

followup:remind

followup:escalate

leads:fix-stuck-statuses {--apply}

leads:fix-stuck-visits {--apply} {--lead=} {--when=}

Cron jobs (cPanel)
* * * * * — schedule:run (Laravel scheduler — handles leads:alert-unassigned, etc.)

* * * * * — queue:work --stop-when-empty --max-time=55 --tries=3 (Facebook leads queue processing)

Full queue cron command:

text
cd /home/u748336076/domains/propertypoint.online/public_html/npocrm && /usr/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
Without this cron, FetchMetaLead jobs pile up in the jobs table and no Facebook leads land.

Tables (live DB)
Core
text
users, sessions, password_reset_tokens, cache, cache_locks,
jobs, job_batches, failed_jobs, migrations
CRM
text
activities, activity_types, agents, call_outcomes, followup_action_types,
followups, lead_agents, lead_import_batches, lead_sources,
lead_status_transitions, lead_statuses, leads,
notifications, projects, projects_import_staging,
project_agent, project_team,
settings, team_members, teams, facebook_integrations,
lead_tags, lead_labels, lead_label_pivot,
lead_external_shares,
office_locations, attendances
Contacts
text
contacts, calls, contact_import_batches
lead_external_shares schema (created manually)
sql
id                BIGINT UNSIGNED PK AI
lead_id           BIGINT UNSIGNED NOT NULL  (idx)
shared_by_user_id BIGINT UNSIGNED NOT NULL  (idx)
group_name        VARCHAR(150) NULL         (idx)
reason_key        VARCHAR(50)  NOT NULL DEFAULT 'follow_up'  (idx)
reason_label      VARCHAR(150) NOT NULL
extra_notes       TEXT NULL
created_at        TIMESTAMP NULL
updated_at        TIMESTAMP NULL
followups schema (for reference)
text
id, lead_id, agent_id, scheduled_for, action_type, priority,
status (default 'pending'), escalated_flag, auto_created,
source_activity_id, created_at, updated_at
No completed_at / done_at column. status is the whole completion record.

settings schema (for reference)
text
id, key (unique index), value (LONGTEXT), created_at, updated_at
⚠️ value must be LONGTEXT — TEXT overflows on the 233-form JSON.

Recent column additions
call_outcomes.is_connected (0/1)

leads.unassigned_alerted_at (timestamp, nullable)

agents.phone made nullable (was NOT NULL UNIQUE)

users.is_telecaller (tinyint, default 0)

users.is_on_payroll (tinyint, default 0)

leads.tag_id (bigint, nullable, FK → lead_tags)

lead_label_pivot.updated_at (added after the initial table creation)

⚠️ Migration files still missing
database/migrations/ only has the 3 default Laravel files. All 40+ business tables were created manually via phpMyAdmin. Do NOT run migrate:fresh. Recovery is via full phpMyAdmin SQL export stored off-server. Export taken 2026-09-13; re-export if schema changes significantly.

Models (app/Models)
text
Activity, Agent, AppNotification, Call, Contact, ContactImportBatch,
FacebookIntegration, Followup, Lead, LeadAgent, LeadImportBatch,
Project, Team, TeamMember, User,
LeadTag, LeadLabel,
LeadExternalShare,
Attendance, OfficeLocation,
Config/: ActivityType, CallOutcome, FollowupActionType, LeadSource, LeadStatus, Setting
Lead model key relations
php
agent(), project(), activeAgents(), assignments(),
tag()          // belongsTo LeadTag via tag_id
labels()       // belongsToMany LeadLabel via lead_label_pivot, withTimestamps
latestActivity(), pendingFollowups()
Lead $fillable (currently includes)
text
customer_name, phone, email, source, budget, status, previous_status,
lost_reason, project_id, agent_id, customer_id,
assigned_at, last_activity_at, unassigned_alerted_at, visit_scheduled_at,
booking_amount, property_area_sqft, rate_per_sqft, booking_unit,
booking_payment_mode, booking_date,
brokerage_percentage, brokerage_amount, brokerage_status,
brokerage_expected_at, brokerage_received_at, co_broker_name,
intake_source, intake_ref,
utm_source, utm_medium, utm_campaign, utm_content, utm_term,
click_id, referrer_url, raw_payload,
tag_id
⚠️ If you add a column that gets written via $lead->update([...]), it MUST be added to $fillable.

LeadExternalShare model
php
$table: lead_external_shares
$fillable: lead_id, shared_by_user_id, group_name,
           reason_key, reason_label, extra_notes
$casts: created_at → datetime, updated_at → datetime
relations: lead(), sharedBy()
User model
php
$fillable: name, email, password, role, is_telecaller, is_on_payroll
$casts: role → UserRole, is_telecaller → bool, is_on_payroll → bool
requiresAttendance(): bool  // true only for agents with is_on_payroll = 1
canAccessContacts(): bool   // delegates to AccessService
Controllers (app/Http/Controllers)
text
ActivityController, AgentController, AttendanceController (STUB — needs building),
ContactController, ContactCallController, ContactImportController,
CustomerController, DashboardController, DeleteLeadController, ExportController,
ExternalShareController, FacebookController, FacebookLeadSettingsController,
FollowupController, ImportController,
LeadController, LeadIndexController, LeadTagController, ManagerController,
MetaWebhookController, ModalController, MyTeamController, NotificationController,
PasswordResetController, PasteLeadController, ProjectController,
ProjectRoutingController, PublicLeadController, ReportController, SearchController,
SecurityHeaders, SettingsController, TaskController, TeamReportController,
TelecallingReportController, UserRoleController
ExternalShareController
php
const REASONS = [ 'follow_up', 'site_visit', 'outside_agent',
                  'closing', 'reassignment', 'other' ];

store(Request $request, FollowupService $followups)
Flow (inside one DB transaction):

LeadExternalShare::create([...]) — structured record

If complete_pending checked → mass-update all pending followups on the lead to status = 'done'

Build timeline note (By / To / Reason / Notes / Also closed N pending tasks)

Activity::create([...]) with type = external_share

$lead->update(['last_activity_at' => now()])

If $sharer (Agent) exists: cancel stale check_site_team pending rows, then $followups->scheduleForAgent($lead, $sharer->id, now()->addDays(2)->setTime(10,0), 'check_site_team', 'high')

If $sharer null: Log::warning(...) and return early (no check-back scheduled)

Validation:

php
'lead_id'          => 'required|integer|exists:leads,id',
'group_name'       => 'nullable|string|max:150',
'reason_key'       => 'required|string|in:' . implode(',', array_keys(self::REASONS)),
'extra_notes'      => 'nullable|string|max:2000',
'complete_pending' => 'nullable|boolean',
⚠️ Important use (...) clause — all of $lead, $validated, $userId, $sharerName, $reasonLabel, $sharer, $followups, $closePending must be captured. Do not add variables to the closure body without adding them here.

FacebookLeadSettingsController
Admin-only. Routes:

POST /settings/facebook-leads/token → saveToken

POST /settings/facebook-leads/refresh-pages → refreshPages

POST /settings/facebook-leads/connect-page → connectPage (legacy, kept for compatibility)

POST /settings/facebook-leads/subscribe-all → subscribeAll

POST /settings/facebook-leads/refresh-forms → refreshForms (now fetches from all pages)

POST /settings/facebook-leads/map → saveMapping (AJAX from the picker)

Note: These routes must be listed in routes/web.php above the generic /settings/{type}/... catch-all, or the catch-all will swallow them.

MetaWebhookController
verify() — GET handshake for Meta's webhook subscription

handle() — POST handler, validates X-Hub-Signature-256, dispatches FetchMetaLead per leadgen change

Views (resources/views)
text
auth/           login, forgot-password, reset-password
components/     contact-buttons, lead-tag-chip, lead-labels-row, lead-row, lead-card,
                project-picker, stat-card, status-badge, status-chart,
                task-card, task-type-chip
contacts/       index, show, dialer, import/{index,preview,summary}
import/         index, preview, summary
leads/          index, show, mine
layouts/        app, auth
modals/         add-lead, add-project, edit-project, assign-agent,
                assign-project, change-status, complete-task, edit-booking,
                edit-tags, log-activity, manager-form, agent-form, team-form,
                project-routing, my-team-agent-form, my-team-project-routing,
                revive-lead, schedule-followup, settings-form, change-role,
                log-call, share-lead, external-share
partials/       header, alerts, modals, notifications-panel, pagination
                (attendance-banner — NOT YET BUILT)
reports/        index, brokerage, team, scorecard, crm-health, telecalling
settings/       index + partials/project-routing
tasks/          index
admin/          delete-lead, paste-lead
search/         index
+ dashboard.blade.php, my-team.blade.php, notifications.blade.php, welcome.blade.php
external-share.blade.php
Hidden lead_id

Group / team input with .proj-picker-based autocomplete, fed by distinct(group_name) from lead_external_shares (take 50)

Reason <select> populated from ExternalShareController::REASONS, default follow_up

extra_notes textarea (max 2000)

Conditional amber "Close N pending tasks" checkbox — renders only if Followup::where('lead_id', $lead->id)->where('status', 'pending')->count() > 0, checked by default

Blue info banner (check-back in 2 days)

Inline <script> for the autocomplete (isolated IIFE, safe if CSS is missing)

share-lead.blade.php — WhatsApp link builder
Uses api.whatsapp.com/send, NOT wa.me (see gotchas). Three branches:

js
if (phone.length === 10) {
    url = 'https://api.whatsapp.com/send?phone=91' + phone + '&text=' + encodeURIComponent(text);
} else if (phone.length > 10) {
    url = 'https://api.whatsapp.com/send?phone=' + phone + '&text=' + encodeURIComponent(text);
} else {
    url = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text);
}
window.open(url, '_blank');
Message template (UTF-8 literal emojis): 🔔 New Lead / 👤 name / 📱 phone / ✉️ email / 🏗️ project.

settings/index.blade.php — Facebook tab structure
Health banner (green if token set + all pages subscribed + ≥1 form mapped)

1️⃣ System User Token — paste/verify + refresh page tokens

2️⃣ Pages — subscription status per page + Subscribe All button

3️⃣ Lead Forms → Project Mapping — page-first selector, then search/min-leads/picker

🩺 Diagnostics — queue pending, failed 24h, recent leads

JS blocks (in @push('scripts'), in this order):

Tab switching

initFbProjectPickers — searchable project picker, saves via AJAX to /settings/facebook-leads/map

initFormFilter — page-first filtering + search + min-leads

⚠️ Watch the IIFE nesting — the outer (function () { ... })(); must close with exactly one })(); at the end. A missing closing brace silently breaks the entire script block, disabling tabs and filters.

Public assets
public/assets/js/app.js
Modal open/close + AJAX form submit + runScriptsIn for injected scripts

Client-side required validation + error rendering with banner / shake

Auto-marks trailing * in labels as a red .req span via MutationObserver

Notifications: bells, panels, sound chime (Web Audio API), vibrate, tab flash

Logout confirm on button click (not inline onsubmit) with 60ms delay

Double-back-to-exit on mobile dashboard

Session check on pageshow (bfcache restore)

Mobile drawer open/close

PWA install prompt

Note warning on task type change

Project picker autocomplete

Clickable lead rows/cards ([data-lead-url]) with interactive-child bailout

public/assets/css/app.css
Design tokens + everything. Sectioned by feature. Key additions:

.btn-agent — indigo (#6366f1)

.btn-log, .btn-task, .btn-project, .btn-agent, .btn-view

.btn-whatsapp — real brand green with SVG logo

.task-type-chip + .tt-* tone variants

.task-card + .tc-* for the compact 3-line task layout

.lead-tag-chip + .tag-* colors

.lead-labels-row + .lead-label-chip + .ll-* group tones

.header-chip-btn — the "Add / Edit tags" pill

.pending-tasks-card, .ptc-*, .ptc-log-else

.tag-filter-row, .tag-filter-chip

.leads-table compact actions

.biz-* — Stats & Pipeline tiles

.op-picker, .op-search, .op-results, .op-item — searchable outcome picker

.attendance-banner, .ab-* — attendance (CSS ready, feature not built)

.proj-picker, .proj-input-wrap, .proj-icon, .proj-search, .proj-clear, .proj-results, .proj-item, .proj-item-name — project picker, also reused by external-share autocomplete and the Facebook form mapper

public/agent-guide.html
Multilingual (English / हिन्दी / मराठी) — version 1.3

Language switcher (localStorage + ?lang= query param)

public/samples/
contacts_sample.csv, leads_sample.csv, contacts_advanced.csv

Conventions to keep
Keep session-based auth. Do NOT migrate to Breeze.

Keep SettingsService for anything configurable.

Every dev route must be gated by ?key=DEV_ROUTE_KEY.

Do not change route names without checking views.

Match existing style: snake_case route names, no strict_types, kebab-case CSS classes.

Emojis in modal titles must be rawurlencode()d server-side + decodeURIComponent()d in JS.

On every lead status change, cancel prior auto-created pending followups first.

Report pagination uses LengthAwarePaginator with a distinct pageName per report.

If a column gets written via $model->update([...]), verify it's in $fillable.

Every closure must list all variables it uses in the use (...) clause.

If you introduce a new activity type that's written only by backend code, add it to $systemTypes in both log-activity.blade.php and complete-task.blade.php.

Structured features should store both a stable *_key (for reporting) and a human *_label (for display / timeline), so a future rename doesn't invalidate historical rows.

Any feature that resolves the acting session user to an agents row must gracefully handle the "no Agent record" case — do not let it fatal.

WhatsApp share links must use api.whatsapp.com/send, not wa.me — the redirect hop mangles UTF-8 emoji.

New POST routes under /settings/... must go above the generic /settings/{type}/... catch-all in routes/web.php.

Blade @push('scripts') blocks must have balanced IIFEs — one outer (function () { ... })(); wrapper, closed exactly once at the end.

Recent bug fixes
LeadStatusService::change() closure missing $newIsFinal

complete-task modal filtering outcomes by context_action_key

advance_status checkbox removed — status auto-advances

ManagerController@save — $team undefined on create path

FollowupController@completeWithActivity — scheduleForOutcome called with null $outcome

Notification panel mobile was cut off

WhatsApp message rendering — newlines collapsed by preg_replace('/\s+/', ' ')

Duplicate followups on status change — autoCreateForStatusChange now cancels stale auto-created pending ones first

Modal titles with emoji showed â — fixed with rawurlencode / decodeURIComponent

assigned_at was NULL on lead 44 — missing from Lead::$fillable

last_activity_at was NULL on new leads — added to fillable + LeadIntakeService

unshare() was leaving orphaned followups — now re-homes to primary (or cancels)

ActivityController::store() required the removed advance_status checkbox

FollowupController::markDone didn't log an activity — now writes to timeline

lost_reason validation blocked not_interested → lost — now auto-defaults from the outcome label

Two schedule() calls 21 seconds apart produced duplicate tasks

Website intake leads had assigned_at = NULL

Direct agents weren't getting priority over team members

ActivityController + FollowupController had divergent auto-advance logic

Blade @if after a word character broke compilation — space added

Back button on mobile showed login screen — added Cache-Control + pageshow handler

Lead 37 had stale last_activity_at — backfill SQL run

ExternalShareController v1 draft — $sharerUserId missing from closure use (...) (PHP 8.3 latent fatal, only on the no-Agent path). Rewritten in v2 with a proper use clause.

External Share v1 — check-back silently skipped when Admin shared — root cause was Admin having no agents row. Fixed by inserting an Agent record for user_id 2 (agents.id 14).

WhatsApp share modal showed � for emojis — wa.me shortlink redirect mangles UTF-8. Switched to api.whatsapp.com/send.

Facebook Lead Ads — subscribed_apps and leadgen_forms endpoints rejected the System User token; needed the Page token. Fixed by fetching per-page tokens from /me/accounts.

Facebook Lead Ads — pagination in fetchFormsForPage() was re-fetching page 1 repeatedly (params appended on every iteration). Fixed to only pass params on the first call.

Facebook Lead Ads — settings.value column too small for the 233-form JSON cache. Fixed via ALTER TABLE settings MODIFY COLUMN value LONGTEXT.

Facebook Lead Ads — FetchMetaLead was storing phones with the 91 country prefix; website intake stored without. Fixed by adding the same normalization to the job.

Settings page JS — missing closing })(); broke the entire script block, disabling tabs and form mapping.

Queue worker silently not running for days — leads piled up in jobs table unprocessed. Fixed with a cron every minute.

Still known / pending
Migration files missing for 40+ tables — migrate:fresh is still unsafe; do NOT run it. Recovery path is a full phpMyAdmin SQL export, stored off-server. Taken 2026-09-13. If schema changes significantly, re-export.

Timezone mismatch — MySQL UTC vs Laravel IST. Raw SQL date writes need the +330 min trick.

Google Sheets migration tool — discussed, not built. Plan: per-tab import (Booked → status=booking; visited → status=visit_done + activity note; calling lists → contacts; auto-detect phone column; header-row selector).

Attendance check-in/out user-facing flow — schema, models, routes, roster done. Still need: AttendanceController (Haversine + radius), partials/attendance-banner.blade.php (with geolocation JS), dashboard include.

lead_label_pivot.updated_at — added after initial create; if ever re-created, include both timestamps.

ActivityController $systemTypes filter — check both log-activity.blade.php and complete-task.blade.php still apply it after any modal edits.

leads:fix-stuck-statuses command file — the route-based version at /test-fix-stuck-statuses works.

External Share — check-back UX wording — the check-back forces activity type site_team_report with site_team_* outcomes. If the external recipient is a generic WhatsApp group rather than a literal site team, the wording may feel off. Options: (A) live with it, (B) add a parallel external_share_report activity type + neutral outcomes. Currently (A).

External Share — no Admin fallback for missing Agent record — the controller bails if $sharer is null. Currently mitigated by the manual Admin Agent insert, but a code-level fallback ($sharer ?? $lead->agent) would be more robust.

External Share — check_site_team reuse for sharing — semantically it's "check back on shared lead", but the action type key is still check_site_team. If a dedicated check_external_share action type is added later, update both ExternalShareController and complete-task.blade.php's $isCheckBack / $forcedType logic.

Facebook Lead Ads — historic backfill — ~600 leads from May 2026 onward sit in Meta unprocessed. Meta retains 90 days, so older ones are already gone. Plan (when ready): backfill the last 14 days only via a batch script hitting /{form_id}/leads on each form.

Facebook Lead Ads — Admin has no fallback if $sharer is null — same as External Share issue above. Consider adding $sharer ?? Agent::where('user_id', session('user_id'))->first().

Facebook Lead Ads — Lead Access Manager is per-page — even after subscribing pages via the API, each page's admin must grant access in that page's Lead Access Manager. The UI links directly to it via 🔗 Access ↗.

Pabbly Connect — legacy bridge for Facebook leads still active. Should be deactivated once the native pipeline proves stable for 3–5 days.

How to run diagnostics on the server
Create public/ctx.php that bootstraps Laravel from __DIR__.'/..' and prints routes, tables, config. Delete after use. Or use the existing dev routes above.

Or drop public/find.php (grep tool, gated by ?key=DEV_ROUTE_KEY&q=term&path=...) — useful when you don't have shell access.

For Facebook Lead Ads debugging:

storage/logs/laravel.log — grep for FetchMetaLead, Meta lead, leadgen

jobs table — should be near 0. Pile-up = cron broken

failed_jobs table — should be empty. Any rows = real failures to investigate

Meta App Dashboard → Webhooks → Recent Deliveries — shows what Meta sent and what your endpoint returned

Session summary — what changed this round
Added:

External Share module — full pipeline with structured storage, timeline, check-back, close-pending-tasks

Facebook Lead Ads native pipeline — multi-page, System User token route, no Pabbly, no App Review

Facebook Lead Settings UI — token + pages + subscription + page-first form mapping

Queue cron job — was silently missing; jobs piled up unprocessed

Admin Agent record (agents.id 14, user_id 2) — required for check-backs to schedule

Fixed:

ExternalShareController v1 draft's missing closure variable

Admin-shared leads silently skipping the check-back

WhatsApp share emoji corruption — wa.me → api.whatsapp.com/send

Facebook pagination bug — forms were re-fetched 10× on a single page

settings.value column widened to LONGTEXT

Facebook lead phone normalization (strip 91 prefix)

Settings page JS — missing IIFE closer broke all interactivity

FetchMetaLead now uses project-aware routing (assign, not assignLeastLoaded)

Verified working:

External Share end-to-end (lead 73)

Facebook Lead Ads end-to-end (lead 80, then multi-page on 4 pages with 233 forms)

WhatsApp share with emojis after API endpoint swap

All system-type filtering in both task modals

Still pending:

Attendance user-facing flow (controller, banner, dashboard include)

Google Sheets importer

Migration files for all tables

Backfill of historic Facebook leads (last 14 days)

Deactivate Pabbly once native pipeline is proven stable

text

**One thing to note** — the file I just wrote is one continuous block. When you copy it into your editor, make sure you're not accidentally pasting into a truncated buffer. If it looks cut off, scroll down — it's long, but complete.

## v72 — Role-Aware Work Focus & Task Flow (prepared 2026-09-19; NOT YET DEPLOYED)

Built from the deployed v71 Desktop Alerts baseline. This release is intentionally focused on live-work clarity and does NOT include the incomplete WhatsApp / Recent Calls lead-creation implementation.

### Product model
- Dashboard is the landing/overview surface.
- My Work is the personal operational execution queue.
- My Leads is for finding/managing the lead book.
- Team Status is the management surface for Team Managers/Admins.
- Admin and Team Manager dashboards remain management-oriented rather than being flattened into the Agent screen.
- Team Managers can still use My Work for their own leads/tasks when they have an Agent record; their team tasks remain in Team Status.
- Admins do not inherit every CRM task through `/tasks`; if an Admin has personal Agent work, `/tasks` only exposes that Admin's own assigned tasks. Admin navigation remains management-first.

### Implemented changes
- `app/Http/Controllers/TaskController.php`
  - My Work task scope is now the signed-in user's own Agent record only.
  - Team Manager team-wide task visibility remains in Team Status, not My Work.
  - Users without an Agent record do not receive a fabricated task queue.
  - Existing `AccessService::canWorkLead()` firewall remains in place.
- `app/Http/Controllers/DashboardController.php`
  - Adds personal overdue/today counts for Team Managers who also work as agents.
  - Management task counts remain team-scoped on the management dashboard.
- `resources/views/dashboard.blade.php`
  - Makes the distinction between Team work and a Manager's personal work explicit.
  - Manager gets a direct My Work entry showing personal overdue/today workload.
  - Admin remains management-first and is not presented with a generic Current Work queue.
  - Agent secondary action is now explicitly `My Work` rather than `Current Work`.
- `resources/views/partials/header.blade.php`
  - Dashboard is the landing surface.
  - Agent/Team Manager get `My Work` as the operational navigation item.
  - Admin does not get an agent-style My Work navigation item by default.
  - Removed the competing `Follow-ups` navigation label.
- `resources/views/components/task-card.blade.php`
  - Always carries the originating `return_to`, including `/` (Dashboard), so completion can return to the exact origin instead of falling through to a generic lead/My Work destination.
- `public/assets/js/app.js`
  - Marks task-completion modal state on the document so Lead View secondary navigation can be suppressed during active task completion.
- `resources/views/leads/show.blade.php`
  - History / Customer / Visits / Projects / Pipeline / More secondary tab row is hidden while a task is being worked (`focus_work`) or while the Complete Task modal is open.
  - The completion CTA now says `Back to Work`, because the destination may be Dashboard or My Work depending on origin.
- `public/assets/css/app.css`
  - Secondary Lead View tabs are removed from the visual stacking context during task execution (`z-index: 0`, hidden, pointer-disabled), preventing them from competing with the work surface.
- `resources/views/modals/complete-task.blade.php`
  - `Report from Site` is now a normal task-completion activity option for all users who can work the lead, using the existing `site_team_report` backend key.
  - Existing check-site-team tasks still force this activity type as before.
  - Site-team outcomes are explicitly made relevant when `Report from Site` is selected.
  - Free-form Log Activity system-type filtering remains unchanged.

### Safety / compatibility
- No database schema or data changes.
- No WhatsApp / Recent Calls lead creation changes.
- Existing lead/task permissions are preserved; My Work is narrowed to personal execution rather than expanding access.
- Existing return-to validation remains server-side and accepts only safe internal relative paths.
- Mobile navigation z-index is not changed by this release.
- Existing v71 desktop Alerts stacking fix is preserved.
- PHP syntax lint: all 281 PHP files pass.
- `node --check public/assets/js/app.js` passes.
- `node --check public/service-worker.js` passes.
- Blade files were PHP syntax checked; full runtime Blade compilation is not available in the supplied source package because the package does not contain a local Laravel vendor/Artisan runtime.

### Deployment state
- This v72 package is prepared only. It is NOT deployed.
- v71 remains the confirmed live baseline until the user deploys and verifies v72.

## Merged historical release context


## 2026-09-21 — Create Lead Contact Picker UI Consolidation
- The Add Lead modal now exposes one contact-import button only: `📇 From Phone Contacts`.
- The visible button uses the existing `data-device-whatsapp-share` handler because the V4 Android Device Bridge implementation was tested by the user and confirmed to open the faster Android contact chooser and return the selected contact to the CRM Add Lead form.
- The separate `data-device-contact-picker` button is removed from the Add Lead modal to avoid duplicate/confusing contact-entry actions.
- No Android APK/source change is included in this CRM UI patch. The installed V4 Device Bridge remains the native implementation behind the renamed button.
- No database/schema/controller/service/permission change.

## 2026-09-19 — Desktop Alerts Bell / Lead Workbench Stacking Fix
- Built from the actually deployed v70 baseline; this is a targeted desktop-only CSS correction and does not change the mobile navigation z-index hierarchy.
- Problem: on Lead View, the sticky `.lead-workbench-topbar` uses a z-index of 1000, while the global desktop header/notification wrapper was inside a lower header stacking context (`z-index: 120`). The Alerts dropdown therefore appeared behind/cut by the sticky lead-name row.
- Fix: at desktop widths (`min-width: 768px`), `.header` is raised to `z-index: 1100 !important`, placing the complete header stacking context above the Lead Workbench top bar (`1000`) and its section/action layers.
- The notification panel itself remains at its existing z-index; no notification markup, JavaScript, mobile drawer z-index, or notification behavior was changed.
- No database/schema/controller/service/permission changes.
- Validation: CSS source inspection confirms the desktop-only override is after the Lead Workbench z-index rules; mobile remains on the existing header z-index.

## 15. Release 2026-09-16 — first-touch lifecycle correction + lead date filters
### Requirement implemented
The lead lifecycle now treats `New` as an untouched state for customer-facing work. A human work action recorded through the canonical activity processor must not leave a lead in `New`.

Canonical lifecycle:
- New = no recorded human customer-facing work yet.
- Attempted = a first human work/contact attempt has been recorded, but the outcome has not necessarily established customer contact.
- Contacted = customer contact has been established or the configured outcome advances the lead to Contacted.
- Visit Scheduled → Visit Done → Negotiation → Booking remain the normal downstream pipeline.
- Shared Externally is a separate branch: New → Shared Externally → Attempted → Contacted → downstream pipeline.
- Internal cross-project sharing remains a project/agent handover operation. It does not falsely count as customer contact; the receiving lead remains New until actual human work is recorded.

### Code changes
- Added migration: `database/migrations/2026_09_16_100000_harden_lead_lifecycle_attempted_external_shared.php`
  - Adds `attempted` and `external_shared` statuses by key if missing.
  - Reorders the main pipeline to New → Attempted → Contacted → Visit Scheduled → Visit Done → Negotiation → Booking → Lost.
  - Keeps External Shared outside the main pipeline as a branch.
  - Removes direct New → Contacted/Visit/Negotiation/Booking bypass transitions while retaining controlled New → Lost.
  - Adds New → Attempted, New → Shared Externally, Attempted → Contacted/Lost, Shared Externally → Attempted/Lost.
  - Clears status caches after migration.
- Updated `app/Services/LeadActivityProcessor.php`.
  - Every canonical human activity completion from New or Shared Externally first advances to Attempted inside the same transaction.
  - If the selected outcome advances to Contacted or a later valid stage, the processor then continues from Attempted through the configured transition.
  - This covers both Log Activity and Done — Log Now because both use the same processor.
- Updated `app/Services/LeadStatusService.php`.
  - Returning a lead to New is blocked once qualifying human-touch activity exists.
  - Human-touch detection deliberately excludes automated/intake/admin/task-scheduling records.
  - Added canonical `markExternallyShared()` transition helper.
  - Added optional status-change automation suppression for internal first-touch staging so outcome scheduling happens once.
- Updated `app/Http/Controllers/ExternalShareController.php`.
  - External sharing now requires `AccessService::canWorkLead()` server-side.
  - New leads move to Shared Externally through the canonical status helper.
  - Timeline records the lifecycle transition.
- Updated `app/Http/Controllers/FollowupController.php`.
  - Site-team external sharing follows the same New → Shared Externally branch.
- Updated `resources/views/modals/change-status.blade.php`.
  - Status dropdown now exposes only server-configured allowed next statuses instead of showing confusing invalid options.
- Updated `resources/views/modals/complete-task.blade.php`.
  - Outcome-stage filtering now knows Attempted and Shared Externally stages.
- Updated `resources/views/leads/show.blade.php`.
  - Main pipeline excludes the External Shared branch and displays an explicit Shared Externally state banner.
- Updated `resources/views/leads/index.blade.php`.
  - Consolidated date filtering into the main Filters panel rather than maintaining a separate confusing date card.
  - Date filters cover Created, Activity, Visit and Booking ranges.
  - Fixed a malformed Blade empty-state block present in the supplied baseline.

### Behavioral matrix
- New + Done/Log Activity + Not Answered/Busy/etc. → Attempted.
- New + Done/Log Activity + Interested/Asked Details/etc. → Attempted → Contacted (or the configured later stage if valid).
- New + first customer-facing action that advances to Visit Scheduled → Attempted → Visit Scheduled.
- New + first action that results in Lost → Attempted → Lost.
- New + external share → Shared Externally; no false Contacted state.
- Shared Externally + customer-facing work → Attempted, then continues according to outcome.
- Internal cross-project handover → destination lead remains New; project and agent are changed through the controlled handover workflow.
- Attempting to manually/revivally return a lead with qualifying human-touch history to New is rejected.

### Verification performed
- PHP syntax lint was run across the complete supplied PHP source tree; no syntax errors were found.
- Targeted modified PHP files and the new migration were individually linted successfully.
- Static inspection confirmed all current lead list surfaces use the shared lead work-action component and canonical activity processor for Done actions.
- No live-server files or live-server database dump were requested.

### Deployment
The lifecycle release was deployed to the user's cPanel/phpMyAdmin environment using a manual SQL block because this environment has no SSH/Artisan workflow. The SQL was confirmed by the user as working, and the updated PHP/Blade files were uploaded successfully.

### Next baseline rule
Future CRM work must preserve this lifecycle contract. Any new customer-facing action/channel must enter through the same lifecycle decision path; it must not independently invent a status change. Any new sharing mechanism must explicitly declare whether it is external customer-team sharing or internal project/agent handover.
## 16. Release 2026-09-16 — mobile-centric Lead Directory + one canonical work surface
### UX direction established
The CRM is now being treated as a mobile-first working system, not a desktop system squeezed onto a phone. The user should always know where to find a lead, where to understand it, and where to work it.

Core UX contract:
- **Directory = Find.** Leads list is for search, filtering, sorting and selecting a lead.
- **Lead page = Understand + Work.** The Lead page is the canonical place for lead work.
- **Pending Tasks / Work area = Act.** Customer-facing work is recorded through the task workflow and its canonical Done/Log Now processor.
- Do not expose the same customer-facing action as separate workflows on multiple screens. Shortcuts may navigate to the canonical workflow, but must not create a second recording/status path.
- Keep management actions (assignment, project handover, external sharing, tags, booking administration) distinct from customer-contact work.
- Mobile controls must have comfortable tap targets, avoid cramped horizontal layouts, and prioritize the next useful action.

### Lead Directory changes
- `resources/views/components/lead-work-actions.blade.php` is now a navigation-only component on directory rows/cards. It presents one clear **Work Lead** entry instead of repeating Call/WhatsApp/Share/Done/History actions on every lead row. Terminal leads use **Open**.
- Directory Work Lead links target `/leads/{id}#pending-tasks`, taking the user directly to the canonical work area.
- `resources/views/leads/show.blade.php` no longer repeats the large contact-button strip at the top of the lead. The pending-task work surface remains the place to act.
- The pending-task card has the stable `#pending-tasks` anchor for navigation.

### Simple lead date filter
The previous multi-date filter set was simplified to one understandable Lead Date filter based on `leads.created_at`. Existing search/status/agent/tag/label filters continue to combine with it.

Quick choices:
- All time
- Today
- Last 3 days
- Last 7 days
- Last 30 days
- Last 1 year
- Custom

Custom uses one From + To range. The date filter is applied in the same GET form as the other list filters, so users can combine them without losing their current filtered list.

Backend: `app/Http/Controllers/LeadIndexController.php` resolves the quick range into `created_at` bounds for both `/leads` and `/my-leads`. No database migration is required for this UX release.

### Mobile UI
- Added touch-friendly quick-date chips and mobile two-column layout with full-width Custom date fields.
- Added a full-width mobile Work Lead button on directory cards.
- Preserved the existing responsive lead/task layouts and safe-area mobile work bar.

### Verification
- Modified PHP files pass `php -l`.
- Static inspection confirms old Activity/Visit/Booking date controls were removed from the Lead Directory and My Leads UI.
- No live-server files or live-server database dump were requested.
- No database change is required for this release.

### Deployment files for this release
- `AI-Context.md`
- `app/Http/Controllers/LeadIndexController.php`
- `public/assets/css/app.css`
- `resources/views/components/lead-work-actions.blade.php`
- `resources/views/leads/index.blade.php`
- `resources/views/leads/mine.blade.php`
- `resources/views/leads/show.blade.php`

### Next UX audit rule
Before adding a new button, menu item, shortcut or page-level action, check whether the same job already has a canonical location. If it does, route the user to that location instead of creating another workflow. Every screen should have a clear primary job and the mobile layout should make that job obvious within the first viewport.



## Release 2026-09-16 — Canonical Work Navigation / Mobile UX Consistency

- User UX contract strengthened: mobile-first, one canonical workflow per action, no duplicate action paths, clear screen purpose.
- Dashboard and Current Work are now **find/work-queue surfaces**, not execution surfaces. Task cards no longer expose Call/WhatsApp/Share/Done actions there. Each task has one `Open Lead & Work` path.
- Dashboard task navigation opens the Lead page with `focus_work=1`, the exact `focus_followup_id`, a safe `return_to`, and `#pending-tasks`.
- Lead page remains the canonical execution surface: contact action + Done/Log Now are inside the pending-task work area. Timeline is the canonical history surface.
- Removed duplicate task-level Share Lead and History actions from the canonical task work card; lead management actions remain in the secondary Manage Lead panel.
- Lead management controls are now collapsed by default under `Manage lead`, keeping customer work above administrative actions on mobile.
- Removed duplicate header Tags edit action; Tags remain under Manage lead. Booking edit remains in the contextual Booking card rather than duplicated in the management bar.
- Lead completion preserves `return_to`, so after Done the user returns to the same work queue (Dashboard or Current Work) and continues with the next task.
- Lead controller distinguishes `focus_work` from legacy `focus_history`; focused work highlights the exact pending task and scrolls to the canonical work area.
- No DB change required for this UX release.
- Future UX audits must preserve: Dashboard/Current Work = Find, Lead Work Area = Act, Timeline = History, Manage Lead = administration; never duplicate the same action across these surfaces.

## Fix 2026-09-16 — Done/Log Now HTTP 500 (`$skipAutomation`)

- User reported an HTTP 500 immediately after using the canonical completion/recording flow: `Undefined variable $skipAutomation`.
- Root cause: `LeadStatusService::change()` introduced the `$skipAutomation` parameter and correctly used it inside the transaction closure, but the closure's `use (...)` list did not capture `$skipAutomation`.
- Fix: added `$skipAutomation` to the `DB::transaction(function () use (...) { ... })` capture list in `app/Services/LeadStatusService.php`.
- This is a backend-only bug fix. No database migration or SQL change is required.
- Full PHP lint was rerun across the application: 121 PHP files passed with no syntax errors.
- Deployment must include the modified `app/Services/LeadStatusService.php` plus this `AI-Context.md`.
- Do not request live-server files or a live DB dump. Deployment remains cPanel File Manager + phpMyAdmin only.

## Release 2026-09-16 — Automatic Follow-up Timing & Business-Hours Guard

### Purpose
Harden automatic follow-up scheduling so system-generated tasks are always placed in a valid customer-contact window, while explicit manual scheduling remains an intentional override.

### Current automatic scheduling policy
- Default automatic follow-up window: **10:30 AM–8:00 PM**.
- Default working days: **Sunday–Saturday** because real-estate sales/site activity can legitimately happen on weekends.
- The window and working days are now configurable in Settings → General.
- First-contact auto delay is configurable in minutes; default remains **15 minutes**.
- Automatic scheduling normalizes a target time into the next eligible working window/day.
- Manual scheduling (`auto_created=false`) is not clamped by the business-hours guard and remains an explicit user/admin override.
- The `Followup` model also retains a final safety net for every automatically-created record, so direct `Followup::create()` paths cannot bypass the automatic time guard.

### Outcome-driven scheduling
- `SmartFollowupService::scheduleForOutcome()` remains the canonical outcome → next-action engine.
- The configured outcome's next action type and delay are used first.
- If an active lead outcome has no next action configured, the system now falls back to `followup_call` (or the first configured action) after 24 hours rather than leaving an active lead without a next action.
- Final/closed lead logic still prevents inappropriate automatic work after closure.
- Existing real-estate outcome mappings remain configurable from Settings → Call Outcomes; examples include interested, asked details, call later, not answered, busy, site-visit outcomes, negotiation, booking, WhatsApp outcomes, family approval, EMI/possession questions, and site-team/co-agent outcomes.

### Business-hours implementation
- `app/Services/BusinessHoursService.php` is now settings-driven and handles working days plus start/end times.
- `app/Services/SmartFollowupService.php` applies the canonical automatic timing guard and outcome fallback.
- `app/Services/FollowupService.php` continues to route all `autoCreated=true` scheduling through the same guard.
- `app/Models/Followup.php` remains the final model-level protection for automatically-created follow-ups.
- Intake, Meta, paste, contact-promotion, transfer, status-change and outcome-created follow-ups are therefore covered when marked automatic.

### Settings UI
General Settings now exposes:
- First Contact Auto-Follow-up (minutes)
- Automatic Follow-up Start Time
- Automatic Follow-up End Time
- Working days for automatic follow-ups
- Follow-up escalation remains separate from scheduling eligibility.

### Database
Migration:
- `database/migrations/2026_09_16_110000_harden_automatic_followup_business_hours.php`

It ensures these settings exist without overwriting existing values:
- `first_contact_delay_minutes` = 15
- `followup_work_start` = 10:30
- `followup_work_end` = 20:00
- `followup_working_days` = 0,1,2,3,4,5,6

### Important business rule
Automatic follow-up **time eligibility** and outcome **follow-up intent** are separate layers:
1. Outcome decides whether/what follow-up should happen and the configured delay.
2. Business-hours guard decides the earliest valid automatic execution time.
3. Manual scheduling is the explicit override path.
4. No automatic follow-up may be created outside the configured working window/day.

### Validation
- Earlier release note: PHP lint was recorded as **143/143** at that release. The current baseline contains 266 PHP files; the current release validation is recorded below.
- No live-server files or live DB dump requested.
- Deployment remains cPanel File Manager + phpMyAdmin only.

## Release 2026-09-17 — Super Admin Vigilance / Non-Lead Audit Ledger
### Requirement
Create a separate system-level vigilance trail for actions that are not already represented by the canonical lead activity timeline. The purpose is to let Super Admin answer: **who did what, when, from where, and what happened** — especially for downloads, exports, imports, authentication, settings and administrative activity.

### Implemented architecture
- Added `audit_logs` as a dedicated system audit ledger; it is intentionally separate from `activities` because lead history is business history while this is security/administrative vigilance.
- Added `App\Models\AuditLog`.
- Added `App\Services\AuditLogService` with sensitive-field sanitization. Passwords, CSRF tokens, access tokens, API keys, secrets and session/cookie data are never written to the audit details.
- Added `AuditNonLeadActions` web middleware.
  - Excludes canonical lead/customer-facing work routes (`leads`, `activities`, `followups`, task modals, public lead intake/webhook) to avoid duplicate lead history.
  - Records authenticated non-GET mutations, including settings, imports, user administration, attendance and other system changes.
  - Detects download responses via `Content-Disposition: attachment` and records the downloaded filename/content type.
  - Records sensitive read pages such as reports, settings, imports, admin pages and WhatsApp pages.
  - Records failed authenticated requests (HTTP 4xx/5xx) as security events.
- Login success and failed login attempts are explicitly recorded without passwords; failed login records retain the attempted email for vigilance.
- Logout is captured by the middleware before session flush removes the authenticated session.
- Lead CSV and Contact CSV imports explicitly record filename, row totals and imported/skipped/failed counts.
- User role changes explicitly record old role and new role.

### Super Admin visibility
- Added `/admin/audit-logs`, restricted server-side to an active Super Admin.
- Added `Vigilance Audit` to desktop and mobile admin navigation only for Super Admins.
- Audit screen supports filtering by user, category, action text and date range.
- Shows timestamp, actor, role, action, request path/route, HTTP result, details and IP address.
- Newest events appear first and pagination prevents an unbounded page.

### UX / security boundary
- **Lead business history:** remains on the lead timeline and canonical activity processor.
- **System vigilance:** goes into `audit_logs` and is visible only to Super Admin.
- The audit ledger deliberately avoids copying full request payloads so customer data and credentials do not become a second uncontrolled data store.
- Audit recording failures are fail-open for business continuity: an audit failure is logged server-side and must not break the CRM request.

### Database deployment
Migration:
- `database/migrations/2026_09_17_010000_create_audit_logs_table.php`

For the current cPanel/phpMyAdmin-only deployment, the migration can be applied using the matching manual SQL block supplied with the release. No SSH or Artisan is required.

## Release 2026-09-17 — Lead Access Firewall / Visibility / Assignment Hardening
### Requirement
The complete lead-access, visibility, search, customer, status-mutation and assignment path must enforce one consistent scope boundary. Historical participation is read/context only; only active assignment grants agent work access. Delegated Admins must not use permissions alone to reach or mutate leads outside their delegated scope.

### Implemented
- `AccessService::canWorkLead()` now grants agents work access only through an active `lead_agents` relationship. Historical participation remains available only to the controlled `canAddProjectWorkstream()` path.
- Delegated Admin lead mutations now require both the relevant permission and `canManageLead($lead)`.
- Lead assignment/share/unshare paths now enforce delegated Admin lead scope and target-agent scope.
- Lead project reassignment now checks delegated routing scope: a delegated Admin cannot move a lead into a project with no eligible agent inside their delegated agent scope.
- Lead Search now uses `AccessService::visibleAgentIds()` and active `lead_agents` relationships, preventing delegated Admin global-search leakage. Unrestricted Admins retain their existing unrestricted search behavior.
- Customer detail pages now scope enquiries through the same active lead-assignment boundary and deny access when a scoped user has no visible lead for that customer. Unrestricted Admins retain full customer visibility.
- Bulk Lost-reason correction now filters submitted lead IDs through the Lead Index access scope before mutation.
- Automatic assignment now locks the lead row and candidate agent rows while selecting and committing the least-loaded owner, preventing concurrent intake requests from choosing from the same stale load snapshot.
- Legacy `assignLeastLoaded()` now routes through the same serialized assignment path.
- Manual `assignTo()` now locks the lead, target agent and old primary load row during ownership changes and recalculates the actual primary transition inside the transaction.

### Security model
The effective lead firewall now distinguishes:
1. **VIEW** — can the user see the lead?
2. **WORK** — can the user perform operational work?
3. **MANAGE** — can the user administer the lead?
4. **REASSIGN** — can the user change ownership/routing?
5. **HISTORICAL CONTEXT** — prior participation does not restore work rights.

### Validation
- PHP syntax lint passed for **266/266** PHP files in the updated baseline.
- No live-server files or live database information were requested or used.
- No database schema/data change is required for this release.
- Deployment remains cPanel File Manager + phpMyAdmin only.

## Release 2026-09-17 — Payroll Attendance Daily + Monthly Reporting
- Attendance scope remains **payroll-only**. Do not remove or weaken the existing `is_on_payroll` rule.
- The user's intended requirement is to maintain attendance for payroll employees so attendance can be used for salary calculation.
- Added Daily Attendance and Monthly Payroll views under `/attendance`.
- Daily view shows every payroll employee for a selected date, including Present, Absent, Open shift, check-in/out, worked duration, GPS/manual source, and manager actions.
- Monthly view summarizes every payroll employee with calendar days in the reporting period, present days, absent days, open shifts, manual days, attendance percentage, and total worked time.
- Added Daily and Monthly CSV exports for payroll attendance.
- No new salary calculation formula was invented. Weekly offs, approved leave, half-days, deductions, and other payroll rules remain separate until explicitly defined.
- No database schema change is required.

## Release 2026-09-17 — Lead-Sensitive Endpoint Second-Pass Hardening / Usability Guardrails
### Objective
The lead firewall must protect every lead-sensitive endpoint without making legitimate CRM work fail. Authorized users must still be able to open their leads and perform normal operational actions such as logging details/outcomes, follow-ups and site visits. Unauthorized access must not be recoverable through alternate endpoints such as tasks, calls, contacts, search, handover, paste or delete workflows.

### Implemented
- `LeadController::store()` now explicitly requires an authenticated session before creating a CRM lead; public website intake remains on its separate public endpoint.
- `LeadController::recordSiteVisit()` now requires `canWorkLead()` rather than view-only access, so historical participation cannot mutate a lead through site-visit recording.
- `LeadTagController::save()` now requires `canWorkLead()` for tag/label mutation.
- `FollowupController::handoverToAgent()` now constrains delegated Admin destination agents to `AccessService::visibleAgentIds()`, while preserving existing Team Manager team-scope and unrestricted Admin behavior.
- `DeleteLeadController` now checks `canManageLead()` both when previewing a lead and again immediately before permanent deletion, preventing delegated Admin deletion outside scope.
- `PasteLeadController` now scopes delegated Admin agent/project choices to the delegated routing scope and uses scoped least-loaded assignment for automatic routing; unrestricted Admin behavior remains unchanged.
- `SearchController` customer search now uses active `lead_agents` relationships and the same effective agent scope as lead search, closing a customer-search visibility backdoor.
- `ContactController` no longer exposes a promoted lead relation when the current user can view the contact but cannot view that promoted lead.
- `ContactCallController::recent()` filters returned calls so lead details are only included when the current user can still view that lead.
- `TaskController` now requires current `canWorkLead()` access for the underlying lead when returning operational follow-up tasks, preventing historical participation from becoming task access.

### Usability principle
Authorization is applied according to the actual operation rather than using a blanket deny. Normal active owners/shared workers retain operational work access; management/reassignment/booking operations retain their existing narrower permission boundaries. Historical participation does not restore operational work rights.

### Validation
- PHP syntax lint passed for **266/266** PHP files after this release.
- Static second-pass review covered lead routes, lead mutations, follow-ups, activities, tasks, contacts/calls, customer search/detail, modals, assignment/handover, deletion, paste/intake and exports.
- No live-server files or live database information were requested or used.
- No database schema/data change is required for this release.
- A matching rollback ZIP is produced from the exact pre-release baseline versions of every modified file.

## Release 2026-09-17 — Delegated Admin Search SQL Ambiguity Hotfix

- Production error reported on `GET /search?q=kashi` for Delegated Admin.
- Root cause: `SearchController::searchCustomers()` joined `lead_agents` to `leads`, but used unqualified `whereIn('agent_id', ...)` and `where('is_active', ...)`. MySQL correctly rejected the query because both joined tables expose `agent_id`/the relevant column namespace, making the unqualified predicate ambiguous.
- Fixed by qualifying both predicates as `lead_agents.agent_id` and `lead_agents.is_active`.
- No permission model was relaxed; the same delegated-admin active-assignment firewall remains in force.
- No database schema/data change.
- Validation: PHP lint passed for all 266 PHP files.
- Deployment package: `Delegated_Admin_Search_SQL_Ambiguity_Hotfix_2026-09-17.zip`.
- Immediate rollback package: `Delegated_Admin_Search_SQL_Ambiguity_Hotfix_ROLLBACK_2026-09-17.zip`.
- Full baseline updated after this hotfix: `NPO_CRM_Baseline_Updated_2026-09-17_v3.zip`.

## Release 2026-09-17 — Delegated Admin Search SQL Ambiguity Second-Pass Correction
- Production error reported on `GET /search?q=kashi`: MySQL `1052 Column 'agent_id' in WHERE is ambiguous` at `SearchController.php:120`.
- Root cause: the deployed SearchController still contained an unqualified `LeadAgent::whereIn('agent_id', ...)` / `where('is_active', ...)` query in the customer-search path after joining `leads`. The same unqualified predicates also existed in the lead-search path and are now both qualified.
- Corrected both occurrences to `lead_agents.agent_id` and `lead_agents.is_active`.
- This release contains only SearchController plus this context update; no database changes.
- PHP syntax validation passed for the modified controller.
- If production still reports the exact old SQL after overwrite, the server is executing an older cached/not-overwritten SearchController. In that case verify the uploaded file contents at `app/Http/Controllers/SearchController.php` and restart/clear the PHP OPcache through the hosting control panel or PHP-FPM restart mechanism. Do not expose a public OPcache-clearing endpoint.

## Release 2026-09-17 — Delegated Admin Search Production Recheck / v3 Packaging
- Production continues to report the exact pre-fix SQL at `SearchController.php:115-120`: `where agent_id in (...) and is_active = 1` after joining `lead_agents` to `leads`.
- The current baseline `app/Http/Controllers/SearchController.php` has BOTH relevant LeadAgent queries qualified as `lead_agents.agent_id` and `lead_agents.is_active` (customer search and lead search).
- Therefore the reported production SQL cannot be generated by the current baseline SearchController source; it indicates that the production application is still executing an older physical SearchController file or stale PHP opcode/cache.
- v3 deployment package contains the current SearchController only, with no DB changes. After overwrite, the physical production file around lines 115-120 MUST show `whereIn('lead_agents.agent_id', $agentIds)` and `where('lead_agents.is_active', true)`.
- If the old SQL persists after confirming the physical file, restart PHP-FPM/LiteSpeed or clear OPcache through the hosting control panel. Do not expose a public OPcache reset endpoint.

## Release 2026-09-17 — Delegated Admin Project Search Visibility Hardening

### Production issue
Delegated Admin global search for `kashi` returned two projects, including a project outside the Delegated Admin's intended restricted project scope. The affected UI section was `Projects (2)` and showed both `Aditri Kashi Nilayam` and `Kashi Nirmal Heights`.

### Root cause
`SearchController::searchProjects()` only applied `Project::visibleTo()` for the normal `agent` role. An `admin` role with an active delegated profile therefore used the unrestricted project search query and could discover any matching project by name/location/RERA, even though lead/customer search had already been constrained by delegated active-agent scope.

### Fix
`SearchController::searchProjects()` now explicitly scopes delegated Admins using the same effective delegated access boundary:
- active `project_agent` routes to `AccessService::visibleAgentIds()`;
- active `project_team` routes to `AccessService::visibleTeamIds()`;
- unrestricted Admin remains unrestricted;
- normal Agent retains `Project::visibleTo()` behavior;
- Team Manager is also constrained to effective agent/team project scope for consistency;
- empty scope returns no projects rather than all projects.

No database changes were made.

### Validation
- `php -l app/Http/Controllers/SearchController.php` passed.
- Deployment ZIP contains only the modified `app/Http/Controllers/SearchController.php`.
- Rollback ZIP contains the exact pre-fix SearchController.

## Release 2026-09-17 — Delegated Admin Project Search Strict Lead-Scope Correction

### Production feedback
- After the prior delegated-admin project-search route-scope hotfix, the user reported that the same restricted project(s) were still appearing in global search.
- The observed example included a project with `leads 0`, `Direct 1`, `Teams 1`, which demonstrated why the previous route-based project visibility rule was too broad: a project could be routed to an in-scope agent/team while still containing no lead that the delegated Admin was actually allowed to see.

### Root cause of the remaining leakage
- `SearchController::searchProjects()` had been changed to allow any project with an active `project_agent` route to a delegated Admin's visible agents, or an active `project_team` route to a visible team.
- That was insufficient for the user's lead-level restriction model because project routing metadata can exist independently of active lead assignments.
- Therefore a restricted/empty project could still be discovered by name/location even though the user had no accessible lead in that project.

### Correction
- Delegated Admin project search now uses the same active `lead_agents` visibility firewall as Lead Search:
  - resolve `AccessService::visibleAgentIds()`;
  - require an active `lead_agents` relationship for at least one visible agent on a lead whose `project_id` matches the project being searched;
  - if the delegated scope has no visible agents, return zero projects.
- Team Manager and normal Agent project search now use the same active visible-agent lead scope for consistency.
- Unrestricted Admin remains unrestricted.
- Project route presence alone is no longer sufficient for global project discovery by scoped users.
- No database changes.
- PHP syntax validation passed for `SearchController.php`.

### Deployment
- Deployment ZIP: `Delegated_Admin_Project_Search_Strict_Lead_Scope_Hotfix_2026-09-17.zip`
- ZIP contains only `app/Http/Controllers/SearchController.php`.
- Rollback ZIP: `Delegated_Admin_Project_Search_Strict_Lead_Scope_Hotfix_ROLLBACK_2026-09-17.zip`
- Rollback is the exact SearchController version immediately before this strict correction.

### Verification
- Search `kashi` while logged in as the delegated Admin.
- A project with no active lead assigned to any agent in the delegated Admin's visible-agent scope must not appear in `Projects` results, even if it has active project-agent/project-team routes.
- If the exact old result still appears after this ZIP is extracted/overwritten, production is still executing an older/cached `SearchController.php`; verify the physical file and restart/clear PHP OPcache/PHP-FPM/LiteSpeed through the hosting control panel.

## Release 2026-09-17 — Lead View Productivity / Scroll UX v1
- User requested a UI/UX and scrolling productivity pass for the lead detail page, with rollback support.
- Scope intentionally limited to the lead detail Blade view; no controller, service, permission, routing, or database logic changed.
- Root UX issue: the lead work surface used a nested internal scroll (`max-height` + `overflow-y:auto`) while the overall page also scrolled, creating a scroll-inside-scroll experience on desktop and mobile.
- Fix: the lead work wrapper is no longer a sticky/nested scroll container; the browser page is the primary scroll surface.
- Added a compact sticky Lead Command Bar with Back, customer name/phone, Call, Email (when available), and Work jump action.
- Added horizontal section jump navigation for Work, History, Visits, Projects (when useful), Pipeline, and More.
- Added section scroll offsets so sticky header/command UI does not cover the target after smooth scrolling.
- Project jump opens History first because Project Workstreams currently live inside the History accordion.
- Existing accordion behavior and URL `lead_tab` state are preserved.
- Existing task/action, access-control, lead-history, booking, visit, and project-workstream logic is unchanged.
- Mobile keeps the same primary page scroll and gets horizontally scrollable compact section navigation; no second full-page scroll container is introduced.
- PHP syntax validation passed for the modified Blade file.
- No database changes.
- Deployment ZIP contains only `resources/views/leads/show.blade.php` and `AI-Context.md`.
- Rollback ZIP contains the exact pre-release versions of those two files.

## Release 2026-09-18 — Persistent Mobile Navigation + Actionable PWA Notifications v1

### Baseline state used
- Current baseline is the exact pre-Do-Now-button-redesign state restored via rollback SHA `36bd4cf7a3385562467cf9b98720e62e1a75c6b09cf9d9a9f55346d9fc905a86`.
- No live-server files or live-server DB were used. Work was performed only against the supplied CRM source/database baseline.

### Problems addressed
1. Main CRM header/navigation was only sticky on desktop and could scroll away on mobile.
2. Mobile notification panel `Mark all read` could leave badge state visually stale until a manual page refresh.
3. Notification urgency was not visually differentiated in the bell panel.
4. PWA service worker had no active push handler; it only had a future notification-click placeholder.
5. Super Admin notification routing was implicit through `role=admin`; admin notification recipients are now explicitly unioned with active Super Admin authority records.

### Navigation behavior
- `resources/views/partials/header.blade.php` notification bells now expose `aria-haspopup`/`aria-expanded` state.
- `public/assets/css/app.css` makes the header persistently sticky at the top on all screen sizes.
- Mobile header honors `safe-area-inset-*`, reaches the physical top edge, and keeps the notification bell/search/menu available while scrolling.
- Mobile notification panel remains fixed below the persistent header and remains independently scrollable.

### Notification state behavior
- `NotificationController::panel()` now obtains the unread badge count from `NotificationService::unreadCount()` rather than inferring it from the 10-item panel result.
- `markAllRead()` now returns the authoritative post-operation unread count.
- `public/assets/js/app.js` treats the server response as authoritative after Mark all read, refreshes the panel without page reload, and keeps the bell state synchronized.
- Empty state now clearly says the user is caught up rather than showing a generic "No notifications yet" message.
- Push arrival can append `notification_id` to the target URL; the CRM acknowledges that notification as read when the page opens. Reading still does NOT complete the underlying work/task.

### Notification priority model
- `NotificationService::priorityForType()` derives `urgent`, `important`, or `info` from existing notification types without adding a notification-table schema column.
- Urgent notifications receive stronger visual treatment in the bell panel and persistent/re-notifying system notification behavior in the service worker.

### Super Admin routing audit/correction
- The supplied SQL snapshot contains notification rows for Admin user IDs but no notification rows for Super Admin AJ (user ID 19).
- Current notification routing for booking/brokerage events already calls `adminUserIds()`.
- `adminUserIds()` now explicitly unions all `role=admin` IDs with active `super_admins.user_id` records and de-duplicates them. This makes the intended recipient policy explicit and protects against future role/authority representation changes.
- This does not mean every agent event is sent to every Admin/Super Admin; only notification producers that intentionally target admins do so.

### PWA Web Push implementation
New files:
- `app/Models/PushSubscription.php`
- `app/Services/WebPushService.php`
- `app/Console/Commands/SendWebPushNotifications.php`
- `database/migrations/2026_09_18_070000_create_push_subscriptions_table.php`

Modified:
- `app/Http/Controllers/NotificationController.php`
- `app/Services/NotificationService.php`
- `routes/web.php`
- `bootstrap/app.php`
- `public/assets/js/app.js`
- `public/assets/css/app.css`
- `public/service-worker.js`
- `resources/views/partials/header.blade.php`
- `resources/views/partials/notifications-panel.blade.php`
- `AI-Context.md`

Push architecture:
- User explicitly taps `Enable alerts`; browser permission is not requested automatically.
- Current device subscription is stored in `push_subscriptions`.
- VAPID P-256 keys are generated once and stored privately at `storage/app/npo-crm-vapid.json` on the server; the private key is never exposed to the browser.
- The PWA service worker handles `push` events and displays persistent OS notifications, including urgent notifications with `requireInteraction`/`renotify`.
- Notification click opens the relevant CRM URL and carries the notification ID so the CRM marks that notification read after the page loads.
- `notifications:push` sends newly-created notifications to subscribed devices and uses a small burst guard: more than three newly-created notifications are collapsed into a single "new work" push.
- Scheduler runs `notifications:push` every minute. Existing polling remains as a foreground fallback.
- Dead push endpoints returning 404/410 are removed automatically.
- No third-party Composer dependency is required for Web Push delivery; native PHP/OpenSSL is used.

### Database/deployment requirement
- This release adds `push_subscriptions`; the supplied baseline SQL did not contain this table.
- Run the new migration once before enabling push alerts:
  `php artisan migrate --force`
- No existing lead/customer/notification rows are altered by the migration.
- Existing notifications remain the source of truth. Push is an attention channel only.

### Important work semantics
- "Mark all read" means the user has acknowledged the notifications, NOT that the underlying CRM work is complete.
- Completing/rescheduling/handover of the underlying follow-up/lead action remains the actual work state.
- This preserves the CRM's lead-leakage-proof / no-abandoned-work objective.

### Validation
- PHP syntax lint: 270/270 PHP files pass.
- Node syntax check passed for `public/assets/js/app.js` and `public/service-worker.js`.
- No live DB/server verification was performed.
- Browser/device push delivery should be verified after deployment on at least one Android/Chrome PWA installation and one desktop browser.

### Deployment safety
- Deployment ZIP contains only files changed/added for this release.
- A matching rollback ZIP contains exact pre-release versions of every changed existing file; newly added files are removed by rollback instructions if the migration has been applied.
- Because the migration creates a new table, rollback of the code alone does not remove that table. If rollback is required after the migration has been applied, run `php artisan migrate:rollback --step=1` only if this migration is the latest migration and no later migration has been applied; otherwise use the migration's `down()` operation in a controlled maintenance window.

## Release 2026-09-18 — Actionable Next-Action Handoff Notifications v1

### Goal
- Make the notification system drive actual lead work rather than only report that something happened.
- Preserve existing due-soon reminders and overdue escalation while adding an immediate handoff notification whenever a pending follow-up/work item is created.

### Implementation
Modified:
- `app/Models/Followup.php`
- `app/Services/NotificationService.php`
- `AI-Context.md`

`Followup` model:
- Added a `created` model event covering every current `Followup::create()` path, including intake, Meta leads, pasted leads, contact promotion, status-driven followups, Smart Followups, and manual scheduling.
- The event only acts for `pending` followups with an assigned agent.
- Notification dispatch is registered with `DB::afterCommit()`, so a follow-up created inside a transaction cannot leave a phantom notification if that transaction rolls back.
- Existing automatic business-hours scheduling in the `creating` event is unchanged.

`NotificationService`:
- Added `notifyFollowupAssigned()`.
- Sends the responsible agent `🎯 New task assigned to you` with lead, action, scheduled time, and project context where available.
- Uses `followup_assigned_urgent` when the task is due within 30 minutes; otherwise uses `followup_assigned`.
- Added these notification types to the existing priority map: `followup_assigned_urgent` = urgent; `followup_assigned` = important.
- Existing `followup_due_soon` and `followup_escalated` notifications remain in place as subsequent reminder/escalation stages.

### Intended work flow
1. Lead is assigned/routed.
2. A pending follow-up is created.
3. Agent immediately receives an actionable task notification.
4. About 30 minutes before due time, existing due-soon reminder can fire.
5. More than 2 hours overdue, existing escalation notifies the agent and team manager.
6. Completing the underlying follow-up remains the actual work completion; marking a notification read does not complete the task.

### Safety/anti-noise design
- No new database column is required.
- Notifications are generated once per follow-up creation event, not every minute.
- The existing notification push command remains responsible for delivery to subscribed devices.
- Long-range tasks are `important`; tasks due within 30 minutes are `urgent` and use the existing PWA persistent/renotify behavior.
- No existing lead/customer/notification data is modified.

### Current production scheduler state
- User manually created the new Hostinger hPanel cron, every minute:
  `/usr/bin/php /home/u748336076/domains/propertypoint.online/public_html/npocrm/artisan notifications:push >> /dev/null 2>&1`
- Existing queue cron remains unchanged:
  `/usr/bin/php /home/u748336076/domains/propertypoint.online/public_html/npocrm/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1`
- Background PWA push delivery verification remains pending and will be tested later. It must not be considered production-verified yet.

### Validation
- Full PHP syntax lint: 270/270 PHP files pass after this release.
- No database schema change.
- No live server/DB was accessed.

## Release 2026-09-18 — My Leads Actionable Stats / Mobile UX v1

### Goal
- Make every meaningful number on My Leads actionable rather than informational-only.
- Improve mobile-first workflow so users can tap a summary metric and immediately reach the relevant work.

### UI changes
- `resources/views/leads/mine.blade.php`
  - My Leads summary metrics are now tappable links:
    - My Leads → `/my-leads`
    - Active → `/my-leads?preset=active`
    - Booked → `/my-leads?status=booking`
    - Pending Tasks → `/tasks`
    - Overdue → `/tasks?bucket=overdue`
  - Added visible keyboard/focus affordances through `.my-leads-stat-link`.
  - Corrected the header description: My Leads represents leads currently assigned/shared with the user; historical participation is not implied by this page.

- `public/assets/css/app.css`
  - Added mobile/desktop-friendly clickable stat-card styling and focus-visible treatment.
  - Cards retain the existing visual hierarchy while clearly behaving as links.

### Task navigation
- `app/Http/Controllers/TaskController.php`
  - Added optional `?bucket=` filtering for `overdue`, `today`, `tomorrow`, `this_week`, `later`, and `reactivation`.
  - My Leads Overdue now opens the Tasks page directly on the overdue bucket instead of merely opening an unfiltered task list.
  - Existing task access scoping through `AccessService::canWorkLead()` remains unchanged.

### My Leads data correctness
- `app/Http/Controllers/LeadIndexController.php`
  - Pending/overdue task summary counts are now restricted to follow-ups whose `lead_id` is in the user's current active lead-assignment scope.
  - Prevents stale task counts from appearing after the user's active lead relationship changes.

### Scope and safety
- No database schema change.
- No new permissions or bypasses.
- No change to lead visibility/work access rules.
- No live server/DB access used.

### Validation
- PHP syntax lint passes for all modified PHP files.
- Existing deployment remains compatible with the previously configured notification and queue cron jobs.

## Release 2026-09-18 — My Leads Lead Desk / Mobile Experience v2

### Why v1 was replaced
- The previous My Leads page mixed lead navigation with task metrics (Pending Tasks / Overdue), duplicated part of the My Work experience, and presented summary cards without making the page's purpose obvious.
- A lead directory should help a user **find a customer and enter the lead record**. Daily execution belongs in My Work / Tasks.
- The mobile experience therefore now follows a simple information architecture: **Find → Choose view → Open lead**.

### New My Leads experience
- `resources/views/leads/mine.blade.php` was redesigned as a focused **Lead Desk**.
- The page no longer repeats Pending Tasks / Overdue metrics. Those remain on My Work / Tasks where the user actually executes work.
- The top of the page explains the purpose in one sentence: find a lead, open it, work it.
- The only primary lead views are:
  - Working — active leads that need ongoing attention.
  - Booked — booked leads.
  - All — the complete current lead book, including terminal/closed leads.
- Each view is a real navigation target and includes `#lead-results`, so tapping the number/tab lands the user directly on the filtered results rather than leaving them at the top of the page.
- Result heading changes with the selected view so the user can immediately understand what they are looking at.
- Search is now the primary interaction: customer name, phone or email.
- Filters and sorting are deliberately secondary inside a collapsible section instead of occupying the first screen.
- Mobile lead cards were simplified to show only the decision-making information:
  - customer name
  - current status
  - project
  - next pending action/time, or last activity when no next action exists
  - one primary `Open lead` action
  - optional Call / WhatsApp quick actions
- Desktop remains a denser directory/table for larger-screen work.
- Empty states now explain what to do next rather than implying there are no historical leads.

### Routing / controller changes
- `app/Http/Controllers/LeadIndexController.php`
  - Added the `view` parameter with supported values `working`, `booked`, and `all`.
  - `working` maps to the existing active-lead behavior.
  - `booked` maps to `status=booking`.
  - `all` explicitly includes terminal/closed leads.
  - Existing direct `preset` / `status` compatibility remains available for older links.

### Product principle established
- **My Work = execute today's work.**
- **My Leads = find/manage the lead book.**
- **Lead page = perform the actual lead work.**
- Avoid duplicating tasks, reminders, and management dashboards across multiple screens. Every screen should have one clear job.

### Scope / safety
- No database schema or data changes.
- No new permissions or access paths.
- No live server/DB was accessed; implementation used only the supplied CRM baseline.

### Validation
- Full PHP syntax lint: 270/270 PHP files pass.
- `node --check public/assets/js/app.js` passes.
- `node --check public/service-worker.js` passes.
- Blade runtime compilation could not be executed locally because the supplied baseline does not contain an Artisan/vendor installation; Blade syntax was reviewed statically.

### Deployment
- Deployment ZIP contains only the four files modified for this release:
  - `resources/views/leads/mine.blade.php`
  - `app/Http/Controllers/LeadIndexController.php`
  - `public/assets/css/app.css`
  - `AI-Context.md`
- This release follows the standing rule that the full updated baseline is also produced after every live deployment package.

## 2026-09-18 — Tasks / My Work Execution UX + Navigation Hardening v1
- Reframed `/tasks` as an execution surface rather than a dump of every pending task.
- Three primary task views: `Do now` (overdue + today), `Coming up` (tomorrow + this week + later), and `Nurture` (future reactivation).
- Counts are actionable navigation tabs; no decorative task metrics.
- Mobile-first copy explains what to do without requiring users to interpret buckets.
- Existing task cards remain the actual work entry point (`Open Lead & Work`).
- `/tasks?bucket=...` deep links remain supported for notification/My Leads navigation.
- Added smart Back behavior for the Tasks page: browser history is used when the user arrived from another CRM page; dashboard is only the safe direct-entry fallback.
- Fixed mobile hamburger navigation: it now toggles open/closed on repeated taps and updates ARIA state/label.
- No database/schema changes.

## Lead Workbench / Open Lead & Work Experience v2 — 2026-09-18

### User product direction
The user explicitly established a standing product principle: CRM screens must be designed around the user's job-to-be-done, not around displaying every available data point. The CRM should feel like a place people love to work, with minimal confusion and minimal time spent figuring out what is what.

For lead detail specifically:
- My Work = execute today's work.
- My Leads = find/manage the lead book.
- Lead Workbench = successfully work one customer from next action through completion.
- Every page should have one clear job.
- Numbers/statuses should be actionable where appropriate, not decorative.
- Navigation must remember where the user came from; Back should return through actual browser history or a safe internal `return_to` fallback, not blindly redirect to Dashboard.
- Actions must be complete and usable on mobile as well as desktop.
- Permission checks must be aligned with the actual action so legitimate users do not encounter unexpected 403s.

### Lead Workbench v2 changes
Rebuilt `resources/views/leads/show.blade.php` around an action-first mobile-first workbench while retaining existing lead-management/business workflows and lower-detail sections.

New experience:
- Persistent compact lead top bar with Back, lead identity, project/status context, and a More action menu.
- Lead hero with customer identity, phone, status/tag, and direct Call / WhatsApp / Email actions.
- Explicit primary-work state: `Do this now`, `Next action scheduled`, `Lead is closed`, or `No next action`.
- Due tasks are the primary work surface; future tasks remain visible as the plan.
- Primary action jumps directly to the task when work is due.
- No-task state prompts the user toward a next step rather than leaving an empty page.
- Compact context cards show project, owner, budget and last activity.
- Secondary information is organized behind History / Visits / Projects / Pipeline / More rather than competing with the primary work.
- Existing booking, site-visit, project-workstream, history and pipeline logic is retained.
- Existing management actions are moved out of the main work surface and into the More/details area so agents are not overwhelmed by administrative controls.

### Navigation hardening
`LeadController::show()` now sanitizes `return_to` to relative internal CRM paths only and passes the safe value to the view. This prevents open redirects while preserving user navigation context.

The lead workbench Back control:
- uses browser history when available, preserving the actual path the user came from;
- falls back to the safe internal `return_to` path;
- falls back to My Leads only when no navigation context exists.

Related lead links carry the current request URI as a return context so moving between cross-project leads remains reversible.

### Follow-up scheduling navigation
`resources/views/modals/schedule-followup.blade.php` now preserves `return_to` in the form.
`app/Http/Controllers/FollowupController.php::store()` validates the return path as an internal relative path and redirects back to it after scheduling instead of always sending the user to `/`.
This removes a major navigation discontinuity when scheduling directly from a lead.

### Mobile hamburger
`public/assets/js/app.js` now visibly tracks drawer open state with `is-open`, while preserving the existing true toggle behavior. `public/assets/css/app.css` animates the hamburger into a close/X state when open and restores the hamburger when closed.

Existing drawer behavior remains:
- tap once = open;
- tap again = close;
- close button/backdrop/Escape = close;
- navigation links close the drawer.

### Files changed in this release
- `resources/views/leads/show.blade.php`
- `app/Http/Controllers/LeadController.php`
- `app/Http/Controllers/FollowupController.php`
- `resources/views/modals/schedule-followup.blade.php`
- `public/assets/css/app.css`
- `public/assets/js/app.js`
- `AI-Context.md`

### Validation
- No database schema changes.
- No live server or live database accessed; supplied baseline/source only.
- `resources/views/leads/show.blade.php` PHP syntax check passed.
- `app/Http/Controllers/LeadController.php` PHP syntax check passed.
- `app/Http/Controllers/FollowupController.php` PHP syntax check passed.
- Full PHP lint and JS syntax validation must be repeated before deployment packaging.

### Deployment principle
Before changing another CRM page, first identify:
1. why the user opens the page;
2. the one primary job the page must accomplish;
3. what must be immediately available to complete that job;
4. what belongs behind secondary/advanced controls;
5. what navigation path the user should return through;
6. what each role is legitimately allowed to do;
7. what happens after every save/action, including success, validation failure, permission failure and stale/race conditions.

Never optimize only for visual appearance. The goal is a reliable, action-completing CRM workflow that remains safe for users already working on live leads.

## Lead Workbench v3 — mobile focus and information deduplication (2026-09-18)

User feedback: the secondary tab buttons already work well, but the visible accordion summaries beneath them (History, Projects, Pipeline, More, etc.) duplicate the same navigation and waste scarce mobile space. The Lead Workbench must not repeat the customer/lead name or project name throughout the page when the surrounding context already identifies them.

Changes:
- Secondary accordion `<summary>` labels are visually removed from the Lead Workbench; the existing sticky History/Visits/Projects/Pipeline/More tab row is now the single navigation control for those sections. The underlying `<details>` state/content and deep-link behavior remain intact.
- Removed the duplicate Project summary card because project is already present in the persistent lead context bar.
- Removed the repeated customer name from the hero; the hero now uses a compact CUSTOMER CONTACT label plus phone/contact actions.
- On mobile Lead Workbench task cards, the repeated lead name and project metadata are hidden because the page is already inside that lead and the top bar identifies the project. Task type, timing, owner (where relevant) and action controls remain.
- Added a mobile current-work focus bar that appears when the main Work section scrolls out of view. It shows only the current/next task and its timing, with a direct Done action for due work or Open work for future work. This keeps the task focus continuously available while History/Visits/Projects/Pipeline/More is being reviewed without pinning the entire task card and consuming the screen.
- The mobile focus bar uses IntersectionObserver and is hidden while the main Work section is visible; it slides into view only when the work section is no longer in the user's viewport.
- Existing lead permissions, task completion flow, status handling, booking controls, site visits, project workstreams, history, auditability and navigation behavior are unchanged.
- No database schema/data changes.

Product rule reinforced:
- Context should be stated once and then reused visually.
- Never repeat the same lead/project name merely to fill cards.
- Mobile surfaces must preserve the user's primary task focus even while secondary information is open.
- Secondary navigation should have one control surface, not buttons plus duplicate accordion headings.

## Complete Task Mobile Transaction Form v2 — 2026-09-18
- User explicitly rolled back the previous Complete Task redesign to the original pre-task-redesign files.
- New direction: preserve the existing configured "What did you do?" options, existing Outcome catalogue/filtering, existing Custom next action time, existing Notes field, and all existing conditional booking/visit/lost/WhatsApp fields; only improve mobile usability and declutter.
- Lead details and instructional paragraphs were removed from the completion form because the user arrives after already viewing the lead.
- Outcome uses the existing dynamically configured outcome catalogue in a native mobile `<select>` rather than a custom searchable dropdown. This avoids an in-modal keyboard/search interaction and lets the mobile OS handle the option picker.
- The outcome filtering logic remains the existing activity-type + lead-stage relevance logic, including "Show more/all outcomes" behavior.
- Custom next action time remains the same field/name/value semantics, but is progressively disclosed so optional content does not occupy the initial mobile screen.
- Notes remains the same field/name/placeholder and is intentionally last so the keyboard is only needed at the end of the workflow.
- Conditional visit date/time, WhatsApp send checkbox, Lost Reason, booking and brokerage fields remain available using the existing names and configured values; they are only shown when existing outcome logic requires them.
- The form does not autofocus a text field when opened. Native select/date controls are used so the user does not have to close the keyboard to reach dropdown options.
- Sticky mobile save bar keeps "Done — Log Now" available while the form scrolls.
- Focused fields are kept in view on mobile using visualViewport enhancement without automatically opening the keyboard.
- Existing modal AJAX submission/redirect flow is preserved. The existing return_to hidden field remains intact so completion can return to the originating work queue/context instead of Dashboard.
- Deep audit found FollowupController::completeWithActivity() validated `lost_reason` but not the existing form's `lost_reason_key`; added `lost_reason_key` as nullable string max 80 so the existing controlled Lost Reason selection reaches LeadActivityProcessor. No database change.
- No live server/database accessed. Deployment is based on supplied baseline/rollback files only.

## Task Share → Site WhatsApp Mobile UX v1 — 2026-09-18

### User direction
The user wants existing CRM behavior and configured data preserved exactly. Improvements must reduce mobile friction and clutter rather than invent new business fields, options, outcomes, or workflows. The Share Lead flow is a transaction surface: the user is already inside the lead and should not be shown repeated lead/project details merely for context.

### Site WhatsApp share modal changes
`resources/views/modals/task-share-lead.blade.php` was refined without changing the existing Site WhatsApp data or business meaning:
- Removed repeated lead name, project name, and current-task summary from the initial Share Lead modal.
- Shortened the two share choices to clear action labels: Site WhatsApp and Other project agent, while retaining the existing purpose/behavior.
- Site WhatsApp panel now uses only the existing fields: group_name, message, followup_id, allow_early.
- Group name remains optional and continues to use the existing known-group datalist. The actual WhatsApp group is still chosen in WhatsApp because an entered group name is a CRM record, not a WhatsApp recipient selector.
- Existing prefilled WhatsApp message content is preserved; the textarea is simply smaller and mobile-friendly so it occupies less screen space.
- Removed the large explanatory info/note banner. Replaced it with one compact action hint stating the existing behavior: CRM records the share, completes the current follow-up, and schedules the existing 2-day check-back.
- Primary action remains the existing combined behavior: open WhatsApp and finish the current follow-up. Button label shortened to `Open WhatsApp & Finish`.
- Added mobile-friendly sticky action area, larger touch target, safe-area handling, and field scroll margins.
- No lead/project details were removed from the WhatsApp message itself because those details are the payload the site team needs; they are only no longer repeated as CRM UI chrome.

### Navigation / completion handling
- `public/assets/js/app.js` now treats `task-share-lead` like `complete-task` for modal autofocus: no input is auto-focused when this transaction modal opens, preventing the mobile keyboard from appearing before the user chooses an action.
- Site-share completion continues through the existing `/leads/external-share` transaction and permission checks. The UI still passes the existing followup, group, message, reason_key=follow_up, complete_pending=1 and allow_early values.
- The existing post-share redirect URL returned by the server is captured. When the WhatsApp handoff causes the CRM page to become hidden and then visible again, the page returns to the server-provided lead URL so the completed follow-up state is refreshed instead of leaving a stale Share Lead modal open.
- No new DB fields or migrations.

### Important preserved behavior
- Existing `ExternalShareController::store()` remains the canonical endpoint used by this Site WhatsApp task-share action.
- Existing permission checks (`canWorkLead`) and early-action gate remain server-side.
- Existing LeadExternalShare record, activity log, follow-up completion, external-shared lifecycle transition, and 2-day `check_site_team` follow-up scheduling remain unchanged.
- The separate cross-project agent handover path remains unchanged.

### Validation
- No live server or live database accessed; supplied baseline/source only.
- No database schema/data changes.
- Full PHP lint and JS syntax validation required before packaging.

### Product rule reinforced
For mobile share/action modals: preserve the exact configured business choices and payload; remove duplicated context; keep only information required to make the action; avoid keyboard autofocus; keep the primary action reachable; and return the user to the workflow they were already in after completion.

## Task Share return-to-work fix — 2026-09-18
- User reported that the Site WhatsApp Share Lead flow had lost the earlier ability to return to the task/work surface after completion; this must not happen.
- The Share Lead button on task cards now passes the current request URI as `data-return-to`, matching the existing Done/Log Now task behavior.
- `task-share-lead.blade.php` carries the existing `return_to` value into the Site WhatsApp POST.
- `ExternalShareController::store()` now validates `return_to`, accepts only safe relative internal paths, and returns JSON with `redirect_url` for AJAX requests. This preserves the existing share transaction and prevents the generic AJAX flow from falling through to an HTML redirect response.
- After Site WhatsApp share completion, the user is returned to the work surface they came from (for example My Work/Tasks), rather than being forced to the lead page. If no safe return context was supplied, the existing lead-page completion fallback remains.
- No new business fields, options, outcomes, or DB changes were introduced.

## New Development Baseline — post-rollback task completion return (2026-09-18)

The user explicitly rolled the CRM back to the Complete Task Mobile Execution v2 rollback (SHA-256 `f183162c4a16ad37c12bc5dde3a5343fb584f0af71905deb4bf1691e300dfd3e`) and also rolled back the WhatsApp Share changes. That exact rolled-back application state is now the new development baseline.

Preserved baseline product state:
- The new Lead Workbench / Lead View remains in place, including the mobile focus/deduplication behavior.
- The existing `Done — Log Now` / `✓ Done` task action and its current completion form remain in place.
- The completion form's existing configured `What did you do?`, Outcome catalogue/filtering, conditional fields, custom next-action time, Notes, booking/visit/lost/WhatsApp fields and existing backend processing must not be fabricated, replaced, or redefined.
- The WhatsApp Share flow is the rolled-back version and is not part of this change.

### Required next workflow
When a user starts from My Work/Tasks, opens a due task's lead, presses the Lead Workbench Done action, completes the existing form, and presses `Complete task`, the CRM must return to the exact Tasks/My Work list URL from which the lead was opened. The originating query parameters (bucket/filter/search/sort/page, etc.) must be preserved. The completed task should therefore disappear/update in the returned work list through the normal server-rendered refresh.

### Implementation in this release
- `resources/views/components/task-card.blade.php` now distinguishes the originating work-list URL from the current lead URL. When a task card is rendered on a lead opened with `return_to`, its Done action carries that original safe return path into the completion modal. When rendered directly on `/tasks`, the current Tasks request remains the return path.
- `resources/views/leads/show.blade.php` now carries the already-sanitized `$returnTo` into the mobile current-work `✓ Done` action as well.
- `public/assets/js/app.js` now normalizes `data-return-to` (`dataset.returnTo`) to the server field `return_to` when opening only the Complete Task modal. This was necessary because simply adding `data-return-to` to the Lead Workbench button does not make the modal GET receive a `return_to` query parameter automatically. The normalization is intentionally scoped to `complete-task` so the rolled-back WhatsApp Share flow is not changed.
- Existing `FollowupController::completeWithActivity()` return-to validation and JSON `redirect_url` behavior are preserved; no completion business logic was changed.
- Existing modal AJAX submission/redirect handling is preserved; no new form fields or outcomes were added.
- No database schema/data changes.

### Deployment principle for this baseline
For task completion opened from My Work/Tasks, navigation is part of transaction correctness: complete the existing form exactly as configured, then return to the exact originating work queue so the user can continue with the next task.


## 2026-09-18 — Unified Work Return Context for Done + Share Lead v1

The rolled-back Complete Task Mobile Execution v2 state (baseline v20) is the development baseline for this release. Do NOT deploy the earlier Return-to-Work v2 patch that was built on top of it; this release supersedes that attempt.

Product rule: when a user opens a lead from a Tasks/My Work queue to perform the current task, every completion-style action from that work context must return to the same originating work surface. Done and Share Lead must not have different navigation behavior.

Implementation:
- `public/assets/js/app.js` now canonicalizes `data-return-to` / `dataset.returnTo` into the server field `return_to` for ALL modals. This is critical because the browser dataset casing otherwise sent `returnTo`, which the Blade modal did not read.
- `resources/views/components/task-card.blade.php` accepts an explicit `returnTo` prop. Lead Workbench task cards receive the original Tasks URL; task-list cards use the current Tasks request. Both Done and Share Lead carry that same return context.
- `resources/views/components/work-actions.blade.php` accepts `returnTo` and applies it identically to Share Lead and Done.
- `resources/views/leads/show.blade.php` passes the originating return context into task cards and restores the completion end-state card: “Work completed”, next follow-up information when present, and a “Back to Work” link.
- `resources/views/modals/task-share-lead.blade.php` carries `return_to` into both Site WhatsApp and Other Project Agent handover forms.
- `app/Http/Controllers/FollowupController.php` now accepts a safe relative `return_to` for cross-project handover and returns to that originating work surface after successful handover.
- `app/Http/Controllers/ExternalShareController.php` already supports safe `return_to` for Site WhatsApp/external-share completion; no new backend behavior was required there.
- `resources/views/modals/task-share-lead.blade.php` also passes the actual lead WhatsApp number into the existing WhatsApp identity chooser; the chooser was intentionally restored to receive the recipient instead of an empty phone number.

Expected flow:
Tasks/My Work → Open Lead & Work → Lead Workbench → Done → Complete existing form → Complete → same Tasks/My Work URL.
Tasks/My Work → Open Lead & Work → Lead Workbench → Share Lead → Site WhatsApp OR Other Project Agent → completion → same Tasks/My Work URL.

The existing task form/options, Share Lead data/workflow, permissions, follow-up processing, activity history, and database schema are not redesigned by this release.

## 2026-09-18 — Unified Work Return CTA Visibility v1
- Built from the deployed v22 baseline after Unified Done + Share Lead Work Return v1 was confirmed working.
- User reported that the post-completion `← Back to My Work` action was technically present but visually too subtle on both mobile and desktop.
- Changed `resources/views/leads/show.blade.php` completion CTA to a dedicated `lead-wb-return-work` action with explicit `← Back to My Work` wording and stable id `lead-wb-return-work`.
- Added strong primary-button styling in `public/assets/css/app.css`: larger touch target, visible border/halo, hover state, keyboard focus ring, full-width mobile treatment, and completion-card spacing.
- Added a small client-side focus step in `public/assets/js/app.js` so when the completion state is rendered, keyboard focus is placed on the return-to-work action without unexpectedly scrolling the page.
- Business logic, completion form, return URL handling, Share Lead behavior, WhatsApp behavior, permissions, and database schema are unchanged.
- Canonical UX rule: after completing work from My Work, the return action must be visually unmistakable and immediately available on both desktop and mobile.

## Mobile Hamburger User Name Reliability — v1 — 2026-09-18

### Issue
After the persistent mobile navigation changes, the mobile hamburger drawer showed a blank user/agent name for users even though the name had previously appeared.

### Root cause
`resources/views/partials/header.blade.php` relied only on the legacy `session('user_name')` value for the mobile drawer and desktop user menu. The authenticated `session('user_id')` remained the canonical identity used throughout the CRM, so a missing/stale `user_name` session value could render a blank name.

### Fix
The header now resolves the current user from `session('user_id')` using `App\\Models\\User::find()` and uses the session name when present, otherwise the authenticated user's database `name`. The role similarly falls back to the authenticated user's role.

No database schema/data change. No lead permissions or business workflow change.

### Canonical UX
Mobile hamburger → drawer header must always show the logged-in user's/agent's name and role. Do not remove this fallback in future header/navigation changes.


## 2026-09-18 — Site WhatsApp External-Team Recipient Correction
- Current baseline: v25 (mobile hamburger user identity visibility fix).
- Confirmed issue: Share Lead → Site WhatsApp was passing the lead/client phone number into `window.NpoWhatsApp.choose(...)`, causing WhatsApp to open addressed to the client.
- Corrected only the recipient argument in `resources/views/modals/task-share-lead.blade.php` to an empty string. The existing `WhatsAppAccountController::open()` explicitly treats an empty recipient as opening WhatsApp's own contact/group picker, while preserving the prepared message.
- No other Share Lead logic, completion behavior, return-to-work behavior, WhatsApp account chooser logic, permissions, database schema, or lead data was changed.
- Deployment artifact: `NPO_CRM_Site_WhatsApp_External_Team_Recipient_Fix_v1_2026-09-18.zip` (SHA-256 `a1f70ca04b00402aeedb7ff6926d8aebebea919d846de67425d66d94335f41eb`).
- Rollback artifact: `NPO_CRM_Site_WhatsApp_External_Team_Recipient_Fix_v1_ROLLBACK_2026-09-18.zip` (SHA-256 `ea9c88c8de59e544bfe6d04ef07a6634f546e9c7da6bb45b9376bd3ce6d3e6ac`).
- This is a targeted bug fix only; do not expand it into recipient/team configuration changes unless explicitly requested.


### 2026-09-18 — Site WhatsApp External-Team Launch Fix
- Current v26 baseline confirmed the Site WhatsApp action passes an empty recipient intentionally; the existing WhatsApp chooser was then incorrectly parsing `https://api.whatsapp.com/send` as if `send` were the recipient when constructing Android `intent://send?phone=...`.
- This produced `phone=send` and also crossed an asynchronous `fetch()` before launching the Android intent, causing the browser `user gesture is required` error.
- Fixed only the shared WhatsApp chooser launch logic: when recipient is empty (Site WhatsApp), Android now constructs a package-specific `intent://send?text=...` with NO phone parameter and launches it directly from the account-choice click; non-Android uses the existing `https://api.whatsapp.com/send?text=...` picker/share URL.
- Direct lead/client WhatsApp actions retain the existing server-authorized recipient flow.
- No Share Lead business workflow, completion behavior, permissions, database schema, or WhatsApp account data was changed.

## 2026-09-18 — Site WhatsApp Android/Web Launch Fallback Hotfix v3
- Site WhatsApp must never pass the lead/client phone as recipient; it intentionally opens WhatsApp without a recipient so the user can choose the external site-team/group.
- Android package-specific `intent://` launch remains tied directly to the WhatsApp identity-selection click.
- Added a guarded 1.2s fallback to the web WhatsApp picker if the selected package is not registered/installed; fallback is cancelled when the page becomes hidden or unloads so a successful native app launch is not overwritten when returning.
- This hotfix addresses browser errors such as `Failed to launch 'intent://send?...' because the scheme does not have a registered handler.`
- No lead/task/share business logic or database schema changed.


## 2026-09-18 — Site WhatsApp Return-to-Lead / Work-Completed UX v4
- Site WhatsApp continues to use an empty recipient and never sends the client number as a recipient.
- Account selection opens WhatsApp in a separate browser window/tab from the selection click, preserving the CRM page underneath; Android native intent is attempted from the user gesture with web fallback.
- After the server records the Site WhatsApp share and completes the current follow-up, the CRM returns to the lead completion state (`Work completed`) rather than relying on browser back/visibility-change behavior.
- A dedicated `completion_url` is returned by `ExternalShareController` for task-based Site WhatsApp completion; the existing `redirect_url` remains unchanged for other callers.
- This release changes only Site WhatsApp navigation/completion presentation; existing task/share business rules and direct client WhatsApp actions remain unchanged.

## Site WhatsApp Return / Work Completion Hotfix v5 — 2026-09-18
- v4 regression: Site WhatsApp stopped opening reliably because the launcher used an intent:// handoff and browser/app handler behavior varied by desktop/mobile.
- v5 restores the simple reliable flow: Site WhatsApp opens `https://api.whatsapp.com/send?text=...` in a separate tab/window from the account-selection click. No recipient phone is supplied.
- The CRM tab is never replaced by the WhatsApp page. The share is committed first, then the CRM redirects independently to the completion URL.
- Direct client WhatsApp actions remain unchanged and continue through the existing server-authorized `/my-whatsapp/open` path.
- Lead Workbench completion focus: `resources/views/leads/show.blade.php` now gives the `Work completed` card id `lead-wb-completion`, makes it focusable, and scrolls/focuses it after the completion context loads. This makes the just-completed work confirmation the immediate visual/keyboard focus.
- v5 patch files: `resources/views/partials/header.blade.php`, `resources/views/modals/task-share-lead.blade.php`, `resources/views/leads/show.blade.php`, `AI-Context.md`.
- No database/schema change. No change to follow-up catalogue, outcomes, task completion rules, permissions, or client WhatsApp behavior.

## Timeline Intelligence — Super Admin Test v1 (2026-09-18)
- Purpose: first explainable intelligence layer built from existing CRM timeline data; Super Admin only during testing.
- No external AI provider and no new database tables/migrations. Intelligence is computed from existing `activities`, `followups`, `site_visits`, and lead fields.
- New service: `app/Services/TimelineIntelligenceService.php`.
- New controller: `app/Http/Controllers/TimelineIntelligenceController.php`.
- New Super Admin-only routes:
  - `GET /admin/timeline-intelligence`
  - `GET /admin/timeline-intelligence/lead/{id}`
- New views:
  - `resources/views/admin/timeline-intelligence.blade.php`
  - `resources/views/admin/timeline-intelligence-lead.blade.php`
- Super Admin navigation now includes Timeline Intelligence.
- Lead show includes a small Super Admin-only intelligence card linking to the detailed view.
- Current intelligence outputs are intentionally deterministic/explainable:
  - client facts extracted from documented timeline text (configuration, budget, possession, location, timeline where safely detected);
  - operational signals such as overdue work, no next action, repeated no-answer, recent interest, completed/upcoming site visit and booking stage;
  - next-action guidance that prefers existing pending/overdue tasks and otherwise gives a clearly labelled recommendation.
- Every extracted fact stores the source activity id; signals may also expose source activity ids. The original timeline remains unchanged.
- Important boundary: this first version must not be presented to ordinary users. Access is enforced server-side through `SuperAdminService::requireSuperAdmin()`.
- Future phases can add stronger structured fact extraction, customer-level cross-enquiry understanding, change-over-time detection, confidence, and eventually an optional LLM layer. Any inferred statement should remain visibly distinct from documented CRM facts.


## Active Lead Next-Action Hard Invariant — Transfer/Completion Gap Fix v1 (2026-09-18)
- Critical finding from Super Admin Timeline Intelligence testing: lead #325 showed as active with no pending next action immediately after a cross-project handover created lead #354 and completed the handover task.
- Root cause: `LeadTransferService::createCrossProjectLead()` deliberately keeps the source lead active but directly marked the triggering follow-up done without guaranteeing a replacement next action. The hourly `leads:ensure-next-actions` safety net could leave a temporary gap.
- Fix:
  - `SmartFollowupService::ensureLeadHasNextAction()` is now an idempotent, row-locked hard safety primitive for every non-final lead.
  - Cross-project transfer calls it after completing the source handover follow-up, so the source workstream immediately receives a future automatic next action if none exists.
  - Additional project-interest creation also calls the same primitive because the source workstream remains active.
  - `leads:ensure-next-actions` now runs every 5 minutes with `withoutOverlapping` and no longer waits 30 minutes after lead creation before repairing a missing action.
- Final statuses remain excluded. Existing pending follow-ups are never duplicated.
- No database/schema change.
- This fix is intended to enforce the business invariant: **an active/non-final lead must never be left without an explicit pending next action**, including after transfer, handover, task completion, status changes, and repair paths.


## Timeline Intelligence — Customer Understanding Layer v2 (2026-09-18)
- Built on v30 and remains **Super Admin-only** for testing. No ordinary-user access was added.
- The intelligence detail page is now organized around the intended CRM intelligence model rather than a signal list: **What we know → Lead origin → Requirement history → What changed → What we still don't know → Signals → Evidence timeline**.
- `TimelineIntelligenceService` now returns provenance-aware current facts, requirement history, detected changes, information gaps, and lead-origin context.
- Current facts are explicitly labelled as structured lead facts or timeline-derived facts. Structured lead budget, when present, takes precedence over free-text budget extraction.
- Requirement history keeps distinct documented values instead of overwriting earlier values; change detection exposes previous → current values with source activity ids where available.
- Deterministic extraction now also recognizes common intake forms such as `2_bhk` when the surrounding text explicitly labels configuration/property/unit. It does not infer unsupported requirements.
- Information-gap checks currently cover configuration, budget, preferred location, possession requirement, purchase/decision timeline, and post-site-visit feedback when applicable. These are framed as **unknowns**, not negative facts.
- Lead origin exposes existing structured `intake_source`/`source`, `origin_type`, and `origin_note`; no new data is written.
- The detail-page factual evidence timeline is now paginated at 20 activities per page. The intelligence list is paginated at 25 active leads per page instead of loading/limiting an unpaginated list.
- The list view now prioritizes customer understanding, documented changes, information gaps, and next action; signals remain supporting context.
- No database/schema change and no external AI provider. This release is still deterministic and explainable.
- Next planned layer after this is **conversation objective / action intelligence**, built only after validating the customer-understanding output against real leads.

## Timeline Intelligence Customer Understanding v2 Hotfix — Fact History Initialization (2026-09-18)
- Production/runtime failure found immediately after Customer Understanding v2 deployment: `TimelineIntelligenceService::pushFact()` called `array_unshift($history[$key], $fact)` for a structured fact (notably budget) when that history key had not yet been initialized by timeline extraction.
- Symptom: `TypeError: array_unshift(): Argument #1 ($array) must be of type array, null given` at `TimelineIntelligenceService.php:155` on `/admin/timeline-intelligence`.
- Fix: initialize the fact-history bucket with `$history[$key] ??= [];` before either prepend or append. This preserves the intended precedence of structured facts and does not alter extraction rules or business data.
- No database/schema change, no route change, no access-control change, and no change to the underlying timeline.
- Validation: patched `TimelineIntelligenceService.php` passes PHP 8.3 syntax lint locally.

## Lead Show Timeline Render Hotfix v2 — Blade control-flow regression (2026-09-18)
- Production `/leads/325` still failed after the first timeline render hotfix, with a Blade/PHP parse error around the Activity Timeline wrapper: `unexpected token "endforeach", expecting "elseif" or "else" or "endif"`.
- v2 attempted to replace the timeline wrapper with `@forelse ... @empty ... @endforelse`, but production still reported the same class of parse error. Therefore v2 is **not considered validated in production** and must not be treated as a successful fix.
- Root cause is now being isolated by removing Blade control-flow directives from the Activity Timeline rendering section entirely. The next hotfix uses native PHP `foreach`/`if` syntax for that section, avoiding Blade directive parsing ambiguity while preserving the existing timeline output.
- No database/schema, controller, service, route, permission, follow-up, assignment, or intelligence logic is intentionally changed.
- The timeline section still groups activities by logged date, renders the same activity metadata/outcomes/notes, and keeps the same visual markup.
- Empty timelines are handled explicitly by a native PHP conditional so the existing empty-state message remains available.
- Future work starts from this corrected v35 baseline after deployment verification; do not revert to v33/v34 merely because they are earlier snapshots.

## v36 — Lead Show Blade Parse/Rendering Hardening (2026-09-18)
- Fixed the remaining Lead Show Blade control-flow boundary around the optional Site Visits accordion.
- The `$showSiteVisitTab` wrapper now uses native PHP `if/endif` syntax, avoiding Blade directive parser ambiguity in this large mixed-control-flow view.
- Preserved the Super Admin-only Timeline Intelligence card and its route helper exactly as intended.
- Release packages MUST preserve CRM-root relative paths (for example `resources/views/leads/show.blade.php`); never flatten modified files to ZIP root.
- No database/schema/service/controller/permission changes in this release.

## v37 — Timeline Intelligence Lead Show `@php` Directive Hardening (2026-09-18)
- Production diagnosis via SSH + Artisan confirmed Laravel resolves `leads.show` to `resources/views/leads/show.blade.php` and can compile the current source.
- The compiled production Blade view exposed the exact defect: the single-line `@php(...)` directive for Timeline Intelligence compiled as `<?php($timelineIntelligence = ...` without closing the PHP block, causing the following Blade/Markdown-looking markup to be emitted literally in the browser.
- Replaced only that single-line directive with the explicit block form:
  `@php` → assignment with semicolon → `@endphp`.
- Preserved the Super Admin-only restriction, `TimelineIntelligenceService::analyze($lead)` call, displayed fields, and `admin.timeline-intelligence.lead` route unchanged.
- No database/schema, controller, service, permission, route, follow-up, assignment, or business-logic changes.
- This fix is based on v36 and supersedes the previous Lead Show render hotfix for this specific parser defect.
- Deployment ZIP must contain only `resources/views/leads/show.blade.php` and `AI-Context.md`, with CRM-root-relative paths. A rollback ZIP contains the v36 versions of those same files.

## v38 — Timeline Intelligence Customer Journey + Conversation Objective (2026-09-18)
- Extended the Super Admin-only Timeline Intelligence layer from customer understanding/signals into an evidence-backed **Customer Journey** and **Conversation Objective**.
- Customer Journey is deterministic and descriptive. It maps documented CRM evidence through: New enquiry → Contact established → Requirement discovery → Project evaluation → Site visit → Decision / negotiation → Booking.
- Journey position uses existing lead status, activity outcomes/types, structured extracted facts, and site-visit records. It does not predict customer intent or fabricate milestones.
- Conversation Objective is generated from the highest-value documented gap or recent milestone. For attended site visits it prioritizes capturing post-visit decision/feedback; otherwise it prioritizes missing requirement discovery (configuration, budget, location, possession, purchase/decision timeline), with fallback to confirming current requirement and next commitment.
- Suggested questions are generated deterministically from the documented missing field/objective. Questions are presented as prompts for the salesperson, not as customer facts.
- Objective basis and evidence activity IDs are exposed so the intelligence remains explainable.
- No database/schema change, no external AI provider, no new route, and no access-control expansion. Still Super Admin-only for testing.
- Lead Show was not modified in this release, preserving the v37 Blade rendering fix.
- Modified application files: `app/Services/TimelineIntelligenceService.php` and `resources/views/admin/timeline-intelligence-lead.blade.php` plus this context file.
- All 274 PHP files pass syntax lint locally with zero errors.

## v39 — Decision Dependencies + Evidence Hierarchy (2026-09-18)
- Built on the validated v38 baseline and remains **Super Admin-only** for testing.
- Timeline Intelligence now adds a deterministic **Decision Dependencies** layer between customer understanding/journey and conversation execution. Dependencies describe documented gaps, constraints or prerequisites that may need resolution before a next milestone; they are not treated as customer-intent facts.
- Current dependency types include: information gaps for configuration/budget/location/possession/decision timeline; explicit competing-option evidence such as an existing token/booking/finalized alternative; visit-outcome dependency when visit progression is documented without a completed attended visit; post-visit decision capture when a customer-attended visit exists; and funding/financing confirmation when the timeline explicitly mentions funding, loan, finance or self-funding.
- Added an explicit four-level evidence hierarchy:
  1. structured CRM field;
  2. standardized CRM outcome/status;
  3. explicit timeline text;
  4. deterministic inference requiring confirmation.
- Existing extracted facts retain provenance and now expose evidence level/label metadata. Level-4 dependency interpretations are visibly labelled `INFERENCE · REQUIRES CONFIRMATION`.
- Decision dependencies carry source activity ids wherever the dependency is grounded in timeline evidence. No original timeline data is modified.
- The lead intelligence detail page now displays Decision Dependencies and the Evidence Hierarchy before the customer-fact sections.
- No database/schema migration, no new route, no access-control expansion, and no external AI provider.
- No Lead Show changes; the validated v37 Blade fix remains intact.
- All 274 PHP files pass syntax lint locally with zero errors.
- Deployment packages preserve CRM-root-relative paths. Release ZIP contains only the modified TimelineIntelligenceService.php, timeline-intelligence-lead.blade.php and AI-Context.md. Rollback contains the v38 versions of those same files.

## v40 — Recommended Next Action Intelligence (2026-09-18)
- Built on the v39 Decision Dependencies + Evidence Hierarchy baseline and remains **Super Admin-only** for testing.
- Reworked deterministic next-action selection so an explicit pending/overdue CRM follow-up always remains authoritative; intelligence does not create or mutate tasks.
- When no pending task exists, the recommendation now uses the highest-priority documented dependency before falling back to generic contact guidance.
- Priority order is: post-visit decision capture → confirm visit outcome → clarify competing/existing option → confirm funding position → resolve documented information gap → latest failed-contact retry → generic active-lead next step.
- Recommended actions now expose `confidence_level`, `confidence_label`, `basis`, and `evidence_activity_ids`. Level 4 recommendations are explicitly labelled `INFERENCE · REQUIRES CONFIRMATION` and must not be presented as customer facts.
- The existing Next Action card and a dedicated **Recommended Next Action Intelligence** section display the recommendation rationale, evidence basis and source activity ids where available.
- No task is auto-created, no customer data is overwritten, and no database/schema migration is introduced.
- No new route, permission expansion, external AI provider, or Lead Show modification.
- All 274 PHP files must pass syntax lint before release. Release ZIP must contain only the modified TimelineIntelligenceService.php, timeline-intelligence-lead.blade.php and AI-Context.md with CRM-root-relative paths. Rollback contains the v39 versions of those same files.

## v41 — Action Objective + Execution Questions (2026-09-18)
- Built on the validated v40 Recommended Next Action baseline and remains **Super Admin-only** for testing.
- Separated **CRM action control** from **action objective**. Existing overdue/pending follow-ups remain authoritative and are never replaced, rescheduled, duplicated, or auto-created by Timeline Intelligence.
- Added deterministic `action_objective` intelligence that answers what the agent should accomplish while completing the current CRM action, using the strongest documented decision dependency available.
- Objective priority is: post-visit decision → competing/existing option → visit outcome → funding → information gap → generic requirement/commitment confirmation.
- Added deterministic suggested questions tied to the objective. Questions are prompts for the salesperson and never customer facts.
- For competing-project evidence, the objective explicitly focuses on resolving comparison status, deciding criteria, and the condition needed for a decision. This is designed for timelines such as Lead #168 where an overdue follow-up exists but the customer history contains a documented cross-project move.
- Improved Customer Journey display semantics: prior stages are `documented`, the current stage is `in_progress`, and later stages are `not_established`. This avoids presenting the current journey stage as simultaneously both documented and not established. Added a current-stage progress label.
- Existing `conversation_objective` remains available for compatibility; the UI now uses the more operational `action_objective` card.
- No database/schema migration, no new route, no access-control expansion, no external AI provider, and no Lead Show modification.
- No timeline records are modified.
- All 274 PHP files must pass syntax lint before release. Release ZIP contains only the modified TimelineIntelligenceService.php, timeline-intelligence-lead.blade.php and AI-Context.md with CRM-root-relative paths. Rollback contains the v40 versions of those same files.

## v42 — Requirement Maturity + Decision Readiness (2026-09-18)
- Built from the validated v41 baseline.
- Super Admin-only Timeline Intelligence test remains unchanged.
- Added deterministic `requirementMaturity()` to `TimelineIntelligenceService`.
- Each core requirement (configuration, budget, preferred location, possession requirement, purchase/decision timeline) is classified as `confirmed` only when an explicit extracted/structured fact exists; otherwise `unknown`.
- Added deterministic decision-readiness states: early discovery, partially documented, requirements substantially documented, comparison/decision qualification, and final lifecycle state.
- Competing-option evidence is surfaced separately and never converted into customer intent.
- Conversation Objective now avoids generic requirement-discovery wording when core requirements are substantially documented and a comparison/decision path is evidenced; it instead focuses on qualifying the project comparison and decision path.
- Added Requirement Maturity & Decision Readiness UI showing each requirement, value/provenance, confirmed vs unknown counts, and readiness interpretation.
- No DB schema/migration, new route, permission change, external AI provider, or operational task mutation was introduced.
- Existing CRM next-action/task control remains authoritative; intelligence enriches the task and does not replace, reschedule, or create it.
- v41 Lead Show fixes remain untouched.

## v43 — Formal Information Readiness Foundation (2026-09-18)
- Built on the validated v42 baseline and remains **Super Admin-only** for testing.
- Added a formal `information_readiness` result to `TimelineIntelligenceService::analyze()` without adding a database table/column or changing operational CRM records.
- The readiness model explicitly separates four evidence classes: CRM already knows, channel-provided intake, customer information, and agent/CRM events. Project facts and operational events are never treated as customer preferences.
- Added readiness states: `SYSTEM_KNOWN`, `CUSTOMER_PROVIDED`, `CHANNEL_PROVIDED`, `NEEDS_CONFIRMATION`, `UNKNOWN`, `ASKED_NO_RESPONSE`, `AMBIGUOUS`, `CHANGED`, `NOT_APPLICABLE`, plus `CRM_EVENT` for operational events.
- Each readiness item exposes basis text, source type and evidence activity ids, while the declared provenance model is `source`, `source_type`, `captured_at`, `evidence_id`, `confidence`. No schema change is required yet.
- Facebook intake is surfaced as channel-provided, including form/campaign/ad/page/leadgen metadata and non-identity form answers found in the existing Meta capture Activity note. Those answers are not copied into new database fields.
- WhatsApp/WABA readiness recognizes explicit enquiry verification and button/action evidence from the existing timeline. It does not infer intent from a WhatsApp interaction alone.
- Website intake is surfaced from the existing `raw_payload`/structured lead fields. Website `location` is explicitly treated as intake data and is not automatically interpreted as customer preferred location.
- `leads.budget` is handled conservatively: when provenance is not field-level attributable, it is not automatically treated as a verified customer budget. Project facts remain separate from customer facts.
- Added deterministic intelligence gates for Customer understanding, Project evaluation, Decision path, and Post-visit decision. Gates describe prerequisite information only; they do not mutate tasks, statuses, assignments or customer data.
- Added an Information Readiness card to the Super Admin intelligence lead view and a compact readiness summary to the Super Admin intelligence list.
- Existing v42 requirement maturity, decision dependencies, action objective, recommended next action and CRM task authority remain intact. No route, permission, migration, scheduler or operational workflow change was introduced.
- Release package must contain only: `app/Services/TimelineIntelligenceService.php`, `resources/views/admin/timeline-intelligence-lead.blade.php`, `resources/views/admin/timeline-intelligence.blade.php`, and `AI-Context.md`, preserving CRM-root-relative paths. Rollback contains the v42 versions of these same files.

## v43 Hotfix — Information Readiness null evidence argument (2026-09-18)
- Production error: `TimelineIntelligenceService::readinessItem()` received `null` for the sixth argument `$evidenceActivityIds` while rendering the project-location readiness item.
- Root cause: the project-location call passed `null` explicitly as the evidence-id argument in a method whose parameter is correctly typed as `array`.
- Fix: pass an empty array `[]` for project-location evidence. The project-location item remains a system/project fact and still carries its explanatory note that it is not customer preference evidence.
- No change to readiness semantics, database schema, permissions, routes, task behavior, or operational CRM records.
- The service passes PHP 8.3 syntax lint after the fix.

## v47 — Controlled Customer Information + Label Evidence UI (2026-09-18)
- Reworked the optional Customer tab based on the existing Tags & Labels taxonomy rather than duplicating it.
- Existing label groups audited in supplied DB: Property Type, Buyer Type, Payment, Property Status, Special. Customer tab no longer asks separately for configuration, purpose, funding, or property status because those are already represented by controlled labels.
- Customer tab now captures only non-label customer requirements: customer budget range, preferred area, possession requirement, purchase/decision timeline, decision criteria, and concerns/objections.
- Customer tab is optional and hidden behind a Customer tab in the Lead Workbench; History remains the default.
- Removed duplicate Budget summary card from Owner / Last Activity strip.
- Existing label chips are clickable via the existing `lead-labels-row` component and route to `/leads?label_id=...` so agents can find similar leads by label.
- Added controlled customer confirmation action for current labels. It creates a timeline Activity marked `EVIDENCE · CUSTOMER CONFIRMATION` and does not alter labels.
- Label/tag changes now create a controlled timeline Activity marked `EVIDENCE · CRM CLASSIFICATION`, recording added/removed classifications. These classification events intentionally do not update `last_activity_at`, because they are not customer-contact activity.
- Customer-information save uses only controlled select/multi-select options except preferred area, which uses a controlled visible-project-location selector plus a bounded `Other specific area` field. No free-form notes field is provided.
- Customer-information save creates normal timeline evidence marked `EVIDENCE · CUSTOMER CONVERSATION` and does not overwrite project facts or operational `leads.budget`.
- Route `leads.customerInformation` points to `ActivityController::storeCustomerInformation`; no separate LeadInformationController is required.
- No DB schema/migration changes.

## v47 — Controlled Customer Information / Labels Integration (2026-09-18)
- Customer tab remains optional and hidden behind the Lead Workbench tab; History stays default.
- Main Lead Workbench Owner / Last Activity summary no longer repeats the operational lead Budget field.
- Customer tab deliberately excludes fields already represented by existing controlled labels: configuration/property type, buyer type/purpose, payment/funding classification, property status. These remain in Tags & Labels.
- Customer tab captures only non-label requirements: customer-stated budget range, customer preferred area, possession requirement, purchase/decision timeline, decision criteria, concerns/objections.
- Customer entry is controlled: budget range, possession and decision timeline use fixed choices; decision criteria and objections use controlled multi-selects; preferred area uses visible project-location choices plus bounded Other specific area input. No general free-notes field exists in this UI.
- Existing label chips use `lead-labels-row` and are clickable, routing to `/leads?label_id=...` so agents can find similar leads by label. This behavior must remain intact.
- All Tag/Label changes now create a timeline Activity with `action_source=crm_classification` and `EVIDENCE · CRM CLASSIFICATION`. These events do not update `last_activity_at` because classification is not customer-contact activity.
- Customer label confirmation creates a timeline Activity with `action_source=customer_confirmation` and `EVIDENCE · CUSTOMER CONFIRMATION`.
- Customer information entries use the canonical `LeadActivityProcessor` with `action_source=customer_conversation` and are marked `EVIDENCE · CUSTOMER CONVERSATION`.
- `LeadActivityProcessor` now preserves an explicitly supplied `action_source` while defaulting to `manual`, so existing activity behavior remains unchanged.
- `TimelineIntelligenceService::factSourceType()` recognizes customer_conversation, customer_confirmation, crm_classification, Facebook, WABA and automated sources. Customer readiness treats only actual customer/channel evidence as customer-provided; CRM classification stays `NEEDS_CONFIRMATION` until confirmed.
- No DB schema/migration changes.


### v48 Lead Workbench UI cleanup (2026-09-18)
- Preferred area in the Customer tab is now a controlled free-text customer field rather than a dropdown; it supports ranges/sector combinations such as “Nerul to Kharghar” or “Kharghar Sector 1, 2, 4, 8”.
- Customer form remains optional and only contains information not represented by controlled labels: customer budget range, preferred area, possession requirement, purchase/decision timeline, decision criteria, concerns/objections.
- Lead hero no longer shows duplicate Call/WhatsApp action buttons. Contact actions remain available through existing work/task contact controls.
- Lead hero now consolidates customer identity/context: name, phone, project location, current status, owner, and last activity. The separate Owner/Last Activity summary cards were removed.
- Sticky lead topbar is intentionally minimal (back, customer name, project) while the hero owns phone/location/status/owner/last-activity display, avoiding duplicated status/location/contact information.
- Lead section navigation and overflow action panel use high z-index and sticky offsets so they remain above scrolling content.
- Desktop Customer information editor summary now visibly scrolls the opened editor into view; mobile behavior remains unchanged.
- Existing clickable label-to-similar-leads behavior remains the controlled classification/search path.

## v49 Lead actions menu cleanup — 2026-09-18
- Removed the duplicate `🏷️ Tags` action from the three-dot/topbar Lead actions menu. Tags & Labels remain managed from the dedicated controlled Tags & Labels UI, so the topbar menu no longer duplicates that control.
- Replaced the ambiguous `•••` button with a clearly labeled `Lead actions ⋯` control, with an accessible title, so users can understand what the button does before opening it.
- The opened Lead actions menu is no longer sticky. It is positioned as a local absolute popover and is automatically closed on page scroll or resize, preventing it from behaving like a second sticky navigation bar.
- Menu keeps click-outside dismissal and Escape/normal modal behavior unchanged; no DB, route, permission, or business-logic changes.
- v49 is a UI-only incremental release on top of v48.


## v50 Nudge + Escalation Audit UX
- Standardized manager/admin Nudge across Task cards, Dashboard Team Status, and Team Status to use a dedicated Nudge flow.
- Nudge opens WhatsApp in a separate window/tab; CRM remains on the current work surface. The message is generated server-side and includes the lead/customer name plus a direct CRM lead action link.
- Nudge initiation is recorded in the immutable audit ledger as `team_management / nudge_initiated`; UI shows a cumulative nudge count so managers can see that a nudge has already been initiated. CRM explicitly treats this as “opened for sending” rather than falsely claiming WhatsApp delivery.
- Escalation command now records `followup / followup_escalated` with the overdue reason, assigned agent, and manager recipients. Team Status and Dashboard expose escalation history: what task/lead escalated, why, to whom, current task status, and the first subsequent CRM activity.
- Dashboard expandable sections now move the opened content into view so a click does not appear dead.
- No new database table/migration introduced by this release; it uses the existing immutable audit ledger.

## v51 — Team Status opened-list visibility UX
- Team Status agent `<details class="ts-agent">` sections now auto-scroll the opened agent summary into view near the top of the viewport.
- This prevents the expanded task list from opening below the fold and appearing to be a dead click.
- Existing Team Status nudge, escalation history, task links, filters, and selected-agent behavior are unchanged.


## v52 — Business Pulse Pipeline Aging made actionable (2026-09-18)
- Dashboard Business Pulse Pipeline Aging previously displayed `active` and a `stale` count that was actually counting status buckets whose oldest lead was 7+ days old, not stale leads. This was misleading: e.g. `313 active / 3 stale` could mean three stages, with no way to know which leads were stale.
- v52 changes stale semantics to count actual leads that have remained in their current non-final status for 7+ days, using the existing status-change timestamp logic already used by pipeline aging.
- Pipeline Aging now shows a direct `Stale leads · 7+ days in current stage` block with the oldest stale leads (up to 10), each linking directly to the lead and preserving Dashboard return context.
- Each status row also shows its own stale-lead count.
- If there are no stale leads, the expanded section explicitly says so.
- The Business Pulse expandable content remains subject to the Dashboard viewport-focus rule: opening meaningful content must bring it into view rather than leaving it below the fold.
- No database schema/migration changes.
## v53 — Dashboard Who Needs Help direct operational flow (2026-09-18)
- Dashboard Team Status — Who Needs Help agent `View tasks →` now links directly to `/team-status?filter=all&agent_id={agent_id}` rather than opening an intermediate Team Status agent row.
- Team Status recognizes `agent_id` as an operational deep-link: the selected agent's current pending tasks are rendered immediately near the top, before the all-agent investigation list.
- The selected-agent task block has a clear `Current Tasks` heading, direct `Open Lead / Work →` actions, existing nudge/count behavior, and an `← All agents` return link.
- On direct selected-agent entry, the page automatically scrolls the selected current-task block into the viewport while accounting for the sticky header, so the Dashboard click has an immediately visible result.
- When no `agent_id` is supplied, the existing Team Status all-agent expandable rows remain available; their open-row scroll behavior is preserved.
- UX rule reinforced: Dashboard operational links should take management directly to actionable work, not require a second expansion/click to reveal it.

## v54 — Super Admin Work Summary
- Added Super Admin-only operational work summary on Dashboard and `/admin/work-summary`.
- Metrics: new leads received, leads called, leads contacted on WhatsApp, external shares, internal shares, tasks completed, current overdue tasks, tasks due tomorrow, bookings recorded, site visits recorded.
- Today / This week / This month are supported. Historical metrics use CRM records and each non-zero count drills to the underlying lead/task records.
- Definitions are documented in the UI. Overdue/tomorrow are current snapshots; period selection applies to historical work metrics.
- New leads received = distinct primary lead assignments created in the selected period. Calls/WhatsApp/shares = distinct lead activity records of the corresponding type by agent. Tasks completed = followups marked done in the selected period. Bookings = booking status-change activities. Site visits = site_visits recorded for the agent.
- Access is Super Admin only via `SuperAdminService::requireAccess()`.

## v55 — Super Admin Work Reconciliation
- Added a Super Admin-only reconciliation view for an individual agent and Today/Week/Month.
- Added a Super Admin Work tab/link inside the Reports navigation.
- Reconciliation compares each operational work metric with raw CRM history and explains differences, especially distinct-lead reporting vs repeated activity events.
- Reconciliation compares Dashboard active lead scope (`lead_agents.is_active`) against legacy Agent Performance primary-pointer scope (`leads.agent_id`) by total and status, with lead-level drill-downs explaining Dashboard-only vs primary-pointer-only records.
- Read-only; no DB schema changes.

## v57 — Super Admin Work Reconciliation dedicated audit view (2026-09-18)
- Reconciliation is now a dedicated page instead of rendering underneath the main work-summary list.
- `SuperAdminWorkSummaryController::reconciliation()` now returns `admin.super-admin-work-reconciliation`.
- Dedicated reconciliation UI shows Today / This week / This month, report count vs raw CRM count, difference, reason, and a `Show records` drill-down for every metric with a non-zero discrepancy.
- Discrepancy evidence is built from actual existing CRM records: primary lead assignment history (including inactive assignments), Activity rows for calls/WhatsApp/shares, and booking status-change history. Each evidence row links directly to the lead and explains whether it was counted, repeated, or excluded and why.
- Pipeline/ownership reconciliation remains on the dedicated page and retains Dashboard-only / primary-pointer-only lead-level drill-downs.
- Reconciliation remains strictly read-only. No database schema, migration, permission, or CRM workflow changes.
- Existing Super Admin Work summary list and metric drill-down routes remain intact.

## v58 — Reconciliation model import hotfix (2026-09-18)
- Fixed `App\Services\SuperAdminWorkSummaryService` missing `use App\Models\Lead;` import.
- The dedicated reconciliation route previously failed with `Class "App\Services\Lead" not found` when pipeline reconciliation executed `Lead::query()`.
- No behavior, counting definitions, permissions, routes, or database schema changed; this is a syntax/runtime dependency fix only.



## 2026-09-19 — Live production alignment audit

The live-server snapshot was inspected against the prepared v72 Final package. Most of the v72 target files match byte-for-byte. Ten live files contain additional/different production changes, documented above:
- `resources/views/components/task-card.blade.php`
- `routes/web.php`
- `app/Services/WebPushService.php`
- `app/Http/Controllers/TaskController.php`
- `resources/views/reports/index.blade.php`
- `app/Http/Controllers/DeviceLeadImportController.php`
- `app/Http/Controllers/SuperAdminWorkSummaryController.php`
- `resources/views/modals/add-lead.blade.php`
- `app/Http/Controllers/ExternalShareController.php`
- `resources/views/modals/task-share-lead.blade.php`

This means future work must use the **live-aligned production state**, not blindly regenerate a v72 package from the earlier prepared baseline.

### Protected exclusions
- No WhatsApp/Recent Calls automatic lead-creation workflow.
- No unrequested database/schema redesign.
- No regression of mobile navigation z-index.
- No casual redesign of Complete Task / Done → Log Now.
- No treating a prepared package as production unless explicitly confirmed by the user.

## Release 2026-09-20 — Same-Project Lead Duplicate Guard v1
- Contact-to-Lead promotion now preserves the existing project-specific duplicate protection: the same normalized customer phone cannot create a second Lead for the same project.
- Physical Contact duplicate cleanup was completed and verified with zero remaining normalized-phone duplicate groups under the cleanup rule.
- Deployment SHA: `4045be8c85bab0104c713a84a910513183901335bea4076ba89cb3f334802da4`.

## Release 2026-09-21 — Contact → Lead Interested Handoff v1 + Production Hotfixes
- Qualification threshold is `Interested`: a connected Contact call selecting Interested immediately promotes/handoffs the Contact into the Lead workflow.
- Normal agent caller becomes the Lead owner. Dedicated telecaller creates the Lead through project-aware routing, remains a temporary collaborator/supporting task until site visit scheduling, and is released when site-visit scheduling completes.
- Contact workflow ends at Lead creation; the Contact remains as historical/source context and is marked Converted to Lead.
- Production hotfixes corrected ContactLeadHandoffService dependency injection in CallService and removed reliance on a non-existent `followups.notes` column. The live `followups` schema uses status as the completion state.
- Existing known Contact values are reused automatically; known site-visit datetime is not unnecessarily requested again.
- No database schema change was introduced by the handoff implementation.

## Release 2026-09-21 — Team Manager Contact Caller Scope Correction
- Team Managers already use the existing Contact authorization/delegated-access model; no new Contact role was introduced.
- `/contacts/dialer` now uses `AccessService::visibleAgentIds()` for Team Managers, matching the Contact directory's existing effective team scope instead of limiting the queue to only the manager's own Agent ID plus unassigned Contacts.
- Delegated exclusions and team/user scope therefore remain authoritative for the caller queue.
- No database/schema change.

## Release 2026-09-21 — Delegated Contact CSV Import Access v1
- Reused the existing Delegated Access authorization model; no new role or separate access system was introduced.
- Added `contacts.import` to `DelegatedAccessService::PERMISSIONS`, so Super Admin can grant Contact CSV import explicitly from the existing Delegated Access profile editor.
- Added `AccessService::canImportContacts()`: Super Admin/unrestricted Admin remain allowed; delegated Admins and Team Managers require the explicit `contacts.import` permission. Team Managers without a delegated profile do not receive import rights automatically.
- `ContactImportController` now protects index/preview/execute/summary/error-download with the same authorization. Restricted users can only view their own import batches; unrestricted Admin/Super Admin retain existing all-batch visibility.
- Contacts navigation now shows `Import CSV` whenever the current user actually has import permission, rather than checking only for the `admin` role.
- Existing ContactService import behavior, duplicate checks, project resolution, database schema, and Contact assignment behavior were intentionally left unchanged. Imported Contacts continue to follow the existing Contact visibility rules.
- No SQL or migration changes.


## Release 2026-09-21 — Incentive Payroll-Only + Delegated Access v1
- Incentive Control Center is now restricted to payroll employees only: an incentive participant must be a CRM user with `users.is_on_payroll = 1` and an existing Agent record.
- Payroll employee names are fetched from the existing payroll roster; Super Admin/authorized delegated users do not create separate employee records from Incentives. Salary, employee ID, joining date, confirmation date and exit date are maintained from the Incentives view against the existing Agent record.
- Incentive access is no longer Super Admin-only. Super Admin retains unrestricted full access. Delegated Admins and Team Managers may access Incentives only through the existing Delegated Access profile and explicit permissions `incentives.view` and/or `incentives.manage`.
- An active Delegated Access profile supersedes normal role defaults for Incentive access, consistent with the existing Delegated Access authorization model. A delegated profile without the relevant Incentive permission is denied even if the user would otherwise have role-based access.
- Delegated Incentive users are restricted to payroll employees represented by their existing delegated agent scope. Deals, collections, reports, simulations, annual true-ups and exit settlements are filtered/enforced through the same scope.
- Salary and incentive master fields remain editable only from the Incentives view; CRM operational Agent status/role are not editable through Incentives.
- No new database schema or SQL is required for this release. It uses the already-deployed payroll flag, incentive fields/tables, and Delegated Access tables.

## Release 2026-09-21 — Incentive Blade ParseError Hotfix
- Corrected the Incentive Control Center Blade view after the payroll/delegated-access release exposed a Laravel Blade compile error (`unexpected token "endif"`) in `resources/views/admin/incentives/index.blade.php`.
- The agents and deals sections were rewritten with explicit multiline Blade control blocks so nested `@if`, `@else`, `@foreach`, and `@endif` directives are unambiguous to the Laravel Blade compiler.
- No controller, service, authorization, payroll filtering, incentive calculation, database schema, or business-policy behavior changed.
- View-only users retain view-only behavior; manage users retain salary/master, deal, collection, and exit-settlement controls.
- This is a presentation/Blade syntax hotfix only and supersedes the Incentive Payroll-Only + Delegated Access v1 view file.

## Release 2026-09-21 — Incentive Blade canManage render hotfix v2
- Fixed the Incentive view rendering path where `$canManage` was referenced by Agent Master and Deal Master controls but was not exposed as a Blade view variable.
- The view now derives `$canManage` from the existing `AccessService::can('incentives.manage')`, preserving Super Admin and existing Delegated Access authorization semantics without adding a new permission or database change.
- No incentive calculation, payroll scope, workflow, or SQL behavior changed.

## Release 2026-09-21 — Incentive Collection/Payout Reconciliation Hardening v1
- Collection entry now rejects cumulative collections above the deal's effective Settled NB, preventing over-receipt from inflating incentive eligibility.
- Collection-created payout ledger rows now retain the triggering `collection_id` for traceability.
- Added an explicit Super Admin/authorized Manager `Reconcile payouts` action for deals with collections. This is a POST mutation and never runs during a GET page load, so imported/legacy collections can be brought into the immutable payout ledger safely.
- Payout reconciliation now records a recovery-carry entry when a downward collection/NB reconciliation exceeds available held incentive. It never converts the unrecovered amount into salary recovery.
- Existing annual true-up and exit-settlement behavior remains unchanged.
- No database schema change is required; the existing `collection_id` field on `incentive_payouts` is used.

## Release 2026-09-21 — Incentive Mobile Workflow + Guide v1
- Incentive Control Center UI is now mobile-first for the complete incentive workflow: larger touch targets, single-column forms on small screens, horizontally scrollable financial tables, compact navigation, and contextual Next-step guidance on every tab.
- A persistent `📘 How to use` link opens `/incentive-guide.html`, a self-contained mobile-friendly HTML operating guide explaining the Net Brokerage formula, deal fields, collection/reconciliation workflow, mobile use, testing sequence and annual true-up warning.
- Deal Master Add Deal is collapsed behind a clear `＋ Add Deal` disclosure when no deal is being edited, so the Deals table below is immediately visible and users can understand that a second section exists. Editing an existing deal still opens directly.
- Annual true-up remains available server-side for authorized users but is removed from the normal dashboard flow and placed behind an explicit advanced warning disclosure with a stronger confirmation prompt. It remains intentionally a controlled year-end financial operation rather than a normal daily action.
- Contextual Next-step guidance links Dashboard → Statements, Policy → Simulation, Payroll Employees → Deal Master, Deal Master → Statements, Simulation → Policy, and Statements → Guide.
- No database schema change in this release. Existing incentive routes and financial calculations remain authoritative.

## Release 2026-09-21 — Incentive System / Booking Integration Baseline

### Deployment state
- Incentive Super Admin v1, payroll/delegated access patch, Blade/runtime hotfixes, payout reconciliation hardening, and Simulation/Mobile/Guide patch are user-confirmed deployed/live unless a later explicit rollback is stated.
- Latest guide patch user-confirmed deployed on 2026-09-21:
  `NPO_CRM_INCENTIVE_GUIDE_SIMULATION_HOWTO_V2_2026-09-21.zip`
  SHA-256 `23768c4153972ff9abe601101b4d21e80cbe41783aa11e0c47b446c10cd4b642`.
- Current baseline must preserve all live CRM behavior and all deployed incentive behavior. No live code/schema changes are authorized merely by this context update.

### Incentive authoritative model
- Net Brokerage (NB) is the incentive base after developer adjustments, client passbacks, sub-broker shares and referral fees.
- Booked NB is an estimate at deal entry; Settled NB is the final confirmed amount; incentive reconciles to Settled NB.
- Average Monthly NB = total NB / confirmed months in the relevant period.
- Performance Multiple = Avg Monthly NB / Monthly Salary.
- Default threshold = 5x salary.
- Avg Monthly Surplus = Avg Monthly NB - threshold; <=0 produces zero incentive.
- Quarterly Incentive = Avg Monthly Surplus x slab rate x 3.
- Provisional = 75% of quarterly incentive as collections are received; Held = 25% until true-up.
- Annual true-up is every June under the deployed policy.
- Probation default 2 months; probation NB excluded; incentive clock starts after confirmation.
- Exit settlement uses active confirmed months and reconciles provisional/held amounts without salary recovery/legal action.
- NB revisions default to 24 months and are restricted to Manager+ with audit reason; slab-changing revisions require reason.
- Payout reconciliation is deployed and collection over-receipt is blocked.

### Incentive access
- Super Admin: full incentive access.
- Delegated Admin / Team Manager: controlled through existing delegated permissions `incentives.view` / `incentives.manage` and scoped Agent visibility.
- Incentive participants are payroll users with existing Agent records; do not silently create operational Agents.
- Operational Agent status remains read-only from Incentives; payroll salary/employee/joining/confirmation/exit fields are editable there according to authorization.

### Incentive data model currently deployed
- `incentive_deals`: booking/deal financial snapshot including lead_id, agent_id, booking_date, booked_nb, settled_nb, adjustments, passbacks, sub-broker share, referral fee, status, policy snapshot and audit creator.
- `incentive_collections`: collection against a deal with date, amount, notes and creator.
- `incentive_payouts`: payout ledger with deal/collection traceability, period, type, amount and audit fields.
- Existing Deal Master remains required as a controlled manual facility for historical/exceptional recording even after live booking integration.

### Booking integration requirement — NEXT DEVELOPMENT, NOT YET DEPLOYED
The next build is to connect the CRM's existing booking workflow to the deployed incentive system without changing or breaking current live behavior.

Required booking entry modes:
1. Live CRM booking from an existing Lead Workbench lead.
2. Historical/manual booking for business that occurred before CRM adoption or for leads absent from CRM.
3. Bulk import from CSV/XLSX/other sources with preview, validation, duplicate detection, mapping and import-batch traceability.

Historical/manual booking must support:
- Create customer when absent.
- Create project when absent, subject to existing permissions.
- Create historical lead when absent.
- Record original booking date separately from CRM entry/import date.
- Mark source as Historical Entry or Imported rather than pretending it was CRM-originated.
- Edit relevant customer/lead/project/booking information under existing authorization rules.
- Feed the same incentive deal/collection/reconciliation engine as live bookings.

Live booking target flow:
Lead -> Booking Draft -> Submit for Authorization -> Authorized -> Incentive Deal -> Collections -> Payout Reconciliation -> Reports.

Required booking authorization audit:
- entered by/date-time
- submitted by/date-time
- authorized by/date-time
- authorization status
- authorized financial values
- subsequent revisions
- revision reason
- source type/import batch where applicable
- cancellation/backout history

Important separation:
- Booking authorization means the booking is accepted as an authorized business transaction.
- It does NOT mean brokerage has been collected.
- Collections represent cash/receipts actually received.
- Settled NB is the final incentive base.

Required reporting layers:
- Booking report: booking date, entry date, customer, project, unit, agent/team, booking value, booked NB, authorization status, source/import batch.
- Brokerage report: booked NB, settled NB, collected, outstanding, adjustments, passbacks, sub-broker/referral amounts.
- Incentive report: employee, NB, confirmed months, multiple, slab, incentive, provisional, held, recovered, true-up.
- Reconciliation: Booking -> Incentive Deal -> Collections -> Payout Ledger with drill-down to source records.

### Safety rule for booking integration
- Do NOT replace or redesign the current Lead Workbench booking workflow blindly.
- First audit the supplied/live-aligned baseline: existing booking fields, `edit-booking` modal, LeadStatusService booking path, booking authorization/permissions, reports, project/customer/lead creation, import architecture, Agent/User attribution, delegated scope and existing Incentive Deal Master.
- Reuse existing CRM booking concepts where appropriate; do not create a parallel duplicate booking universe.
- Do not deploy booking integration until the patch is prepared, validated, supplied as a minimal deployment ZIP plus rollback, and the user explicitly confirms deployment.
- Never claim the booking integration is live/deployed before explicit user confirmation.
- Preserve all existing mobile navigation, Lead Workbench, Complete Task, notifications, Contact Work, delegated access, project routing, lead access firewall and current incentive functionality.

### Baseline rule
This 2026-09-21 context is the current development baseline. All future CRM patches must start from this live-aligned state and preserve the current production behavior unless the user explicitly requests a behavior change.
