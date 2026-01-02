<?php

use App\Http\Controllers\DialogflowSyncController;

Route::match(['get', 'post'], '/dialogflow/sync', [DialogflowSyncController::class, 'sync']);
