<?php declare(strict_types=1);

/**
 * Bootstrap for Access module tests.
 *
 * Uses the Common module's test bootstrap for database setup and module installation.
 */

require dirname(__DIR__, 3) . '/modules/Common/test/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    ['Common', 'Access'],
    'AccessTest',
    __DIR__ . '/AccessTest'
);
