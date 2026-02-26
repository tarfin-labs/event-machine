<?php

config()->set('database.connections.testing', [
    'driver'   => 'sqlite',
    'database' => ':memory:',
]);

$migration = include __DIR__.'/../database/migrations/create_machine_events_table.php.stub';
$migration->up();

$archiveMigration = include __DIR__.'/../database/migrations/create_machine_events_archive_table.php.stub';
$archiveMigration->up();
