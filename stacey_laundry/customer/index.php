<?php
require_once __DIR__ . '/../app/customer_helpers.php';
if (currentCustomer()) {
    redirect('dashboard.php');
}
redirect('login.php');
