<?php

// 设置时区
if (!ini_get('date.timezone')) {
    date_default_timezone_set('PRC');
}

// 定义dev mode
if (defined('DEV_MODE')) {

	ini_set("display_errors", "On");
	error_reporting(E_ALL);

	if (isset($_GET['phpinfo'])) {
	    phpinfo();
	    exit;
	}
	
}
