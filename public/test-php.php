<?php
echo "<h2>PHP Info</h2>";
phpinfo();

echo "<h2>Checking Dialogflow Installation</h2>";

// Check vendor directory
$vendorDir = __DIR__ . '/../vendor';
echo "Vendor directory exists: " . (is_dir($vendorDir) ? 'YES' : 'NO') . "<br>";

// Check autoload
$autoloadFile = $vendorDir . '/autoload.php';
echo "Autoload file exists: " . (file_exists($autoloadFile) ? 'YES' : 'NO') . "<br>";

if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
    echo "Autoload loaded successfully<br>";
    
    // Check Dialogflow
    echo "Dialogflow IntentsClient class exists: ";
    echo class_exists('Google\Cloud\Dialogflow\V2\IntentsClient') ? 'YES' : 'NO';
    echo "<br>";
    
    // List Google packages
    $googleDir = $vendorDir . '/google';
    if (is_dir($googleDir)) {
        echo "<h3>Installed Google packages:</h3>";
        $packages = array_diff(scandir($googleDir), ['.', '..']);
        foreach ($packages as $package) {
            echo "- $package<br>";
        }
    }
} else {
    echo "Cannot load autoload.php<br>";
    
    // List root directory
    echo "<h3>Files in root directory:</h3>";
    $rootFiles = array_diff(scandir(__DIR__ . '/..'), ['.', '..']);
    foreach ($rootFiles as $file) {
        echo "- $file<br>";
    }
}
