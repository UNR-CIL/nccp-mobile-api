// This is a process that continuously updates the hourly data of up to 5 logical sensors 
// at a time.  That means it makes API calls for the sensors then checks on them (getting the
// the hourly data for a single sensor can take > 4 hours).  It also makes sure data is actually
// moving and will reset itself if not.  It's also capable of threading (you can run several of
// these on different servers at the time same and they won't interfere with each other).

// Libraries
var mysql = require( 'mysql' ),
	_ = require( 'underscore' ),
	http = require( 'http' ),
	request = require( 'request' );

// Get ze config info
var config = require( 'config' );

// Constants and bookkeeping
var UPDATE_INTERVAL = 4, 	// Hours, how often a sensor should be updated
	IDLE_INTERVAL 	= 10, 	// Seconds, idle period to wait if the max # of sensors are currently being updated
	MAX_SENSORS 	= 5, 	// Maximum number of sensors to update at a time
	TIMEOUT 		= 300, 	// Seconds, max amount of time to attempt to update a sensor before moving on
	timer 			= 0,
	sensorPool 		= [], 	// Sensors currently updating
	connCount 		= 0;

// Set up the connection pool - this is not the same as the sensor pool
var pool = mysql.createPool({
	host: config.db.host,
	user: config.db.user,
	password: config.db.pass,
	database: config.db.name
});

// Do some clean up before starting over ////////////////////////////

Reset();

// Start checking for sensors to update /////////////////////////////

UpdateSensors();

//SendUpdateRequest( 2 );

/*var interval = setInterval( function () {

	// Check the current sensor pool and remove old sensors/add new ones as needed
	UpdateSensors();

	// Timekeeping
	timer += UPDATE_INTERVAL;

	if ( config.debug ) {
		console.log( "Current sensor: ", currentSensor );
		console.log( "Connection count: ", connCount );
		console.log( "Time: ", timer );	
	}	

}, UPDATE_INTERVAL * 1000 );*/

// END //////////////////////////////////////////////////////

/////////////////////////////////////////////////////////////
// Functions ////////////////////////////////////////////////
/////////////////////////////////////////////////////////////

// Events

process.on( 'SIGINT', function() {
    console.log("\nShutting down by interrupt...");

    pool.end();
    process.exit( 0 );
});

// Functions

// Remove all current connections to the database and remove old pending statuses
function Reset () {

	// Clear any old pending states
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'Startup connection added.' );
		connCount++;

		connection.query( "UPDATE ci_logical_sensor SET pending = 0", function ( err, rows ) {
			if ( err ) console.log( err );

			connection.end();
			console.log( 'Startup connection removed.' );
			connCount--;
		});
	});

	timer = 0;
	sensorPool = []
}

function UpdateSensors () {
	// Grab more sensors to update if connections are available, otherwise
	// hang out
	if ( sensorPool.length < MAX_SENSORS ) {
		var sensor = GetSensor( function ( sensorId ) {
			if ( sensorId ) {
				sensorPool.push( sensorId );
				SendUpdateRequest( sensorId );

				if ( config.debug ) console.log( 'Updating sensor ' + sensorId );
			} else {
				// If there aren't any sensors left to update, go to idle state for the wait period
				Idle( UPDATE_INTERVAL * 60 * 60 );
			}
		});
	} else {
		// If we're updating the max number of sensors, do nothing
		Idle( IDLE_INTERVAL );
	}
}

// Check current sensors in pool and update appropriately
/*function UpdateSensors () {

	// Don't do anything if we're already updating
	if ( ! updatePending ) {
		GetSensor( function ( sensorId ) {
			if ( sensorId ) {
				currentSensor = sensorId;
				SendUpdateRequest( sensorId );

				if ( config.debug ) console.log( 'Updating sensor ' + sensorId );				
			} else {
				// If there aren't any sensors left to update, clear any pending sensors by going back
				// to startup state
				Startup();
			}	
		});		
	} else {
		// Make sure we haven't been trying to update this sensor for too long
		if ( timer > TIMEOUT ) {
			SetPending( currentSensor );

			Reset();
		}
	}
	
}*/

// Retrieve a sensor that hasn't been updated yet
function GetSensor ( UpdateCallback ) {

	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		connCount++;
		if ( config.debug ) console.log( 'GetSensor connection added.' );			

		// First get a sensor that needs to be updated (is out of date by > 2 day)
		// along with the last timestamp for that sensor
		// If timestamp is empty this means there's no data for that sensor
		connection.query( "SELECT list.logical_sensor_id FROM ci_logical_sensor AS list \
			WHERE pending = 0 \
			AND ( ( ( sensor_updated + INTERVAL 4 HOUR ) < ( CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', 'US/Pacific' ) ) ) \
			OR sensor_updated IS NULL ) \
			LIMIT 1",
			function ( err, rows ) {					
				if ( err ) console.log( err );

				if ( rows ) {
					UpdateCallback( rows[0].logical_sensor_id );
				}

				connection.end();

				connCount--;
				if ( config.debug ) console.log( 'GetSensor connection removed.' );					
		});
	});

}

function SendUpdateRequest ( sensorId ) {
	// Set pending status on the sensor
	SetPending( sensorId, 1 );

	request.post( config.paths.base + 'nccp/index.php/data/update_sensor_data_combined',
	    { form: { sensor_id: sensorId, period: 'update' } },
	    function ( error, response, body ) {
	    	if ( error ) console.log( error );

	        if ( ! error && response.statusCode == 200 ) {
	        	console.log( body );
	        	/*var parsed = JSON.parse( body );

	        	// If success was given, remove sensor from the pool
	        	if ( parsed.success ) sensorPool.splice( sensorPool.indexOf( sensorId ), 1 );

	            if ( config.debug ) {
	            	if ( parsed.success ) console.log( 'Sensor ' + sensorId + ' successfully updated' );

	            	console.log( parsed );
	            }*/
	        }

	        // Clear pending status on that sensor
	        SetPending( sensorId, 0 );
	    }
	);

}

// Set pending status on a single sensor to 1 or 0
function SetPending ( sensorId, one_or_zero ) {
	if ( config.debug ) console.log( 'Setting sensor ' + sensorId + ' to pending' );

	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		connCount++;
		if ( config.debug ) console.log( 'SetPending connection added.' );		

		connection.query( "UPDATE ci_logical_sensor SET pending = ? WHERE logical_sensor_id = ?", [ one_or_zero, sensorId ], function ( err, rows ) {
			if ( err ) console.log( err );
			connection.end();
			console.log( 'SetPending connection removed.' );
			connCount--;
		});
	});
}

// Period is specified in seconds
function Idle ( period ) {
	if ( config.debug ) console.log( 'Idling for ' + period + ' seconds.' );

	setTimeout( function () {
		if ( config.debug ) console.log( 'Current sensors: ' + sensorPool );
	}, period * 1000 );
}