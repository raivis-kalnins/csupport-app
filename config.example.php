<?php
// Copy to config.php. Keep the data directory OUTSIDE every public web directory.
return [
    'data_dir' => '/home/YOUR_CPANEL_USER/clientwatch-private',
    // Make up a private installation key of at least 24 characters. Used only once.
    'setup_key' => 'CHANGE_THIS_TO_YOUR_OWN_LONG_RANDOM_INSTALLATION_KEY',
    'allow_http' => false, // Local development ONLY. Use HTTPS in production.
];
