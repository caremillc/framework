# ADR-0007 — HTTP Request-Body Security and JSON Parsing

**Status:** Proposed — awaiting Day 19 closure  
**Framework:** Careminate 0.1.0-dev  
**Scope:** HTTP request creation, body-size limits, JSON decoding, framing metadata, safe error handling

## Context

Untrusted HTTP input must pass clearly defined validation boundaries before application code consumes it.

Careminate separates inbound request construction, HTTP routing, JSON object parsing, and response emission. These responsibilities must remain independently testable.

This ADR documents the implemented Day 19 behavior and its limitations.

## Decision 1 — Inbound request construction

`Careminate\Http\ServerRequestFactory` constructs a `Request` from trusted runtime inputs and untrusted request metadata.

Its responsibilities include:

- Validating request method and target values.
- Normalizing inbound headers.
- Rejecting invalid or conflicting header sources.
- Enforcing a configured body-size limit.
- Validating declared `Content-Length` against received body bytes.
- Rejecting requests that supply both `Content-Length` and `Transfer-Encoding`.

`fromServer()` accepts a provided body string. `fromGlobals()` reads `php://input` using a bounded read.

The default factory body limit is 1,048,576 bytes (1 MiB). Applications may configure a different nonnegative limit.

Body sizes are measured in bytes using `strlen()`, not Unicode character counts.

### Content-Length contract

When `Content-Length` is present:

1. It must contain a nonnegative ASCII decimal integer.
2. Leading zeroes are permitted.
3. Its numeric value must not exceed the configured body limit.
4. Its value must equal the received body's byte count.
5. Conflicting header aliases are rejected.
6. A request containing both Content-Length and Transfer-Encoding is rejected.

Large decimal values are compared without first converting untrusted input to an integer.

A missing Content-Length is supported. Actual body-size enforcement still applies.

This contract validates the body delivered to PHP. It does not implement raw network framing, chunk decoding, or reverse-proxy validation.

## Decision 2 — JSON object parsing

`Careminate\Http\Body\JsonBodyParser` provides explicit, opt-in JSON parsing.

The parser accepts a JSON object as its root value and returns an associative PHP array.

Accepted media types include:

- `application/json`
- Concrete `application/*+json` structured-suffix types, such as `application/problem+json` and `application/vnd.api+json`

Media-type matching is case-insensitive. An optional UTF-8 charset declaration is accepted, including a quoted UTF-8 value.

Unsupported media types, unsupported charsets, and unsupported additional parameters are rejected.

JSON arrays, strings, numbers, booleans, and null are not valid top-level values for `parseObject()`.

The parser does not automatically handle XML, HTML forms, multipart requests, or file uploads.

### Parsing limits

The default JSON body limit is 1 MiB.

The default JSON nesting depth is 64.

The maximum configured depth supported by this parser is 512.

The parser enforces its own size boundary, independently of the request factory.

Applications should configure compatible limits at the HTTP server, PHP runtime, request factory, and JSON parser.

### Numeric values

`JSON_BIGINT_AS_STRING` preserves JSON integer values that exceed native integer capacity as strings.

Application validation must still check numeric ranges and expected field types. Preservation is not equivalent to accepting a value as a valid business identifier.

## Decision 3 — Duplicate JSON object members

Duplicate JSON object member names are rejected with HTTP 400.

The policy applies to every JSON object, including nested objects and objects contained in arrays.

Names are compared after JSON escape decoding.

Examples of equivalent names include:

- `"name"` and `"\u006eame"`
- `"a/b"` and `"a\/b"`
- A literal Unicode character and its equivalent JSON Unicode escape

Name comparisons remain case-sensitive.

Distinct Unicode normalization forms are not automatically treated as identical.

The duplicate-key guard runs only after normal JSON syntax and nesting validation.

A rejected duplicate must never be silently resolved by retaining its first or last value.

## Decision 4 — Error classification

| Failure | HTTP status |
|---|---|
| Invalid request method, target, or header | 400 |
| Malformed Content-Length or size mismatch | 400 |
| Ambiguous request framing | 400 |
| Malformed JSON, invalid JSON root, or duplicate key | 400 |
| Actual or declared body exceeds the configured limit | 413 |
| Unsupported JSON content type or charset | 415 |

The order of validation may determine which error is reported when a request violates multiple rules.

Error responses must not disclose submitted field values, credentials, stack traces, exception file paths, or internal implementation details.

## Decision 5 — Safe runtime entry points

`HttpRunner::run()` and `HttpRunner::runFromGlobals()` preserve their original exception-propagation contract.

The opt-in methods `runSafely()` and `runFromGlobalsSafely()` convert request-construction failures into HTTP problem responses:

- `RequestBodyTooLargeException` becomes HTTP 413.
- Other inbound `InvalidArgumentException` failures become HTTP 400.

These mappings occur before kernel dispatch.

Invalid inbound requests do not invoke route handlers.

The runner remains one-shot after both successful execution and rejected input.

Other failures, including unexpected stream-reading, response-emission, or uncaught handler errors, can still propagate.

## Decision 6 — JSON problem responses

`ProblemResponseFactory` produces minimal JSON problem documents.

Example:

```json
{
  "type": "about:blank",
  "title": "Bad Request",
  "status": 400
}
```

Responses include:

- `Content-Type: application/problem+json`
- `Cache-Control: no-store`
- `X-Content-Type-Options: nosniff`

The `ProblemExceptionHandler` middleware converts downstream `HttpException` instances into corresponding problem responses. Unexpected downstream exceptions become generic HTTP 500 responses.

This middleware must be explicitly registered.

Exception reporting and secure internal logging are separate responsibilities. Sanitizing a response does not itself record an incident.

Ordinary router-generated HTTP 404 and 405 responses retain their existing routing contract; they are not automatically converted into JSON problem documents.

### HEAD behavior

HTTP HEAD responses must have empty bodies.

This applies to successful handler responses, routing errors, and problem responses emitted by the safe runtime.

## Alternatives considered

**Automatically parsing every request body:** Rejected because parsing requirements vary by endpoint and content type.

**Silently accepting duplicate JSON members:** Rejected because multiple interpretations of a payload can create security and application-consistency problems.

**Changing every existing HttpRunner method to catch exceptions:** Rejected to preserve established public contracts.

**Returning raw exception messages in JSON errors:** Rejected because messages may contain internal details or untrusted input.

**Implementing multipart and streaming upload handling here:** Deferred outside this release's supported body-parsing scope.

## Consequences

Applications must deliberately configure JSON parsing and problem-response middleware.

Changes to Content-Length validation may reject previously tolerated malformed fixtures.

Request body limits must be aligned across deployment layers.

A successful JSON parse establishes valid syntax, not valid application data. Field-level validation, required-field rules, authorization, and business constraints remain separate concerns.

## Verification evidence

At the end of Increment 5, the reported framework regression baseline was:

- 928 passing PHPUnit tests
- 4,517 assertions
- Successful Careminate smoke test on PHP 8.5.11

Relevant suites:

- `JsonBodyParserTest`
- `JsonDuplicateKeyGuardTest`
- `ContentLengthBoundaryTest`
- `HttpProblemHandlingIntegrationTest`
- `JsonApiEndToEndIntegrationTest`
- `HttpBoundarySecurityMatrixTest`

Formal acceptance requires a clean current full-suite run, static analysis, repository hygiene verification, and confirmation that the documentation matches the checked-out implementation.

## Review status

**Proposed.**

Change to **Accepted** only after the Day 19 closure gate is recorded.

This ADR does not assert complete HTTP standards compliance or production readiness.