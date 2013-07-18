/* 
  API for providing access to the data caching database.  The database
  itself is populated via a separate CodeIgniter API which is run auto-
  matically.  This is meant to provide quick access to the staging database
  with as little overhead as possible
*/

// Example query

// Get the mysql driver and express for building the RESTful-ness
var mysql 	= require( 'mysql' ),
	e 		= require( 'express' ),
	_ 		= require( 'underscore' ),
	http 	= require( 'http' ),
	csv 	= require( 'csv' ),
	fs 		= require( 'fs' ),
	uuid	= require( 'node-uuid' );

// Get ze config info
var config = require( 'config' );

// Parameters
var table = 'ci_logical_sensor_data',
	port = 6227, // No, this isn't random
	intervals = { // Period plus the record offset corresponding to it
		yearly: 8760,
		monthly: 720, 
		weekly: 168,
		daily: 24,
		hourly: null
	},
	limit = 1000000000; // ONE BEEELION ROWS.  This is seriously the best way to default 'all rows' in mysql. /shrug

// Make the app
var api = e();

// Configure said app
api.configure( function () {
	api.use( e.compress() );
});

// Create connection pool for use with the database

var pool = mysql.createPool({
	host: config.db.host,
	user: config.db.user,
	password: config.db.pass,
	database: config.db.name
});

// Define the API routes

// This returns a list of sensors based on query parameters which should
// be arrays of the following:
// properties - sensor property ID(s) (for temperature, wind speed, etc.)
// sites - data site ID(s)
// types - type of measurement ID(s) (maximum, average, etc.)
api.get( '/api/search', function ( request, response ) {
	var q = request.query;

	if ( ! q.properties && ! q.sites && ! q.types ) {
		response.jsonp( { error: 'Must send at least one of: sensor properties, sites or types.' } );
	} else {

		// Build the query from passed parameters
		var sql = "SELECT r.*, d.site_id, d.name FROM ci_logical_sensor_relationships AS r " +
			"JOIN ci_logical_sensor_deployment AS d ON d.deployment_id = r.deployment_id " +
			"WHERE 1 ";

		if ( q.properties ) {
			sql += "AND ( " + _.map( q.properties, function ( v ) { return 'property_id = ' + v } ).join( " OR " ) + " ) ";
		}

		if ( q.sites ) {
			sql += "AND ( " + _.map( q.sites, function ( v ) { return 'site_id = ' + v } ).join( " OR " ) + " ) ";
		}

		if ( q.types ) {
			sql += "AND ( " + _.map( q.types, function ( v ) { return 'type_id = ' + v } ).join( " OR " ) + " ) ";
		}

		var limit = q.count ? q.count : 1000;

		sql += "GROUP BY r.logical_sensor_id LIMIT " + limit;

		pool.getConnection( function ( err, connection ) {
			if ( err ) console.log( err );

			// Send the query
			connection.query( sql, function ( err, rows ) {
				console.log( 'Sending response...' );

				// Process errors first
				if ( err ) {

					console.log( err );
					response.jsonp( { error: 'An error occurred. =(' } );

				// Otherwise process the results
				} else {
					if ( rows.length > 0 ) {					
						response.jsonp( rows );
					} else
						response.jsonp( { msg: 'No results found.' } );
				}

				connection.end();
			});		
		});
	}
});

// Request data points from the database
// Params:
// sensor_ids*
// start*
// end*
// period: time format - P1M, PT6H are examples
// count: limit results
// format: 'raw' will return array of ONLY data values, no timestamps
// Example AJAX request:
// $.getJSON( 'http://nccp.local:6227/api/get/?callback=?', { sensor_ids: 7, count: 10, start: '2013-02-08', end: '2013-02-10' }, function ( response ) { console.log( response ) } )
api.get( '/api/get', function ( request, response ) {
	var q = request.query;

	// Make sure this is a valid request
	if ( ! q.sensor_ids ) {
		response.jsonp( { error: 'Must send valid sensor ID(s).' } );
	} else if ( ! q.start || ! q.end ) {
		response.jsonp( { error: 'Must send valid start and end' } );
	} else {
		console.log( 'Request received for sensor(s) ' + q.sensor_ids );

		// Set skip if interval was passed.  This assumes interval 
		if ( q.interval && _.has( intervals, q.interval) ) {
			var skip = intervals[q.interval];

			// Switch to the hourly table for anything above minute data
			table = "ci_logical_sensor_data_hourly";
		}		
		
		// If interval is set, multiply the limit by the skip because
		// the interval is processed server-side, not in the DB (quicker)
		if ( q.count ) limit = skip ? q.count * skip : q.count;

		// Make sure sensors_ids is an array
		if ( ! ( q.sensor_ids instanceof Array ) ) q.sensor_ids = [q.sensor_ids];

		// Format the sensor list
		sensor_ids = _.map( q.sensor_ids, function ( v ) { return 'logical_sensor_id = ' + v } ).join( " OR " );

		_GetSensorData( table, sensor_ids, {
			start: q.start, 
			end: q.end, 
			limit: parseInt( limit ),
			skip: skip
		}, function ( results ) {
			if ( results ) {
				console.log( 'Sending response...' );

				// If requested, send back a link to a CSV.  Otherwise send results directly.
				if ( q.csv ) {
					_GenerateCSV( results, function ( link ) {
						response.jsonp( { download_link: link } );
					});
				} else {
					response.jsonp( results );
				}				
			} else {
				response.jsonp( { msg: 'No results found.' } );
			}
		});		
	}
});

