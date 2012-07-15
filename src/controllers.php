<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs before any request
 */
$app->before(function (Request $request) use ($app) {

    if(empty($app['config.github']['access_token']) && $request->get('_route') != "authorisation") {
        $request = $app['url_generator']->generate('authorisation');
        return $app->redirect($request);
    }

});

/**
 * Index
 */
$app->get('/', function (Silex\Application $app, Request $request)  {
    $request = $app['url_generator']->generate('events');
    return $app->redirect($request);
})
->bind('dashboard');

/**
 * Hooks - Index
 */
$app->get('/hooks', function (Silex\Application $app, Request $request)  {

    $viewData = array('repos'=>array());
    foreach ($app['config.github']['repos'] as $repo) {

        $viewData['repos'][$repo] = array();
        
        $repoUrl = $app['config.github']['api_url']
                        ."/repos/"
                        .$repo
                        ."/hooks"
                        ."?access_token=".$app['config.github']['access_token'];

        $jsonResponse = file_get_contents($repoUrl);
        $hooks = json_decode($jsonResponse, true);

        foreach ($hooks as $hook) {
            if(in_array('pull_request', $hook['events'])) {
                $hook['test_url'] = $hook['url'] . "/test?access_token=".$app['config.github']['access_token'];
                $hook['url'] .= "?access_token=".$app['config.github']['access_token'];
                $viewData['repos'][$repo]['hooks'][] = $hook;
            }
        }
    }

    return $app['twig']->render('hooks/index.html.twig', $viewData);

})
->bind('hooks');

/**
 * Hooks - Create
 */
$app->post('/hooks/create', function (Silex\Application $app, Request $request) {

    $hook = $request->get('hook');

    $data = json_encode(array(
        "name" => 'web',
        "active" => 1,
        "config" => array(
            'url' => $app['config.github']['callback_url'],
            'secret' => $app['config.github']['callback_secret']
        ),
        "events" => array('pull_request')
    ));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $app['config.github']['api_url']."/repos/".$hook['repo']."/hooks?access_token=".$app['config.github']['access_token']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data))
    );

    $response = json_decode(curl_exec($ch), true);

    if(true == isset($response->id)) {
        $requestData['message'] = "New hook created with ID #" . $response->id;
    } else {
        $requestData['message'] = $response['message'];
        $requestData['error'] = true;
    }
    curl_close($ch);

    $request = $app['url_generator']->generate('hooks', $requestData);
    return $app->redirect( $request );

})
->bind('hooks_create');

/**
 * Hooks - Delete
 */
$app->get('/hooks/delete/{id}', function (Silex\Application $app, Request $request, $id) {

    $repo = $request->get('repo');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $app['config.github']['api_url']."/repos/".$repo."/hooks/".$id."?access_token=".$app['config.github']['access_token']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $response = json_decode(curl_exec($ch), true);

    if(empty($response)) {
        $requestData['message'] = "Hook deleted";
    } else {
        $requestData['message'] = $response['message'];
        $requestData['error'] = true;
    }
    curl_close($ch);

    $request = $app['url_generator']->generate('hooks', $requestData);
    return $app->redirect( $request );

})
->bind('hooks_delete');

/**
 * Events - Log
 */
$app->get('/events', function (Silex\Application $app, Request $request) {

    $viewData = array(
        'events' => array()
    );

    $finder = new Finder();
    $files = $finder
        ->files()
        ->name('payload.json')
        ->depth(1)
        ->in($app['config.event_folder_path']);

    foreach($files as $file) {
        $viewData['events'][] = json_decode(file_get_contents($file->getRealpath()), true);
    }

    return $app['twig']->render('events/index.html.twig', $viewData);
})
->bind('events');

/**
 * Events - Process
 */
