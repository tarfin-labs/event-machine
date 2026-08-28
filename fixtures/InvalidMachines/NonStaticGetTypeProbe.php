<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

/**
 * A class that is not an event but satisfies `method_exists($class, 'getType')`.
 *
 * The scenario resolver used to accept the `event` CLI argument on exactly that basis, then
 * call `$event::getType()` — which raised `Non-static method cannot be called statically`
 * from inside the resolver. Requiring an EventBehavior subclass settles it.
 *
 * A public static marker records whether anything invoked this class, so a test can assert
 * that a stranger is never reached rather than only that the command exited cleanly.
 */
class NonStaticGetTypeProbe
{
    public static bool $touched = false;

    public function getType(): string
    {
        self::$touched = true;

        return 'PROBE_TOUCHED';
    }
}