// Request information about specified sensors (type, unit, deployment, etc.)
// Params:
// sensor_ids* - array of at least one sensor ID
api.get( '/api/get/sensor-info', function ( request, response ) {
	var q = request.query;

	if ( ! q.sensor_ids ) {
		response.jsonp( { error: 'Must send at least one valid sensor ID.' } );
	} else {
		// Format the sensor_ids
		sensor_ids = _.map( q.sensor_ids, function ( v ) { return 'r.logical_sensor_id = ' + v } ).join( " OR " );

		pool.getConnection( function ( err, connection ) {
			if ( err ) console.log( err );

			// Send the query
			connection.query( "SELECT DISTINCT " +
			"r.logical_sensor_id, r.deployment_id, r.property_id, r.system_id, r.type_id, r.unit_id, r.`interval`, " +
			"d.lat, d.lng, d.z_offset, d.`name` AS deployment_name, d.site_id, d.site_name, " +
			"p.description AS property_description, p.`name` AS property_name, p.system_name, " +
			"t.description AS type_description, t.`name` AS type_name, " +
			"u.abbreviation, u.aspect_id, u.aspect_name, u.`name` AS unit_name " +
			"FROM ci_logical_sensor_relationships AS r " +
			"JOIN ci_logical_sensor_deployment AS d ON d.deployment_id = r.deployment_id " +
			"JOIN ci_logical_sensor_property AS p ON p.property_id = r.property_id " +
			"JOIN ci_logical_sensor_types AS t ON t.type_id = r.type_id " +
			"JOIN ci_logical_sensor_units AS u ON u.unit_id = r.unit_id " +
			"WHERE ( " + sensor_ids + " )", function ( err, rows ) {
				console.log( 'Sending response...' );

				// Process errors first
				if ( err ) {

					console.log( err );
					response.jsonp( { error: 'An error occurred. =(' } );

				// Otherwise process the results
				} else {

					if ( rows.length > 0 ) {					
						response.jsonp( rows );
					} else
						response.jsonp( { msg: 'Nothing found.' } );

				}

				connection.end();
			});
		});		
	}
});

// Retrieve the current list of properties.  Searching said properties uses search
// defined above.
api.get( '/api/get/sensors/properties', function ( request, response ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		// Send the query
		connection.query( "SELECT property_id, description, name FROM ci_logical_sensor_property ORDER BY name", function ( err, rows, fields ) {
			console.log( 'Sending response...' );

			// Process errors first
			if ( err ) {

				console.log( err );
				response.jsonp( { error: 'An error occurred. =(' } );

			// Otherwise process the results
			} else {

				if ( rows.length > 0 ) {					
					response.jsonp( rows );
				} else
					response.jsonp( { msg: 'Nothing found.' } );

			}

			connection.end();
		});	
	});
});

// Retrieve the current list of data sites.  Searching said sites uses search
// defined above.
api.get( '/api/get/sensors/sites', function ( request, response ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		// Send the query
		connection.query( "SELECT lat, lng, site_id, site_name FROM ci_logical_sensor_deployment GROUP BY site_name ORDER BY site_name", function ( err, rows, fields ) {
			console.log( 'Sending response...' );

			// Process errors first
			if ( err ) {

				console.log( err );
				response.jsonp( { error: 'An error occurred. =(' } );

			// Otherwise process the results
			} else {

				if ( rows.length > 0 ) {					
					response.jsonp( rows );
				} else
					response.jsonp( { msg: 'Nothing found.' } );

			}

			connection.end();
		});	
	});	
});

// Retrieve the current list of property types (avg, min, max, etc.).  Searching said types uses search
// defined above.
api.get( '/api/get/sensors/types', function ( request, response ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		// Send the query
		connection.query( "SELECT * FROM ci_logical_sensor_types ORDER BY name", function ( err, rows, fields ) {
			console.log( 'Sending response...' );

			// Process errors first
			if ( err ) {

				console.log( err );
				response.jsonp( { error: 'An error occurred. =(' } );

			// Otherwise process the results
			} else {

				if ( rows.length > 0 ) {					
					response.jsonp( rows );
				} else
					response.jsonp( { msg: 'Nothing found.' } );

			}

			connection.end();
		});
	});
});

