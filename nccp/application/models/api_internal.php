<?php 
// API class for accessing API info from database.  
// Use raw_api to call the NCCP Web Services directly

if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Api_internal extends CI_Model {

	public function __construct () {
		
		parent::__construct();
		$this->load->database();

	}

	// Returns all time zones in the system
	public function get_time_zones () {

		return $this->return_results( $this->db->query( "SELECT * FROM ci_timezones" ) );	

	}

	// id is a string specifying the time zone (ex. 'Pacific Standard Time')
	// Returns an offset and various names for the time zone
	public function get_time_zone ( $id ) {

		return $this->return_results( $this->db->query( sprintf( 
			"SELECT * FROM ci_timezones WHERE `nccp_timezone_id` = '%s'",
			$id 
		)));

	}

	// Returns all current sensors in database
	// Note that this is probably a bad idea because there
	// are over 2000 sensors on average
	public function get_all_sensors () {

		return $this->return_results( $this->db->query( sprintf(
			"SELECT * FROM ci_logical_sensor"
		)));

	}

	// Returns sensors based on specified search properties
	public function get_sensors ( $args ) {
		
	}

	// Get single sensor based on specified search properties
	public function get_sensor ( $args ) {

	}

	// Get sensor deployment.  This includes multiple sensors
	// per deployment.
	public function get_deployment ( $deployment_id ) {

	}

	// Get sensor property based on search properties.  Note that property ID 
	// corresponds to multiple sensors
	public function get_property ( $args ) {

	}

	// Update the list of physical sensor locations.  This is used
	// to build map markers of these locations.
	public function get_sensor_locations () {

		// Get the locations
		$locations = $this->return_results( $this->db->query( 
			"SELECT `lat`, `lng`, `z_offset`, `altitude_offset` FROM ci_logical_sensor GROUP BY `lat`" 
		));

		// For each location, get which properties are available for the location
		foreach ( $locations as &$location ) {
			$location->properties = $this->return_results( $this->db->query( sprintf( 
				"SELECT * FROM 
				ci_logical_sensor AS s 
				JOIN ci_logical_sensor_relationships AS r ON r.logical_sensor_id = s.logical_sensor_id
				JOIN ci_logical_sensor_property AS p ON p.property_id = r.property_id
				WHERE lat = '%s' AND lng = '%s'
				GROUP BY p.property_id ORDER BY p.name ASC",
				$location->lat,
				$location->lng 
			)));
		}
		
		return $locations;

	}

	// Get data from a specified sensor in a specified period
	// Supported periods: year, month, week, day, hour, minute
	// Be careful with minute
	public function get_sensor_data ( $sensor_id, $period ) {

		// Set periods up
		switch ( $period ) {
			case 'year':
			case 'yearly':
				$skip = 525600;
			break;

			case 'month':
			case 'monthly':
				$skip = 43200;
			break;

			case 'week':
			case 'weekly':
				$skip = 10080;
			break;

			case 'day':
			case 'daily':
				$skip = 1440;
			break;

			case 'hour':
			case 'hourly':
			case 'default':
				$skip = 60;
			break;

			case 'minute':
			case 'minutely':
				$skip = null;
			break;
		}

		// Set period offset up
		$offset = 0;

		// Get the data
		if ( $skip ) {
			$sql = sprintf(
				"SELECT timestamp, value 
				FROM ( SELECT @row := @row +1 AS rownum, logical_sensor_id, timestamp, value
				    FROM ( SELECT @row :=0) r, ci_logical_sensor_data ) ranked 
				WHERE ( rownum + %d) %% %d = 1 AND logical_sensor_id = %d",
				$offset,
				$skip,
				$sensor_id
			);
		} else {
			$sql = sprintf(
				"SELECT `value`, `timestamp` FROM ci_logical_sensor_data WHERE logical_sensor_id = %d",
				$sensor_id
			);
		}

		return $this->return_results( $this->db->query( $sql ) );

	}

	// Checks query object for results and returns results if so or false if not
	private function return_results ( $query_object ) {

		return $query_object->num_rows() > 0 ? $query_object->result() : false;

		/*if ( $query_object->num_rows() > 0 )
			return $query_object->num_rows() == 1 ? $query_object->row() : $query_object->result();
		else
			return false;*/

	}

}
