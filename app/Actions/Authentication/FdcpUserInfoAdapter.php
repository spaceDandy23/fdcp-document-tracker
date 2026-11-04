<?php

namespace App\Actions\Authentication;

class FdcpUserInfoAdapter
{
    /**
     * @param  array<string, mixed>  $claims
     * @return array{
     *     user_id: string,
     *     name: string,
     *     email: string,
     *     active: bool,
     *     division_code: string,
     *     is_division_head: bool,
     *     is_administrator: bool
     * }
     */
    public function map(array $claims): array
    {
        $role = data_get($claims, 'apps.document_tracking.role');

        if ($role === null) {
            throw new FdcpUserInfoException('apps.document_tracking.role', 'missing');
        }

        if (! is_string($role)) {
            throw new FdcpUserInfoException('apps.document_tracking.role', 'malformed');
        }

        [$isAdministrator, $isDivisionHead] = match ($role) {
            'admin' => [true, false],
            'division_head' => [false, true],
            'staff' => [false, false],
            default => throw new FdcpUserInfoException('apps.document_tracking.role', 'unsupported'),
        };

        return [
            'user_id' => $this->requiredString($claims, 'sub'),
            'name' => $this->requiredString($claims, 'name'),
            'email' => $this->email($claims),
            'active' => $this->requiredBoolean($claims, 'is_active'),
            'division_code' => $this->requiredString($claims, 'department'),
            'is_division_head' => $isDivisionHead,
            'is_administrator' => $isAdministrator,
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function requiredString(array $claims, string $path): string
    {
        $value = data_get($claims, $path);

        if ($value === null) {
            throw new FdcpUserInfoException($path, 'missing');
        }

        if (! is_string($value) || trim($value) === '') {
            throw new FdcpUserInfoException($path, 'malformed');
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function email(array $claims): string
    {
        $email = strtolower($this->requiredString($claims, 'email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new FdcpUserInfoException('email', 'invalid_format');
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function requiredBoolean(array $claims, string $path): bool
    {
        $value = data_get($claims, $path);

        if ($value === null) {
            throw new FdcpUserInfoException($path, 'missing');
        }

        if (is_bool($value)) {
            return $value;
        }

        throw new FdcpUserInfoException($path, 'malformed');
    }
}
