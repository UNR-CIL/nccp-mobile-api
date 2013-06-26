<?php

// Standalone script for updating logical sensor data - simply issues
// curl requests for each sensor to the API
// The list of sensors is updated separately with update_sensors.php

// Set the URLs
define( 'BASE', 'http://nccp.local/nccp/index.php/' ); // Local
//define( 'BASE', 'http://nccp.monterey-j.com/nccp/index.php/' ); // Live

define( 'GET_SENSORS_URL', BASE . "measurements/get_sensors" );
define( 'GET_NUM_RESULTS', BASE . "data/num_results" );
define( 'UPDATE_SENSOR_DATA_URL', BASE . "data/update_sensor_data_bulk" );
define( 'DATABASE_PARAMS', BASE . "data/get_parameters" );

$ch = curl_init(); 

// Set curl params
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

// Extend these a bit just in case 
curl_setopt( $ch, CURLOPT_TIMEOUT, 600 );  
set_time_limit( 600 );

// Get database parameters
$params = get_database_params( $ch );
print_r( $params );
exit();

// Create the start and end dates from the parameters - end is assumed to be now


// Get the current sensors
$sensors = get_sensor_list();

// Put the keys together for transport
for ( $i = 0; $i < count( $sensors ); $i += 1000 ) {

	// Get the list of sensor IDs
	$sensor_ids = get_sensor_ids( $sensors, $i );

	// Retrieve the number of results for the sensor ID string
	$num_results = get_num_results( $sensor_ids ); 

	echo $num_results;
}


// Update the sensor data
if ( ! empty( $sensors ) ) {

	foreach ( $sensors as $sensor )
		// Update sensor for at least the specific period.  Note that if a last_timestamp is present
		// in the sensor table, it will just update since that date instead of a whole month
		echo update_sensor( $ch, $sensor->Id, 'P1M' );		

} else

	echo json_encode( array( 'error' => 'Could not retrieve the list of sensors.' ) );

// Close curl out and we're done
curl_close($ch);

function get_num_results ( &$ch, &$sensor_ids, $start, $end ) {

	curl_setopt( $ch, CURLOPT_URL, GET_NUM_RESULTS );

	$fields = array(
	            'sensor_ids' => urlencode( $sensor_ids ),
	            'start' => urlencode( $start ),
	            'end' => urlencode( $end )
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

function get_sensor_ids ( &$sensors, $i ) {

	$output = '';

	array_map( function ( $v ) use ( &$output ) { $output .= $v->Id . ","; }, array_slice( $sensors, $i, 1000 ) );
	
	return rtrim( $output, ',' );

}

function get_sensor_list ( &$ch ) {

	curl_setopt( $ch, CURLOPT_URL, GET_SENSORS_URL ); 

	// Get the list of sensors
	$output = curl_exec( $ch );
	return json_decode( $output );

}

function get_database_params ( &$ch ) {

	curl_setopt( $ch, CURLOPT_URL, DATABASE_PARAMS ); 

	// Get the current database parameters
	$output = curl_exec( $ch );
	return json_decode( $output );

}

function update_sensor ( &$ch, $id, $period ) {

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