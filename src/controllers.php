<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs before any request
 */
$app->before(function (Request $request) use ($app) {

    if (empty($app['config.github']['access_token']) && $request->get('_route') != "authorisation") {
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
            if (in_array('pull_request', $hook['events'])) {
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

    if (true == isset($response['id'])) {
        $requestData['message'] = "New hook created with ID #" . $response['id'];
    } else {
        $requestData['message'] = $response['message'];
        $requestData['error'] = true;
    }
    curl_close($ch);

    $request = $app['url_generator']->generate('hooks', $requestData);

    return $app->redirect($request);

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

    if (empty($response)) {
        $requestData['message'] = "Hook deleted";
    } else {
        $requestData['message'] = $response['message'];
        $requestData['error'] = true;
    }
    curl_close($ch);

    $request = $app['url_generator']->generate('hooks', $requestData);

    return $app->redirect($request);

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

    foreach ($files as $file) {
        $viewData['events'][] = json_decode(file_get_contents($file->getRealpath()), true);
    }

    return $app['twig']->render('events/index.html.twig', $viewData);
})
->bind('events');

/**
 * Events - Process
 */
$app->post('/events/process', function (Silex\Application $app, Request $request) {

    if (! $eventRaw = $request->get('payload')) {
        echo("No playload!");
        exit;
    }
    $event = json_decode($eventRaw, true);

    // $eventRaw = false;
    // $event = array();
    // $event['number'] = 3;
    // $event['repository'] = array();
    // $event['repository']['full_name'] = "leevigraham/github-pr-code-sniffer";
    // $event['pull_request']['diff_url'] = "https://github.com/{$event['repository']['full_name']}/pull/{$event['number']}.diff";

    $diffFile = file_get_contents($event['pull_request']['diff_url']);

    $fs = new Filesystem();
    $eventTime =  time();
    $eventFolderPath = $app['config.event_folder_path'] . "/" . $eventTime;

    try {
        $fs->mkdir($eventFolderPath);
    } catch (IOException $e) {
        echo "An error occurred while creating your directory";
    }

    file_put_contents($eventFolderPath."/payload.json", $eventRaw);
    file_put_contents($eventFolderPath."/diff.txt", $diffFile);

    $patterns = array(
        'diff' => "/^diff/",
        'originalFile' => "/^--- a?\/(.*)/",
        'newFile' => "/^\+\+\+ b?\/(.*)/",
        'chunk' => "/^@@ -(\d+)(,(\d+))? \+(\d+)(,(\d+))? @@/",
        'addedLines' => "/^\+(.*)/",
        'removedLines' => "/^-(.*)/",
        'unchangedLines' => "/^ (.*)/",
    );

    $diffResult = array();

    foreach (explode("\n", $diffFile) as $lineNum => $line) {
        $lineNum = $lineNum+1;
        foreach ($patterns as $patternKey => $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                switch ($patternKey) {
                    case 'diff':
                        unset($position);
                        $currentDiff = $line;
                        // var_dump($currentDiff);
                        // exit;
                        $diffResult[$currentDiff][$patternKey] = array(
                            'diffLineIndex' => $lineNum,
                            "diffString" => $line,
                        );
                        break;
                    case 'originalFile':
                        $diffResult[$currentDiff][$patternKey] = array(
                            'diffLineIndex' => $lineNum,
                            "diffString" => $line,
                            'fileName' => $matches[1]
                        );
                        break;
                    case 'newFile':
                        $diffResult[$currentDiff][$patternKey] = array(
                            'diffLineIndex' => $lineNum,
                            "diffString" => $line,
                            'fileName' => $matches[1]
                        );
                        break;
                    case 'chunk':
                        if (false == isset($position)) {
                            $position = 0;
                        }
                        $currentChunk = $lineNum;
                        $addedSourceLine = (int) $matches[4];
                        $removedSourceLine = (int) $matches[1];
                        $diffResult[$currentDiff]['chunks'][$currentChunk] = array(
                            'changedLinesStart' => (int) $matches[4],
                            'changedLinesCount' => (int) $matches[6],
                            'removedLinesStart' => (int) $matches[1],
                            'removedLinesCount' => (int) $matches[3],
                            'position' => $position,
                            'diffString' => $line
                        );
                        $position++;
                        break;
                    case 'addedLines':
                        $diffResult[$currentDiff]['chunks'][$currentChunk][$patternKey][] = array(
                            'fileLineIndex' => $addedSourceLine,
                            'diffLineIndex' => $lineNum,
                            'position' => $position,
                            'diffString' => $line,
                        );
                        $addedSourceLine++;
                        $position++;
                        break;
                    case 'removedLines':
                        $diffResult[$currentDiff]['chunks'][$currentChunk][$patternKey][] = array(
                            'fileLineIndex' => $removedSourceLine,
                            'diffLineIndex' => $lineNum,
                            'position' => $position,
                            'diffString' => $line,
                        );
                        $removedSourceLine++;
                        $position++;
                        break;
                    case 'unchangedLines':
                        // $diffResult[$currentDiff]['chunks'][$currentChunk][$patternKey][] = array(
                        //                             'diffString' => $line,
                        //                             'diffLineIndex' => $lineNum
                        //                         );
                        $addedSourceLine++;
                        $removedSourceLine++;
                        $position++;
                        break;
                }
                continue(2);
            }
        }
    }

    file_put_contents($eventFolderPath."/parsedDiff.json", json_encode($diffResult));

    $pullRequestUrl =   $app['config.github']['api_url']
                        . "/repos/"
                        . $event['repository']['full_name']
                        . "/pulls/"
                        . $event['number'];

    $changedFiles = array();

    try {
        $fs->mkdir($eventFolderPath . "/files");
    } catch (IOException $e) {
        echo "An error occurred while creating your directory";
    }

    $pullRequestFilesJson = file_get_contents($pullRequestUrl."/files?access_token=".$app['config.github']['access_token']);

    file_put_contents($eventFolderPath.'/pull_request_files.json', $pullRequestFilesJson);

    foreach (json_decode($pullRequestFilesJson) as $file) {
        if ($file->status == 'removed') {
            continue;
        }

        $fileContents = file_get_contents($file->raw_url);

        try {
            $fs->mkdir(dirname($eventFolderPath . "/files/" . $file->filename));
            file_put_contents($eventFolderPath . "/files/" . $file->filename, $fileContents);
        } catch (IOException $e) {
            echo "An error occurred while creating your directory";
        }

        $changedFiles[$file->filename] = $file;
    }

    exec("phpcs --standard=Symfony2 --report-checkstyle --extensions=php {$eventFolderPath}/files", $output);
    $report = implode($output);

    // Save the output and create XML
    file_put_contents($eventFolderPath."/phpcs-checkstyle.xml", $report);
    $report = new SimpleXMLElement($report);

    // Loop over the files in the report
    // var_dump($report);

    $comments = array();
    $errors = array(
        'files' => array(),
        'totals' => array('error' => 0, 'warning' => 0)
    );

    // Loop over the checkstyle files
    foreach ($report->file as $fileReport) {
        // Get the file name
        $fileName = str_replace($eventFolderPath."/files/", "", (string) $fileReport['name']);
        $errors['files'][$fileName] = array('error' => 0, 'warning' => 0);
        // Loop over the diff results
        foreach ($diffResult as $diffReport) {

            // Binary File?
            if (isset($diffReport['newFile']) == false) {
                continue;
            }

            // If results[newFile]['fileName'] == checkstyle file
            if ($diffReport['newFile']['fileName'] == $fileName) {
                // Loop over errors
                foreach ($fileReport->error as $checkstyleReportError) {
                    // Loop over chunks
                    foreach ($diffReport['chunks'] as $diffReportChunk) {
                        // Loop over addedLines
                        foreach ($diffReportChunk['addedLines'] as $addedLine) {
                            if ($checkstyleReportError['line'] == $addedLine['fileLineIndex']) {
                                $severity = (string) $checkstyleReportError['severity'];
                                $comments[] = array(
                                    "body" => "**".ucfirst($severity).":** ". (string) $checkstyleReportError['message'],
                                    "commit_id" => $changedFiles[$fileName]->sha,
                                    "path" => $changedFiles[$fileName]->filename,
                                    "position" => $addedLine['position']
                                );

                                $errors['files'][$fileName][$severity]++;
                                $errors['totals'][$severity]++;
                            }
                        }
                    }
                }
            }
        }
    }

    // Setup Curl
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pullRequestUrl."/comments?access_token=".$app['config.github']['access_token']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    foreach ($comments as $comment) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($comment));
        $output = curl_exec($ch);
    }

    $issueUrl = $app['config.github']['api_url']
                        . "/repos/"
                        . $event['repository']['full_name']
                        . "/issues/"
                        . $event['number'];

    $summaryReport = $app['twig']->render('events/phpcs-summary-report.html.twig', $errors);

    $comment = array(
        "body" => $summaryReport,
    );

    curl_setopt($ch, CURLOPT_URL, $issueUrl."/comments?access_token=".$app['config.github']['access_token']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($comment));
    $output = curl_exec($ch);

    curl_close($ch);

    // var_dump($comments);
    // var_dump($diffResult);
    // exit;

    return $app['twig']->render('events/process.html.twig', array(
        'payload' => $event,
        'report' => $report,
        'comments' => $comments
    ));

})
->bind('events_process');

/**
 * Authorisation - Index
 */
$app->get('/authorisation', function (Silex\Application $app, Request $request) {

    $viewData = array();

    // No client settings
    if (false == isset($app['config.github']['client_id'])
        || false == isset($app['config.github']['client_secret'])
    ) {

        $viewData['step'] = 1;

    // Is there a code in the query string
    } elseif ($code = $request->get('code')) {

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

        if (isset($response['access_token'])) {
            $url = $app['url_generator']->generate('authorisation', $response);

            return $app->redirect($url);
        }

        $viewData['step'] = 2;
        $viewData['auth_url'] = "https://github.com/login/oauth/authorize"
                                ."?client_id={$app['config.github']['client_id']}"
                                ."&scope=public_repo,repo,delete_repo";
        $viewData['response'] = $response;

    // Is there a token in the query string or is there a token
    } elseif (false == empty($app['config.github']['access_token']) || $request->get('access_token')) {

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

})
->bind('authorisation');