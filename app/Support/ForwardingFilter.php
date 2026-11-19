<?php

namespace App\Support;

final class ForwardingFilter
{
    public static function matches(array $filter, string $method, array $headers, string $body): bool
    {
        if (isset($filter['method']) && $filter['method'] !== $method) {
            return false;
        }

        if (isset($filter['header'])) {
            $name = strtolower($filter['header']);
            // Match only inspectable headers; never route based on redacted secrets.
            if (preg_match('/authorization|cookie|token|secret|api[-_]key|signature/i', $name)
                || ! in_array($filter['header_value'], $headers[$name] ?? [], true)) {
                return false;
            }
        }

        if (! isset($filter['json_path'])) {
            return true;
        }

        $value = json_decode($body, true, 64);
        foreach (explode('.', $filter['json_path']) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return $value === $filter['json_value'];
    }
}
