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
var MAX_SENSORS = 5,
	INTERVAL = 4,
	TIMEOUT = 300, // 5 min
	RESET_TIMEOUT = 28800, // 8 hours
	sensorPool = [], // Currently working on
	removedPool = [], // Useful for telling if there's a problem
	rowCount = 0,
	connCount = 0,
	timer = 0,
	resetTimer = 0;

// Set up the connection pool - this is not the same as the sensor pool
var pool = mysql.createPool({
	host: config.db.host,
	user: config.db.user,
	password: config.db.pass,
	database: config.db.name
});

// Initial startup /////////////////////////////////////////

Startup( pool );

// Start polling every <interval> seconds //////////////////////////

var interval = setInterval( function () {

	// Check the current sensor pool and remove old sensors/add new ones as needed
	CheckSensors( pool, sensorPool );

	// And make sure we're actually still moving
	CheckRowCount( pool, sensorPool );

	// Keep track of time so we can restart if necessary (data count hasn't changed in a while)
	// or when the reset timer is hit (experience has shown it's just a good idea to clear things
	// out every once and awhile)
	timer += INTERVAL;
	resetTimer += INTERVAL;

	if ( timer > TIMEOUT ) ResetSensors( pool, sensorPool );
	if ( resetTimer > RESET_TIMEOUT ) {
		Startup( pool );
		resetTimer = 0;
	}

	// Clear out any stale connections still sitting around
	ClearConnections( pool );	

	console.log( "Current sensors: ", sensorPool );
	console.log( "Connection count: ", connCount );
	console.log( "Time: ", timer );
	console.log( "Reset time: ", resetTimer );	

}, INTERVAL * 1000 );

// END //////////////////////////////////////////////////////

// Events

process.on( 'SIGINT', function() {
    console.log("\nShutting down...");

    pool.end();
    process.exit( 0 );
});

// Functions

// Remove all current connections to the database and remove old pending statuses
function Startup ( pool ) {

	// Clear any old pending states
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'Startup connection added.' );
		connCount++;

		connection.query( "UPDATE ci_logical_sensor_hourly SET pending = 0", function ( err, rows ) {
			if ( err ) console.log( err );

			connection.end();
			console.log( 'Startup connection removed.' );
			connCount--;
		});
	});

	// Then clear any sleeping host threads
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'Startup connection added.' );
		connCount++;

		connection.query( "SHOW PROCESSLIST", function ( err, rows ) {
			if ( err ) console.log( err );

			_.each( rows, function ( process ) {
				if ( process.db == config.db.name && process.Command == 'Sleep' && process.Info != 'SHOW PROCESSLIST' && process.Time > 5 ) {
					KillConnection( pool, process.Id );
				}
			});

			connection.end();
			console.log( 'Startup connection removed.' );
			connCount--;
		});
	});

	// Clear the removed sensors pool
	removedPool = [];

}

// Check current sensors in pool and update appropriately
function CheckSensors ( pool, sensorPool ) {

	if ( sensorPool.length ) {
		_.each( sensorPool, function ( sensor ) {
			pool.getConnection( function ( err, connection ) {
				if ( err ) console.log( err );

				console.log( 'CheckSensors connection added.' );
				connCount++;

				connection.query( "SELECT * FROM ci_logical_sensor_hourly WHERE logical_sensor_id = ?", [ sensor ], function ( err, rows ) {
					if ( err ) console.log( err );

					if ( rows ) {
						if ( rows[0].pending == 0 ) {
							removedPool.push( sensor );
							sensorPool.splice( sensorPool.indexOf( sensor ), 1 );
							console.log( "Removing sensor: ", sensor );
						}	
					} else {
						pool.end();
					}

					connection.end();
					console.log( 'CheckSensors connection removed.' );
					connCount--;
				});
			});
		});	
	} 
			
	if ( sensorPool.length < MAX_SENSORS ) {
		GetSensor( pool, sensorPool );	
	} else {
		console.log( "Idling..." );
	}	
	
}

