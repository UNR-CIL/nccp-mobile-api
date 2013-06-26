#!/usr/local/bin/php -q

<?php

// Standalone script for updating the list of logical sensors
// This is separate from the actual sensor data update script because
// the list of sensors does not need to be updated as often

// Set the URLs
//define( 'BASE', 'http://nccp.local/nccp/index.php/' ); // Local
define( 'BASE', 'http://nccp.monterey-j.com/nccp/index.php/' ); // Live

define( 'UPDATE_SENSORS_URL', BASE . "measurements/update_sensors" );

$ch = curl_init(); 

// Set curl params
curl_setopt( $ch, CURLOPT_URL, UPDATE_SENSORS_URL ); 
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

// Extend these a bit just in case 
curl_setopt( $ch, CURLOPT_TIMEOUT, 300 );  
set_time_limit( 300 );

// Update the sensor list
echo curl_exec( $ch );

// Close curl out
curl_close($ch);
