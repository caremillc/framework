# Careminate — Input Validation and JSON API Guide

**Framework:** Careminate 0.1.0-dev  
**Introduced:** Day 20  
**Scope:** JSON object validation, nested schemas, error codes and HTTP integration

## 1. Purpose

Careminate separates three responsibilities:

1. `JsonBodyParser` validates JSON syntax, media type, body size and duplicate JSON object members.
2. `Validator` validates application fields against a developer-defined schema.
3. `ProblemExceptionHandler` converts supported HTTP and validation exceptions into sanitized JSON problem responses.

`ValidatedJsonBodyParser` composes JSON parsing and field validation for endpoint handlers.

Validation uses strict PHP types. No implicit coercion, trimming or Unicode normalization is performed.

## 2. Basic usage

```php
use Careminate\Validation\Validator;

$schema = [
    'title' => [
        'type' => 'string',
        'required' => true,
        'notBlank' => true,
        'maxLength' => 120,
        'maxBytes' => 240,
    ],
    'priority' => [
        'type' => 'integer',
        'min' => 1,
        'max' => 5,
    ],
    'active' => [
        'type' => 'boolean',
    ],
];

$result = (new Validator())->validate(
    [
        'title' => 'New task',
        'priority' => 3,
        'active' => false,
    ],
    $schema,
);

if ($result->isValid()) {
    $data = $result->validated();
} else {
    $errors = $result->errors();
}
```

`validated()` throws `LogicException` when any error exists. The framework does not expose a partially validated result.

Optional fields are omitted when absent. Null values are accepted only when `nullable` is true.

A required nullable field must still be present, even though its value may be null.

## 3. Supported field types

| Type | PHP input type | Description |
|---|---|---|
| `string` | `string` | Valid UTF-8 text |
| `integer` | `int` | Native integer, never a numeric string |
| `boolean` | `bool` | Strict true or false |
| `object` | Associative `array` | Nested declared fields |
| `array` | List `array` | Validated items |

An unsupported type or rule is a schema-definition error and raises `InvalidArgumentException`.

## 4. Supported rules

| Rule | Types | Purpose |
|---|---|---|
| `required` | Any | Require a field to be present |
| `nullable` | Any | Permit explicit null |
| `notBlank` | String | Reject empty or whitespace-only strings |
| `minLength` | String | Minimum Unicode code-point length |
| `maxLength` | String | Maximum Unicode code-point length |
| `maxBytes` | String | Maximum UTF-8 byte length |
| `enum` | String, integer, boolean | Strict allowed-value list |
| `min` | Integer | Inclusive minimum |
| `max` | Integer | Inclusive maximum |
| `fields` | Object | Child-field schema |
| `items` | Array | Member-validation rule |
| `minItems` | Array | Minimum number of members |
| `maxItems` | Array | Maximum number of members |
| `uniqueItems` | Array | Strict uniqueness of primitive members |

Enum choices must match the declared scalar type. A string `"1"`, integer `1` and boolean `true` are different values.

The current enum schema supports 1–64 choices. String enum choices must be valid UTF-8 and no longer than 256 bytes.

`uniqueItems: true` supports arrays whose declared items are strings, integers or booleans. Null is also checked for duplicates when explicitly allowed. Object and nested-array uniqueness are not supported.

## 5. Nested schemas

```php
$schema = [
    'profile' => [
        'type' => 'object',
        'required' => true,
        'fields' => [
            'name' => [
                'type' => 'string',
                'required' => true,
                'notBlank' => true,
            ],
        ],
    ],
    'tasks' => [
        'type' => 'array',
        'minItems' => 1,
        'items' => [
            'type' => 'object',
            'fields' => [
                'title' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
        ],
    ],
];
```

Nested validation errors use predictable paths:

```json
{
  "profile.name": ["required"],
  "tasks[2].title": ["type"],
  "tasks[3]._input": ["unknown_fields"]
}
```

Unknown fields are rejected by default at every object level.

Call `validate($input, $schema, rejectUnknown: false)` to ignore unknown fields. Ignored fields are not returned by `validated()`.

The validator limits schema nesting depth to 16 and error paths to 256 bytes. Schema field names must satisfy the documented ASCII identifier format and be no longer than 64 bytes.

### Empty-container limitation

The current associative JSON decoder maps both `{}` and `[]` to the empty PHP array.

