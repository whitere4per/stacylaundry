<?php
require_once __DIR__ . '/../app/customer_helpers.php';

if (isset($_SESSION['customer'])) {
    unset($_SESSION['customer']);
    session_regenerate_id(true);
}
bootMessage('success', 'You have been signed out of the Customer Portal.');
redirect('login.php');
