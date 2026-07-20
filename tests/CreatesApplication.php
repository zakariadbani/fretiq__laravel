<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Production uses MySQL-specific domain/e-mail collations and scalar
        // functions. Register compatible SQLite equivalents when the suite is
        // deliberately run against an isolated in-memory database.
        $connection = $app->make('db')->connection();

        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            $pdo->sqliteCreateCollation(
                'ascii_general_ci',
                static fn (string $left, string $right): int => strcasecmp($left, $right),
            );
            $pdo->sqliteCreateFunction('GREATEST', static fn (...$values) => max($values), -1);
            $pdo->sqliteCreateFunction('LEAST', static fn (...$values) => min($values), -1);
        }

        return $app;
    }
}
