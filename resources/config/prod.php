<?php

$app['config.github'] = array(
    'user' => 'leevigraham',
    'client_id' => 'a6f356e693fb28987533',
    'client_secret' => '444281f23d938bab0f1857c91009a57e93b70ea2',
    'access_token' => '223b5c41b9eb210ed9c9a77c7c85306f23445b55',
    'api_url' => 'https://api.github.com',
    // 'callback_url' => $app['url_generator']->generate('hooks_process', array(), true),
    'callback_url' => 'http://requestb.in/1e5ae8z1',
    'callback_secret' => '868yw2CzuHE[xzF6ePPosbTvRDmYRw',
    'repos' => array(
        'leevigraham/jenkins-test',
        'leevigraham/jquery-bigTarget.js'
    ),
);

$app['config.event_folder_path'] = realpath(__DIR__ . "/../../../events");