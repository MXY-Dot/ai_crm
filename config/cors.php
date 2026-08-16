<?php

// No config/cors.php existed before this — Laravel's HandleCors middleware is
// part of the global stack but with no config file, config('cors.paths', [])
// resolves empty and CORS is effectively off everywhere. Only the public
// widget API needs it (called via fetch() from arbitrary customer websites —
// see WidgetController); every other route stays same-origin/session-based,
// so deliberately not the common 'api/*' default here.
return [
    'paths' => ['api/widget/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
