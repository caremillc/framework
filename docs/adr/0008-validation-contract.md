# ADR-0008 — Strict Input Validation and Structured HTTP Errors

**Status:** Proposed  
**Framework:** Careminate 0.1.0-dev  
**Decision scope:** Input validation, schema behavior and API error responses

## Context

Applications need reusable field validation without mixing JSON parsing, application rules, and HTTP exception formatting.

The framework must preserve predictable failure classifications and avoid exposing untrusted values through client-facing error messages.

The initial release is limited to request-per-process PHP with a small validated JSON surface.

## Decision

Careminate adopts a strict schema-based validator with five field types: string, integer, boolean, object and array.

Validation is non-coercing. Unexpected fields are rejected by default, and only schema-declared fields are returned upon success.

`ValidationResult` separates validation success from field-error reporting. Invalid results cannot expose partially validated values.

`ValidationException` represents business-input validation failures through approved error codes and bounded, schema-derived field paths.

`ValidationProblemResponseFactory` formats those errors as HTTP 422 problem documents.

`ValidatedJsonBodyParser` composes the existing JSON parser and field validator without taking responsibility for routing, persistence, authentication or error emission.

## Error classification

JSON syntax and structural parsing failures are not field-validation failures.

Malformed JSON and duplicate JSON object members produce HTTP 400. Oversized bodies produce HTTP 413. Unsupported media types produce HTTP 415. Valid JSON with rejected application fields produces HTTP 422.

Unexpected downstream exceptions are sanitized as HTTP 500 when handled by `ProblemExceptionHandler`.

## Security decisions

- Do not coerce numeric strings or booleans.
- Do not include submitted values in validation error messages.
- Do not reveal unknown field names.
- Do not expose partially validated data.
- Validate schemas before processing input fields.
- Bound schema nesting depth and error-path length.
- Bound enum definitions and permit only strict scalar enum comparisons.
- Keep arbitrary object/list uniqueness out of the first-release validator.
- Keep error formatting separate from parsing and validation.

## Alternatives considered

**Automatic input coercion:** Rejected because it creates ambiguity at trust boundaries.

**Embedding validation into JsonBodyParser:** Rejected because field validation should also be usable independently of HTTP.

**Throwing a generic HTTP 422 exception containing validation details:** Rejected in favor of a dedicated validation exception with a constrained error vocabulary.

**Returning the first validation error only:** Rejected because collecting safe field errors provides better application feedback.

**Implementing a broad validation-rule ecosystem immediately:** Deferred to preserve the first-release scope.

## Known limitations

Associative JSON decoding does not preserve the distinction between empty `{}` and `[]`.

String length is based on Unicode code points, not grapheme clusters. Unicode normalization is not applied.

Array uniqueness supports primitive values only.

The current schema validator does not enforce a general maximum number of collected field errors independently of request-size and schema limits. Resource profiling for adversarial but syntactically valid payloads remains follow-up work.

The framework's authentication, authorization, persistence constraints and deployment-layer limits must independently enforce their own security requirements.

## Compatibility

The existing `Validator::validate()` method retains its signature.

Basic flat-field schemas continue to work.

Nested schemas and extended rules are opt-in.

The `ProblemExceptionHandler` middleware remains opt-in, preserving the exception-propagation behavior of earlier runtime APIs when not registered.

## Acceptance evidence

Before accepting this ADR:

1. Verify the complete PHPUnit suite and expected test discovery.
2. Verify zero PHPStan and coding-standard errors.
3. Confirm a passing framework smoke test.
4. Check that invalid request and validation payloads expose no sensitive details.
5. Review dependency and runtime security status.
6. Record unresolved deployment-layer concerns.

**Decision status remains Proposed until these checks are complete.**