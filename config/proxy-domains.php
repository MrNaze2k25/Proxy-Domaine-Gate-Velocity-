<?php

return [
    'proxy_server_id' => env('PROXY_DOMAINS_SERVER_ID', null),
    'proxy_type' => env('PROXY_DOMAINS_PROXY_TYPE', 'gate'),
    'base_domain' => env('PROXY_DOMAINS_BASE_DOMAIN', 'pelican.dev'),
    'config_path' => env('PROXY_DOMAINS_CONFIG_PATH', 'config.yml'),
    'only_minecraft' => env('PROXY_DOMAINS_ONLY_MINECRAFT', true),
    'use_ip_alias' => env('PROXY_DOMAINS_USE_IP_ALIAS', false),
    'blacklist' => env('PROXY_DOMAINS_BLACKLIST', 'admin,panel,proxy,gate,velocity,mail,www,play,mc'),
];
