# tools/openapi

Generates `packages/api-types` from the platform's OpenAPI spec. Run after any change to
`apps/platform/routes/v1.php` or its `Http/Resources/` so web and mobile stay in sync with
the API contract.
