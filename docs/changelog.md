# NCCP Mobile - Changelog

## v2.0

* Revised design.  Removed texturing and redesigned several elements to make them more reliable across screen sizes.
* Added Bootstrap CSS as well as Backbone/Underscore for JS.
* Converted all CSS to Compass/SASS along with the redesign.
* Converted all JS to Backbone View for event simplicity.  Namespaced major JS components.
* Modified navigation to fit mobile requirements better.  Nested some pages within new parent pages to cut down on the number of top-level links.
* Reviewed all page content.  Modified, added and removed content as necessary, updated page styles, fixed links and broken images, etc.
* Removed current line graph and replaced with NVD3 graphs - line, bar, scatter and stacked area graphs.
* Fixed a number of graph style issues.
* Converted the data update API to all Node (aside from the SOAP bits which CodeIgniter still handles because it just isn't worth the bother to rewrite in Node).
* Implemented data cleanup, as a standalone file and later on a per-sensor basis (faster).
* Revised homepage content.

## v1.0

This is considered to be the version submitted at the end of Senior Projects.  A final copy of this version can therefore likely be obtained from Dr. Sergiu Dascalu by request.