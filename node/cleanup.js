/**
 * Cleanup is in charge of scouring old records (> DELETE_THRESHOLD months from the data base)
 * at intervals.
 **/

// Libraries
var mysql = require( 'mysql' ),
	_ = require( 'underscore' );

// Get ze config info
var config = require( 'config' );

// Constants and bookkeeping
var DELETE_THRESHOLD 	= 3, // Months - any data past this age will be deleted
	UPDATE_INTERVAL 	= 4, // Hours, how often we should check for something to do
	MAX_CONNECTIONS 	= 5,
	connCount 			= 0;

// Set up the connection pool - this is not the same as the sensor pool
var pool = mysql.createPool({
	host: config.db.host,
	user: config.db.user,
	password: config.db.pass,
	database: config.db.name
});

/////////////////////////////////////////////////////////////
// Start cleanup ////////////////////////////////////////////
/////////////////////////////////////////////////////////////

Cleanup();

// END //////////////////////////////////////////////////////

/////////////////////////////////////////////////////////////
// Events ///////////////////////////////////////////////////
/////////////////////////////////////////////////////////////

process.on( 'SIGINT', function() {
    console.log("\nShutting down...");

    pool.end();
    process.exit( 0 );
});

//////////////////////////////////////////////////////////////
// Functions /////////////////////////////////////////////////
//////////////////////////////////////////////////////////////

function Cleanup () {
	// Clean up both tables...
	if ( connCount < MAX_CONNECTIONS ) {
		// High-res sensor data (per minute table)
		DeleteFromTable( 'ci_logical_sensor_data' );

		// Low-res sensor data (hourly)
		DeleteFromTable( 'ci_logical_sensor_data_hourly' );
	}

	// ... then wait a while and do it again
	Idle( UPDATE_INTERVAL * 60 * 60, function () {
		Cleanup();
	});
}

function DeleteFromTable ( table ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		connCount++;
		if ( config.debug ) console.log( 'Cleanup connection added.' );

		connection.query( "DELETE FROM " + table + " WHERE `timestamp` < ( ( CONVERT_TZ( UTC_TIMESTAMP(), 'UTC', 'US/Pacific' ) ) - INTERVAL ? MONTH )", 
			[ DELETE_THRESHOLD ],
			function ( err, rows ) {
				if ( err ) console.log( err );

				if ( config.debug ) {
					console.log( table + ' cleanup complete.' );
					console.log( rows );
				}

				connection.end();
				connCount--;
				if ( config.debug ) console.log( 'Cleanup connection removed.' );
		});
	});
}

// Wait the specified period.  Will call finished upon completion.
function Idle ( period, finished ) {
	if ( config.debug ) console.log( 'Idling for ' + period + ' seconds.' );

	setTimeout( function () {
		finished();
	}, period * 1000 );
}
