# P07 — Building Group: tasks and defaults

Rules: [ARCHITECTURE.md § 6.8](../../ARCHITECTURE.md) (the Building Group), § 6.10 (feature enablement), § 8 (modules talk through contracts and events); [DATABASE_SCHEMA.md § 6](../../DATABASE_SCHEMA.md); [BILLING_PAYMENTS.md § 7.4](../../BILLING_PAYMENTS.md) (what a management handover moves); AGENT.md § 3 rules 1 (tenancy), 6 (idempotency) and 7 (no data destruction).

## P07-01 — per-building group, membership sync from leases

Started 2026-11-02 at the product owner's request ("Start P07-01"); one task, built by the orchestrator in the new module `Modules\BuildingGroup` (ARCHITECTURE.md § 6.8 "How the groups and their members are built").

### Defaults taken in P07-01 (orchestrator, 2026-11-02; for the product owner to confirm)

1. **Who counts as living in a building: a tenant with an active lease there.** A draft lease (the unit reserved, the contract not signed) does not add the tenant; an ended lease (expired, terminated, removed by an import rollback) removes them. A renewal keeps the tenant (the renewal is active), and so does a second unit in the same building until their last active lease there ends. An active lease that has not started yet (activated early) counts: the tenant has signed. A lease that passed its end date but is kept active because it is marked "auto-renew" counts until it is ended.
2. **Leaving keeps the row.** A tenant who leaves gets `left_at`; one who comes back is a member again on the same row with a new `joined_at`. Nothing is deleted, so the member's history (and, from P07-04, a mute) stays with them, and their posts stay attributed when they have gone.
3. **Only tenants are stored as members in P07-01.** The building's agents, the organization's owners and administrators, and the building's owner reach the group through their permissions, not membership rows; P07-02 (who may post) and P07-04 (who moderates) settle that. `group_members.user_id` stays for that use.
4. **A tenant is a member through their tenant record, portal account or not.** `group_members.tenant_profile_id` is added to the schema; the tenant's account is found through the record when they open the group (P07-02), so a tenant who accepts their portal invitation later needs no change here.
5. **Membership is kept for every organization, whatever its plan.** The plan switch `building_group` (Growth and up) will gate the group's pages in P07-02; keeping members for everyone means the group is right the day an organization's plan or override turns the switch on. It costs one small write per lease change.
6. **One group per building and organization, opened automatically:** when a building is added (by a member or an import), when a lease of it changes, and by the nightly `building-groups-sync` (02:20, after `leases:expire-and-flag`), which also opens groups for the buildings that existed before P07-01 and puts their members in from today's active leases. The group's name is the building's (stored empty, so a rename follows).
7. **A closed building closes its group.** An archived building, one removed by an import rollback, and one handed to another organization have their group closed (`is_active` false, `closed_at`); a restored or returned building opens it again. Archiving and restoring take effect at the next nightly sync (no new event was added for them; an archived building has no active leases, so nobody is a member meanwhile).
8. **Management handover: groups never change organization**, like tickets (P04-05). The organization the building leaves keeps its group, closed, its members gone; the receiving organization opens its own, with the moved tenants' records there as members. Posts (P07-02) will therefore not move with the building: the receiving agency starts a fresh group. On the end of the management, the landlord's old group opens again with the same members' rows.
9. **Membership changes are not audited one by one.** They follow the leases, which are audited; a handover's audit entry counts the members who left and joined (`building_group_members_left`, `building_group_members_joined`).

**Open question for the product owner:** default 8 means a group's posts stay with the organization that wrote them when a building is handed over. If the receiving agency should see the landlord's earlier posts, that is a copy step for P07-02 to add.

## P07-02 — posts, comments, reactions; rich text; images

Started 2026-11-02 at the product owner's request ("Start P07-02"); one task, built by the orchestrator in `Modules\BuildingGroup` (ARCHITECTURE.md § 6.8 "How posts, comments and reactions are built").

### Defaults taken in P07-02 (orchestrator, 2026-11-02; for the product owner to confirm)

