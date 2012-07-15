<?php

$app['config.github'] = array(
    'user' => 'leevigraham',
    'client_id' => '',
    'client_secret' => '',
    'access_token' => '',
    'api_url' => 'https://api.github.com',
    'callback_url' => 'http://requestb.in/1e5ae8z1',
    'callback_secret' => '868yw2CzuHE[xzF6ePPosbTvRDmYRw',
    'repos' => array(
        'leevigraham/github-pr-code-sniffer'
    ),
);

$app['config.event_folder_path'] = realpath(__DIR__ . "/../../events");