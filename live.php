<?php
/**
Copyright 2018 Josh Roy
Copyright 2018 Subin Siby

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

   http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

if (php_sapi_name() !== "cli") {
    die("You may only run this inside of the PHP Command Line! If you did run this in the command line, please report: \"".php_sapi_name()."\" to the InstagramLive-PHP Repo!");
}

logM("Loading InstagramLive-PHP v0.4...");
set_time_limit(0);
date_default_timezone_set('America/New_York');

//Load Depends from Composer...
require __DIR__ . '/vendor/autoload.php';
use InstagramAPI\Instagram;
use InstagramAPI\Request\Live;

require_once __DIR__ . '/config.php';
/////// (Sorta) Config (Still Don't Touch It) ///////
$debug = false;
$truncatedDebug = false;
/////////////////////////////////////////////////////

if (IG_USERNAME == "USERNAME" || IG_PASS == "PASSWORD") {
    logM("Default Username and Passwords have not been changed! Exiting...");
    exit();
}

//Login to Instagram
logM("Logging into Instagram...");
$ig = new Instagram($debug, $truncatedDebug);
try {
    $loginResponse = $ig->login(IG_USERNAME, IG_PASS);

    if ($loginResponse !== null && $loginResponse->isTwoFactorRequired()) {
        logM("Two-Factor Required! Please check your phone for an SMS Code!");
        $twoFactorIdentifier = $loginResponse->getTwoFactorInfo()->getTwoFactorIdentifier();
        print "\nType your 2FA Code from SMS> ";
        $handle = fopen ("php://stdin","r");
        $verificationCode = trim(fgets($handle));
        logM("Logging in with 2FA Code...");
        $ig->finishTwoFactorLogin(IG_USERNAME, IG_PASS, $twoFactorIdentifier, $verificationCode);
    }
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Challenge") !== false) {
        logM("Account Flagged: Please sign out of all phones and try logging into instagram.com from this computer before trying to run this script again!");
        exit();
    }
    echo 'Error While Logging in to Instagram: '.$e->getMessage()."\n";
    exit(0);
}

//Block Responsible for Creating the Livestream.
try {
    if (!$ig->isMaybeLoggedIn) {
        logM("Couldn't Login! Exiting!");
        exit();
    }
    logM("Logged In! Creating Livestream...");
    $stream = $ig->live->create();
    $broadcastId = $stream->getBroadcastId();
    $ig->live->start($broadcastId);
    // Switch from RTMPS to RTMP upload URL, since RTMPS doesn't work well.
    $streamUploadUrl = preg_replace(
        '#^rtmps://([^/]+?):443/#ui',
        'rtmp://\1:80/',
        $stream->getUploadUrl()
    );

    //Grab the stream url as well as the stream key.
    $split = preg_split("[".$broadcastId."]", $streamUploadUrl);

    $streamUrl = $split[0];
    $streamKey = $broadcastId.$split[1];

    logM("================================ Stream URL ================================\n".$streamUrl."\n================================ Stream URL ================================");

    logM("======================== Current Stream Key ========================\n".$streamKey."\n======================== Current Stream Key ========================");

    logM("^^ Please Start Streaming in OBS/Streaming Program with the URL and Key Above ^^");

    logM("Live Stream is Ready for Commands");
    startHandler($ig, $broadcastId, $streamUrl, $streamKey);

    logM("Something Went Super Wrong! Attempting to At-Least Clean Up!");

    $ig->live->getFinalViewerList($broadcastId);
    $ig->live->end($broadcastId);
} catch (\Exception $e) {
    echo 'Error While Creating Livestream: '.$e->getMessage()."\n";
}

function addLike($user) {
    global $cfg_callbacks;

    $current = json_decode(@file_get_contents(__DIR__ . '/live_response'), true);
    if (!is_array($current))
        $current = [];

    $new = $current;

    $new['likes'][] = $user->getUsername();

    file_put_contents(__DIR__ . '/live_response', json_encode($new));

    if (
        $cfg_callbacks &&
        isset($cfg_callbacks['like']) &&
        is_callable($cfg_callbacks['like'])
    ) {
        $cfg_callbacks['like']($user);
    }
}

/**
 * Add a comment to list
 * @param InstagramAPI\Response\Model\User    $user    User info
 * @param InstagramAPI\Response\Model\Comment $comment Comment info
 */
function addComment($user, $comment) {
    global $cfg_callbacks;

    $current = json_decode(@file_get_contents(__DIR__ . '/live_response'), true);
    if (!is_array($current))
        $current = [];

    $new = $current;

    $new['comments'][] = [
        'comment'  => $comment->getText(),
        'id' => $comment->getPk(),
        'pinned' => false,
        'profile_pic_url' => $user->getProfilePicUrl(),
        'username' => $user->getUsername(),
    ];

    file_put_contents(__DIR__ . '/live_response', json_encode($new));

    if (
        $cfg_callbacks &&
        isset($cfg_callbacks['comment']) &&
        is_callable($cfg_callbacks['comment'])
    ) {
        $cfg_callbacks['comment']($user, $comment);
    }
}

/**
 * Set pinned comment in storage
 * @param string $comment Comment ID
 */
function setPinnedComment($commentId) {
    $current = json_decode(@file_get_contents(__DIR__ . '/live_response'), true);
    if (!is_array($current))
        $current = [];

    $new = $current;

    foreach ($new['comments'] as $index => $comment) {
        if ($comment['id'] == $commentId) {
            $new['comments'][$index]['pinned'] = true;
        } else {
            $new['comments'][$index]['pinned'] = false;
        }
    }

    file_put_contents(__DIR__ . '/live_response', json_encode($new));
}

