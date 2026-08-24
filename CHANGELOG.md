# Changelog

## [1.2.0] - 2026-08-24

### Added

- Bulk fan-out: a ticket can now be propagated to several destination entities in one action, not just one at a time. Useful for something like a shared vendor outage that affects more than one customer entity at once. Pick several entities in the destination dropdown (now multi-select) and each one gets its own ticket, its own preview, and its own pass/fail result.
- This is deliberately the synchronous, scoped-down version of bulk fan-out, not a queued or scheduled one. Each destination still runs through the same transaction and ledger row a single propagation already used; one destination failing has no effect on the others. A request is capped at 25 destinations at once, enforced on the server regardless of what the UI allows, so one click can't turn into hundreds of tickets by accident. If that cap ever gets in the way in real use, that's the signal an asynchronous, queued version is actually needed, not a reason to just raise the number.
- The preview panel now shows one block per selected destination, each with its own kept/cleared breakdown, instead of a single panel for one entity.
- After submitting, each destination gets its own result line (which entity, success or failure, and a link to the new ticket if it succeeded) instead of one combined message for the whole request.

### Changed

- The destination dropdown is now a multi-select. Selecting just one entity behaves exactly as before.
- The "Destination entity" label is now "Destination entities" to match.
- Retry safety now covers the whole set of destinations picked in one submission, not a single entity: resubmitting the exact same set of destinations within the retry window is treated as one retried attempt (already-succeeded destinations come back as an idempotent replay, only the ones that failed are retried); picking a different set of destinations is treated as a new attempt.

### Closing out from 1.1.0

The 1.1.0 release left "further SLA and business-rule hardening" as an open, loosely defined item. It isn't open. Creating the propagated ticket through GLPI's normal `Ticket::add()`, so the destination entity's own rules and SLA assignment run the way they would for any ticket created there directly, has been the mechanism since 1.1.0. That was the actual goal behind the phrase, and it's done. It won't be carried forward as pending work.

### Known limitations

These are deliberate, not oversights. Here's what's still out, and why:

- Only Tickets are supported, not Problems or Changes. Both share Ticket's underlying object model, so extending to them would be a moderate refactor, not a rewrite. But Problems and Changes make up a small fraction of ITIL traffic next to Tickets in most deployments, and moving one across an entity boundary is rare. Building it before an actual need shows up would mean maintaining code nobody exercises.
- No mapping profiles, a saved rule like "Group X in the source entity always becomes Group Y in this destination." A propagated ticket can already be reassigned by hand in a couple of clicks, which covers how often this actually comes up in practice. Persistent mapping configuration would be the most complex thing on this list for the least benefit unless a real, repeated pattern shows up to justify it.
- Documents, followups, and tasks on the source ticket are still not copied. Documents stay excluded on purpose and permanently: an attachment meant for one customer landing on a different customer's propagated ticket is exactly the kind of leak this plugin exists to prevent. Followups and tasks are technician-authored, not customer-supplied, so the risk is smaller, if bulk fan-out above sees real use, one status update needing to land on every propagated ticket without retyping it each time is the case that would justify revisiting this for followups and tasks specifically, not documents.
- No configuration page. Which rights are needed to see the "Propagate" button is fixed in code rather than exposed in an admin settings screen. Propagation is already a supervisor or admin action, and the cheap way to make it configurable later is registering it as a proper GLPI plugin right so it shows up under Administration > Profiles, not building a settings page for it. A full config page stays off this list until something needs it that a plugin right can't cover.
- The retry protection is time-limited on purpose (about thirty minutes). It's meant to catch accidental double-submits and network timeouts, not to permanently stop someone from deliberately propagating the same ticket to the same entity again later.

## [1.1.0] - 2026-08-23

This is a rewrite of how the plugin moves a ticket between entities. The short version: instead of cloning the ticket row and hoping everything on it still makes sense in the new entity, the plugin now checks each entity-scoped field before deciding whether to keep it.

### Why

The original 1.0.0 clone worked fine as long as both entities shared the same categories, locations, technicians, and groups. Once you tried it across genuinely separate entities, which is the actual use case for an MSP or a multi-entity deployment, it could quietly attach a category or technician from the source entity that had no business being on a ticket in the destination entity. A technician with rights only in the source entity would end up assigned to a ticket they couldn't see or act on. That's the bug this release fixes.

### Added

