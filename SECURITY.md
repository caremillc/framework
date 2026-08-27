# Security Policy

## Supported development line

| Version | Security updates |
|---|---|
| `0.1.x-dev` | Yes |
| Older development snapshots | No |

## Reporting a vulnerability

Do not publish exploitable vulnerability details in a public issue.

Report the vulnerability privately to the project maintainers and include:

- the affected component;
- the affected version or commit;
- reproduction steps;
- the expected and actual behavior;
- the potential impact;
- a suggested mitigation, when available.

The maintainers should acknowledge a valid report, assess severity, prepare a
fix, add regression tests, and coordinate disclosure.

## Security expectations

Framework changes must:

- avoid exposing secrets in exceptions and logs;
- validate untrusted identifiers and paths;
- use parameterized database operations when database support is introduced;
- reject unsafe defaults;
- preserve least-privilege boundaries;
- receive regression tests for corrected vulnerabilities.
---