function unsetPinnedComment() {
    $current = json_decode(@file_get_contents(__DIR__ . '/live_response'), true);
    if (!is_array($current))
        $current = [];

    $new = $current;

    foreach ($new['comments'] as $index => $comment) {
        $new['comments'][$index]['pinned'] = false;
    }

    file_put_contents(__DIR__ . '/live_response', json_encode($new));
}

function writeOutput($cmd, $msg) {
    $response = [
        'cmd'    => $cmd,
        'values' => is_array($msg) ? $msg : [$msg],
    ];
    file_put_contents(__DIR__ . '/response', json_encode($response));
}

/**
 * The handler for interpreting the commands passed via the command line.
 */
function startHandler($ig, $broadcastId, $streamUrl, $streamKey) {
    // The following loop performs important requests to obtain information
    // about the broadcast while it is ongoing.
    // NOTE: This is REQUIRED if you want the comments and likes to appear
    // in your saved post-live feed.
    // NOTE: These requests are sent *while* the video is being broadcasted.
    $lastCommentTs = 0;
    $lastCommentPin = false;
    $lastLikeTs = 0;

    // The controlling variable for the infinite while loop
    $exit = false;

    @unlink(__DIR__ . '/request');

    do {
        $cmd = '';
        $values = [];

        $request = json_decode(@file_get_contents(__DIR__ . '/request'), true);

        if (!empty($request)) {
            $cmd = $request['cmd'];
            $values = $request['values'];
        }

        if($cmd == 'ecomments') {
            $ig->live->enableComments($broadcastId);
            writeOutput('info', "Enabled Comments!");

            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'dcomments') {
            $ig->live->disableComments($broadcastId);
            writeOutput('info', "Disabled Comments!");

            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'pin') {
            $commentId = $values[0];

            if (strlen($commentId) === 17) {
                try {
                    $ig->live->pinComment($broadcastId, $commentId);
                    writeOutput('info', 'Pinned comment');
                } catch (\Exception $e) {
                    writeOutput('info', 'Unable to pin comment. Probably the comment doesn\'t exist');
                }
            }

            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'unpin') {
            if ($lastCommentPin) {
                $ig->live->unpinComment($broadcastId, $lastCommentPin['id']);

                unsetPinnedComment();
                $lastCommentPin = false;

                writeOutput('info', 'Unpinned comment');
            } else {
                writeOutput('info', 'No comments are pinned!');
            }

            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'end'){
            $added = '';
            $archived = $values[0];

            writeOutput('info', "Wrapping up and exiting...");

            //Needs this to retain, I guess?
            $ig->live->getFinalViewerList($broadcastId);
            $ig->live->end($broadcastId);

            if ($archived == 'yes') {
                $ig->live->addToPostLive($broadcastId);
                $added = 'Livestream added to archive!<br/>';
            }

            writeOutput('info', $added . "Ended stream");
            unlink(__DIR__ . '/request');

            exit();
        } elseif ($cmd == 'clear') {
            unlink(__DIR__ . '/live_response');
            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'stream_info') {
            writeOutput('stream_info', 'URL : <pre>' . $streamUrl . '</pre>Key : <pre>' . $streamKey . '</pre>');

            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'info') {
            $info = $ig->live->getInfo($broadcastId);
            $status = $info->getStatus();
            $muted = var_export($info->is_Messages(), true);
            $count = $info->getViewerCount();
            writeOutput('info', "Info:<br/>Status: $status<br/>Muted: $muted<br/>Viewer Count: $count");
        } elseif ($cmd == 'viewers') {
            $output = '';
            $ig->live->getInfo($broadcastId);
            foreach ($ig->live->getViewerList($broadcastId)->getUsers() as &$cuser) {
                $output .= "@".$cuser->getUsername()." (".$cuser->getFullName().")<br/>";
            }
            writeOutput('viewers', $output);
        }

        // Get broadcast comments.
        // - The latest comment timestamp will be required for the next
        //   getComments() request.
        // - There are two types of comments: System comments and user comments.
        //   We compare both and keep the newest (most recent) timestamp.
        $commentsResponse = $ig->live->getComments($broadcastId, $lastCommentTs);
        $systemComments = $commentsResponse->getSystemComments();
        $comments = $commentsResponse->getComments();
        if (!empty($systemComments)) {
            $lastCommentTs = end($systemComments)->getCreatedAt();
        }
        if (!empty($comments) && end($comments)->getCreatedAt() > $lastCommentTs) {
            $lastCommentTs = end($comments)->getCreatedAt();
        }

        if ($commentsResponse->isPinnedComment()) {
            $pinnedComment = $commentsResponse->getPinnedComment();
            $lastCommentPin = [
                'comment' => $pinnedComment->getText(),
                'id' => $pinnedComment->getPk(),
                'user' => $pinnedComment->getUser()->getUsername(),
            ];
            setPinnedComment($lastCommentPin['id']);
        } else {
            $lastCommentPin = false;
        }

        foreach ($comments as $comment) {
            $user = $ig->people->getInfoById($comment->getUserId())->getUser();
            addComment($user, $comment);
        }

        // Get broadcast heartbeat and viewer count.
        $ig->live->getHeartbeatAndViewerCount($broadcastId);

        // Get broadcast like count.
        // - The latest like timestamp will be required for the next
        //   getLikeCount() request.
        $likeCountResponse = $ig->live->getLikeCount($broadcastId, $lastLikeTs);
        $lastLikeTs = $likeCountResponse->getLikeTs();

        foreach($likeCountResponse->getLikers() as $user) {
            $user = $ig->people->getInfoById($user->getUserId())->getUser();
            addLike($user);
        }

        sleep(2);
    } while(!$exit);
}

/**
 * Logs a message in console but it actually uses new lines.
 */
function logM($message) {
    print $message."\n";
}
