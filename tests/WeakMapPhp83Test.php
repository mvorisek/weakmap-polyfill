<?php

declare(strict_types=1);

use Brick\WeakmapPolyfill\WeakMapPhp83;

class WeakMapPhp83Test extends WeakMapTest
{
    protected function createWeakMap() : object
    {
        return new WeakMapPhp83();
    }
}
