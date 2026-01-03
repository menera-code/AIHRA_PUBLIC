<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ✅ Use the built-in Laravel CORS middleware
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
        
        $middleware->web(prepend: [
            HandleCors::class,
        ]);
        
        // Or add it globally
        $middleware->appendToGroup('web', [
            HandleCors::class,
        ]);
        
        $middleware->appendToGroup('api', [
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
