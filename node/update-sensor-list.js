/**
 * Update the list of sensors (shouldn't change very often, but check every now
 * and again anyway).
 **/

// Libraries
var mysql = require( 'mysql' ),
	_ = require( 'underscore' ),
	request = require( 'request' );

// Get ze config info
var config = require( 'config' );

// Constants and bookkeeping
var UPDATE_PATH 	= config.paths.base + 'nccp/index.php/measurements/update_sensors',
	UPDATE_INTERVAL = 24; // Hours, how often we should check for something to do

/////////////////////////////////////////////////////////////
// Start update /////////////////////////////////////////////
/////////////////////////////////////////////////////////////

UpdateSensorList();

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

function UpdateSensorList () {
	// Update the sensor list...
	SendUpdateRequest( function () {
		// ... then wait a while and do it again
		Idle( UPDATE_INTERVAL * 60 * 60, function () {
			UpdateSensorList();
		});	
	});	
}

// Send POST request to the CodeIgniter interface to update a sensor.  This only sends the
// ID of the sensor to update - timekeeping is handled by CI.  Will called UpdateSensorData
// again on completion to immediately get another sensor.
function SendUpdateRequest ( UpdateCallback ) {
	request.post( UPDATE_PATH,
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
	        }

	        console.log( body );

	        UpdateCallback();
	    }
	);
}

// Wait the specified period.  Will call finished upon completion.
function Idle ( period, finished ) {
	if ( config.debug ) console.log( 'Idling for ' + period + ' seconds.' );

	setTimeout( function () {
		finished();
	}, period * 1000 );
}
