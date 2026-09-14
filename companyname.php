<?php
session_start();

if (isset($_SESSION['company_name']) && !empty($_SESSION['company_name'])) {
    echo $_SESSION['company_name'];
} else {
    echo "My Account";
}
?>
