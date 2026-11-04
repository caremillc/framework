# Careminate HTTP Request-Body Security Review

**Review:** Day 19, Increment 6  
**Status:** Proposed — verification pending  
**Baseline:** 928 tests, 4,517 assertions

## 1. Security objective

Prevent malformed HTTP input from being ambiguously interpreted, bypassing request-size controls, corrupting request isolation, or exposing sensitive implementation details in responses.

The review covers:

- HTTP request construction and framing metadata
- Request-body size enforcement
- JSON object parsing
- Duplicate JSON property detection
- Safe HTTP error generation
- Middleware boundaries and response emission

It does not constitute a penetration test or independent security audit.

## 2. Trust boundaries

| Boundary | Trusted responsibility | Untrusted input |
|---|---|---|
| Reverse proxy / web server | Network protocol handling | Client HTTP messages |
| ServerRequestFactory | Request construction and limits | Server variables, headers, body bytes |
| JsonBodyParser | JSON syntax and media-type validation | Request body and Content-Type |
| Router / Kernel | Method dispatch and middleware order | Method, path, parameters |
| Application handler | Business rules | Parsed JSON fields |
| ResponseEmitter | HTTP output emission | Handler response data |

Validation must not be delegated to a later boundary when the earlier boundary can enforce it safely.

## 3. Threat and control matrix

| Threat | Current control | Residual risk |
|---|---|---|
| Oversized request body | Factory and parser byte limits | Web-server and PHP limits must also be configured |
| Invalid Content-Length | Decimal validation and exact-byte comparison | Network framing remains outside PHP |
| Integer overflow in length | Decimal-string comparison | Very large headers still consume input processing resources |
| Conflicting framing headers | Content-Length / Transfer-Encoding conflict rejection | Reverse proxy must independently validate framing |
| Header injection | Existing request/header validation | Deployment-specific server normalization needs verification |
| Malformed JSON | Strict decoding with exceptions | Application must validate decoded field values |
| Duplicate JSON keys | Reject decoded-equivalent member names | Unicode normalization differences remain distinct |
| Excessive JSON nesting | Configurable depth limit | Resource use requires representative load testing |
| Unsupported content type | Media-type and charset validation | No automatic negotiation across arbitrary formats |
| Sensitive exception messages | Sanitized problem responses | Internal logging and alerting remain necessary |
| Cross-request contamination | Stateless parser and repeated-request tests | Long-running workers are unsupported |
| Cross-origin requests | No automatic permissive CORS headers | Same-origin application policy must be enforced |
| Outdated runtime | Security update required | PHP 8.5.11 remains unapproved for deployment |

## 4. Security properties established by regression testing

The current test inventory includes checks for:

1. Valid and malformed JSON objects.
2. Duplicate JSON member names, including escaped equivalents.
3. Binary and multibyte body-size boundaries.
4. Content-Length mismatch, conflicting aliases, and overflow.
5. Unsupported JSON media types and charsets.
6. HTTP 400, 413, and 415 problem responses.
7. Sensitive-message suppression in client responses.
8. HEAD response-body suppression.
9. Middleware exception handling and one-shot runtime execution.
10. Repeated-request isolation and recovery after failures.

Passing tests support the documented behaviors for tested inputs. They do not establish that every possible protocol, encoding, or attack variation has been covered.

## 5. Remaining risks and decisions

### High — PHP runtime patching

The development runtime was last reported as PHP 8.5.11.

Before deployment, use a security-fixed PHP runtime and verify both CLI and web-server execution paths.

Record the tested runtime version in the release evidence.

### High — Deployment-layer body limits

The first deployment must enforce compatible limits in Nginx, PHP-FPM, and Careminate.

A PHP application cannot reliably control resources already consumed while the web server receives an oversized request.

Record the selected limits and verify rejection with real HTTP requests.

### High — Trusted-host and proxy policies

Host validation and trusted proxy configuration require separate deployment verification.

Do not blindly trust forwarded headers or infer a secure request scheme from untrusted client headers.

### Medium — JSON parser resource use

JSON object decoding and duplicate-key scanning perform separate processing passes.

The existing byte and depth limits reduce exposure, but worst-case CPU and memory costs have not been benchmarked.

Add representative malformed, nested, and high-member-count payloads to the performance test plan.

### Medium — Error reporting and observability

Sanitized HTTP problem responses must not become silent operational failures.

Ensure internal exceptions are recorded through the established reporting mechanism with secret redaction and correlation identifiers.

Do not expose internal exception details to clients.

### Medium — JSON key normalization

Duplicate detection compares decoded JSON member names exactly and case-sensitively.

It does not apply Unicode normalization or application-specific field-name transformations.

If an application normalizes names, validate uniqueness again after that normalization.

### Medium — Application-level validation

Valid JSON syntax does not prove that fields are safe or appropriate.

Day 20 must introduce field validation, required fields, type checks, bounded string lengths, and a stable validation-error contract.

### Deferred — Unsupported capabilities

The initial request-body surface does not include:

- Multipart or file-upload processing
- Streaming JSON documents
- XML parsing
- General-purpose form parsing
- Long-running worker guarantees
- Automatic permissive CORS handling

Unsupported capabilities must not be advertised as implemented.

## 6. Release-gate evidence

The review is complete only when the following evidence is recorded:

| Gate | Evidence required |
|---|---|
| PHPUnit | Passing complete suite and assertion count |
| Static analysis | PHPStan reports no errors |
| Composer metadata | Strict Composer validation passes |
| Repository hygiene | Git diff whitespace check passes |
| Runtime | Security-fixed PHP version confirmed |
| Documentation | Architecture ADR and security review exist |
| Dependency security | Composer audit findings reviewed |
| Deployment | Server-layer request limits and trust settings verified before deployment |

## 7. Outcome

Day 19 introduces a bounded and explicit JSON request-body contract suitable for continued framework development.

The current implementation must not be described as fully security-audited, fully HTTP-compliant, or production-ready solely on the basis of unit and integration tests.

**Disposition:** Keep this review open until the Day 19 final gate passes. Record remaining deployment risks as follow-up work rather than weakening the verified parsing and framing contracts.