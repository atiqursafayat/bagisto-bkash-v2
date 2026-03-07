<?php

namespace AtiqurSafayat\Bkash\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AtiqurSafayat\Bkash\Bkash
 */
class Bkash extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AtiqurSafayat\Bkash\Bkash::class;
    }
}
