protected $middleware = [
    \App\Http\Middleware\TrustProxies::class,
    \App\Http\Middleware\Cors::class, // ADD THIS LINE
    \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
    \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
];
