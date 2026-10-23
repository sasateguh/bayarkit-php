<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * A callback as it arrived. Some gateways sign fields in the body (toyyibPay); others sign the raw body in a header
 * (CHIP), so all three parts are kept. Laravel: `Bayarkit\Laravel\Callback::from($request)`; plain PHP: `fromGlobals()`.
 */
final readonly class CallbackInput
{
    /** @var array<string, string> Text fields from the body, or from the query string for return URLs. */
    public array $fields;

    /** @var array<string, string> Header names in lower case. */
    public array $headers;

    /**
     * Non-text values (arrays from `$request->all()`, objects) are dropped here, so no adapter ever sees one: a
     * `verifyCallback` that must never throw cannot be handed something it cannot read.
     *
     * @param  array<mixed>  $fields
     * @param  array<mixed>  $headers
     */
    public function __construct(
        array $fields = [],
        public string $rawBody = '',
        array $headers = [],
    ) {
        $this->fields = self::strings($fields);
        $this->headers = array_change_key_case(self::strings($headers), CASE_LOWER);
    }

    /**
     * Build from a decoded array (e.g. the JSON that `bayarkit-fake` serves); non-string values are dropped.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_array($data['fields'] ?? null) ? $data['fields'] : [],
            is_string($data['rawBody'] ?? null) ? $data['rawBody'] : '',
            is_array($data['headers'] ?? null) ? $data['headers'] : [],
        );
    }

    /**
     * Read the current request in plain PHP. The body is read once as text (header-signed gateways need the exact
     * bytes); fields come from `$_POST` (url-encoded and multipart), a JSON body, or the query string when there is no
     * body.
     */
    public static function fromGlobals(): self
    {
        $raw = (string) file_get_contents('php://input');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with((string) $k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return new self(self::fieldsOf($headers['content-type'] ?? '', $raw, $_POST, $_GET), $raw, $headers);
    }

    /**
     * @param  array<mixed>  $parsedBody  form/multipart fields as the framework parsed them
     * @param  array<mixed>  $query
     * @return array<string, string>
     */
    public static function fieldsOf(string $contentType, string $raw, array $parsedBody, array $query): array
    {
        $fields = [];
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode($raw, true);
            $fields = is_array($json) && ! array_is_list($json) ? self::strings($json) : [];
        } elseif (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $fields = self::strings($parsedBody);
        }
        // Multipart bodies are not in php://input, so "no body" means no raw body AND no parsed fields.
        if ($raw === '' && $fields === []) {
            $fields = self::strings($query);
        }

        return $fields;
    }

    /**
     * Scalars as JavaScript's String() writes them (true → "true"), so the twins read the same text; the rest dropped.
     *
     * @return array<string, string>
     */
    public static function strings(mixed $values): array
    {
        $out = [];
        if (! is_array($values)) {
            return $out;
        }
        foreach ($values as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = Text::of($v);
            }
        }

        return $out;
    }
}
