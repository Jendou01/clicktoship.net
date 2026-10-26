<?php

return [
    // Exact DNS/IP hostnames, no URLs, ports, wildcards or implicit subdomains.
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('APP_ALLOWED_HOSTS', ''))))),
];
