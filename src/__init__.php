<?php


if (!ini_get('date.timezone')) {
    date_default_timezone_set('PRC');
}

if (isset($_GET['phpinfo'])) {
    phpinfo();
    exit;
}