#!/usr/local/bin/php -q

<?php

// Standalone script for updating logical sensor data - simply issues
// curl requests for each sensor to the API
// The list of sensors is updated separately with update_sensors.php

// Set the URLs
//define( 'BASE', 'http://nccp.local/nccp/index.php/' ); // Local
define( 'BASE', 'http://nccp.monterey-j.com/nccp/index.php/' ); // Live

define( 'GET_SENSORS_URL', BASE . "measurements/get_sensors" );
define( 'UPDATE_SENSOR_DATA_URL', BASE . "data/update_sensor_data" );
define( 'SET_PARAMETER_URL', BASE . "data/set_parameter" );

$ch = curl_init(); 

// Set curl params
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

// Extend these a bit just in case 
curl_setopt( $ch, CURLOPT_TIMEOUT, 300 );
set_time_limit( 300 );

// Update the sensor_updated field
$now = new DateTime();
set_parameter( $ch, 'sensor_data_updated', $now->format( "Y-m-d H:i:s" ) );

// Get the list of sensors
curl_setopt( $ch, CURLOPT_URL, GET_SENSORS_URL );

$output = curl_exec( $ch );
$sensors = json_decode( $output );

// Update the sensor data
if ( ! empty( $sensors ) ) {

	foreach ( $sensors as $sensor ) {
		// Update sensor for at least the specific period.  Note that if a last_timestamp is present
		// in the sensor table, it will just update since that date instead of a whole month
		echo update_sensor( $ch, $sensor->Id, 'P1M' );
	}		

	// Update the sensor_updated field
	$now = new DateTime();
	set_parameter( $ch, 'sensor_data_updated', $now->format( "Y-m-d H:i:s" ) );

} else

	echo json_encode( array( 'error' => 'Could not retrieve the list of sensors.' ) );

// Close curl out and we're done
curl_close( $ch );

function set_parameter ( $ch, $parameter, $value ) {
	
	curl_setopt( $ch, CURLOPT_URL, SET_PARAMETER_URL );

	$fields = array(
        'parameter' => urlencode( $parameter ),
        'value' => urlencode( $value )
    );

	// Format POST variables
	$fields_string = '';
	foreach ( $fields as $key=>$value ) { $fields_string .= $key.'='.$value.'&'; }
	rtrim( $fields_string, '&' );

	curl_setopt( $ch, CURLOPT_POST, count( $fields ) );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );

	// Execute the query
	$output = curl_exec( $ch );

	// Reset the time limit
	set_time_limit( 300 );

	// Return the output
	return $output;

}

function update_sensor ( $ch, $id, $period ) {

	curl_setopt( $ch, CURLOPT_URL, UPDATE_SENSOR_DATA_URL );

	$fields = array(
        'sensor_id' => urlencode( $id ),
        'period' => urlencode( $period )
    );

	// Format POST variables
	$fields_string = '';
	foreach ( $fields as $key=>$value ) { $fields_string .= $key.'='.$value.'&'; }
	rtrim( $fields_string, '&' );

	curl_setopt( $ch, CURLOPT_POST, count( $fields ) );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );

	// Execute the query
	$output = curl_exec( $ch );

	// Reset the time limit
	set_time_limit( 300 );

	// Return the output
	return $output;

} 
