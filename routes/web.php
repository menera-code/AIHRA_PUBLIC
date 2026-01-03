<?php

use App\Http\Controllers\DialogflowSyncController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::match(['get', 'post'], '/dialogflow/sync', [DialogflowSyncController::class, 'sync']);

Route::get('/test', function() {
    return response()->json(['message' => 'Laravel is working', 'time' => now()]);
});

Route::get('/test-composer', function() {
    // Test 1: Check if vendor/autoload.php exists
    $autoloadPath = base_path('vendor/autoload.php');
    $autoloadExists = file_exists($autoloadPath);
    
    // Test 2: Try to require it
    $autoloadLoaded = false;
    if ($autoloadExists) {
        try {
            require_once $autoloadPath;
            $autoloadLoaded = true;
        } catch (\Exception $e) {
            $autoloadLoaded = false;
        }
    }
    
    // Test 3: Check Dialogflow class
    $dialogflowExists = class_exists('Google\Cloud\Dialogflow\V2\IntentsClient');
    
    // Test 4: List installed Google packages
    $googleDir = base_path('vendor/google');
    $googlePackages = [];
    if (is_dir($googleDir)) {
        $googlePackages = array_diff(scandir($googleDir), ['.', '..']);
    }
    
    return response()->json([
        'autoload_file_exists' => $autoloadExists,
        'autoload_loaded' => $autoloadLoaded,
        'dialogflow_class_exists' => $dialogflowExists,
        'google_packages_installed' => $googlePackages,
        'current_dir' => getcwd(),
        'files_in_root' => array_diff(scandir(base_path()), ['.', '..'])
    ]);
});
