=== Visit Seattle Events Importer ===
Contributors: jaybillings
Tags: events, api, admin, importer
Requires at least: 4.7.0
Tested up to: 4.8.1
Stable tag: 1.1.0

Fetches event data from the VisitSeattle API, sourced from BeDynamic.

== Description ==

Internal plug-in for Visit Seattle which fetches event data,
sourced from BeDynamic, and creates the appropriate taxonomy.

== Installation ==

Move /visit-seattle-events-importer folder into /wp-content/plugins folder.

== Changelog ==

= 1.1.1 =

* When importing, new data is fetched on all 'hard' inits (generally those initiated by button presses).
* Adds ability to clear cache from 'advanced' section of UI.

= 1.1.0 =

* Re-factors process handling so that the client is responsible for keeping track of the current action.
  * Since PHP is not designed for long-running processes, it is easy to run out of memory/execution time during long
    processes. Moving control of the process to the client makes error conditions less likely and easier to recover from.
* Adds ability to resume canceled/failed action.
* Adds ability to import single events.

= 1.0.2 =

* Adds 'startAt' parameter under 'Advanced' to manually indicate a starting index for fetches.
  * Temporary fix for large pulls until resume is implemented.

= 1.0.1 =

* Updates variable names.
* Updates action names to prevent collision with VSPI.
* Encapsulates styling.
* Increases script execution time.
  * This is a temporary fix before porting VSPI's resume functionality.

= 1.0.0 =

Establishes basic plugin functionality and user experience.

