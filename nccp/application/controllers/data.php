
<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

// Raw calls from the NCCP API - Data service
// Note that most of these tend to be REALLY SLOW, so they generally
// shouldn't be called publicly, use the regular API class instead
// This API assumes data is already populated from the database using api_raw_measurements calls

class Data extends CI_Controller {

	public function __construct () {

		parent::__construct();
		$this->load->model( 'Api_data' );
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

	// Update sensor data of specific logical sensor - combined tables
	// Params:
	// sensor_id - single sensor ID
	// period - how far back to update, specified in interval format (P6M, P2W, etc.)
	public function update_sensor_data_combined ( $sensor = null ) {

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
			die( 'Could not retrieve last timestamp.' );
		}

		// If start/end is cool, get the number of results from the API
		// and perform the data update
		if ( isset( $start ) && isset( $end ) ) {
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
				if ( ! empty( $result ) ) $data = array_merge( $data, $result );

				// Fast forward
				$skip += $num_to_process;
			}

			// Process the data set now that we have all of it
			if ( ! empty( $data ) ) {
				$hourly_data = $this->get_hourly_data( $data, $sensor_info );

				$this->process_data_set_combined( $data, 'ci_logical_sensor_data' );
				$this->process_data_set_combined( $hourly_data, 'ci_logical_sensor_data_hourly' );

				$total += count( $data );
				$hourly_total += count( $hourly_data );					
			} else {
				echo json_encode( array( "warning" => "No data received on sensor " . $sensor_id ) );
			}								

			// If that succeeded, enter new timestamps into logical_sensor table, calculate processing time and output success
			// Calculate processing time
			$end_time = new DateTime();
			$difference = $start_time->diff( $end_time );

			// Update the updated time
			$this->db->query( sprintf(
				"UPDATE ci_logical_sensor SET `sensor_updated` = '%s' WHERE `logical_sensor_id` = %d",
				$end_time->format( 'Y-m-d H:i:s' ),
				$sensor_id
			));

			// And echo the result
			echo json_encode( array(
				'sensor' 			=> $sensor_id,
				'success' 			=> $hourly_total . " hourly entries/" . $total . " normal entries entered successfully.",
				'time_elapsed' 		=> $difference->format( '%h:%i:%s' ),
				'sensor_updated' 	=> $end_time->format( 'Y-m-d H:i:s' )
			));		
		} else {
			die( json_encode( array( 'error' => 'Sensor ' . $sensor_id . ' could not be updated.' ) ) );
		}
	}

	// Update sensor data of specific logical sensor - hourly table
	// Params:
	// sensor_id - single sensor ID
	// period - how far back to update, specified in interval format (P6M, P2W, etc.)
	public function update_sensor_data_hourly ( $sensor = null, $period = null ) {

		// How many records should be processed at once
		// 1000 is simply the max the NCCP API will return, so no point going
		// higher than
		$num_to_process = 1000;

		// Make sure we should be here
		if ( ! ( $this->input->post('sensor_id') || $sensor ) ) die( 'Sensor id is required.' );
		if ( ! ( $this->input->post('period') || $period ) ) die( 'Period must be specified' );

		$sensor_id = $sensor ? $sensor : $this->input->post('sensor_id');
		$period = $period ? $period : $this->input->post( 'period' );

		// This is a special period that just means all data from the last available
		// data point
		if ( $period == 'update' ) {
			$query = $this->db->query( sprintf(
				"SELECT * FROM ci_logical_sensor_data_hourly
				WHERE logical_sensor_id = %d ORDER BY `timestamp` DESC
				LIMIT 1",
				$sensor_id
			));

			if ( $query->num_rows() > 0 ) {
				$last = new DateTime( $query->row()->timestamp );
				$now = new DateTime();
				$difference = $now->diff( $last->sub( new DateInterval( 'P1D' ) ) ); // A day's overlap
				$period = $difference->format( 'P%yY%mM%dD' );
			} else { // If there's nothing, there's no data for that sensor, so grab everything
				$period = 'P5Y';
			}
		}

		// Set up timekeeping - note that the END is always now, the START is at the end minus <specified period>
		$end = new DateTime();
		$end->add( new DateInterval( 'PT8H' ) ); // Adjust for timezone difference
		$start = clone $end;
		$start->sub( new DateInterval( $period ) );		
		$start->sub( new DateInterval( 'PT8H' ) ); // Adjust for timezone difference

		// If start/end is cool, get the number of results from the API
		// and perform the data update
		if ( isset( $start ) && isset( $end ) ) {

			// Figure out how much data there is
			$num_results = $this->Api_data->NumberOfResults( array( $sensor_id ), $start, $end );

			$skip = 0;
			$total = 0;

			// Start time of processing
			$start_time = new DateTime();

			// Grab information on the sensor
			$sensor_info = $this->db->query( sprintf(
				"SELECT * FROM ci_logical_sensor_hourly WHERE `logical_sensor_id` = %d",
				$sensor_id
			));
			$sensor_info = $sensor_info->row();

			// Set the pending flag
			$this->db->query( sprintf(
				"UPDATE ci_logical_sensor_hourly SET `pending` = 1 WHERE `logical_sensor_id` = %d",
				$sensor_id
			));

			while ( $skip < $num_results ) {
				// Set the time limit before proceeding
				set_time_limit( 300 );

				// Now that we have that, fetch the data values
				$data = $this->Api_data->search( array( $sensor_id ), $start, $end, $skip, $num_to_process );

				// If the data exists, enter it into the database
				if ( ! empty( $data ) ) {
					$final_data = array();

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

					for ( $i = $offset; $i < count( $data ); $i += $divider ) {
						$final_data[] = $data[$i];
					}

					$this->process_data_set_hourly( $final_data );	
					$total += count( $final_data );
				} else {
					echo json_encode( array( "warning" => "No data received on sensor " . $sensor_id ) );
				}					

				// Fast forward
				$skip += $num_to_process;
			}					

			// If that succeeded, enter new timestamps into logical_sensor table, calculate processing time and output success

			// Calculate processing time
			$end_time = new DateTime();

			$difference = $start_time->diff( $end_time );

			// Update the updated time
			$this->db->query( sprintf(
				"UPDATE ci_logical_sensor_hourly SET `sensor_updated` = '%s', `pending` = 0 WHERE `logical_sensor_id` = %d",
				$end_time->format( 'Y-m-d H:i:s' ),
				$sensor_id
			));

			echo json_encode( array(
				'sensor' => $sensor_id,
				'success' => $total . " entries entered successfully.",
				'time_elapsed' => $difference->format( '%h:%i:%s' )
			));		
		}
	}

	// Update sensor data of specific logical sensor
	// Params:
	// sensor_id - single sensor ID
	// period - how far back to update, specified in interval format (P6M, P2W, etc.)
	public function update_sensor_data ( $sensor = null, $period = null ) {

		// How many records should be processed at once
		// 1000 is simply the max the NCCP API will return, so no point going
		// higher than
		$num_to_process = 1000;

		// Make sure we should be here
		if ( ! ( $this->input->post('sensor_id') || $sensor ) ) die( 'Sensor id is required.' );
		if ( ! ( $this->input->post('period') || $period ) ) die( 'Period must be specified' );

		$sensor_id = $sensor ? $sensor : $this->input->post('sensor_id');
		$period = $period ? $period : $this->input->post( 'period' );

		// Set up timekeeping - note that the END is always now, the START is at the end minus <specified period>
		$end = new DateTime();
		$end->add( new DateInterval( 'PT8H' ) ); // Adjust for timezone difference
		$start = clone $end;
		$start->sub( new DateInterval( $period ) );

		// Get the last date the sensor was updated and see if that period is shorter than the specified one
		$query = $this->db->query( sprintf(
			"SELECT * FROM ci_logical_sensor WHERE logical_sensor_id = %d AND last_timestamp IS NOT NULL",
			$sensor_id
		));

		// If a last updated date exists, use that as the start instead of the period
		if ( $query->num_rows() > 0 ) {
			$start = new DateTime( $query->row()->last_timestamp );	
			$start->add( new DateInterval( 'PT8H' ) ); // Adjust for timezone difference
		}				

		// If start/end is cool, get the number of results from the API
		// and perform the data update
		if ( isset( $start ) && isset( $end ) ) {

			// Figure out how much data there is
			$num_results = $this->Api_data->NumberOfResults( array( $sensor_id ), $start, $end );

			$skip = 0;

			// Start time of processing
			$start_time = new DateTime();

			while ( $skip < $num_results ) {
				// Set the time limit before proceeding
				set_time_limit( 300 );

				// Now that we have that, fetch the data values
				$data = $this->Api_data->search( array( $sensor_id ), $start, $end, $skip, $num_to_process );

				// If the data exists, enter it into the database
				if ( ! empty( $data ) ) {
					$this->process_data_set( $data, $num_results, $skip, $num_to_process );
				} else {
					echo json_encode( array( "warning" => "No data received on sensor " . $sensor_id ) );
				}					

				// Fast forward
				$skip += $num_to_process;
			}					

			// If that succeeded, enter new timestamps into logical_sensor table, calculate processing time and output success

			// Calculate processing time
			$end_time = new DateTime();

			$difference = $start_time->diff( $end_time );

			// Update the sensor_updated field
			$this->db->query( sprintf( 
				"UPDATE ci_logical_sensor SET sensor_updated = '%s' WHERE logical_sensor_id = %d", 
				$end_time->format( "Y-m-d H:i:s" ), 
				$sensor_id
			));

			echo json_encode( array(
				'sensor' => $sensor_id,
				'success' => $num_results . " entries entered successfully.",
				'time_elapsed' => $difference->format( '%h:%i:%s' )
			));		
		}
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

	private function process_data_set_combined( &$data, $table ) {
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

	// Enter the provided data into the database - hourly table
	private function process_data_set_hourly ( &$data ) {	

		if ( count( $data ) > 0 ) {
			// Compound insert statements
			$sql = "INSERT IGNORE INTO ci_logical_sensor_data_hourly VALUES ";	

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

	// Enter the provided data into the database
	private function process_data_set ( &$data, $num_results, $skip, $num_to_process ) {	

		// Compound insert statements
		$sql = "INSERT IGNORE INTO ci_logical_sensor_data VALUES ";	

		// If only a single record exists, the NCCP API will return a single row instead of
		// an array and screw all this up (IT MAKES PERFECT SENSE).
		if ( is_array( $data ) ) {

			foreach ( $data as $index => $row ) {
				
				// Create timestamp from row timestamp
				$date = new DateTime( $row->TimeStamp );
			
				// If this is the first record and no first timestamp exists, set the first timestamp
				if ( $index == 0 && $skip == 0 ) {
					$query = $this->db->query( sprintf( 
						"SELECT * FROM ci_logical_sensor WHERE logical_sensor_id = %d AND first_timestamp IS NOT NULL",
						$row->LogicalSensorId
					));	
					if ( $query->num_rows() == 0 )
						$this->db->query( sprintf(
							"UPDATE ci_logical_sensor SET `first_timestamp` = '%s', `first_unix_timestamp` = %d WHERE `logical_sensor_id` = %d",
							$row->TimeStamp,
							$date->getTimestamp(),
							$row->LogicalSensorId
						));			
				}

				// If the the last record, update the last updated record
				if ( ( $skip + $num_to_process >= $num_results ) && ( $index == count( $data ) - 1 ) )
					$this->db->query( sprintf(
						"UPDATE ci_logical_sensor SET `last_timestamp` = '%s', `last_unix_timestamp` = %d WHERE `logical_sensor_id` = %d",
						$row->TimeStamp,
						$date->getTimestamp(),
						$row->LogicalSensorId
					));

				if ( isset( $row->LogicalSensorId ) && $row->LogicalSensorId > 0 ) // This should never be 0.  EVER.  >=(
					$sql .= sprintf(
						"( %d, '%s', %d, %.18f )",
						$row->LogicalSensorId,
						$row->TimeStamp,
						$date->getTimestamp(),
						$row->Value				
					);
				else
					echo json_encode( array( 'warning' => 'No data for sensor on index ' . $index, 'data' => $data ) );

				if ( $index != ( count( $data ) - 1 ) )
					$sql .= ',';
			}

		} else {
			
			$date = new DateTime( $data->TimeStamp );

			// This is a single record so it's last by default
			$this->db->query( sprintf(
				"UPDATE ci_logical_sensor SET `last_timestamp` = '%s', `last_unix_timestamp` = %d WHERE `logical_sensor_id` = %d",
				$data->TimeStamp,
				$date->getTimestamp(),
				$data->LogicalSensorId
			));

			$sql .= sprintf(
				"( %d, '%s', %d, %.18f )",
				$data->LogicalSensorId,
				$data->TimeStamp,
				$date->getTimestamp(),
				$data->Value				
			);
				
	 	}		

		$this->db->query( $sql );

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

	// Formats DateTime object into useable string, either
	// in standard mysql or asp formats
	private function format_date ( $date, $type = 'asp' ) {

		return $type == 'asp' ? $date->format( "Y-m-d\TH:i:s" ) : $date->format( "Y-m-d H:i:s" );

	}	

}
