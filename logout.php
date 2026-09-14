<?php
session_start();
session_unset();
session_destroy();

// Prevent browser from caching protected pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>

<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="refresh" content="0; url=index.html">
  <script>
    // ✅ Clear all sessionStorage flags like admin_logged_in
    sessionStorage.clear();
  </script>
</head>
<body>
  Logging out...
</body>
</html>
