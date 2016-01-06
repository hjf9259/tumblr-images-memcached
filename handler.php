<?php
/**
 * Created by PhpStorm.
 * User: Youi
 * Date: 2015-12-09
 * Time: 21:53
 */
class handler {

    /**
     * @var mc|null $mc
     */
    private static $mc;

    public static function loadMemcached($mc = null) {
        !static::$mc && (static::$mc = $mc ? $mc : (new mc()));
    }

    private static function parseUrlParam($url) {
        if (preg_match('<https?://(.+)/post/(\d+)>', $url, $match)) {
            return array(
                'post_domain' => $match[1],
                'post_id'     => $match[2]
            );
        } else {
            return false;
        }
    }

    public static function handle($url) {

        $postParam = static::parseUrlParam($url);
        $recordForNextTime = null;

        try {

            if (!$postParam) {
                $errMsg = "No a valid tumblr URL.";
                throw new Exception($errMsg);
            } else {
                $quickInfo = Input::fetchQuickResponseInfoFromCache($postParam);
                if ($quickInfo) {
                    syslog(LOG_INFO, "Quick Response.");
                    //make quick response
                    switch ($quickInfo['type']) {
                        case 'html':
                            Output::echoHtmlFile($quickInfo['content']);
                            break;
                        case 'video':
                        case 'singlePhoto':
                            Output::redirect($quickInfo['content']);
                            break;
                        case 'error':
                            Output::echoTxtFile($quickInfo['content']);
                            break;
                    }

                    return true;
                }
            }

            $postJSON = Input::fetchPostInfoFromCache($postParam);
            !$postJSON && ($postJSON = Input::queryTumblrApi($postParam));
            if (!$postJSON) {
                $postParam = false; //don't write quick response
                $errMsg = 'No post info back from Tumblr.';
                throw new Exception($errMsg);
            } else {
                //save post info to memcached
                Output::writePostInfoToCache($postParam, $postJSON);
            }

            $postInfo = $postJSON['posts'][0];
            $postType = Content::parsePostType($postInfo);
            $parserName = 'parse' . ucfirst($postType);

            switch ($postType) {
                case 'answer':
                case 'link':
                case 'regular':
                case 'quote':
                    $output = Content::$parserName($postInfo);
                    Output::echoHtmlFile($output);
                    $recordForNextTime = array(
                        'type' => 'html',
                        'content' => $output
                    );
                    break;
                case 'video':
                    $output = Content::$parserName($postInfo);
                    if (!$output) {
                        $errMsg = "Can't not parse video post, maybe it's too complicated to get the video source out.";
                        throw new Exception($errMsg);
                    } else {
                        Output::redirect($output);
                        $recordForNextTime = array(
                            'type' => 'video',
                            'content' => $output
                        );
                    }
                    break;
                case 'unknow':
                case 'photo':
                default:
                    $photoUrls = Content::$parserName($postInfo);
                    $photoCount = count($photoUrls);

                    if ($photoCount === 0) {

                        $errMsg = "No images found in the tumblr post.";
                        throw new Exception($errMsg);

                    } elseif ($photoCount === 1) {
                        Output::redirect($photoUrls[0]);

                        $recordForNextTime = array(
                            'type' => 'singlePhoto',
                            'content' => $photoUrls[0]
                        );

                    } else {

                        $imagesFromCache = Input::fetchImagesFromCache($photoUrls);

                        $total = count($photoUrls);
                        $cached = count($imagesFromCache);
                        $fetched = 0;
                        $startTime = microtime(true);

                        $images = array_fill_keys($photoUrls, null);
                        $randomUrls = array_values($photoUrls);
                        shuffle($randomUrls);
                        foreach ($randomUrls as $photoUrl) {
                            $fileName = basename($photoUrl);
                            if (isset($imagesFromCache[$fileName])) {
                                $images[$photoUrl] = &$imagesFromCache[$fileName];
                            } else {
                                $images[$photoUrl] = Input::fetchImageFromNetwork($photoUrl);
                                $fetched++;
                                static::$mc->singleSet($fileName, $images[$photoUrl]);
                            }
                        }

                        $zipPack = Content::getImagesZipPack($images);
                        Output::echoZipFile($zipPack);

                        $timeUsed = number_format(microtime(true) - $startTime, 3, '.', '');
                        syslog(LOG_INFO, "Total: $total, From cache: $cached, From network: $fetched, Time used: {$timeUsed}s");

                        static::$mc->touchKeys(array_keys($imagesFromCache));
                        //Output::writeImagesToCache($images, array_keys($imagesFromCache));
                    }
                    break;

            }

        } catch (Exception $e) {

            $errText = Content::getErrorText($e->getMessage());

            $recordForNextTime = array(
                'type' => 'error',
                'content' => $errText
            );

            Output::echoTxtFile($errText);

        } finally {

            $postParam && $recordForNextTime && Output::writeQuickResponseInfoToCache($postParam, $recordForNextTime);

        }

        return true;
    }
}