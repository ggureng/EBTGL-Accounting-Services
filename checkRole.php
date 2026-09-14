<?php
session_start();
echo json_encode([
  "role" => $_SESSION['role'] ?? 'user'
]);
<?php
session_start();
echo json_encode([
  "role" => $_SESSION['role'] ?? 'user'
]);
