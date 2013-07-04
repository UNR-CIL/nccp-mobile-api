
<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

// Raw calls from the NCCP API - Data service
// Note that most of these tend to be REALLY SLOW, so they generally
// shouldn't be called publicly, use the regular API class instead
// This API assumes data is already populated from the database using api_raw_measurements calls

class Data extends CI_Controller {

	public function __construct () {

		parent::__construct();
		$this->load->database();

		date_default_timezone_set( 'America/Los_Angeles' );
	}

	// Search the NCCP database.  Note that this is very basic and isn't filtered - it just
	// return all data between start and end for the specified sensor.  It can only do this
	// 1000 records at a time, so be patient.
	// Params (in post):
	// sensor_ids (single or comma-separated)*
	// start (specified as Y-m-d H:i:s)*
	// end (specified as Y-m-d H:i:s)*
	// Example ajax request: $.post( '../nccp/index.php/data/get', { sensor_ids: 7, start: "2012-01-01", end: "2012-02-01" }, function ( response ) { console.log( response ) } )
	public function get () {
		// Load this here instead of the constructor because the SOAP client takes
		// a while to load
		$this->load->model( 'Api_data' );

		// Make sure we should be here
		if ( ! $this->input->post('sensor_ids') ) die( 'At least one sensor_id must be supplied.' );
		if ( ! $this->input->post('start') ) die( 'Start date and/or time must be specified.' );
		if ( ! $this->input->post('end') ) die( 'End date and/or time must be specified.' );

		// Set up sensors
		$sensors = explode( ',', str_ireplace( ' ', '', $this->input->post('sensor_ids') ) );

		// Start with blank data array.  This will be added to 1000 rows at a time until
		// the entire dataset is present
		$results = new stdClass();
		$results->result = array();		

		// Set up timekeeping - note that the END is always now, the START is at the end - <specified period>
		$start = new DateTime( $this->input->post('start') );
		$end = new DateTime( $this->input->post('end') );		

		// Get the number of results
		$results->num_results = $this->Api_data->NumberOfResults( $sensors, $start, $end );

		if ( $results->num_results > 0 ) {

			for ( $skip = 0; $skip < $results->num_results; $skip += 1000 ) {
				// Set the time limit before proceeding
				set_time_limit( 300 );

				// Fetch the data values
				$data = $this->Api_data->search( $sensors, $start, $end, $skip );

				if ( ! empty( $data ) )
					foreach ( $data as $row )
						$results->result[] += $row;
			}
		}

		// Return results if they exist or an error if not
		if ( ! empty( $results->result ) )
			echo json_encode( $results );
		else
			echo json_encode( array( "error" => "No results." ) );
	}

	// Update all available sensors since last updated date (or to specified period, whichever is longer)
	// Params:
	// period - how far back to update, specified in interval format (P6M, P2W, etc.)
	// Note the preferred way to do this is to call update_sensor_data.php directly from php
	public function update_all_sensors ( $period = null ) {
		// Make sure we should be here
		if ( ! ( $this->input->post('period') || $period ) ) die( 'Period must be specified' );
		$period = $period ? $period : $this->input->post( 'period' );

		// Get the sensors
		$query = $this->db->query( "SELECT * FROM ci_logical_sensor" );

		foreach ( $query->result() as $sensor ) {
			$this->update_sensor_data( $sensor->logical_sensor_id, $period );
			set_time_limit( 300 );
		}

		// Output success if we got this far
		echo json_encode( array( 'success' => 'sensors successfully updated' ) );
	}