// Check main NCCP portal status
// Example AJAX call
// $.getJSON( 'http://nccp.local:6227/api/status/website?callback=?', function ( response ) { console.log( response ) } )
api.get( '/api/status/website', function ( request, response ) {
	// Set up parameters
	var params = {
		host: 'sensor.nevada.edu',
		port: 80,
		path: '/NCCP/Default.aspx'
	}

	// Check the main site
	_CheckUrl( params, function ( result ) {
		if ( result.up ) {
			response.jsonp({ success: 'The NCCP portal is up.' });
		} else {
			response.jsonp({ error: 'The NCCP portal is currently down.' });
		}	
	});	
});

// Check NCCP Measurement service status
api.get( '/api/status/services/measurement', function ( request, response ) {
	// Set up parameters
	var params = {
		host: 'sensor.nevada.edu',
		port: 80,
		path: '/Services/Measurements/Measurement.svc'
	};

	// Check the Measurement service
	_CheckUrl( params, function ( result ) {
		if ( result.up ) {
			response.jsonp({ success: 'The measurement service is up.' });
		} else {
			response.jsonp({ error: 'The measurement service is currently down.' });
		}	
	});	
});

// Check NCCP Data service status
api.get( '/api/status/services/data', function ( request, response ) {
	// Set up parameters
	var params = {
		host: 'sensor.nevada.edu',
		port: 80,
		path: '/Services/DataRetrieval/DataRetrieval.svc'
	};

	// Check the Data service
	_CheckUrl( params, function ( result ) {
		if ( result.up ) {
			response.jsonp({ success: 'The data service is up.' });
		} else {
			response.jsonp({ error: 'The data service is currently down.' });
		}	
	});	
});

// Start the music ///////////////////////////////////////

api.listen( port );

console.log( 'Data API is active on port ' + port );

/////////////////////////////////////////////////////////
// EXIT /////////////////////////////////////////////////
/////////////////////////////////////////////////////////

process.on( 'SIGINT', function() {
    console.log("\nShutting down by interrupt...");

    pool.end();
    process.exit( 0 );
});

//////////////////////////////////////////////////////////
// Internal functions ////////////////////////////////////
//////////////////////////////////////////////////////////

// Args: start, end, limit, skip
function _GetSensorData ( table, sensor_ids, args, callback ) {
	pool.getConnection( function ( err, connection ) {
		if ( err ) console.log( err );

		// Send the query
		connection.query( "SELECT logical_sensor_id, timestamp, value " + 
			"FROM " + table + " " + 
			"WHERE (" + sensor_ids + ") AND timestamp " +
			"BETWEEN ? AND ? " +
			"ORDER BY timestamp " +
			"LIMIT ?",
			[ args.start, args.end, args.limit ], 
			function ( err, rows ) {
				// Process errors first
				if ( err ) {

					console.log( err );
					response.jsonp( { error: 'An error occurred. =(' } );

				// Otherwise process the results
				} else {
					if ( rows.length > 0 ) {
						var final_results = {
							num_results: rows.length
						};

						// Group results by sensor_id
						final_results.sensor_data = _.groupBy( rows, 'logical_sensor_id' );

						// Filter data by interval if it was passed
						if ( args.skip ) {
							var final_num_results = 0;

							_.each( final_results.sensor_data, function ( sensor, index ) {
								var final_rows = [];

								for ( var i = 0; i < sensor.length; i += skip ) {
									final_rows.push( sensor[i] );
								}

								final_results.sensor_data[index] = final_rows;
								final_num_results += final_rows.length;
							});	

							final_results.num_results = final_num_results;
						}				

						callback( final_results );							
					} else { 
						callback( null );
					}
				}

				connection.end();
		});		
	});
}

function _GenerateCSV ( data, callback ) {
	var filename = 'logical_sensor_data_' + uuid.v1() + '.csv',
		filepath = config.csv.directory + filename,
		file = fs.openSync( filepath, 'w' )
		final_array = [];

	// Push header rows
	final_array.push( [ 'Logical Sensor ID', 'Timestamp', 'Value' ] );

	// For each sensor, format the data rows and push to the final array
	_.each( data.sensor_data, function ( sensor ) {
		final_array = final_array.concat( _.map( sensor, function ( row ) {
			return [ JSON.stringify( row.logical_sensor_id ), row.timestamp.toJSON(), row.value.toString() ];
		}));
	});

	// Write the CSV
	csv()
		.from( final_array )
		.to( filepath, { flags: 'a' } );	

	// Respond with the link to the finished CSV
	callback( config.csv.path + filename );

	fs.closeSync( file );
}

// Check url and return appropriate success/error to callback
function _CheckUrl ( params, callback ) {

	// Send the request
	http.get( params, function( res ) {

		// Respond with the status depending on how it went
		callback( res.statusCode == 200 ? { up: true } : { up: false, code: res.statusCode } );
		
	}).on( 'error', function ( error ) {

		// If we couldn't connect, don't even send status code
		callback( { up: false } );

	});

}

// Cuts data down to just values
function _FormatData ( data, format ) {
	var final = [];

	if ( format == 'raw' )
		_.each( data, function ( el ) {
			final.push( el.value );
		});

	return final;
}
