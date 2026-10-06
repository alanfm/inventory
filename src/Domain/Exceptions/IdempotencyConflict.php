<?php

namespace Acme\Inventory\Domain\Exceptions;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException {}
