# CI provider dependency policy

## Blocking checks

The pull request and release gates verify StoX behavior without contacting live
external providers. PHPUnit, JavaScript tests, type checking, production builds,
and browser journeys remain blocking. Any test that sends Laravel HTTP requests
must provide a deterministic fake or mock. The shared PHP test base calls
`Http::preventStrayRequests()` so an accidental request fails the test.

Fixture and mocked tests for parser behavior, provider adapters, fallback,
security, data integrity, and API/data contracts are mandatory. This includes
NSE XBRL and inline XBRL parsing and the integrated filing client. HTTP 404/429,
provider outages, access denials, and changed live payload formats are not
reasons to skip or weaken those tests; represent them with explicit fixtures.

## Nonblocking live checks

Live provider availability is monitored only by the scheduled or manually
dispatched `Provider smoke monitor` workflow. It reports the HTTP status and
elapsed time for public NSE and Yahoo probes in the run summary and uploads the
same redacted report as an artifact. It does not print response bodies, request
headers, or credentials, and it never fails a deployment gate. Authenticated
providers such as Kite are not probed without a separately approved secret
handling design.

Provider alerts belong in monitoring, not in deterministic test expectations.
When a live smoke check fails, the workflow run and artifact provide visibility;
this workflow does not create GitHub issues automatically, avoiding duplicate
issue spam during extended outages.

## Change classification

| Check | Network dependency | Gate |
| --- | --- | --- |
| PHPUnit parser, adapter, fallback, security, integrity, and contract fixtures | None; stray Laravel HTTP is rejected | Blocking |
| Frontend unit/type/build checks and local browser journeys | No live market provider dependency | Blocking |
| Public NSE and Yahoo availability probes | Live provider endpoints | Nonblocking scheduled/manual workflow |
| Authenticated Kite checks | No probe configured | Not run |
