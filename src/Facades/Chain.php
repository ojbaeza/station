<?php

declare(strict_types=1);

namespace Station\Facades;

use Illuminate\Support\Facades\Facade;
use Station\Core\Chain as ChainClass;

/**
 * @method static ChainClass create(array<object> $jobs)
 *
 * @see ChainClass
 */
final class Chain extends Facade
{
    /**
     * Create a new chain instance.
     *
     * @param array<object> $jobs
     */
    public static function create(array $jobs): ChainClass
    {
        return new ChainClass($jobs);
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ChainClass::class;
    }
}