$app->post('/events/process', function (Silex\Application $app, Request $request) {

    if(! $event = $request->get('payload')) {
        echo("No playload!");
        exit;
    }

    $fs = new Filesystem();
    $eventFolderPath = $app['config.event_folder_path'] . "/" . time();

    try {
        $fs->mkdir($eventFolderPath);
    } catch (IOException $e) {
        echo "An error occurred while creating your directory";
    }

    // $event = new stdClass();
    // $event->number = 4;
    // $event->repository = new stdClass();
    // $event->repository->full_name = "leevigraham/jenkins-test";

    file_put_contents($eventFolderPath."/payload.json", $event);
    $event = json_decode($event);


    $pullRequestUrl =   $app['config.github']['api_url']
                        . "/repos/"
                        . $event->repository->full_name
                        . "/pulls/"
                        . $event->number;

    $changedFiles = array();
    mkdir($eventFolderPath . "/files", 0777, true);

    $pullRequestFilesJson = file_get_contents($pullRequestUrl."/files?access_token=".$app['config.github']['access_token']);

    foreach (json_decode($pullRequestFilesJson) as $file) {
        if($file->status == 'removed') {
            continue;
        }
        $fileContents = file_get_contents($file->raw_url);
        file_put_contents($eventFolderPath . "/files/" . $file->filename, $fileContents);
        $changedFiles[$file->filename] = $file;
    }

    // Run PHP Code Sniffer
    exec("phpcs --report=checkstyle --standard=Symfony2 {$eventFolderPath}/files/*", $output);
    $report = implode($output);

    // Save the output and create XML
    file_put_contents($eventFolderPath."/checkstyle.xml", $report);
    $report = new SimpleXMLElement($report);

    // Setup Curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pullRequestUrl."/comments?access_token=".$app['config.github']['access_token']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // Loop over the files in the report
    foreach ($report->file as $file) {

        // Make the filename relative
        $fileName = str_replace($eventFolderPath."/files/", "", (string)$file['name']);

        foreach ($file->error as $error) {

            // Create a new comment
            $comment = array(
                "body" => "**".ucfirst((string)$error['severity']).":** ".(string)$error['message'],
                "commit_id" => $changedFiles[$fileName]->sha,
                "path" => $changedFiles[$fileName]->filename,
                "position" => (string)$error['line']
            );

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($comment));
            $output = curl_exec($ch);
        }
    }

    curl_close($ch);

    return $app['twig']->render('events/process.html.twig', array(
        'payload' => $event,
        'report' => $report
    ));

})
->bind('events_process');

/**
 * Authorisation - Index
 */
$app->get('/authorisation', function (Silex\Application $app, Request $request) {

    $viewData = array();

    // No client settings
    if(false == isset($app['config.github']['client_id']) 
        || false == isset($app['config.github']['client_secret'])
    ) {

        $viewData['step'] = 1;

    // Is there a code in the query string
    } elseif($code = $request->get('code')) {

        $payLoad = 
            "client_id=".$app['config.github']['client_id'] . 
            "&client_secret=".$app['config.github']['client_secret'] . 
            "&code=".$code;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://github.com/login/oauth/access_token");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payLoad);
        $response = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($response, true);
        
        if(isset($response['access_token'])) {
            $url = $app['url_generator']->generate('authorisation', $response);
            return $app->redirect( $url );
        }

        $viewData['step'] = 2;
        $viewData['auth_url'] = "https://github.com/login/oauth/authorize"
                                ."?client_id={$app['config.github']['client_id']}"
                                ."&scope=public_repo,repo,delete_repo";
        $viewData['response'] = $response;

    // Is there a token in the query string or is there a token
    } elseif(true == isset($app['config.github']['access_token']) || $request->get('access_token')) {

        $viewData['step'] = 3;
        $viewData['access_token'] = $request->get('access_token') ?: $app['config.github']['access_token'];

    // // Authorise
    } else {

        $viewData['step'] = 2;
        $viewData['auth_url'] = "https://github.com/login/oauth/authorize"
                                ."?client_id={$app['config.github']['client_id']}"
                                ."&scope=public_repo,repo,delete_repo";

    }
    
    return $app['twig']->render('authorisation/index.html.twig', $viewData);
    
    // (true == isset($app['config.github']['access_token'])) {
})
->bind('authorisation');