10. **Staff see the groups of the buildings they see, and write with `building_groups.participate`.** Seeing needs `properties.view` (owners, administrators, agents), so a suspended organization's staff can still read; an agent assigned to buildings sees their buildings' groups only. Posting, commenting and reacting need `building_groups.participate` (the same three roles already hold it). Moderation (`building_groups.moderate`) waits for P07-04.
11. **Tenants see and write in a group only while they are members** (P07-01: an active lease in the building) and the group is open. A tenant whose lease ends loses the group at once, their old posts staying with their name. Tenants write from the portal as the tenant record that makes them a member.
12. **The plan switch gates everything.** Without Building Group (Starter), the sidebar item is hidden, the workspace pages answer 403 with the upgrade message, the portal leaves that agency's groups out and the writer refuses; the posts are kept for the day the plan includes it again. An organization that has not chosen a plan yet is not gated (as every switch).
13. **Rich text is bold, italic, bulleted and numbered lists, and links (http, https, mailto)**, the toolbar ARCHITECTURE.md § 6.8 names. Links open in a new tab with `rel="nofollow noopener noreferrer ugc"`. Pasted text comes in plain; pasted headings become paragraphs; anything else is removed. No headings, quotes, underline, colours or embedded images in the words.
14. **Lengths:** a post's text at most 5,000 characters (the schema's 5,000, counted as text, not markup), a comment's 2,000; more than 20,000 bytes of HTML is refused, never cut.
15. **Photos are for posts only:** up to 4, JPEG, PNG or WebP, 2 MB each (ARCHITECTURE.md § 6.8), within the media library's pixel caps (8,000 px a side, 25 MP). GIF, HEIC (iPhones: save as JPEG), SVG, PDF and anything else are refused. A collection of their own (`group_images`, attachments) rather than the 10 MB `photos` of buildings. Comments carry no photos.
16. **Names in the feed:** a tenant shows as the agency's record of them, first name and surname initial ("Kemi A."), never their unit, phone or email; staff show by name with the agency's name beside it.
17. **Reactions: six emojis** (👍 ❤️ 😂 😮 😢 🙏), **one per person per post or comment**: the same again takes it back, another replaces it. The schema's unique key listed the emoji too, which would have allowed several; it is left out to match ARCHITECTURE.md § 6.8 "one reaction per user". Taking a reaction back deletes its row: a toggle, not a record.
18. **Replies are one level deep:** a reply to a reply is filed under the same comment.
19. **Group settings** (`building_groups.settings`): `members_post` (default on: tenants start posts; off: only staff start posts, tenants still comment and react) and `images` (default on). Read and enforced now; the form to change them comes with moderation (P07-04).
20. **Authors remove their own posts and comments** (soft delete, gone for everyone). A removed post takes its comments and **its photos are deleted for good** (attachments; a photo posted by mistake must really go), audited with their sha256; a removed comment takes its replies.
21. **No editing yet.** `edited_at` stays for a later task; an author removes and posts again.
22. **Limits per person, across groups:** 10 posts an hour, 60 comments an hour, 120 reactions a minute.
23. **While GoProperty staff impersonate**, a write session may post words (audited with both identities) but photos are neither added nor shown (people's photos, PLATFORM_CONSOLE.md § 6), as on tickets; removing is refused (a delete).
24. **Read-only cases:** a suspended organization's groups (staff and tenants), a closed group (staff read it; tenants do not see it), a muted member (P07-04 sets the mute; it is honoured now).
25. **Audit:** publishing and removing posts and comments are logged (`building_group`), ids only, never the words; reactions are not logged.
26. **No notifications for posts yet.** P07-03 brings live updates; whether to notify by email or SMS is for later.
27. **The building's owner (`/owner`) has no group page in P07-02.** Owners are not members (default 3); whether they read or write in their buildings' groups is for the product owner.
28. **Default 8's open question stays open:** posts are not copied when a building is handed over; the receiving agency starts a fresh group.

**Found while building (not changed):** `App\Media\UploadValidator` checks that a JPEG, PNG or WebP decodes by reading its header only (to keep memory low on shared hosts), so a file cut short after its header passes and its thumbnail then fails on the `media` queue (the page then links the original, which a browser cannot show). A full decode before storing would catch it at a memory cost; for the product owner to decide.

## P07-03 — live updates: polling on shared hosting, Reverb on a VPS

Started 2026-11-03 at the product owner's request ("Start P07-03"); one task, built by the orchestrator in `Modules\BuildingGroup\Live` with Core's hosting switches (ARCHITECTURE.md § 6.8 "How live updates are built", § 12.1; runbook docs/runbooks/vps-realtime.md).

### Defaults taken in P07-03 (orchestrator, 2026-11-03; for the product owner to confirm)

29. **Every 15 s on shared hosting, every 60 s behind Reverb**, the figures of ARCHITECTURE.md § 12.1 (§ 6.8 said "every 20–30 s"; it now says 15 s). Both are settings (`REALTIME_POLL_SECONDS`, `REALTIME_FALLBACK_POLL_SECONDS`).
30. **New posts, comments and reactions simply appear in place** at the next refresh: no "3 new posts" bar, no sound, no badge in the sidebar. What the person is typing, and how far they have read, are kept.
31. **Polling pauses in a hidden tab and after 30 minutes without activity** (no tap, key, scroll or focus), and catches up at once when the person is back. So a group page left open no longer keeps its session alive past the usual timeout, and forgotten tabs do not load a shared host.
32. **A nudge says only "this group changed"** (a version number): never words, names or ids. The page then asks for its feed through Livewire, with every check of a normal request.
33. **Who may listen is who may open the page**, checked when the browser subscribes. A tenant whose lease ends while their page is open keeps hearing content-free nudges until the page's next refresh, which sends them to their list of groups; they never receive words after leaving.
34. **Nudges go at once from the writer's request, not through the queue**, so pages hear them within a second. If Reverb is down the write still succeeds (the error is logged) and pages catch up at their next poll; the app waits at most 2 s to connect to Reverb and 3 s for an answer.
35. **The writer's own tab is not nudged** (its page shows the change with its answer); the writer's other tabs are.
36. **Reverb only carries nudges:** browsers cannot send messages to each other (client events off), only this site's pages may connect (APP_URL's host; `REVERB_ALLOWED_ORIGINS` adds others), each connection is rate-limited (60 messages a minute), and Reverb listens on 127.0.0.1:8080 behind the web server's proxy.
37. **Reverb is pinned to a commit of its main branch** (`laravel/reverb` `dev-main#94ccfe1`, 2026-10-19): its latest release, 1.12.0, does not accept Guzzle 8 (`guzzlehttp/psr7` v3), which GoProperty has used from the start. The commit is 1.12.0 plus Guzzle 8 support and a security fix (client events limited to members of the connection's app). Replace it with the first release that includes them (`~1.13`).
38. **Only the Building Group's pages are live in P07-03.** Ticket threads (named with them in `App\Hosting\Realtime`) keep refreshing on the person's own actions; making them live is a later task.

**For P07-04:** moderation writes (pin, hide, mute, remove) must call `Live\LiveFeed::changed()` like `Posts\GroupPosts` does, so open pages follow them.
