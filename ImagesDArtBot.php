<?php

include('rmnApiKey.php');
include('twitterCredentials.php');
include("Helpers.php");
include("museumToTwitter.php");
require_once('TwitterAPIExchange.php');
header('Content-Type: text/html; charset=utf-8');

// RMN-GP API key
$RmnAPIKey = $rmnApiKey;

/** Set access tokens here - see: https://apps.twitter.com/ **/
$APIsettings = array(
    'oauth_access_token' => $oauthToken,
    'oauth_access_token_secret' => $oauthTokenSecret,
    'consumer_key' => $consumerKey,
    'consumer_secret' => $consumerSecret
);
$twitter = new TwitterAPIExchange($APIsettings);

// Get Twitter config
$twitterConfigURL = 'https://api.twitter.com/1.1/help/configuration.json';
$requestMethod = 'GET';
$twitterConfig = $twitter->setGetfield('')
    ->buildOauth($twitterConfigURL, $requestMethod)
    ->performRequest();
$twitterConfig = json_decode($twitterConfig);

// Twitter Media Upload URL
$mediaUploadURL = 'https://upload.twitter.com/1.1/media/upload.json';

// Get number of total hits available for works with image and title
$requestURL = 'http://api.art.rmngp.fr:80/v1/works?exists=images%2Ctitle.fr&per_page=0';
$result = getCURLOutput($requestURL, $RmnAPIKey);

if(isset($result->hits)){
  $totalHits = $result->hits->total;

  // Get a random work with image and title
  $randomPage = rand(1, $totalHits);
  $requestURL = 'http://api.art.rmngp.fr:80/v1/works?exists=images%2Ctitle.fr&per_page=1&page=' . $randomPage;
  $result = getCURLOutput($requestURL, $RmnAPIKey);

  if(isset($result->hits) && count($result->hits->hits) > 0){
    $work = $result->hits->hits[0]->_source;

    // Upload image of the work
    foreach ($work->images as $image) {
      if($image->default){
        $permalink = 'http://art.rmngp.fr/fr/library/artworks/' . $work->slug;
        $imageContent = file_get_contents($image->urls->original);
        $imageData = base64_encode($imageContent);

        $postfields = array('media_data' =>  $imageData);
        $requestMethod = "POST";
        $response = $twitter->resetFields()
                      ->buildOauth($mediaUploadURL, $requestMethod)
                      ->setPostfields($postfields)
                      ->performRequest();
        $response = json_decode($response);
        if(isset($response->media_id_string))
          $mediaId = $response->media_id_string;
      }
    }
    if(!isset($mediaId))
      return false;

    // Build tweet
    // Set max string length based on Twitter config
    $maxLength = 140 - ($twitterConfig->short_url_length + 1);
    // Work title
    $tweet = $work->title->fr;
    // Add first author if available and not too long, else add first authorship detail if available and not too long
    if(isset($work->authors) && count($work->authors) > 0 && isset($work->authors[0]->name) && isset($work->authors[0]->name->fr)
      && strlen($tweet . ", " . $work->authors[0]->name->fr) < $maxLength + 1){
        $tweet .= ", " . $work->authors[0]->name->fr;
    }else if(isset($work->authorship_details) && count($work->authorship_details) > 0 && isset($work->authorship_details[0]->name) && isset($work->authorship_details[0]->name->fr)
      && strlen($tweet . ", " . $work->authorship_details[0]->name->fr) < $maxLength + 1){
        $tweet .= ", " . $work->authorship_details[0]->name->fr;
    }
    // Add location if available and not too long
    if(isset($work->location) && isset($work->location->suggest_fr) && isset($work->location->suggest_fr->output)){
      $location = $work->location->suggest_fr->output;
      $museumMention = "";
      foreach ($museumToTwitter as $loc => $mention) {
        if(stripos($location, $loc) !== FALSE)
          $museumMention .= " @" . $mention;
      }
      if (strlen($museumMention) > 0 && strlen($tweet . $museumMention) < $maxLength + 1)
        $tweet .= $museumMention;
      else if(strlen($tweet . ", " . $location) < $maxLength + 1)
        $tweet .= ", " . $location;
    }
    // Finalize tweet
    if($tweet > $maxLength)
      $tweet = substr($tweet, 0, $maxLength - 3) . "...";
    $tweet .= " " . $permalink;

    // Post tweet with media
    $postfields = array(
      'status' =>  $tweet,
      'media_ids' => $mediaId);
    $url = "https://api.twitter.com/1.1/statuses/update.json";
    $requestMethod = "POST";
    echo $twitter->resetFields()
                  ->buildOauth($url, $requestMethod)
                  ->setPostfields($postfields)
                  ->performRequest();

  }
}

 ?>