	// Update sensor data of specific logical sensor - combined hourly and normal tables
	// Params:
	// sensor_id - single sensor ID
	// period - how far back to update, specified in interval format (P6M, P2W, etc.)
	public function update_sensor_data ( $sensor = null ) {
		$errors = array();

		// How many records should be processed at once
		// 1000 is simply the max the NCCP API will return, so no point going
		// higher than
		$num_to_process = 1000;

		// Make sure we should be here
		if ( ! ( $this->input->post('sensor_id') || $sensor ) ) die( 'Sensor id is required.' );

		$sensor_id = $sensor ? $sensor : $this->input->post('sensor_id');

		// Get the last data point timestamp
		$query = $this->db->query( sprintf(
			"SELECT * FROM ci_logical_sensor_data
			WHERE logical_sensor_id = %d ORDER BY `timestamp` DESC
			LIMIT 1",
			$sensor_id
		));

		// Set up timekeeping
		if ( $query->num_rows() > 0 ) {
			$start = new DateTime( $query->row()->timestamp );
			$end = new DateTime();

			// Adjust for timezone difference
			$start->add( new DateInterval( 'PT8H' ) );			
			$end->add( new DateInterval( 'PT8H' ) );
		} else {
			$errors[] = 'Could not retrieve last timestamp.';
		}

		// If start/end is cool, get the number of results from the API
		// and perform the data update
		if ( isset( $start ) && isset( $end ) ) {
			$this->load->model( 'Api_data' );
			
			// Figure out how much data there is
			$num_results = $this->Api_data->NumberOfResults( array( $sensor_id ), $start, $end );

			$skip = 0;
			$total = 0;
			$hourly_total = 0;
			$data = array();

			// Grab information on the sensor
			$sensor_info = $this->db->query( sprintf(
				"SELECT * FROM ci_logical_sensor WHERE `logical_sensor_id` = %d",
				$sensor_id
			));
			$sensor_info = $sensor_info->row();			

			// Start time of processing
			$start_time = new DateTime();

			// Collect all the data from the query
			while ( $skip < $num_results ) {
				// Set the time limit before proceeding
				set_time_limit( 300 );

				// Now that we have that, fetch the data values
				$result = $this->Api_data->search( array( $sensor_id ), $start, $end, $skip, $num_to_process );

				// First collect all the data
				if ( ! empty( $result ) && is_array( $result ) ) $data = array_merge( $data, $result );

				// Fast forward
				$skip += $num_to_process;
			}

			// Process the data set now that we have all of it
			if ( ! empty( $data ) ) {
				$hourly_data = $this->get_hourly_data( $data, $sensor_info );

				$this->process_data_set( $data, 'ci_logical_sensor_data' );
				$this->process_data_set( $hourly_data, 'ci_logical_sensor_data_hourly' );

				$total += count( $data );
				$hourly_total += count( $hourly_data );					
			} else {
				echo json_encode( array( "warning" => "No data received on sensor " . $sensor_id ) );
			}								

			// If that succeeded, enter new timestamps into logical_sensor table, calculate processing time and output success
			// Calculate processing time
			$end_time = new DateTime();
			$difference = $start_time->diff( $end_time );

			// And echo the result
			echo json_encode( array(
				'sensor' 			=> $sensor_id,
				'success' 			=> $hourly_total . " hourly entries/" . $total . " normal entries entered successfully.",
				'time_elapsed' 		=> $difference->format( '%h:%i:%s' ),
				'sensor_updated' 	=> $end_time->format( 'Y-m-d H:i:s' )
			));		
		} else {
			$errors[] = 'Sensor ' . $sensor_id . ' could not be updated.';
		}

		// Return errors if there were any
		if ( ! empty( $errors ) ) echo json_encode( array( 'error' => $errors ) );

		// Update the updated time even if the sensor is empty so it doesn't thrash
		$this->db->query( sprintf(
			"UPDATE ci_logical_sensor SET `sensor_updated` = '%s' WHERE `logical_sensor_id` = %d",
			date( 'Y-m-d H:i:s' ),
			$sensor_id
		));
	}

