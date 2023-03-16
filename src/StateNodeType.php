<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine;

enum StateNodeType: string
{
    /* no child state nodes **/
    case ATOMIC = 'atomic';

    /* nested child state nodes (XOR) */
    case COMPOUND = 'compound';

    /* orthogonal nested child state nodes (AND) */
    case PARALLEL = 'parallel';

    /* history state node  **/
    case FINAL = 'final';

    /* final state node **/
    case HISTORY = 'history';
}
