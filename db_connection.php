<?php

$host = 'sql201.ezyro.com';         // or your database host

$db   = 'ezyro_39028485_client_info'; // replace with your DB name

$user = 'ezyro_39028485';      // your MySQL username

$pass = 'pogiako09';      // your MySQL password

$charset = 'utf8mb4';



$conn = new mysqli($host, $user, $pass, $db);



// Connection check

if ($conn->connect_error) {

    die("Connection failed: " . $conn->connect_error);

}



$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [

    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throws exceptions on errors

    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // returns rows as associative arrays

    PDO::ATTR_EMULATE_PREPARES   => false,                  // use native prepared statements

];



try {

    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (\PDOException $e) {

    die("Database connection failed: " . $e->getMessage());

}

?><?php

$host = 'sql201.ezyro.com';         // or your database host

$db   = 'ezyro_39028485_client_info'; // replace with your DB name

$user = 'ezyro_39028485';      // your MySQL username

$pass = 'pogiako09';      // your MySQL password

$charset = 'utf8mb4';



$conn = new mysqli($host, $user, $pass, $db);



// Connection check

if ($conn->connect_error) {

    die("Connection failed: " . $conn->connect_error);

}



$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [

    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throws exceptions on errors

    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // returns rows as associative arrays

    PDO::ATTR_EMULATE_PREPARES   => false,                  // use native prepared statements

];



try {

    $pdo = new PDO($dsn, $user, $pass, $options);

} catch (\PDOException $e) {

    die("Database connection failed: " . $e->getMessage());

}

?>