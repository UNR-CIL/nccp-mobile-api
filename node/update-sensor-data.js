/** 
 * This is a process that continuously updates the hourly data of up to 5 logical sensors 
 * at a time.  That means it makes API calls for the sensors then checks on them (getting the
 * the hourly data for a single sensor can take > 4 hours).  It also makes sure data is actually
 * moving and will reset itself if not.  It's also capable of threading (you can run several of
 * these on different servers at the time same and they won't interfere with each other).
**/

// Libraries
var mysql = require( 'mysql' ),
	_ = require( 'underscore' ),
	request = require( 'request' );

// Get ze config info
var config = require( 'config' );

// Constants and bookkeeping
var UPDATE_PATH 	= config.paths.base + 'nccp/index.php/data/update_sensor_data',
	UPDATE_INTERVAL = 4, 	// Hours, how often a sensor should be updated
	MAX_SENSORS 	= 5, 	// Maximum number of sensors to update at a time
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

UpdateSensorData();

// END //////////////////////////////////////////////////////

/////////////////////////////////////////////////////////////
// Events ///////////////////////////////////////////////////
/////////////////////////////////////////////////////////////

process.on( 'SIGINT', function() {
    console.log("\nShutting down by interrupt...");

    pool.end();
    process.exit( 0 );
});

/////////////////////////////////////////////////////////////
// Functions ////////////////////////////////////////////////
/////////////////////////////////////////////////////////////

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

	sensorPool = [];
}

// Main update function.  Will pull a single sensor, check if it's already in the queue,
// and update it if not.  Called recursively until the max # of sensors is reached.
// Also called by SendUpdate Request upon finishing updating a sensor so the max # of
// sensors is always being updated.
function UpdateSensorData () {
	// Grab more sensors to update if connections are available, otherwise
	// hang out
	if ( sensorPool.length < MAX_SENSORS ) {
		var sensor = GetSensor( function ( sensorId ) {
			if ( sensorId ) {
				// Update the sensor only if it's not already in the queue
				if ( sensorPool.indexOf( sensorId ) == -1 ) {
					sensorPool.push( sensorId );
					SendUpdateRequest( sensorId );

					if ( config.debug ) console.log( 'Updating sensor ' + sensorId );
				}

				// Get another if we're not full yet
				UpdateSensorData();
			} else {
				// If we didn't get a sensor, this mean we're done updating for now, so wait
				// for the wait interval and start again
				Idle( UPDATE_INTERVAL * 60 * 60, function () {
					UpdateSensorData();
				});
			}				
		});
	}

	// If the queue is full, do nothing.  UpdateSensorData will be called again
	// from SendUpdateRequest upon completion of updating the next sensor so
	// another can immediately be updated.
	if ( config.debug ) {
		console.log( 'Current sensor queue: ', sensorPool );
		console.log( 'Connection count: ', connCount );
	}
}

// Retrieve a sensor that hasn't been updated in the update interval.
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
			AND ( ( ( sensor_updated + INTERVAL ? HOUR ) < ( CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', 'US/Pacific' ) ) ) \
			OR sensor_updated IS NULL ) " +
			_.reduce( sensorPool, function ( memo, num ) { return memo + 'AND logical_sensor_id <> ' + num + ' ' }, '' ) +
			"LIMIT 1",
			[ UPDATE_INTERVAL ],
			function ( err, rows ) {					
				if ( err ) console.log( err );

				if ( rows[0] && rows[0].logical_sensor_id ) {
					UpdateCallback( rows[0].logical_sensor_id );
				} else {
					UpdateCallback( null );
				}

				connection.end();

				connCount--;
				if ( config.debug ) console.log( 'GetSensor connection removed.' );					
		});
	});
}

// Send POST request to the CodeIgniter interface to update a sensor.  This only sends the
// ID of the sensor to update - timekeeping is handled by CI.  Will called UpdateSensorData
// again on completion to immediately get another sensor.
function SendUpdateRequest ( sensorId ) {
	// Set pending status on the sensor
	SetPending( sensorId, 1 );

	request.post( UPDATE_PATH,
	    { form: { sensor_id: sensorId, period: 'update' } },
	    function ( error, response, body ) {
	    	if ( error ) console.log( error );

	        if ( ! error && response.statusCode == 200 ) {
	        	try {
	        		var parsed = JSON.parse( body );
	        	} catch ( e ) {
	        		console.log( e );
	        		console.log( body );
	        	}	        	

				if ( config.debug ) console.log( parsed );

	        	// Remove sensor from the pool...
	        	sensorPool.splice( sensorPool.indexOf( sensorId ), 1 );

	        	// ... and get another
	        	UpdateSensorData();	            
	        }

	        // Clear pending status on that sensor
	        SetPending( sensorId, 0 );
	    }
	);
}

// Set pending status on a single sensor to 1 or 0
function SetPending ( sensorId, one_or_zero ) {
	if ( config.debug ) console.log( 'Setting sensor ' + sensorId + ' pending status to ' + one_or_zero );

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

// Wait the specified period.  Will call finished upon completion.
function Idle ( period, finished ) {
	if ( config.debug ) console.log( 'Idling for ' + period + ' seconds.' );

	setTimeout( function () {
		if ( config.debug ) console.log( 'Current sensors: ' + sensorPool );

		finished();
	}, period * 1000 );
}