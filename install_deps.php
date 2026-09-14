<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
echo "Error reporting enabled";
echo shell_exec('php composer.phar install 2>&1');
echo "Dependencies installed!";