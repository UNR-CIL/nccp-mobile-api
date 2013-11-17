# NCCP Mobile - Deployment

## Components

The site consists of an API component and a display component.  Both can be installed on the same server but should ideally be on different servers to split the load between the data API and simple page display.

## API

The API component manages the mobile database and provides a standard API for accessing sensor data as well as sensor info, server status, etc.  It consists of a CodeIgniter application which interfaces with the Web Services of the main database and a pair of Nodes, one which provides a mobile database API and another for pulling updates from the main database at regular intervals.

### Requirements

#### Internal API
* CodeIgniter v2.1.3

#### External API: 
* nodejs (tested on v0.8.21): http://nodejs.org.
* npm (should be installed with node, tested on v1.2.12): http://npmjs.org

##### Packages (installed via npm):
* express v3.1.0
* csv v0.3.3
* forever v0.10.0
* mysql v2.0.0
* node-uuid v1.4.0
* request v2.14.0
* underscore v1.4.4

### Notes

node/data.js provides the public-facing data API while update-sensor-data.js provides data update capabilities.  It will regularly check for data updates from the main server and will also cull data points older than 3 months (be default) from the main data table.  The hourly data table will not be culled.

Both files should be started as daemons using forever and the following commands (as root or the web user):

    forever start -l update-sensor-data.log -o update-sensor-data-out.log -e update-sensor-data-err.log -a ../update-sensor-data.js
    
Note that the above is from the node/logs directory.

## Display

The display component is build on WordPress and provides simple page display. 

### Requirements
* WordPress, currently on v3.7.1 (stable)

### Notes
The standard WordPress core can be pulled with the following:

    git clone https://github.com/WordPress/WordPress.git
    git checkout tags/3.7.1
    
Or can simple be downloaded from http://wordpress.org.  If the repo route is used, be sure to delete the core .git folder before downloading the site repo (which will not overwrite core files, but is also in the root directory).

## Environment

The following comprises the major components utilized on the AWS server the API component is currently installed on.

* Ubuntu v12.10 LTS
* PHP v5.4.6
* MySQL v5.5.29
* Node JS v0.8.21

## Repositories

The two components have separate repositories, both privately on Bitbucket.  Please email aradnom@gmail.com if access is needed.

API:

https://bitbucket.org/aradnom/nccp-mobile-api.git

Site:

https://bitbucket.org/aradnom/nccp-mobile-site.git

Note that both repos are at the site root.  For the site repo this means the repo should be cloned into the desired directory **first**, after which the WordPress core should be dropped in.  This also means if the core is cloned from github, the .git folder should be deleted before moving as it will conflict with the site repo .git.

## Process

### API

1. Pull down the API repo.  Node will start a server indepedent of Apache/Nginx so this doesn't particularly need to be in the normal webroot (but the CodeIgniter component does require Apache/PHP).  Make sure the API port (6227) is open in the server firewall if needed.  Make sure the API files are not publicly accessible (as they're just .js files, so normal server security will not lock these down).
2. Install Forever:
	npm install forever -g
3. Start the API servers (api.js/update-sensor-data.js) using the Forever command in Notes above.
4. Test the API by going to the following: <server url>:6227/api/get?sensor_ids=2&start=2013-01-01&end=2013-02-01

### Site

1. Grab a fresh copy of WordPress core (http://wordpress.org/latest.zip or the github repo listed above).  Put into a temp directory.
2. Pull down the site repo into the web/vhost root.
3. Drop everything from the temp directory into the site directory except for the wp-content folder.  Be sure to delete the .git folder from the temp directory if the core was pulled from github before doing this.
4. Create site database and populate with most recent dump in _dev/sql/
5. Configure wp-config.php with database credentials.
6. Log in at <site root>/wp-admin to make sure everything worked as advertised.