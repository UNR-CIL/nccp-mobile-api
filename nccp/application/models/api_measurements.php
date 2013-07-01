<?php

// Raw calls from the NCCP API - Measurements service
// Note that most of these tend to be slow, so they generally
// shouldn't be called publicly, use the regular API class instead

class Api_measurements extends CI_Model {

	private $measurements_client;

	public function __construct () {
		parent::__construct();
		$this->load->database();

		$path = 'http://sensor.nevada.edu/Services/Measurements/Measurement.svc?wsdl'; // Live

		$this->measurements_client = new SoapClient( $path );
	}

	// Update the database with the current list of sensors
	public function update_sensors () {
		// Save the current logical sensor table to keep timestamp times
		$query = $this->db->query( 'SELECT * FROM ci_logical_sensor' );
		$old_sensors = array();

		// Key the old sensors by sensor ID instead of db ID
		foreach ( $query->result() as $row ) {
			$old_sensors[$row->logical_sensor_id] = $row;
		}

		$sensors = $this->get_sensors();

		// Truncate relational data before continuing
		$this->db->query( "TRUNCATE TABLE ci_logical_sensor_deployment" );
		$this->db->query( "TRUNCATE TABLE ci_logical_sensor_property" );
		$this->db->query( "TRUNCATE TABLE ci_logical_sensor_types" );
		$this->db->query( "TRUNCATE TABLE ci_logical_sensor_units" );

		if ( ! empty( $sensors ) ) {
			foreach ( $sensors as $sensor ) {
				if ( array_key_exists( $sensor->Id, $old_sensors ) ) {
					// First deal with sensors that already exist
					$result = $this->update_sensor( $sensor );
				} else {
					// Otherwise this is a new sensor
					$result = $this->insert_new_sensor( $sensor );
				}

				// Update relational data from the sensor
				$this->update_sensor_relationships( $sensor );

				// Output result and update the time limit
				echo json_encode( $result );
				set_time_limit( 300 );
			}

			// Now do this in reverse and check for old sensors not in the list of new sensors
			foreach ( array_keys( $old_sensors ) as $sensor ) {
				$in_array = array_filter( $sensors, function ( $var ) use ( $sensor ) { return $var->Id == $sensor; } );

				if ( empty( $in_array ) ) {
					// Set the sensor as inactive
					$this->db->query( sprintf( "UPDATE ci_logical_sensor SET active = 0 WHERE logical_sensor_id = %d", $sensor ) );
				}
			}

			// Update the sensor_updated field
			$now = new DateTime();
			$this->db->query( sprintf( "UPDATE ci_parameters SET value = '%s' WHERE parameter = 'sensor_list_updated'", $now->format( "Y-m-d H:i:s" ) ) );

			return array( 'success' => 'Sensors successfully updated.' );
		} else {
			return array( 'error' => 'Old sensor list could not be fetched.' );
		}			
	}

	private function update_sensor( $sensor ) {
		// Format the sensor coordinates
		preg_match( "/\((.+)\s(.+)\s(.+)\)/i", $sensor->LocationWkt, $coords );

		// Main sensor table
		$this->db->query( sprintf(
			"UPDATE ci_logical_sensor SET `interval` = '%s', lat = '%s', lng = '%s', z_offset = '%s', altitude_offset = '%s', active = 1 WHERE logical_sensor_id = %d",
			$sensor->MeasurementInterval,
			$coords[2],
			$coords[1],
			$coords[3],
			$sensor->SurfaceAltitudeOffset,
			$sensor->Id
		));
		
		// Insert relationships info (how deployment, property, unit and type relate to each sensor)
		switch ( $sensor->MeasurementInterval ) {
			case 'PT1M': 	$interval = 1; break;
			case 'PT10M': 	$interval = 10; break;
			case 'PT30M':	$interval = 30; break;
			case 'PT1H':	$interval = 60; break;
		}

		$this->db->query( sprintf(
			"UPDATE ci_logical_sensor_relationships SET deployment_id = %d, property_id = %d, system_id = %d, type_id = %d, unit_id = %d, `interval` = %d WHERE logical_sensor_id = %d",
			$sensor->Deployment->Id,
			$sensor->MonitoredProperty->Id,
			$sensor->MonitoredProperty->System->Id,
			$sensor->Type->Id,
			$sensor->Unit->Id,
			$interval,
			$sensor->Id
		));

		// Output success and update the time limit
		return array( 'success' => 'Updated sensor ' . $sensor->Id );
	}

