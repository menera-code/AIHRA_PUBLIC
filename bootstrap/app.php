<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Fruitcake\Cors\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Add CORS middleware globally
        $middleware->append(HandleCors::class);
        
        // Or add to specific middleware groups
        $middleware->web(append: [
            // HandleCors::class, // For web routes
        ]);
        
        $middleware->api(prepend: [
            HandleCors::class, // For API routes
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