	// Get the number of results for a sensor(s) and a specified period
	// Params:
	// sensor_ids - single or comma-separated list*
	// start (specified as Y-m-d H:i:s)*
	// end (specified as Y-m-d H:i:s)*
	// Sample ajax query: $.post( '../nccp/index.php/data/num_results', { sensor_ids: "1734, 2", start: "2012-01-01", end: "2012-02-01" }, function ( response ) { console.log( response ) } )
	public function num_results () {
		// Make sure we should be here
		if ( ! $this->input->post('sensor_ids') ) die( 'At least one sensor_id must be supplied.' );
		if ( ! $this->input->post('start') ) die( 'Start date and/or time must be specified.' );
		if ( ! $this->input->post('end') ) die( 'End date and/or time must be specified.' );

		// Set up sensors
		$sensors = explode( ',', str_ireplace( ' ', '', $this->input->post('sensor_ids') ) );

		// Set up timekeeping - note that the END is always now, the START is at the end - <specified period>
		$start = new DateTime( $this->input->post('start') );
		$end = new DateTime( $this->input->post('end') );

		// Get the number of results
		echo json_encode( array( 'num_results' => $this->Api_data->NumberOfResults( $sensors, $start, $end ) ) );
	}

	private function process_data_set( &$data, $table ) {
		if ( count( $data ) > 0 ) {
			// Compound insert statements
			$sql = sprintf( "INSERT IGNORE INTO %s VALUES ", $table );

			foreach ( $data as $index => $row ) {
				// Create timestamp from row timestamp
				$date = new DateTime( $row->TimeStamp );

				if ( isset( $row->LogicalSensorId ) && $row->LogicalSensorId > 0 ) { // This should never be 0.  EVER.  >=(
					$sql .= sprintf(
						"( %d, '%s', %d, %.18f )",
						$row->LogicalSensorId,
						$row->TimeStamp,
						$date->getTimestamp(),
						$row->Value				
					);
				} else {
					echo json_encode( array( 'warning' => 'No data for sensor on index ' . $index, 'data' => $data ) );
				}					

				if ( $index != ( count( $data ) - 1 ) )
					$sql .= ',';
			}	

			$this->db->query( $sql );	
		}
	}

	private function get_hourly_data ( &$data, $sensor_info ) {
		$hourly_data = array();

		// Calculate divider based on sensor interval
		// (Only applies to sensors with interval < 1 hour)
		switch ( $sensor_info->interval ) {
			case 'PT1M': $divider = 60; break;
			case 'PT10M': $divider = 6; break;
			case 'PT30M': $divider = 2; break;
		}

		// Align on the top of the hour if necessary
		if ( $sensor_info->interval != 'PT1H' ) {
			$align = new DateTime( $data[0]->TimeStamp );
			$offset = $divider - ( (int)$align->format( 'i' ) / ( 60 / $divider ) );	
		} else {
			$offset = 0;
			$divider = 1;
		}					

		for ( $i = $offset; $i < count( $data ); $i += $divider ) $hourly_data[] = $data[$i];

		return $hourly_data;
	}

	// Return database parameters, like when the last time sensor list was updated
	// or the last time the data was updated
	public function get_parameters () {
		$params = array(); // Empty to start

		$query = $this->db->query( "SELECT * FROM ci_parameters" );

		// Remove the fluff
		array_map( function ( $p ) use ( &$params ) { $params[$p->parameter] = $p->value; }, $query->result() );

		// Output params or appropriate error
		echo json_encode( ! empty( $params ) ? $params : array( 'error' => 'Database parameters could not be fetched.' ) );
	}

	// Set a database parameter (if it exists)
	public function set_parameter ( $parameter = null, $value = null ) {
		// Make sure we should be here
		if ( ! ( $this->input->post('parameter') || $parameter ) ) die( 'Must send parameter name.' );
		if ( ! ( $this->input->post('value') || $value ) ) die( 'Must send value.' );

		// Set up the parameters
		$parameter = $parameter ? $parameter : $this->input->post('parameter');
		$value = $value ? $value : $this->input->post('value');

		return $this->db->query( sprintf( "UPDATE ci_parameters SET value = '%s' WHERE parameter = '%s'", $value, $parameter ) );
	}

}