// Retrieve a sensor that hasn't been updated yet
function GetSensor ( pool, sensorPool ) {

	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'GetSensor connection added.' );
		connCount++;

		// First get a sensor that needs to be updated (is out of date by > 2 day)
		// along with the last timestamp for that sensor
		// If timestamp is empty this means there's no data for that sensor
		connection.query( "SELECT list.logical_sensor_id FROM ci_logical_sensor_hourly AS list " +
			"WHERE pending = 0 " + 
			"AND ( ( ( sensor_updated + INTERVAL 2 DAY ) < NOW() ) OR sensor_updated IS NULL ) " +
			"AND list.logical_sensor_id NOT IN " +
			"( SELECT DISTINCT logical_sensor_id " +
			"FROM ci_logical_sensor_data_hourly " +
			"WHERE `timestamp` > ( NOW() - INTERVAL 2 DAY ) " +
			"ORDER BY logical_sensor_id ) LIMIT 1",
			function ( err, rows ) {
				if ( err ) console.log( err );

				if ( rows ) {
					var sensorId = rows[0].logical_sensor_id;
					if ( sensorPool.indexOf( sensorId ) == -1 ) {
						// Add the sensor only if it wasn't recently removed (because the only GOOD reason
						// it should be removed is if it was finished)
						if ( removedPool.indexOf( sensorId ) == -1 ) {
							MakeSensorRequest( sensorId );
							sensorPool.push( sensorId );
							console.log( "Adding sensor: ", sensorId );
						} else {
							// If the above happened, this means multiple connections are working on the same
							// sensor - easiest way to fix this is to restart the connections
							Startup( pool );
						}
					} else {
						console.log( "Sensor " + sensorId + " already in pool." );
					}
					
				} else {
					pool.end(); // Guess we're done, so end it
				}			

				connection.end();

				console.log( 'GetSensor connection removed.' );
				connCount--;
		});
	});

}

function MakeSensorRequest ( sensorId ) {

	request.post( config.paths.base + 'nccp/index.php/data/update_sensor_data_hourly',
	    { form: { sensor_id: sensorId, period: 'update' } },
	    function ( error, response, body ) {
	    	if ( error ) console.log( error );

	        if ( ! error && response.statusCode == 200 ) {
	            console.log( body );
	        }
	    }
	);

}

function CheckRowCount ( pool, sensorPool ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'CheckRowCount connection added.' );
		connCount++;

		connection.query( "SELECT COUNT(*) AS rows FROM ci_logical_sensor_data_hourly",	function ( err, rows ) {
			if ( err ) console.log( err );		

			// This means we're still good, reset the timer
			if ( rows[0].rows > rowCount ) timer = 0;

			rowCount = rows[0].rows;
			console.log( "Total rows: ", rowCount );

			connection.end();
			console.log( 'CheckRowCount connection removed.' );
			connCount--;
		});
	});
}

// Release all the current sensors back into the wild (set pending status to 0)
// This will force the get request to the API to be made again
function ResetSensors ( pool, sensorPool ) {

	// Flip all the current sensors in the pool back to not pending
	if ( sensorPool.length ) {
		_.each( sensorPool, function ( sensor ) {
			SetPending( pool, sensor, 0 );
		});	
	}

	// Then go back to the startup state
	Startup( pool );

}

// Set pending status on a single sensor to 1 or 0
function SetPending ( pool, sensor, one_or_zero ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'SetPending connection added.' );
		connCount++;

		connection.query( "UPDATE ci_logical_sensor_hourly SET pending = ? WHERE logical_sensor_id = ?", [ one_or_zero, sensor ], function ( err, rows ) {
			if ( err ) console.log( err );
			connection.end();
			console.log( 'SetPending connection removed.' );
			connCount--;
		});
	});
}

function ClearConnections ( pool ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'ClearConnections connection added.' );
		connCount++;

		connection.query( "SHOW PROCESSLIST", function ( err, rows ) {
			if ( err ) console.log( err );

			_.each( rows, function ( process ) {
				if ( process.Time > 600 ) {
					KillConnection( pool, process.Id );
				}
			});

			connection.end();
			console.log( 'ClearConnections connection removed.' );
			connCount--;
		});
	});
}

function KillConnection ( pool, pid ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'KillConnection connection added.' );
		connCount++;

		connection.query( "KILL ?", [ pid ], function ( err, rows ) {
			if ( err ) console.log( err );
			connection.end();
			console.log( 'KillConnection connection removed.' );
			connCount--;
		});
	});
}

// Update the sensor completion info and whatnot
// Probably not needed
function UpdateSensorInfo ( pool, sensorId ) {

	var now = new Date;
	var timestamp = now.getUTCFullYear() + '-' + ( now.getUTCMonth() + 1 ) + '-' + 
		now.getUTCDay() + ' ' + now.getUTCHours() + ':' + now.getUTCMinutes() + ':' 
		+ now.getUTCSeconds();

	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		console.log( 'UpdateSensorInfo connection added.' );
		connCount++;

		connection.query( "UPDATE ci_logical_sensor_hourly SET sensor_updated = ?, pending = 0 WHERE logical_sensor_id = ?", [ timestamp, sensorId ], function ( err, rows ) {
			connection.end();
			console.log( 'UpdateSensorInfo connection removed.' );
			connCount--;
		});
	});

}
