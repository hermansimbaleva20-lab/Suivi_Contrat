<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Providers\AppServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        AppServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

  ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'project' => \App\Http\Middleware\ProjectContext::class,
            'permission' => \App\Http\Middleware\EnsureProjectPermission::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();



