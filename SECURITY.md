# Security Policy

## Repository Scope

This repository currently contains three different surfaces:

- a fresh root PHP implementation surface in [`src/`](./src)
- an experimental TypeScript playground in [`playground/`](./playground)
- specification and planning documents for the next Axiom v1 implementation
- an archived pre-v1 PHP runtime in [`legacy/`](./legacy)

When reporting an issue, please state which surface is affected.

## Supported Versions

We currently handle security issues for:

| Surface | Supported |
| ------- | --------- |
| Current root PHP implementation surface | best effort |
| Current `main` branch development work | best effort |
| Archived legacy runtime | no new feature work; security triage only if still relevant |

No stable Axiom v1 reference implementation has been released from this
repository yet.

## Reporting a Vulnerability

Please do not report security vulnerabilities in public issues.

Send the report privately to the maintainers and include:

- a clear description of the issue
- the affected surface or component
- steps to reproduce
- likely impact
- any mitigation ideas you already have

## Response Expectations

- initial acknowledgement within 48 hours where possible
- follow-up once the issue is confirmed and scoped
- coordinated disclosure after a fix is available

## Notes

- specification wording issues are usually not security issues unless they can
  be shown to create an exploitable implementation weakness
- playground-only problems should be identified as such
- if the issue affects the archived runtime, say so explicitly
