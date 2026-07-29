This folder is reserved for cross-module/shared AJAX endpoints, per the
required project structure.

In practice, every AJAX endpoint built for this project turned out to belong
to exactly one module (e.g. modules/hearings/ajax_search.php), so keeping
each endpoint next to the module it serves was more cohesive than routing
everything through a shared /ajax folder. This directory is kept in place
and ready to use if a genuinely cross-module AJAX endpoint is needed later
(for example, a global search-everything box).
