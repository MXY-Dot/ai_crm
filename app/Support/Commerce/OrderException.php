<?php

namespace App\Support\Commerce;

use RuntimeException;

/** Thrown for a customer-facing order problem (out of stock, invalid transition, etc.) -- controllers turn this into a real validation error, never a 500. */
class OrderException extends RuntimeException
{
}
