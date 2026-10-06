<?php

namespace Acme\Inventory\Domain\Exceptions;

use RuntimeException;

final class InsufficientStock extends RuntimeException
{
    public function __construct(public readonly int $variantId, public readonly int $available, public readonly int $requested)
    {
        parent::__construct("Insufficient stock for variant {$variantId}.");
    }
}
