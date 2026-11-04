
# ADR-0006: HTTP Method Semantics and Route Resolution

Status: Proposed — pending Day 18 closure verification

## Context

Careminate 0.1 requires deterministic HTTP method matching,
route precedence, HEAD and OPTIONS handling, and accurate
404/405 responses.

Method resolution must remain predictable when exact and
dynamic routes overlap, and must not compromise middleware
execution or request isolation.

## Decision

### 1. Request methods

Incoming HTTP methods are validated as HTTP tokens and
normalized to uppercase.

The method visible to handlers and middleware remains
the original normalized request method, including when a
HEAD request is serviced through a GET handler.

### 2. Explicit route matching

For ordinary requests, a route registered for the
requested method is eligible for execution.

Exact paths outrank dynamic paths for the same method.

Matching dynamic routes are ranked by:

1. Greater literal segment count.
2. Greater constrained parameter count.
3. Earlier registration order.

A dynamic route that fails its parameter constraints
does not count as a matching path.

### 3. HEAD semantics

An explicit HEAD route takes priority over a GET fallback.

When no explicit HEAD route matches, HEAD may use an
eligible GET route.

The original request method remains HEAD.

Explicit HEAD resolution precedes GET fallback even
when the fallback GET route has a more specific path.

The final HEAD response body is suppressed at the
HTTP kernel and runtime boundaries.

Status and ordinary response headers are preserved.

Direct Router::dispatch() is a routing API; callers
should use the kernel/runtime to obtain the complete
HEAD response contract.

HEAD does not fall back to POST, PUT, PATCH or DELETE.

### 4. OPTIONS semantics

An explicitly registered OPTIONS route takes precedence
over automatic OPTIONS generation.

When the requested path matches registered routes but
no explicit OPTIONS handler matches, Careminate returns:

- Status: 204 No Content
- Body: empty
- Allow: applicable supported methods

Automatic OPTIONS does not execute ordinary route handlers.

Global middleware still runs.

Route-group middleware runs for explicitly matched OPTIONS
routes, but not for an automatic OPTIONS response.

OPTIONS on a path with no matching route returns 404.

Automatic OPTIONS does not imply a CORS authorization
policy. Access-Control-Allow-* headers are not generated
without an explicit CORS implementation.

### 5. Allow header

For a matched path, Careminate collects applicable methods
from exact and matching dynamic route patterns.

GET implies HEAD support.

Matched routes also imply automatic OPTIONS support.

Methods are deduplicated and sorted alphabetically.

Examples:

| Registered methods | Allow header |
| --- | --- |
| GET | GET, HEAD, OPTIONS |
| POST | OPTIONS, POST |
| HEAD | HEAD, OPTIONS |
| GET, POST | GET, HEAD, OPTIONS, POST |
| GET, PATCH, POST | GET, HEAD, OPTIONS, PATCH, POST |
| OPTIONS | OPTIONS |

The Allow header is generated for 405 responses and
automatic OPTIONS responses.

A successful ordinary route response does not
automatically receive an Allow header.

### 6. Error responses

404 Not Found:
No eligible route pattern matches the requested path.

405 Method Not Allowed:
One or more eligible route patterns match the path,
but none handles the requested method or its defined
fallback.

405 responses include an Allow header.

HEAD 404 and HEAD 405 responses have empty bodies
when returned through the kernel/runtime.

### 7. Registration conflicts

Duplicate method/path registrations are rejected.

Duplicate route names are rejected.

A dynamic pattern registered for multiple methods must
have compatible parameter constraints.

Validation completes before registration mutates
the router's stored routes or names.

Separate method registrations for the same path are
permitted when no registration conflict exists.

Route registration is prohibited after router freezing.

### 8. Request and middleware isolation

Route parameters belong to the matched request.

Later requests must not inherit parameters from previous
dispatches.

Global middleware executes for matched and unmatched
requests.

Route-group middleware applies to a successfully
selected route, not to an automatic routing response.

Middleware ordering remains deterministic.

Handler exceptions propagate according to the existing
exception-handling contract.

A failed handler must not corrupt the kernel's internal
handling state.

## Alternatives

- Return 405 for every HEAD request without an explicit
  HEAD route.
- Automatically register synthetic HEAD and OPTIONS
  route objects.
- Implement HEAD fallback by rewriting Request::method
  from HEAD to GET.
- Generate OPTIONS responses before global middleware.
- Use registration order alone for route precedence.

These alternatives were rejected because the selected
behavior provides clearer method semantics, avoids
synthetic registration state, and preserves the actual
incoming method for downstream code.

## Consequences

Applications may observe changes to:

- HEAD requests that previously returned 405.
- OPTIONS requests that previously returned 405.
- Allow headers that previously omitted implicit methods.

Existing tests and applications must account for these
public contract changes.

The first release does not claim complete HTTP
standards compliance from this routing decision alone.

CORS, structured body parsing, and content-type error
responses are separate concerns.

## Verification

Required coverage includes:

- Explicit HEAD precedence.
- HEAD-to-GET fallback.
- Final HEAD body suppression.
- Explicit OPTIONS precedence.
- Automatic OPTIONS for matched paths.
- OPTIONS without a matching route.
- Correct 404 versus 405 behavior.
- Dynamic constraints.
- Exact versus dynamic precedence.
- Alphabetically sorted Allow headers.
- Atomic registration failure.
- Router freezing.
- Middleware execution boundaries.
- Request isolation across repeated dispatches.
- HTTP response emission.

Primary regression suites:

- HeadMethodSemanticsTest
- OptionsMethodSemanticsTest
- HttpMethodInteractionMatrixTest
- RouterMethodConflictMatrixTest
- HttpMethodRuntimeIntegrationTest
- HttpBoundarySecurityMatrixTest

Acceptance requires the complete discovered regression
suite, static analysis, and smoke tests to pass.

## Status and review

This ADR becomes Accepted only after the Day 18
verification gate passes and the resulting repository
revision has been recorded.
