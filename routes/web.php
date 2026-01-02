<?php

use App\Http\Controllers\DialogflowSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['get', 'post'], '/dialogflow/sync', [DialogflowSyncController::class, 'sync']);