- A propagation engine (`src/`) that checks category, location, requester, assignee, observer, and assigned group against the destination entity before creating the new ticket, instead of copying them unconditionally.
- The new ticket is created through GLPI's normal `Ticket::add()`, so the destination entity's own business rules and SLA assignment run the way they would for any ticket created there directly. Priority and SLA are no longer copied from the source ticket, for the same reason.
- Linked assets are relinked only if they're actually visible from the destination entity.
- A propagation history table (`glpi_plugin_clone_propagations`) records every attempt: which ticket, which destination entity, whether it succeeded, and why it failed if it didn't.
- The new ticket is linked back to the source ticket using GLPI 11's `CommonITILObject_CommonITILObject` relation, so a propagated ticket can be traced back to where it came from.
- Retrying a request that timed out, or clicking twice by accident, no longer creates a duplicate ticket. The browser and server agree on what counts as "the same attempt" for about thirty minutes, tracked separately per ticket and destination entity.
- A small test suite covering the scenario this whole change exists for: a technician with a recursive profile at a parent entity keeps their assignment after propagation, one whose profile is local to the source entity does not.
- A preview panel in the propagation modal: as soon as you pick a destination entity, it shows what will happen to the category, location, requester, assignee, observer, and group, each one marked kept or cleared along with why. It calls the same preflight check the propagation itself runs, so what you see before clicking Propagate is what actually happens, not a separate guess at the outcome.

### Changed

- The button on the ticket form is now labelled "Propagate to entity" instead of "Clone to another entity", to describe what it actually does. The plugin's internal name and install directory (`clone`) are unchanged, so this doesn't require reinstalling.
- "Cloning in progress..." became "Propagating...".

### Removed

- The plugin no longer copies a ticket's category, location, technician, requester, observer, or assigned group to the destination entity just because the same ID exists there. Each one is checked first, and only kept if it's actually valid in the destination entity.

### Fixed

Running this against a live GLPI 11 instance, not just the test suite, turned up real bugs:

- The assignee check was calling `Profile_User::getUserEntitiesForRight()` with an array of rights (`[UPDATE, Ticket::OWN]`). GLPI's own implementation builds that into `rights & $rights` in SQL, which expects a single value, not a list. MySQL rejected the array with "Operand should contain 1 column(s)", so propagating a ticket with an assignee failed outright. Fixed by combining the rights into one value (`UPDATE | Ticket::OWN`) before passing it in.
- That failure exposed a second, worse problem. The code that checks category, location, and actors ran before the try/catch block meant to handle failures, so when it threw, the propagation attempt never got marked as failed. It just sat there marked "processing" forever, and every retry after that was told the propagation was still in progress even though nothing was running anymore. Moved that check inside the try block so a failure there gets recorded properly and a retry can pick it back up.
- The preview panel's "refresh when you change the destination entity" behaviour just didn't work. The entity dropdown uses Select2, which updates the underlying field through jQuery's own event system, and a plain `addEventListener('change', ...)` never sees that. The preview loaded once for whatever entity was selected by default, then sat there showing stale results no matter what you picked afterward. Fixed by binding through jQuery's `.on('change', ...)` when Select2 is present, the same jQuery-first-then-plain-DOM split already used elsewhere in this file.

### Known limitations

I kept this narrow on purpose. Here's what it doesn't do yet:

- Only Tickets are supported. Problems and Changes aren't handled.
- Only one destination entity at a time. Sending a ticket to several entities at once, useful for an incident affecting more than one customer, isn't built.
- The retry protection is time-limited on purpose (about thirty minutes). It's meant to catch accidental double-submits and network timeouts, not to permanently stop someone from deliberately propagating the same ticket to the same entity again later.
- Documents, followups, and tasks on the source ticket are not copied.
- No configuration page yet. The rights required to see the button, and the fields checked during propagation, are fixed in code.

### Status

I ran this against a live GLPI 11.0.8 instance with a real entity tree rather than trusting the test suite alone. Recursive technician rights, category and location visibility, group visibility, linked asset visibility, the native `Ticket_Ticket` relation, and duplicate-submission handling all held up. Two identical requests with the same idempotency key still only produced one destination ticket.

This was also submitted upstream to the original repository as a pull request. It went unreviewed for several weeks, so this fork is tagging and releasing it independently rather than waiting indefinitely. The pull request stays open in case the original maintainer wants to pick it up later.
