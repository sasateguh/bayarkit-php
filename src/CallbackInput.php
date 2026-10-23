<?php

declare(strict_types=1);

namespace Bayarkit;

/**
 * A callback as it arrived. Some gateways sign fields in the body (toyyibPay); others sign the raw body in a header
 * (CHIP), so all three parts are kept. Laravel: `Bayarkit\Laravel\Callback::from($request)`; plain PHP: `fromGlobals()`.
 */
final readonly class CallbackInput
{
    /**
     * @param  array<string, string>  $fields  Text fields from the body, or from the query string for return URLs.
     * @param  array<string, string>  $headers  Header names in lower case.
     */
    public function __construct(
        public array $fields = [],
        public string $rawBody = '',
        public array $headers = [],
    ) {}

    /**
     * Build from a decoded array (e.g. the JSON that `bayarkit-fake` serves); non-string values are dropped.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::strings($data['fields'] ?? []),
            is_string($data['rawBody'] ?? null) ? $data['rawBody'] : '',
            array_change_key_case(self::strings($data['headers'] ?? []), CASE_LOWER),
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

    /** @return array<string, string> */
    private static function strings(mixed $values): array
    {
        $out = [];
        if (! is_array($values)) {
            return $out;
        }
        foreach ($values as $k => $v) {
            if (is_string($v) || is_int($v) || is_float($v)) {
                $out[(string) $k] = (string) $v;
            } elseif (is_bool($v)) {
                $out[(string) $k] = $v ? 'true' : 'false';
            }
        }

        return $out;
    }
}
