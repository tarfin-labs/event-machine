<?php

declare(strict_types=1);

// Prints the arguments this process received, as JSON. Used to observe what a shell
// actually hands a scheduled command after parsing the command line the scheduler built.
echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);
