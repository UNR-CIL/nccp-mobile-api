/**
 * Cleanup is in charge of scouring old records (> 3 months from the data base)
 * at intervals.
 */

// Libraries
var mysql = require( 'mysql' ),
	_ = require( 'underscore' );

// Get ze config info
var config = require( 'config' );

// Constants and bookkeeping
var DELETE_THRESHOLD = 3, // Months - any data past this age will be deleted
	UPDATE_INTERVAL, = 4, // Hours, how often we should check for something to do
	MAX_CONNECTIONS = 5,
	connCount = 0,
	deletePending = false;

// Set up the connection pool - this is not the same as the sensor pool
var pool = mysql.createPool({
	host: config.db.host,
	user: config.db.user,
	password: config.db.pass,
	database: config.db.name
});

// Start polling //////////////////////////

var interval = setInterval( function () {

	// Delete any data points older than the threshold (if we're not busy doing that already)
	if ( ! deletePending ) Cleanup();

	if ( config.debug ) {
		console.log( "Connection count: ", connCount );
	}	

}, UPDATE_INTERVAL * 1000 * 60 );

// END //////////////////////////////////////////////////////

// Events

process.on( 'SIGINT', function() {
    console.log("\nShutting down...");

    pool.end();
    process.exit( 0 );
});

// Functions /////////////////////////////////////////////////

function Cleanup () {
	if ( connCount < MAX_CONNECTIONS ) {
		// High-res sensor data (per minute table)
		DeleteFromTable( 'ci_logical_sensor_data' );

		// Low-res sensor data (hourly)
		DeleteFromTable( 'ci_logical_sensor_data_hourly' );
	}	
}

function DeleteFromTable ( table ) {
	deletePending = true;

	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		connCount++;
		if ( config.debug ) console.log( 'Cleanup connection added.' );

		connection.query( "DELETE FROM ? WHERE `timestamp` < ( ( CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', 'US/Pacific' ) ) - INTERVAL ? MONTH )", 
			[ table, DELETE_THRESHOLD ], 
			function ( err, rows ) {
				if ( err ) console.log( err );

				if ( config.debug ) console.log( rows );

				connection.end();
				connCount--;
				if ( config.debug ) console.log( 'Cleanup connection removed.' );

				deletePending = false;
		});
	});
}
