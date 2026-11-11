# Prism's change council

The Lab hosts its own `particle-academy/change-council` 0.7.1 ledger at `/council`. It stays local and private. Source integration does not provision or serve the Lab; access refuses until the supported dedicated database cutover and owner configuration.

## Owner

Use the existing human account configured by `LAB_OWNER_USER_ID`. `COUNCIL_OWNERS` must contain exactly that account's login email. Both settings are required; an email alone, another signed-in account, the generic harness operator or a caller's owner label grants nothing. Config emails are trimmed and compared without case; the stable account ID is still checked on every request. Do not create a second Council owner account or expose signup.

Council's native actor hook stores `owner:<numeric user ID>` only after verifying the current session, configured ID and single email. Missing or inconsistent identity returns null and the package refuses it; there is no email fallback. Historical email actors remain unchanged when an account's email changes. Lab run approvals also record numeric owner IDs. Council approval does not create a paid-run intent, GO or invoice guarantee.

Pages, JSON reads, feed and all owner decisions share the socket-loopback, session, ID/email, CSRF and package authorization boundary. Owner controls are human consent surfaces, including portaled dialogs, with no editable agent bridge or owner console command. The browser marker is defense in depth; a machine administrator is outside the web-session boundary.

## Package pages and checks

Pages are unchanged package copies under `resources/js/pages/Council`. Lower-case `pages` matches this application's glob; upper-case `Council` matches the package renderer's literal names on Linux. Set `council.pages.publish_path` before publishing. A lower-case `js/pages/council` does not match the renderer.

After a reviewed package update run `council:pages-check`. Missing or stale copies fail; re-publish the exact reviewed pages instead of silently maintaining a fork. The host document boundary covers owner modals without modifying those copies. Locked/current gates and offline browser controls exercise actual pages against the host's Fancy version.

## Database and operation

`/council/members` and each member profile use native package pages. Only the verified human owner can change vote weights, project metadata, silence policy, the blocking threshold or membership. Removal preserves the row/history and withdraws open petitions. Closed tallies keep their recorded weights and threshold. All 21 registered routes use the full host boundary; native API calls use the same identity predicate.

The native census rule names publisher `Prism` and replaces the complete default: PyPI `prism-ai-*` and exact `prism-conformance`, Composer `particle-academy/prism*`, npm `@particle-academy/prism*`. These are literal trailing-prefix patterns or exact names, never regexes. Missing ecosystems accept nothing; invalid entries fail package boot. Census replies are strict data bound to the actual sender and issued nonce; replay does not apply twice, and replies cannot change owner weights/policies/project fields. The clerk's census commands queue and ingest only; this integration adds no automatic real send or census schedule. The eleven Python name controls include planned packages and synthetic versions, so they prove acceptance rather than publication or adoption.

Package discovery loads the base eight-table migration and three additive migrations for member weights/project fields/settings, removal/withdrawal markers and census request/reply tables (eleven tables total). Existing rows survive, with weight 1 and threshold 1 by default. Do not publish duplicate migrations or run fresh/refresh against a real database. Tests force in-memory SQLite through both env and server settings and retain the connection reach guard. SQLite has append-only audit triggers; PostgreSQL has model guards and a verifiable hash chain, without those triggers.

Real additive migration, owner account/configuration and consumer seeding wait on the supported dedicated Lab database cutover. Follow the existing database runbook and verify the actual connection first. Keep feed push and real signing keys unconfigured until their separately approved operator steps. `council:tally` is scheduled every15minutes without overlap; this declaration does not prove a scheduler is running. The agent clerk is a separate procedure, not an application/provider loop.

## Notices

`Council::notifyGenie()` sends owner-event pointers after commit to `prism@prism`: refs, states, codes and counts, never proposal or consumer reply text. The URL/token come from Genie's environment at send time. Never copy them into `.env`, cached config, a receipt or a log. `notify.skipped` means transport is absent; failure or a paused recipient is not successful delivery. Notices do not approve anything.

Source tests use synthetic transport and fake HTTP only. Real notices are an operational acceptance step. The handback includes a synthetic example generated through the actual notifier.

## Clerk procedure

Use native commands through in-process `Artisan::call` with structured arguments and JSON files. Do not interpolate JSON through Windows `cmd`.

1. Run `council:seed-consumers` only in the authorized database. Re-seeding is idempotent and preserves policies, whitelist flags, removal markers and census timestamps. The native seed contains inferred/old constraints and Fancy internal apps; it is not a current Prism consumer census. Keep unknown/stale declarations visible and refresh them through the normal maintainer process.
2. Propose a genuine reviewed Change with a file and actual initiator. Non-whitelisted work waits for the real owner with pros/cons; an agent cannot approve or override it by CLI. Preserve workspace bug/security triage and change classification rules.
3. Read `council:petitions --json`. In the authorized real exercise, send each queued text exactly to its `to` address via AgentInbox. Pass the unaltered raw result to `council:petitions --mark-sent=<id> --result=<raw JSON>` in-process. Refused, paused or unconfirmed sends remain undelivered/unreachable.
4. Preserve the actual reply envelope. For raw ingest, `--from` is the authenticated Genie sender only when it is exactly the petitioned address; pass its actual message ID and a body file. Never replace a mismatched sender with the expected address. Envelope ingest may use `--genie-json`. Sender is never trusted from body JSON; native nonce/change/round/digest/replay checks remain.
5. Tally, revise through the actual initiator if needed, and wait for real owner UI decisions on escalations. Alternatives are data, never executable code or automatically adopted approaches. Record audit verification, delivery receipts, rounds, outcome and the Change URL. Pending decisions remain pending.

Source tests walk a synthetic SQLite loop with synthetic replies and no sends. They do not close the one-real-Change exercise, which waits on DB/owner cutover. Do not fabricate votes or owner sessions to finish it. Signing/export tests use disposable fixture keys only; no real key is generated or printed. This installation adds no org-wide detector repin or release-blocking signed-record policy.
