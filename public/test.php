<?php
require_once __DIR__ . '/../vendor/autoload.php';
echo "Autoload OK<br>";

use MSHW\Proxy\Core\ProxyEngine;
use MSHW\Proxy\Core\CookieJar;

try {
    $jar = new CookieJar('test123');
    echo "CookieJar OK<br>";
    
    $engine = new ProxyEngine($jar);
    echo "ProxyEngine OK<br>";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage();
}
