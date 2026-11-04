<?php

namespace App\Actions\Authentication;

use DomainException;

class FdcpUserInfoException extends DomainException
{
    public function __construct(
        public readonly string $field,
        public readonly string $category,
    ) {
        parent::__construct("FDCP userinfo rejected {$field}: {$category}.");
    }
}