	private function insert_new_sensor ( $sensor ) {
		// Format the sensor coordinates
		preg_match( "/\((.+)\s(.+)\s(.+)\)/i", $sensor->LocationWkt, $coords );

		// Main sensor table
		$this->db->query( sprintf(
			"INSERT INTO ci_logical_sensor VALUES ( NULL, %d, '%s', '%s', '%s', '%s', %d, %s, %s, %s, %s, %s, %d, %d )",
			$sensor->Id,
			$sensor->MeasurementInterval,
			$coords[2],
			$coords[1],
			$coords[3],
			$sensor->SurfaceAltitudeOffset,
			'NULL',
			'NULL',
			'NULL',
			'NULL',
			'NULL',
			0,
			1
		));

		// Insert relationships info (how deployment, property, unit and type relate to each sensor)
		switch ( $sensor->MeasurementInterval ) {
			case 'PT1M': 	$interval = 1; break;
			case 'PT10M': 	$interval = 10; break;
			case 'PT30M':	$interval = 30; break;
			case 'PT1H':	$interval = 60; break;
		}

		$this->db->query( sprintf(
			"INSERT INTO ci_logical_sensor_relationships VALUES ( NULL, %d, %d, %d, %d, %d, %d, %d )",
			$sensor->Id,
			$sensor->Deployment->Id,
			$sensor->MonitoredProperty->Id,
			$sensor->MonitoredProperty->System->Id,
			$sensor->Type->Id,
			$sensor->Unit->Id,
			$interval
		));

		// Output success and update the time limit
		return array( 'success' => 'Inserted sensor ' . $sensor->Id );
	}

	private function update_sensor_relationships ( $sensor ) {
		// Insert Deployment info
		preg_match( "/\((.+)\s(.+)\s(.+)\)/i", $sensor->Deployment->LocationWkt, $coords );

		$this->db->query( sprintf(
			"INSERT IGNORE INTO ci_logical_sensor_deployment VALUES ( %d, '%s', '%s', '%s', '%s', %d, '%s' )",
			$sensor->Deployment->Id,
			$coords[2],
			$coords[1],
			$coords[3],
			$sensor->Deployment->Name,
			$sensor->Deployment->Site->Id,
			$sensor->Deployment->Site->Name
		));

		// Insert Sensor Property info
		$this->db->query( sprintf(
			"INSERT IGNORE INTO ci_logical_sensor_property VALUES ( %d, '%s', '%s', %d, '%s' )",
			$sensor->MonitoredProperty->Id,
			addslashes( $sensor->MonitoredProperty->Description ),
			$sensor->MonitoredProperty->Name,
			$sensor->MonitoredProperty->System->Id,
			$sensor->MonitoredProperty->System->Name
		));

		// Insert Types info
		$this->db->query( sprintf(
			"INSERT IGNORE INTO ci_logical_sensor_types VALUES ( %d, '%s', '%s' )",
			$sensor->Type->Id,
			$sensor->Type->Name,
			$sensor->Type->Description
		));

		// Insert Unit info
		$this->db->query( sprintf(
			"INSERT IGNORE INTO ci_logical_sensor_units VALUES ( %d, '%s', %d, '%s', '%s' )",
			$sensor->Unit->Id,
			$sensor->Unit->Abbreviation,
			$sensor->Unit->Aspect->Id,
			$sensor->Unit->Aspect->Name,
			$sensor->Unit->Name
		));

		return array( 'success' => 'Updated sensor relationships.' );
	}

	// Update the current list of available timezones
	public function update_timezones () {
		$timezones = $this->get_time_zones();
 
		if ( ! empty( $timezones ) ) {
			$this->db->query( "TRUNCATE TABLE ci_timezones" ); // Clear the current timezone listing

			foreach ( $timezones as $timezone ) {
				$this->db->query( sprintf(
					"INSERT INTO ci_timezones VALUES ( NULL, '%s', '%s', '%s', '%s', '%s', %d )",
					$timezone->Id,
					$timezone->BaseUtcOffset,
					$timezone->DaylightName,
					$timezone->StandardName,
					addslashes( $timezone->DisplayName ),
					$timezone->SupportsDaylightSavingsTime
				));
			}

			return array( 'success' => 'Timezones successfully updated.' );

		} else

			return array( 'error' => 'Timezones could not be fetched.' );
	}

	// Retrieve list of available time zones in the NCCP API
	public function get_timezones () {
		$timezones = $this->measurements_client->ObtainTimeZones();
		return ! empty( $timezones ) ? $timezones->ObtainTimeZonesResult->TimeZoneInformation : false;
	}

	// Get the current list of available sensors
	public function get_sensors () {
		$sensors = $this->measurements_client->ListLogicalSensorInformation();	
		return ! empty( $sensors ) ? $sensors->ListLogicalSensorInformationResult->LogicalSensor : false;
	}

	public function convert_from ( $unit_id ) {
		$id = $this->uri->segment( 3 ) ? $this->uri->segment( 3 ) : $unit_id;

		$conversions = $this->measurements_client->ListAvailableConversionsFrom( array( 'originalUnitId' => $id ) );

		return ! empty( $conversions ) ? $conversions->ListAvailableConversionsFromResult->MeasurementUnit : false;
	}

	public function convert_to ( $unit_id ) {
		$id = $this->uri->segment( 3 ) ? $this->uri->segment( 3 ) : $unit_id;

		$conversions = $this->measurements_client->ListAvailableConversionsTo( array( 'newUnitId' => $id ) );

		return ! empty( $conversions ) ? $conversions->ListAvailableConversionsToResult->MeasurementUnit : false;
	}

}