<?php

config()->set('database.connections.testing', [
    'driver'   => 'sqlite',
    'database' => ':memory:',
]);

$migration = include __DIR__.'/../database/migrations/create_machine_events_table.php.stub';
$migration->up();

$archiveMigration = include __DIR__.'/../database/migrations/create_machine_events_archive_table.php.stub';
$archiveMigration->up();

$childrenMigration = include __DIR__.'/../database/migrations/create_machine_children_table.php.stub';
$childrenMigration->up();

$currentStatesMigration = include __DIR__.'/../database/migrations/create_machine_current_states_table.php.stub';
$currentStatesMigration->up();

$timerFiresMigration = include __DIR__.'/../database/migrations/create_machine_timer_fires_table.php.stub';
$timerFiresMigration->up();
