<?php

header("Cache-Control: no-store");
header("Content-Type: text/event-stream");

function find_wordpress_base_path() {
    $dir = dirname(__FILE__);
    do {
        //it is possible to check for other files here
        if( file_exists($dir."/wp-config.php") ) {
            return $dir;
        }
    } while( $dir = realpath("$dir/..") );
    return null;
}

define( 'BASE_PATH', find_wordpress_base_path()."/" );
define('WP_USE_THEMES', false);

require(BASE_PATH . 'wp-load.php');
require_once plugin_dir_path(__FILE__) . '../../vindi.php';

$vindi = WC_Vindi_Payment::get_instance(false);

while (true) {
    echo "\n\n";
    
    $bill = $vindi->settings->routes->findBillById($_GET['id']);

    // Send a simple message at random intervals.
    echo 'data: ' . (!empty($bill) ? $bill['status'] : 'pending')  . "\n\n";

    ob_end_flush();
    flush();

    // Break the loop if the client aborted the connection (closed the page)
    if(connection_aborted() || (!empty($bill) && $bill['status'] == 'paid')) 
        break;

    sleep(5);
}