Therefore, an empty JSON object and an empty JSON array cannot always be distinguished by this validation API. Strict empty-container discrimination is deferred until the parser preserves container identity.

## 6. Error codes

| Code | Meaning |
|---|---|
| `required` | Required field absent |
| `type` | Wrong type or disallowed null |
| `encoding` | Invalid UTF-8 string |
| `blank` | Blank string rejected |
| `min_length` | String shorter than configured code-point minimum |
| `max_length` | String longer than configured code-point maximum |
| `max_bytes` | String exceeds its UTF-8 byte limit |
| `min` | Integer below minimum |
| `max` | Integer above maximum |
| `enum` | Value not in strict allowed-value list |
| `min_items` | Array contains too few members |
| `max_items` | Array contains too many members |
| `unique_items` | Array contains duplicate primitive members |
| `unknown_fields` | Unexpected object fields detected |

The `unknown_fields` code appears under `_input` or a nested path ending in `._input`.

It does not identify or reproduce unexpected field names.

Multiple errors can be reported for a field. Their order follows the validator's rule-evaluation order.

## 7. Validated JSON endpoint integration

```php
use Careminate\Http\Body\JsonBodyParser;
use Careminate\Http\Body\ValidatedJsonBodyParser;
use Careminate\Http\Middleware\ProblemExceptionHandler;
use Careminate\Http\Request;
use Careminate\Http\Response;
use Careminate\Validation\Validator;

$kernel->middleware(new ProblemExceptionHandler());

$input = new ValidatedJsonBodyParser(
    new JsonBodyParser(),
    new Validator(),
);

$schema = [
    'title' => [
        'type' => 'string',
        'required' => true,
        'notBlank' => true,
        'maxBytes' => 120,
    ],
];

$kernel->router()->post(
    '/api/items',
    static function (Request $request) use ($input, $schema): Response {
        $validated = $input->parse($request, $schema);

        return new Response(
            201,
            ['Content-Type' => 'application/json'],
            json_encode(
                ['data' => $validated],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    },
);
```

Production endpoints should return only data appropriate for the caller. Validation does not replace authentication or authorization.

## 8. HTTP error contract

| Condition | Status |
|---|---|
| Malformed HTTP request metadata | 400 |
| Invalid JSON syntax or duplicate JSON names | 400 |
| Exceeded request-body limit | 413 |
| Unsupported JSON media type | 415 |
| JSON object with invalid application fields | 422 |
| Unexpected downstream exception handled by problem middleware | 500 |

Example 422 response:

```json
{
  "type": "about:blank",
  "title": "Unprocessable Content",
  "status": 422,
  "errors": {
    "title": ["required"],
    "priority": ["max"]
  }
}
```

Problem responses use `application/problem+json`, `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.

An invalid schema is a developer error, not a client-side validation failure.

`ProblemExceptionHandler` must be registered explicitly. Safe ingress handling uses the opt-in `HttpRunner::runSafely()` or `runFromGlobalsSafely()` methods.

Ordinary router-generated 404 and 405 responses retain their existing response contract.

## 9. Security and operational limitations

Validation establishes that data matches a schema. It does not establish that the caller is authorized to use or modify the data.

The current implementation does not provide:

- Authentication or authorization decisions.
- Database uniqueness guarantees.
- Automatic Unicode normalization.
- Object/array container identity preservation for empty JSON structures.
- General-purpose regex, email or URL validation rules.
- Full multipart/form-upload validation.
- Automatic rate limiting or CORS permissions.
- Production long-running worker guarantees.

Maintain compatible body-size limits in the web server, PHP runtime, request factory and JSON parser.

Review high-member-count arrays and deeply nested payloads under realistic workload benchmarks.

## 10. Regression verification

Run:

```bash
composer analyse
composer check
git diff --check
```

Tests of particular relevance:

- `ValidatorTest`
- `ValidationProblemContractTest`
- `ValidationHttpProblemIntegrationTest`
- `NestedValidatorTest`
- `NestedValidationHttpIntegrationTest`
- `ExtendedRulesTest`
- `ExtendedValidationHttpIntegrationTest`
- `ValidatedJsonBodyParserTest`
- `ValidatedJsonEndpointIntegrationTest`
- `ValidationSecurityContractTest`

**Document status:** Draft, pending the Day 20 final regression and release-quality gates.