# NCCP Mobile - Development

## Git

This being the initial release branch, the update process should be as follows:

* Commit updates to the dev branch.
* Pull updates from the dev branch on a staging server for testing if desired.
* Pull to master branch on the production server.

This allows changes from the dev branch to be tested without impacting the live server.

Note: the site repo also contains a 1.0 branch which contains the site as it stood at the end of Senior Projects.  This is just for archive purposes.

## CSS

CSS for the site is compiled with Sass (http://sass-lang.com) and Compass (http://compass-style.org).  Any updates to the site CSS should happen in the Sass files in /wp-content/theme/nccp/assets/css/sass.  The config.rb file can be found in /wp-content/theme/nccp/assets/css.
