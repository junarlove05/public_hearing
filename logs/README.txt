Runtime log output goes here.

config/config.php points PHP's error_log to logs/php_errors.log. This
directory must be writable by the web server user in production
(e.g. chmod 755 with the correct owner, or 775 if your web server user
differs from the file owner).

No log files are checked into the repository; they're created automatically
the first time an error occurs.
