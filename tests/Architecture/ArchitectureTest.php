<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'xr'])
    ->each->not->toBeUsed();

it('will use strict types for source files')
    ->expect('Tarfinlabs\EventMachine')
    ->toUseStrictTypes();
