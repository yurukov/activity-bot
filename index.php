<?php

  /*
  *  "This code is not a code of honour... no highly esteemed code is commemorated here... nothing valued is here."
  *  "What is here is dangerous and repulsive to us. This message is a warning about danger."
  *  This is a rudimentary, single-file, low complexity, minimum functionality, ActivityPub server.
  *  For educational purposes only.
  *  The Server produces an Actor who can be followed.
  *  The Actor can send messages to followers.
  *  The message can have linkable URls, hashtags, and mentions.
  *  An image and alt text can be attached to the message.
  *  The Server saves logs about requests it receives and sends.
  *  This code is NOT suitable for production use.
  *  SPDX-License-Identifier: AGPL-3.0-or-later
  *  This code is also "licenced" under CRAPL v0 - https://matt.might.net/articles/crapl/
  *  "Any appearance of design in the Program is purely coincidental and should not in any way be mistaken for evidence of thoughtful software construction."
  *  For more information, please re-read.
  */
 

  $userfolder = "u";
  $users = array_map(function($v) {
    $vs=explode("/",$v);
    return end($vs);
  }, glob("{$userfolder}/*"));

  if (count($users)==0) {      
    header("HTTP/1.1 404 Not Found");
    die();
  }

  //  If the root has been requested, manually set the path to `/`
  !empty( $_GET["path"] ) ? $path = $_GET["path"] : $path = "/";

  if ($path==".well-known/webfinger") {
    if (isset($_GET["resource"])) {
      $resource=$_GET["resource"];
      if (substr($resource,0,5)=="acct:") {
        $resourceC=explode("@",substr($resource,substr($resource,0,6)=="acct:@"?6:5));
        $username=$resourceC[0];
      } elseif (substr($resource,0,4)=="http") {
        $resourceC=explode("/",$resource);
        $username=end($resourceC);        
      }
    }
  } elseif (substr($path,0,1)=="@") {
    $usernameC=explode("/",$path);
    $username=substr($usernameC[0],1);
  } elseif (substr($path,0,6)=="users/") {
    $username=substr($path,6);
  } else {
    $username=$path;
  }
    
  if (!in_array($username,$users))
    $username=$users[0];

  if (!is_dir("{$userfolder}/{$username}")) {      
    header("HTTP/1.1 404 Not Found");
    die();
  }

  //  Preamble: Set your details here
  //  This is where you set up your account's name and bio.
  //  You also need to provide a public/private keypair.
  //  The posting endpoint is protected with a password that also needs to be set here.
  //  Set up the Actor's information here, or in the .env file
  $env = parse_ini_file("{$userfolder}/{$username}/.env");
  if (!$env) {      
    header("HTTP/1.1 404 Not Found");
    die();
  }

  //  Edit these:
  $username = rawurlencode( $env["USERNAME"] );  //  Type the @ username that you want. Do not include an "@". 
  $realName = $env["REALNAME"];  //  The bot's "real" name.
  $summary  = $env["SUMMARY"];  //  The bio of your bot.
  $birthday = $env["BIRTHDAY"];   //  The "joined" date of your bot.
                                  //  Must be in RFC3339 format. 2024-02-29T12:34:56Z
  $language = $env["LANGUAGE"];  //  This is the primary human language used by your bot.
                                  //  Must be a 2 letter ISO 639-1 code
  $oldName  = $env["OLD_NAME"];  //  To transfer followers from an old account, enter the full account URl.
                                  //  For example 'https://example.social/users/username'  
  $attachments = array();
  if (""!=$env["ATTACHMENT1_NAME"]) 
    $attachments[]=array(
      "name"=>$env["ATTACHMENT1_NAME"],
      "link"=>$env["ATTACHMENT1_URL"],
    );
  if (""!=$env["ATTACHMENT2_NAME"]) 
    $attachments[]=array(
      "name"=>$env["ATTACHMENT2_NAME"],
      "link"=>$env["ATTACHMENT2_URL"],
    );

  //  Generate locally or from https://cryptotools.net/rsagen
  //  Newlines must be replaced with "\n"
  $key_private = str_replace('\n', "\n", $env["KEY_PRIVATE"] );
  $key_public  = str_replace('\n', "\n", $env["KEY_PUBLIC"]  );

  //  Password for sending messages
  $password = $env["PASSWORD"];

  /** No need to edit anything below here. But please go exploring! **/

  //  Uncomment these headers if you want to run conformance tests using
  //  https://socialweb.coop/activitypub/actor/tester/
  // header('Access-Control-Allow-Origin: *');
  // header("Access-Control-Allow-Headers: accept, content-type");
  // header("Vary: Origin");

  //  Internal data
  $server   = $_SERVER["SERVER_NAME"];  //  Do not change this!

  //  Some requests require a User-Agent string.
  define( "USERAGENT", "activitybot-single-php-file/0.0" );

  //  Set up where to save logs, posts, and images.
  //  You can change these directories to something more suitable if you like.
  $data = "data";
  $directories = array(
    "inbox"      => "{$userfolder}/{$username}/{$data}/inbox",
    "followers"  => "{$userfolder}/{$username}/{$data}/followers",
    "following"  => "{$userfolder}/{$username}/{$data}/following",
    "logs"       => "{$userfolder}/{$username}/{$data}/logs",
    "posts"      => "{$userfolder}/{$username}/posts",
    "images"     => "{$userfolder}/{$username}/images",
  );
  //  Create the directories if they don't already exist.
  if(!is_dir("{$userfolder}")) mkdir("{$userfolder}");
  if(!is_dir("{$userfolder}/{$username}")) mkdir("{$userfolder}/{$username}");
  if(!is_dir("{$userfolder}/{$username}/{$data}")) mkdir("{$userfolder}/{$username}/{$data}");
  foreach ($directories as $directory) if(!is_dir($directory)) mkdir($directory); 

  // Get the information sent to this server
  $input       = file_get_contents( "php://input" );
  $body        = json_decode( $input, true );
  $bodyData    = print_r( $body,      true );

  //  Routing:
  //  The .htaccess changes /whatever to /?path=whatever
  //  This runs the function of the path requested.
  switch ( $path ) {
    case ".well-known/webfinger":
      webfinger();   //  Mandatory. Static.
    case ".well-known/nodeinfo":
      wk_nodeinfo(); //  Optional. Static.  
    case ".well-known/host-meta":
      meta(); //  Optional? Static.
    case "nodeinfo/2.1":
      nodeinfo();    //  Optional. Static.
    case rawurldecode( $username ):
    case "users/" . rawurldecode( $username ):
    case "@" . rawurldecode( $username ):  //  Some software assumes usernames start with an `@`
      username();    //  Mandatory. Static
    case "@{$username}/following":
      following();          //  Mandatory. Can be static or dynamic.
    case "@{$username}/followers":
      followers( $path );   //  Mandatory. Can be static or dynamic.
    case "@{$username}/followers_synchronization" :  //  Optional. Used for showing followers
      followers_synchronization();
    case "@{$username}/inbox":
      inbox();       //  Mandatory.
    case "@{$username}/outbox":    
      outbox();      //  Optional. Dynamic.
    case "@{$username}/action/send":      
      send();        //  API for posting content to the Fediverse.
    case "@{$username}/action/users":      
      action_users();      //  API for interacting with Fediverse users.
    case "/":         
      view( "home" );
    default:
      if (str_starts_with($path,"@{$username}/posts/")) {
        posts($path);
      } else if (isAcceptingHTML()) {
        header("HTTP/1.1 302 Found");
        header("Location: https://{$server}/");
        header("Content-Type: text/html; charset=UTF-8");
        header("Content-Length: 0");
        die();
      } else {      
        header("HTTP/1.1 404 Not Found");
        die();
      }
  }

  //  The WebFinger Protocol is used to identify accounts.
  //  It is requested with `example.com/.well-known/webfinger?resource=acct:username@example.com`
  //  This server only has one user, so it ignores the query string and always returns the same details.
  function webfinger() {
    global $username, $server;

    $webfinger = array(
      "subject" => "acct:{$username}@{$server}",
         "links" => array(
        array(
           "rel" => "self",
          "type" => "application/activity+json",
          "href" => "https://{$server}/@{$username}"
        )
      )
    );
    header( "Content-Type: application/json" );
    echo json_encode( $webfinger );
    die();
  }

  function isAcceptingHTML() {
    foreach (getallheaders() as $name => $value) {
      if ("Accept" ==  $name) {
        $accepts = explode(",", $value);
        if ( in_array("text/html", $accepts) ) {
          return true;
        }
      }
    }
    return false;
  }

  //  User:
  //  Requesting `example.com/username` returns a JSON document with the user's information.
  function username() {
    global $username, $userfolder, $realName, $summary, $birthday, $oldName, $server, $key_public, $attachments;

    if (isAcceptingHTML()) {
      view("userhome");
      die();  
    }

    if ( "" != $oldName ) {
      $aka = [ "{$oldName}" ];
    } else {
      $aka = [];
    }
  
    $user = array(
      "@context" => [
        "https://www.w3.org/ns/activitystreams",
        "https://w3id.org/security/v1"
      ],
                             "id" => "https://{$server}/@{$username}",
                           "type" => "Application",
                      "following" => "https://{$server}/@{$username}/following",
                      "followers" => "https://{$server}/@{$username}/followers",
                          "inbox" => "https://{$server}/@{$username}/inbox",
                         "outbox" => "https://{$server}/@{$username}/outbox",
              "preferredUsername" =>  rawurldecode( $username ),
                           "name" => "{$realName}",
                        "summary" => "{$summary}",
                            "url" => "https://{$server}/@{$username}",
      "manuallyApprovesFollowers" =>  false,
                   "discoverable" =>  true,
                      "indexable" =>  true,
                      "published" => "{$birthday}",
                    "alsoKnownAs" => $aka,
      "icon" => [
             "type" => "Image",
        "mediaType" => "image/png",
              "url" => "https://{$server}/@{$username}/icon.png"
      ],
      "image" => [
             "type" => "Image",
        "mediaType" => "image/png",
              "url" => "https://{$server}/@{$username}/banner.png"
      ],
      "publicKey" => [
        "id"           => "https://{$server}/@{$username}#main-key",
        "owner"        => "https://{$server}/@{$username}",
        "publicKeyPem" => $key_public
      ]
    );

    if (count($attachments)>0) {
      $user["attachment"]=array();
      foreach ($attachments as $attachment) {
        $shortlink=str_replace("https://","",str_replace("https://www.","",$attachment["link"]));
        if (str_ends_with($shortlink,"/")) $shortlink=substr($shortlink,0,strlen($shortlink)-1);
        $user["attachment"][]=array(
          "type"=>"PropertyValue",
          "name"=>$attachment["name"],
          "value"=>"<a href=\"{$attachment["link"]}\" target=\"_blank\" rel=\"nofollow noopener noreferrer me\" translate=\"no\">$shortlink</a>",
        );
      }
    }
  
    header( "Content-Type: application/activity+json" );
    echo json_encode( $user );
    die();
  }

  //  Follower / Following:
  // These JSON documents show how many users are following / followers-of this account.
  // The information here is self-attested. So you can lie and use any number you want.
  function following() {
    global $server, $directories, $username;

    //  Get all the files 
    $following_files = glob( $directories["following"] . "/*.json" );
    //  Number of users
    $totalItems = count( $following_files );

    //  Sort users by most recent first
    usort( $following_files, function( $a, $b ) {
      return filemtime($b) - filemtime($a);
    });

    //  Create a list of all accounts being followed
    $items = array();
    foreach ( $following_files as $following_file ) {
      $following = json_decode( file_get_contents( $following_file ), true );
      if ($following!=null) {
        $items[] = $following["id"];
      }
    }

    $following = array(
        "@context" => "https://www.w3.org/ns/activitystreams",
              "id" => "https://{$server}/@{$username}/following",
            "type" => "OrderedCollection",
      "totalItems" => $totalItems,
           "items" => $items
    );
    header( "Content-Type: application/activity+json" );
    echo json_encode( $following );
    die();
  }
  function followers() {
    global $server, $username, $directories;
    //  The number of followers is self-reported.
    //  You can set this to any number you like.
    
    //  Get all the files 
    $follower_files = glob( $directories["followers"] . "/*.json" );
    //  Number of users
    $totalItems = count( $follower_files );

    //  Sort users by most recent first
    usort( $follower_files, function( $a, $b ) {
      return filemtime($b) - filemtime($a);
    });

    //  Create a list of everyone being followed
    $items = array();
    foreach ( $follower_files as $follower_file ) {
      $following = json_decode( file_get_contents( $follower_file ), true );
      $items[] = $following["id"];
    }

    //  Which page, if any, has been requested
    if ( !isset($_GET["page"]) ) {
      //  No page, so show the link to the pagination
      $followers = array(
        "@context" => "https://www.w3.org/ns/activitystreams",
              "id" => "https://{$server}/@{$username}/followers",
            "type" => "OrderedCollection",
        "totalItems" => $totalItems,
           "first" => "https://{$server}/@{$username}/followers?page=1"
      );
      header( "Content-Type: application/activity+json" );
      echo json_encode( $followers );
      die();
    } else {
      $page = (int)$_GET["page"];
    }

    //  Pagination 
    $batchSize = 10;
    $batches = array_chunk( $items, $batchSize );
    $followers_batch = $batches[$page - 1];

    $followers = array(
      "@context" => "https://www.w3.org/ns/activitystreams",
            "id" => "https://{$server}/@{$username}/followers?page={$page}",
          "type" => "OrderedCollectionPage",
      "totalItems" => $totalItems,
          "partOf" => "https://{$server}/@{$username}/followers",
    "orderedItems" => $followers_batch
    );

    //  Next and Prev pagination links if necessary
    if ( $page < sizeof( $batches ) ) {
      $followers["next"] =  "https://{$server}/@{$username}/followers?page=" . $page + 1;
    }

    if ( $page > 1 ) {
      $followers["prev"] =  "https://{$server}/@{$username}/followers?page=" . $page - 1;
    }

    header( "Content-Type: application/activity+json" );
    echo json_encode( $followers );
    die();
  }

  function followers_synchronization() {
    //  This is a Follower Synchronisation request
    //  https://docs.joinmastodon.org/spec/activitypub/#follower-synchronization-mechanism
    global $directories, $username;

    //  Host that made the request can be found in the signature
    //  Get the headers send with the request
    $headers = getallheaders();
    //  Ensure the header keys match the format expected by the signature 
    $headers = array_change_key_case( $headers, CASE_LOWER );
    //  Examine the signature
    $signatureHeader = $headers["signature"];
    // Extract key information from the Signature header
    $signatureParts = [];
    //  Converts 'a=b,c=d e f' into ["a"=>"b", "c"=>"d e f"]
                   // word="text"
    preg_match_all('/(\w+)="([^"]+)"/', $signatureHeader, $matches);
    foreach ( $matches[1] as $index => $key ) {
      $signatureParts[$key] = $matches[2][$index];
    }

    //  Get the Public Key
    //  The link to the key might be sent with the body, but is always sent in the Signature header.
    //  This is usually in the form `https://example.com/user/username#main-key`
    $publicKeyURL = $signatureParts["keyId"];

    //  Finally, get the host
    $host = parse_url( $publicKeyURL, PHP_URL_HOST );

    //  Is the domain host name valid?
    $host = filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
    if ( $host == false )  {
      //  Something dodgy is going on!
      header( "HTTP/1.1 401 Unauthorized" );
      echo "Bad domain value";
      die();
    }
    
    //  Get all the follower files
    $follower_files = glob( $directories["followers"] . "/*.json" );
    
    //  Return only the users from this domain
    $items_filtered = [];

    foreach ( $follower_files as $follower_file ) {
      $following = json_decode( file_get_contents( $follower_file ), true );
      $following_id = $following["id"];
      $following_host = parse_url( $following_id )["host"];
      if ( $host === $following_host ) {
        $items_filtered[] = $following_id;
      }
    }

    $followers = array(
      "@context" => "https://www.w3.org/ns/activitystreams",
          "id" => "https://{$server}/@{$username}/followers?domain={$host}",
        "type" => "OrderedCollection",
    "orderedItems" => $items_filtered

    );
    header( "Content-Type: application/activity+json" );
    echo json_encode( $followers );
    die();
  }

  //  Inbox:
  //  The `/inbox` is the main server. It receives all requests. 
  function inbox() {
    global $body, $server, $username, $key_private, $directories, $userfolder;

    //  Get the message, type, and ID
    $inbox_message = $body;
    $inbox_type = $inbox_message["type"];

    //  This inbox only sends responses to follow / unfollow requests.
    //  A remote server sends the inbox a follow request which is a JSON file saying who they are.
    //  The details of the remote user's server is saved to a file so that future messages can be delivered to the follower.
    //  An accept request is cryptographically signed and POST'd back to the remote server.
    if ( "Follow" == $inbox_type ) { 
      //  Validate HTTP Message Signature
      if ( !verifyHTTPSignature() ) { 
        header("HTTP/1.1 401 Unauthorized");
        die(); 
      }

      //  Get the parameters
      $follower_id    = $inbox_message["id"];    //  E.g. https://mastodon.social/(unique id)
      $follower_actor = $inbox_message["actor"]; //  E.g. https://mastodon.social/users/Edent
      
      //  Get the actor's profile as JSON
      $follower_actor_details = getDataFromURl( $follower_actor );

      //  Save the actor's data in `/data/followers/`
      $follower_filename = urlencode( $follower_actor );
      file_put_contents( $directories["followers"] . "/{$follower_filename}.json", json_encode( $follower_actor_details ) );
      
      //  Get the new follower's Inbox
      $follower_inbox = $follower_actor_details["inbox"];

      //  Response Message ID
      //  This isn't used for anything important so could just be a random number
      $guid = uuid();

      //  Create the Accept message to the new follower
      $message = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "https://{$server}/{$guid}",
        "type"     => "Accept",
        "actor"    => "https://{$server}/@{$username}",
        "object"   => [
          "@context" => "https://www.w3.org/ns/activitystreams",
          "id"       =>  $follower_id,
          "type"     =>  $inbox_type,
          "actor"    =>  $follower_actor,
          "object"   => "https://{$server}/@{$username}",
        ]
      ];

      //  The Accept is POSTed to the inbox on the server of the user who requested the follow
      sendMessageToSingle( $follower_inbox, $message );
    } else if ( "Like" == $inbox_type || "Announce" == $inbox_type ) { 
      if ( !verifyHTTPSignature() ) { 
        header("HTTP/1.1 401 Unauthorized");
        die(); 
      }

      $actor = $inbox_message["actor"];
      $object = $inbox_message["object"];
      if (!str_starts_with($object,"https://{$server}/@{$username}/posts/")) {
        header("HTTP/1.1 404 Not Found");
        save_log( "Error", "starts with" );
        die();
      }

      $action_files = glob( $directories["inbox"] . "/*.{$inbox_type}.json" );
      foreach ($action_files as $action_file) {
        $action_data = json_decode( file_get_contents( $action_file ), true );
        if ($action_data && $action_data["actor"]==$inbox_message["actor"]
                         && $action_data["object"]==$inbox_message["object"])
          die();
      }
      
      $postfile=explode("/",$object);
      $postfile=end($postfile);
      if (strpos($postfile,".json")===false) $postfile="{$postfile}.json";
      if (!file_exists($directories["posts"]."/{$postfile}")) {
        header("HTTP/1.1 404 Not Found");
        save_log( "Error", "not found ".$directories["posts"]."/{$postfile}" );
        die();
      }

      $note = json_decode(file_get_contents($directories["posts"]."/{$postfile}"),true);
      
      if (!$note) {
        header("HTTP/1.1 404 Not Found");
        save_log( "Error", "not loaded ".$note );
        die();
      }

      $action="Like" == $inbox_type ? "likes" : "shares";

      if (!isset($note[$action])) {
        $note[$action]=array(
          "id" => "https://{$server}/@{$username}/posts/{$action}/{$postfile}",
          "type" => "Collection",
          "totalItems" => 0
        );
      }
      
      $note[$action]["totalItems"]++;
      $note_json = json_encode( $note );
      file_put_contents( $directories["posts"] . "/{$postfile}", print_r( $note_json, true ) );
      header("HTTP\/1.1 202 Accepted");

    } else if ("Undo" == $inbox_type) {

      if (!isset($inbox_message["object"]) || !isset($inbox_message["object"]["type"]) ||
          $inbox_message["object"]["type"]!="Follow" || $inbox_message["object"]["type"]!="Like" ||
          $inbox_message["object"]["type"]!="Announce") {
        die(); 
      }

      if ($inbox_message["object"]["type"]!="Follow") {
        //  Get a list of every account following us
        //  Get all the files 
        $followers_files = glob( $directories["followers"] . "/*.json" );
        //  Create a list of all accounts being followed
        $followers_ids = array();
        foreach ( $followers_files as $follower_file ) {
          $follower = json_decode( file_get_contents( $follower_file ), true );
          $followers_ids[] = $follower["id"];
        }
        //  Is this from someone following us?
        $from_follower = in_array( $inbox_message["actor"], $followers_ids );
        //  As long as one of these is true, the server will process it
        if ( !$from_follower ) {
          //  Don't bother processing it at all.
          die();
        }
      } 

      //  Validate HTTP Message Signature
      if ( !verifyHTTPSignature() ) die();

      undo( $inbox_message ); 
    } else if ("Create" == $inbox_type) {
      if ( !verifyHTTPSignature() ) die();
      header("HTTP\/1.1 202 Accepted");
    } else {
      die();
    }
    
    //  If the message is valid, save the message in `/data/inbox/`
    $uuid = uuid( $inbox_message );
    $inbox_filename = $uuid . "." . urlencode( $inbox_type ) . ".json";
    file_put_contents( $directories["inbox"] . "/{$inbox_filename}", json_encode( $inbox_message ) );

    die();
  }

  //  Unique ID:
  //  Every message sent should have a unique ID. 
  //  This can be anything you like. Some servers use a random number.
  //  I prefer a date-sortable string.
  function uuid( $message = null) {
    //  UUIDs that this server *sends* will be [timestamp]-[random]
    //  65e99ab4-5d43-f074-b43e-463f9c5cf05c
    if ( is_null( $message ) ) {
      return sprintf( "%08x-%04x-%04x-%04x-%012x",
        time(),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffffffffffff)
      );
    } else {
      //  UUIDs that this server *saves* will be [timestamp]-[hash of message ID]
      //  65eadace-8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4

      //  The message might have its own object
      if ( isset( $message["object"]["id"] ) ) {
        $id = $message["object"]["id"];
      } else {
        $id = $message["id"];
      }

      return sprintf( "%08x", time() ) . "-" . hash( "sha256", $id );
    }
  }

  //  Headers:
  //  Every message that your server sends needs to be cryptographically signed with your Private Key.
  //  This is a complicated process.
  //  Please read https://blog.joinmastodon.org/2018/07/how-to-make-friends-and-verify-requests/ for more information.
  function generate_signed_headers( $message, $host, $path, $method ) {
    global $server, $username, $key_private;
  
    //  Location of the Public Key
    $keyId  = "https://{$server}/@{$username}#main-key";

    //  Get the Private Key
    $signer = openssl_get_privatekey( $key_private );

    //  Timestamp this message was sent
    $date   = date( "D, d M Y H:i:s \G\M\T" );

    //  There are subtly different signing requirements for POST and GET.
    if ( "POST" == $method ) {
      //  Encode the message object to JSON
      $message_json = json_encode( $message );
      //  Generate signing variables
      $hash   = hash( "sha256", $message_json, true );
      $digest = base64_encode( $hash );

      //  Sign the path, host, date, and digest
      $stringToSign = "(request-target): post $path\nhost: $host\ndate: $date\ndigest: SHA-256=$digest";
      
      //  The signing function returns the variable $signature
      //  https://www.php.net/manual/en/function.openssl-sign.php
      openssl_sign(
        $stringToSign, 
        $signature, 
        $signer, 
        OPENSSL_ALGO_SHA256
      );
      //  Encode the signature
      $signature_b64 = base64_encode( $signature );

      //  Full signature header
      $signature_header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="' . $signature_b64 . '"';

      //  Add Follower Synchronization Header
      //  https://docs.joinmastodon.org/spec/activitypub/#follower-synchronization-mechanism
      $sync = "collectionId='https://{$server}/@{$username}/followers," . 
              "url='https://{$server}/@{$username}/followers_synchronization'," .
          "digest='" . followers_digest( $host ) . "'";

      //  Header for POST request
      $headers = array(
                "Host: {$host}",
                "Date: {$date}",
              "Digest: SHA-256={$digest}",
           "Signature: {$signature_header}",
        "Content-Type: application/activity+json",
              "Accept: application/activity+json",
  "Collection-Synchronization: {$sync}", 
      );
    } else if ( "GET" == $method ) {  
      //  Sign the path, host, date - NO DIGEST because there's no message sent.
      $stringToSign = "(request-target): get $path\nhost: $host\ndate: $date";
      
      //  The signing function returns the variable $signature
      //  https://www.php.net/manual/en/function.openssl-sign.php
      openssl_sign(
        $stringToSign, 
        $signature, 
        $signer, 
        OPENSSL_ALGO_SHA256
      );
      //  Encode the signature
      $signature_b64 = base64_encode( $signature );

      //  Full signature header
      $signature_header = 'keyId="' . $keyId . '",algorithm="rsa-sha256",headers="(request-target) host date",signature="' . $signature_b64 . '"';

      //  Header for GET request
      $headers = array(
                "Host: {$host}",
                "Date: {$date}",
           "Signature: {$signature_header}",
              "Accept: application/activity+json, application/json",
      );
    }

    return $headers;
  }

  function followers_digest( $host ) {
    global $server, $directories;

    //  Get all the current followers 
    $follower_files = glob( $directories["followers"] . "/*.json" );

    //  Return only the users from this domain
    $items_filtered = [];

    foreach ( $follower_files as $follower_file ) {
      $following = json_decode( file_get_contents( $follower_file ), true );
      $following_id = $following["id"];
      $following_domain = parse_url( $following_id )["host"];
      if ( $host === $following_domain) {
        $items_filtered[] = $following_id;
      }
    }

    //  Get the XOR of the SHA256 hashes
    $xor_result = array_fill( 0, 32, 0 );  //  SHA256 hashes are 32 bytes long

    foreach ( $items_filtered as $item ) {
      $hash = hash( "sha256", $item, true );
      for ( $i = 0; $i < 32; $i++ ) {
        $xor_result[$i] ^= ord($hash[$i]);  //  XOR each byte
      }
    }

    //  Return the hex representation
      return bin2hex( implode( "", array_map( "chr", $xor_result ) ) );  //  Convert to hex
  }

  // Mastodon .well-known/host-meta
  function meta() {
    global $server;

    header('Content-Type: application/xrd+xml');

echo <<< XML
<?xml version="1.0" encoding="UTF-8"?>
<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0">
  <Link rel="lrdd" template="https://{$server}/.well-known/webfinger?resource={uri}"/>
</XRD>
XML;
    die();  
  }

  // User Interface for Homepage.
  // This creates a basic HTML page. This content appears when someone visits the root of your site.
  function view( $style ) {
    global $username, $users, $userfolder, $server, $realName, $summary, $directories;

    if ($style=="home") {
      $message_files = glob( "{$userfolder}/*/posts/*.json");
    } else if ($style=="userhome") {
      $message_files = glob( $directories["posts"] . "/*.json");
    }

    print_header($style);
    print_sidebar($style);

echo <<< HTML
      <section id="notes">
        <h2>Последни съобщения</h2>

HTML;
    print_notes($message_files);

echo <<< HTML
      </section>
    </main>

HTML;
    print_footer();

    die();
  }

  function print_header($style) {
    global $server, $username, $realName, $summary, $directories;

    if ($style=="home") {
      $url="https://{$server}";
      $title="Main Page Title";
      $description="Main page description";
      $image="https://{$server}/res/banner.png";
    } else {
      $url="https://{$server}/@$username";
      $title="@$username | {$realName}";
      $description=$summary;
      $image="https://{$server}/@{$username}/banner.png";
    }
echo <<< HTML
<!DOCTYPE html>
<html lang="bg">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <meta http-equiv="author" content="Your Name" />
  <meta http-equiv="contact" content="your email" />
  <meta name="keywords" content="some keywords" />

  <link rel="image_src" type="image/jpg" href="{$image}" />

  <meta itemprop="name" content="{$title}"/>
  <meta itemprop="image" content="{$image}"/>
  <meta itemprop="description" content="{$description}"/>

  <meta property="og:url" content="$url">
  <meta property="og:type" content="website">
  <meta property="og:title" content="{$title}">
  <meta property="og:description" content="{$description}">
  <meta property="og:image" content="{$image}">

  <link rel="stylesheet" href="/res/styles.css">
</head>
<body>
  <div>
    <main>

HTML;

    if ($style=="home") {
echo <<< HTML
      <header><div><img src="https://{$server}/res/banner.png" class="header-banner"/></div></header>
      <section id="main-content">
        <h2>Main Page Title</h2>
        <p>
Details about this page
        </p>
        <ul>
          <li><a href="https://link">text</a></li>
          <li><a href="https://link">text</a></li>
          <li><a href="https://link">text</a></li>
          <li><a href="https://link">text</a></li>
        </ul> 
      </section>

HTML;
    } else {
       //  Counters for followers, following, and posts
      $follower_files  = glob( $directories["followers"] . "/*.json" );
      $totalFollowers  = count( $follower_files );
      $following_files = glob( $directories["following"] . "/*.json" );
      $totalFollowing  = count( $following_files );
      $previousPage = $style=="userhome"?"/":"https://{$server}/@{$username}";
echo <<< HTML
      <div><a href="{$previousPage}">↩</a></div>
      <header>
        <div>
          <img src="https://{$server}/@{$username}/res/banner.png" alt="@{$username} banner" class="header-banner"/>
          <img src="https://{$server}/@{$username}/res/icon.png" alt="@{$username} profile pic"/>
        </div>
        <address>
          <h1>{$realName}</h1>
          <h2>
            <a rel="author" href="https://{$server}/@{$username}">@{$username}@{$server}</a>
            <br/>
            <a href="https://{$server}/@{$username}" target="_blank" class="button-mastodon">Mastodon</a>
            <a href="https://bsky.app/profile/{$username}.@{$server}.ap.brid.gy" target="_blank" class="button-bluesky">BlueSky</a>
            <a href="https://twitter.com/{$username}" target="_blank" class="button-twitter">Twitter</a>
          </h2>
          <div id="infobox"><span></span></div>
        </address>
        <summary>{$summary}</summary>
        <p>Follows: {$totalFollowing} | Followers: {$totalFollowers}</p>
      </header>

HTML;
    }
  }

  function print_sidebar($style) {
    global $users, $server;

    $asideclass="";
    if ($style!="home") $asideclass=" class=\"userhome\"";
echo <<< HTML
      <aside{$asideclass}>
        <h2>Profiles</h2>

HTML;
     foreach ($users as $user) {
echo <<< HTML
        <div>
          <a href="https://{$server}/@{$user}"><img src="https://{$server}/@{$user}/icon.png" alt="Profile picture for @{$user}"></a>
          <p><a href="https://{$server}/@{$user}">@{$user}</a></p>
        </div>

HTML;
      }
echo <<< HTML
      </aside>

HTML;
  }

  function print_footer() {
echo <<< HTML
  </div>
  <footer>
    <ul>
      <li><a href="https://link">text</a></li>
      <li><a href="https://link">text</a></li>
      <li><a href="https://link">text</a></li>
    </ul>
    <ul>
      <li><a href="https://link">text</a></li>
    </ul>
  </footer>
  <script type="text/javascript" src="https://d3js.org/d3.v7.min.js"></script>
  <script type="text/javascript" src="/res/scripts.js"></script>  
</body>
</html>
HTML;
  }

  function print_notes($message_files, $max=200) {
    global $server;

    $messages_ordered = [];
    foreach ( $message_files as $message_file ) {
      if (!$message_file) continue;
      $message = json_decode( file_get_contents( $message_file ), true );

      if ( $message["to"][0] != "https://www.w3.org/ns/activitystreams#Public" )
        continue;

      $published = $message["published"];
      $messages_ordered[$published] = $message;
    }    
    krsort($messages_ordered);
    $messages_ordered = array_slice(array_values($messages_ordered), 0, $max); 

    if (!$messages_ordered) {
echo <<< HTML
        <article>
          <div class="nonotes">
            No new notes.
          </div>
        </article>
        <div class="nonotes"/>

HTML;
      return;
    } 

    $allowed_elements = ["p", "span", "br", "a", "del", "pre", "code", "em", "strong", "b", "i", "u", "ul", "ol", "li", "blockquote"];

    foreach ( $messages_ordered as $message ) {  

      $id = $message["id"];
      $published = $message["published"];
      $published = str_replace("T"," ",str_replace("+02:00","",$published));
    
      $likes = isset($message["likes"])?$message["likes"]["totalItems"]:0;
      $shares = isset($message["shares"])?$message["shares"]["totalItems"]:0;
      $likes=$likes>0?($likes==1?"One like":"{$likes} likes")." | ":"";
      $shares=$shares>0?($shares==1?"One share":"{$shares} shares")." | ":"";

      $actor = $message["attributedTo"];
      $actorArray    = explode( "/", $actor );
      $actorName     = str_replace("@","",end( $actorArray ));
  
      $content = $message["content"];
      $content = strip_tags( $content, $allowed_elements );
      $mediaContent = "";

      if ( isset( $message["attachment"] ) ) {
        foreach ( $message["attachment"] as $attachment ) {            
          if ( isset( $attachment["mediaType"] ) ) {
            $mediaURl = $attachment["url"];
            $mime = $attachment["mediaType"];
            $mediaType = explode( "/", $mime )[0];

            if ( "image" == $mediaType ) {
              isset( $attachment["name"] ) ? $alt = htmlspecialchars( $attachment["name"] ) : $alt = "";
              $mediaContent .= "<img src='{$mediaURl}' alt='{$alt}'>";
            } else if ( "video" == $mediaType ) {
              $mediaContent .= "<video controls><source src='{$mediaURl}' type='{$mime}'></video>";
            }else if ( "audio" == $mediaType ) {
              $mediaContent .= "<audio controls src='{$mediaURl}' type='{$mime}'></audio>";
            }
          }
        }
      }

echo <<< HTML
        <article>
          <div>
            <a href="{$actor}"><img src="https://{$server}/@{$actorName}/icon.png" alt="Profile picture for @{$actorName}"></a>
            <a href="{$actor}">@{$actorName}</a>
          </div>
          <blockquote>
            {$content}
            <p>{$mediaContent}</p>
          </blockquote>
          <div class="notedetails">
            {$likes}{$shares}
            <a href="{$id}" rel="bookmark">{$published}</a>
          </div>
        </article>

HTML;
    }
  }

  function posts($path) {
    global $directories, $username, $userfolder;
    
    $pathParts = explode("/",$path);
    $postUUID = array_pop($pathParts);
    $postUUID = str_replace(".json","",$postUUID);
    $postPath = $directories["posts"]."/{$postUUID}.json";
    if (!file_exists($postPath)) {
      header("HTTP/1.1 404 Not Found");
      die();
    }

    $postData = file_get_contents($postPath);

    if (isAcceptingHTML()) {
      print_header("note");
      print_sidebar("note");

echo <<< HTML
      <section id="notes-single">

HTML;

    print_notes(array($postPath));

echo <<< HTML
      </section>

HTML;
echo <<< HTML
      <section id="notes">
        <h2>Lates notes</h2>

HTML;

    $message_files = glob( $directories["posts"] . "/*.json");
    $mfpos = array_search($postPath,$message_files);
    if ($mfpos!==false) $message_files[$mfpos]=null;
    print_notes($message_files,5);

echo <<< HTML
      </section>
    </main>

HTML;

      print_footer();
      die();
    }

    $action = array_pop($pathParts);
    if ($action!="posts" && $action!="likes" && $action!="shares") {
      header("HTTP/1.1 404 Not Found");
      die();
    } 

    header( "Content-Type: application/activity+json" );

    if ($action=="posts") {
      echo $postData;
    } else {
      $postData=json_decode($postData,true);
      echo json_encode($postData[$action]);
    }
  }
  
  //  Send Endpoint:
  //  This takes the submitted message and checks the password is correct.
  //  It reads all the followers' data in `data/followers`.
  //  It constructs a list of shared inboxes and unique inboxes.
  //  It sends the message to every server that is following this account.
  function send() {
    global $password, $server, $username, $key_private, $directories, $language;

    //  Does the posted password match the stored password?
    if( $password != $_POST["password"] ) { 
      header("HTTP/1.1 401 Unauthorized");
      echo "Wrong password.";
      die(); 
    }
    
    //  Get the posted content
    $content = $_POST["content"];
    //  Process the content into HTML to get hashtags etc
    list( "HTML" => $content, "TagArray" => $tags ) = process_content( $content );

    //  Is this a reply?
    if ( isset( $_POST["inReplyTo"] ) && filter_var( $_POST["inReplyTo"], FILTER_VALIDATE_URL ) ) {
      $inReplyTo = $_POST["inReplyTo"];
    } else {
      $inReplyTo = null;
    }

    //  Is this a Direct Message?
    //  https://seb.jambor.dev/posts/understanding-activitypub/#direct-messages
    if ( isset( $_POST["DM"] ) ) {
      $dm_name = $_POST["DM"];
      $details = getUserDetails( $dm_name );
      $profileInbox = $details["inbox"];
      $to = $details["profile"];
      $cc = null;
      $tags[] = array( "type" => "Mention", "href" => $to, "name" => $dm_name );
    } else {
      //  This is a public message
      $to = "https://www.w3.org/ns/activitystreams#Public";
      $cc = "https://{$server}/@{$username}/followers";
    }


    //  Is there an image attached?
    if ( isset( $_FILES['image']['tmp_name'] ) && ( "" != $_FILES['image']['tmp_name'] ) ) {
      //  Get information about the image
      $image      = $_FILES['image']['tmp_name'];
      $image_info = getimagesize( $image );
      $image_ext  = image_type_to_extension( $image_info[2] );
      $image_mime = $image_info["mime"];

      //  Files are stored according to their hash
      //  A hash of "abc123" is stored in "/images/abc123.jpg"
      $sha1 = sha1_file( $image );
      $image_full_path = $directories["images"] . "/{$sha1}{$image_ext}";

      //  Move media to the correct location
      move_uploaded_file( $image, $image_full_path );

      //  Get the alt text
      if ( isset( $_POST["alt"] ) ) {
        $alt = $_POST["alt"];
      } else {
        $alt = "";
      }

      //  Construct the attachment value for the post
      $attachment = array( [
        "type"      => "Image",
        "mediaType" => "{$image_mime}",
        "url"       => "https://{$server}/{$image_full_path}",
        "name"      => $alt
      ] );
    } else {
      $attachment = [];
    }

    //  Current time - ISO8601
    $timestamp = date( "c" );

    //  Outgoing Message ID
    $guid = uuid();

    //  Construct the Note
    //  `contentMap` is used to prevent unnecessary "translate this post" pop ups
    $note = [
      "@context"     => array(
        "https://www.w3.org/ns/activitystreams"
      ),
      "id"           => "https://{$server}/@{$username}/posts/{$guid}",
      "type"         => "Note",
      "published"    => $timestamp,
      "attributedTo" => "https://{$server}/@{$username}",
      "inReplyTo"    => $inReplyTo,
      "content"      => $content,
      "contentMap"   => ["{$language}" => $content],
      "to"           => [$to],
      "cc"           => [$cc],
      "tag"          => $tags,
      "attachment"   => $attachment
    ];

    //  Construct the Message
    //  The audience is public and it is sent to all followers
    $message = [
      "@context" => "https://www.w3.org/ns/activitystreams",
      "id"       => "https://{$server}/@{$username}/posts/{$guid}",
      "type"     => "Create",
      "actor"    => "https://{$server}/@{$username}",
      "to"       => [$to],
      "cc"       => [$cc],
      "object"   => $note
    ];
    

    //  Save the permalink
    $note_json = json_encode( $note );
    file_put_contents( $directories["posts"] . "/{$guid}.json", print_r( $note_json, true ) );

    //  Send the message either publicly or privately
    if ( $cc == null ) {
      $messageSent = sendMessageToSingle( $profileInbox, $message );
    } else {
      //  Send to all the user's followers
      $messageSent = sendMessageToFollowers( $message );
    }
  
    //  Return the JSON so the user can see the POST has worked
    if ( $messageSent ) {
      header( "Location: https://{$server}/@{$username}/posts/{$guid}.json" );
      die();  
    } else {
      header("HTTP/1.1 500 Internal Server Error");
      echo "ERROR!";
      die();
    }
  }


  //  POST a signed message to a single inbox
  //  Used for sending Accept messages to follow requests
  function sendMessageToSingle( $inbox, $message ) {
    global $directories;

    $inbox_host  = parse_url( $inbox, PHP_URL_HOST );
    $inbox_path  = parse_url( $inbox, PHP_URL_PATH );

    //  Generate the signed headers
    $headers = generate_signed_headers( $message, $inbox_host, $inbox_path, "POST" );

    //  POST the message and header to the requester's inbox
    $ch = curl_init( $inbox );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
    curl_setopt( $ch, CURLOPT_POSTFIELDS,     json_encode( $message ) );
    curl_setopt( $ch, CURLOPT_HTTPHEADER,     $headers );
    curl_setopt( $ch, CURLOPT_USERAGENT,      USERAGENT );
    $response = curl_exec( $ch );

    //  Check for errors
    if( curl_errno( $ch ) ) {
      $error_message = curl_error( $ch ) . "\ninbox: {$inbox}\nmessage: " . json_encode($message);
      save_log( "Error", $error_message );
      return false;
    }
    save_log( "Sent-{$inbox_host}", var_export( $headers, true ) . "\n" . json_encode( $message ) . "\n\n" .   $response );
    curl_close( $ch );
    return true;
  }

  //  POST a signed message to the inboxes of all followers
  function sendMessageToFollowers( $message ) {
    global $directories;
    //  Read existing followers
    $followers = glob( $directories["followers"] . "/*.json" );
    
    //  Get all the inboxes
    $inboxes = [];
    foreach ( $followers as $follower ) {
      //  Get the data about the follower
      $follower_info = json_decode( file_get_contents( $follower ), true );

      //  Some servers have "Shared inboxes"
      //  If you have lots of followers on a single server, you only need to send the message once.
      if( isset( $follower_info["endpoints"]["sharedInbox"] ) ) {
        $sharedInbox = $follower_info["endpoints"]["sharedInbox"];
        if ( !in_array( $sharedInbox, $inboxes ) ) { 
          $inboxes[] = $sharedInbox; 
        }
      } else {
        //  If not, use the individual inbox
        $inbox = $follower_info["inbox"];
        if ( !in_array( $inbox, $inboxes ) ) { 
          $inboxes[] = $inbox; 
        }
      }
    }

    //  Some instances of cURL won't work with large numbers of URls.
    //  This splits the resquests into chunks.
    //  Larger numbers will send quicker, but lower numbers are more reliable.
    $batchSize = 15;
    $batches = array_chunk($inboxes, $batchSize);

    foreach ($batches as $batch) {
  
      //  Prepare to use the multiple cURL handle
      //  This makes it more efficient to send many simultaneous messages
      $mh = curl_multi_init();

      //  Arrays to store individual responses
      $responses = [];

      //  Loop through all the inboxes of the followers
      //  Each server needs its own cURL handle
      //  Each POST to an inbox needs to be signed separately
      foreach ( $batch as $inbox ) {
        
        $inbox_host  = parse_url( $inbox, PHP_URL_HOST );
        $inbox_path  = parse_url( $inbox, PHP_URL_PATH );
    
        //  Generate the signed headers
        $headers = generate_signed_headers( $message, $inbox_host, $inbox_path, "POST" );

        //  Save a record of what will be sent
        save_log( "Sent-{$inbox_host}", var_export( $headers, true ) . "\n" . json_encode( $message )  );
        
        //  POST the message and header to the requester's inbox
        $ch = curl_init( $inbox );    
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HEADER,         true );  //  See whether the request was successful
        curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
        curl_setopt( $ch, CURLOPT_POSTFIELDS,     json_encode( $message ) );
        curl_setopt( $ch, CURLOPT_HTTPHEADER,     $headers );
        curl_setopt( $ch, CURLOPT_USERAGENT,      USERAGENT );

        //  Add the handle to the multi-handle
        curl_multi_add_handle( $mh, $ch );
      }

      //  Execute the multi-handle
      do {
        $status = curl_multi_exec( $mh, $active );

        //  Use curl_multi_info_read to see completed requests
        while ( $info = curl_multi_info_read( $mh ) ) {
          //  Get the handle of the completed request
          $ch = $info["handle"];

          //  Store the response for this handle
          //  Use the URL as the array key
          $responses[ curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ] = http_headers_to_array( curl_multi_getcontent( $ch ) );

          //  Remove and close the handle
          curl_multi_remove_handle( $mh, $ch );
          curl_close( $ch );
        }

        if ( $active ) {
          curl_multi_select( $mh );
        }
      } while ( $active && $status == CURLM_OK );

      //  Close the multi-handle
      curl_multi_close( $mh );

      //  Save what happened in each batch
      save_log( "Responses", json_encode( $responses, JSON_PRETTY_PRINT ) );
    }
    return true;
  }

  //  Content can be plain text. But to add clickable links and hashtags, it needs to be turned into HTML.
  //  Tags are also included separately in the note
  function process_content( $content ) {
    global $server;

    //  Convert any URls into hyperlinks
    $link_pattern = '/\bhttps?:\/\/\S+/iu';  //  Sloppy regex
    $replacement = function ( $match ) {
      $url = htmlspecialchars( $match[0], ENT_QUOTES, "UTF-8" );
      return "<a href=\"$url\">$url</a>";
    };
    $content = preg_replace_callback( $link_pattern, $replacement, $content );    

    //  Get any hashtags
    $hashtags = [];
    $hashtag_pattern = '/(?:^|\s)\#(\w+)/';  //  Beginning of string, or whitespace, followed by #
    preg_match_all( $hashtag_pattern, $content, $hashtag_matches );
    foreach ( $hashtag_matches[1] as $match ) {
      $hashtags[] = $match;
    }

    //  Construct the tag value for the note object
    $tags = [];
    foreach ( $hashtags as $hashtag ) {
      $tags[] = array(
        "type" => "Hashtag",
        "name" => "#{$hashtag}",
      );
    }

    //  Add HTML links for hashtags into the text
    //  Todo: Make these links do something.
    $content = preg_replace(
      $hashtag_pattern, 
      " <a href='https://{$server}/tag/$1'>#$1</a>", 
      $content
    );

    //  Detect user mentions
    $usernames = [];
    $usernames_pattern = '/@(\S+)@(\S+)/'; //  This is a *very* sloppy regex
    preg_match_all( $usernames_pattern, $content, $usernames_matches );
    foreach ( $usernames_matches[0] as $match ) {
      $usernames[] = $match;
    }

    //  Construct the mentions value for the note object
    //  This goes in the generic "tag" property
    //  TODO: Add this to the CC field & appropriate inbox
    foreach ( $usernames as $username ) {
      list( , $user, $domain ) = explode( "@", $username );
      $tags[] = array(
        "type" => "Mention",
        "href" => "https://{$domain}/@{$user}",
        "name" => "{$username}"
      );

      //  Add HTML links to usernames
      $username_link = "<a href=\"https://{$domain}/@{$user}\">$username</a>";
      $content = str_replace( $username, $username_link, $content );
    }

    // Construct HTML breaks from carriage returns and line breaks
    $linebreak_patterns = array( "\r\n", "\r", "\n" ); // Variations of line breaks found in raw text
    $content = str_replace( $linebreak_patterns, "<br/>", $content );
    
    //  Construct the content
    $content = "<p>{$content}</p>";

    return [
      "HTML"     => $content, 
      "TagArray" => $tags
    ];
  }

  //  When given the URl of a post, this looks up the post, finds the user, then returns their inbox or shared inbox
  function getInboxFromMessageURl( $url ) {
    
    //  Get details about the message
    $messageData = getDataFromURl( $url );

    //  The author is the user who the message is attributed to
    if ( isset ( $messageData["attributedTo"] ) && filter_var( $messageData["attributedTo"], FILTER_VALIDATE_URL) ) { 
      $profileData = getDataFromURl( $messageData["attributedTo"] );
    } else {
      return null;
    }
    
    //  Get the shared inbox or personal inbox
    if( isset( $profileData["endpoints"]["sharedInbox"] ) ) {
      $inbox = $profileData["endpoints"]["sharedInbox"];  
    } else {
      //  If not, use the individual inbox
      $inbox = $profileData["inbox"];
    }

    //  Return the destination inbox if it is valid
    if ( filter_var( $inbox, FILTER_VALIDATE_URL) ) {
      return $inbox;
    } else {
      return null;
    }
  }

  //  GET a request to a URl and returns structured data
  function getDataFromURl ( $url ) {
    //  Check this is a valid https address
    if( 
      ( filter_var( $url, FILTER_VALIDATE_URL) != true) || 
      (  parse_url( $url, PHP_URL_SCHEME     ) != "https" )
    ) { die(); }

    //  Split the URL
    $url_host  = parse_url( $url, PHP_URL_HOST );
    $url_path  = parse_url( $url, PHP_URL_PATH );

    //  Generate signed headers for this request
    $headers  = generate_signed_headers( null, $url_host, $url_path, "GET" );

    // Set cURL options
    $ch = curl_init( $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
    curl_setopt( $ch, CURLOPT_HTTPHEADER,     $headers );
    curl_setopt( $ch, CURLOPT_USERAGENT,      USERAGENT );

    // Execute the cURL session
    $urlJSON = curl_exec( $ch );

    $status_code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

    // Check for errors
    if ( curl_errno( $ch ) || $status_code == 404 ) {
      // Handle cURL error
      $error_message = curl_error( $ch ) . "\nURl: {$url}\nHeaders: " . json_encode( $headers );
      save_log( "Error", $error_message );
      die();
    } 

    // Close cURL session
    curl_close( $ch );

    return json_decode( $urlJSON, true );
  }

  //  The Outbox contains a date-ordered list (newest first) of all the user's posts
  //  This is optional.
  function outbox() {
    global $server, $username, $directories;

    //  Messages cannot be posted here
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      header("HTTP/1.1 401 Unauthorized");
      die(); 
    }    

    //  Get all posts
    $posts = glob( $directories["posts"] . "/*.json");
    //  Number of posts
    $totalItems = count( $posts );
    //  Create an ordered list
    $orderedItems = [];
    foreach ( $posts as $post ) {
      $postData = json_decode( file_get_contents( $post ), true );
      $orderedItems[] = array(
        "type"   => $postData["type"],
        "actor"  => "https://{$server}/@{$username}",
        "object" => "https://{$server}/{$post}"
      );
    }

    //  Create User's outbox
    $outbox = array(
      "@context"     => "https://www.w3.org/ns/activitystreams",
      "id"           => "https://{$server}/@{$username}/outbox",
      "type"         => "OrderedCollection",
      "totalItems"   =>  $totalItems,
      "summary"      => "All the user's posts",
      "orderedItems" =>  $orderedItems
    );

    //  Render the page
    header( "Content-Type: application/activity+json" );
    echo json_encode( $outbox );
    die();
  }

  //  Verify the signature sent with the message.
  //  This is optional
  //  It is very confusing
  function verifyHTTPSignature() {
    global $input, $body, $server, $directories;

    //  What type of message is this? What's the time now?
    //  Used in the log filename.
    $type = urlencode( $body["type"] );
    $timestamp = ( new DateTime() )->format( DATE_RFC3339_EXTENDED );

    //  Get the headers send with the request
    $headers = getallheaders();
    //  Ensure the header keys match the format expected by the signature 
    $headers = array_change_key_case( $headers, CASE_LOWER );

    //  Validate the timestamp
    //  7.2.4 of https://datatracker.ietf.org/doc/rfc9421/ 
    if ( !isset( $headers["date"] ) ) { 
      //  No date set
      //  Filename for the log
      $filename  = "{$type}.Signature.Date_Failure";

      //  Save headers and request data to the timestamped file in the logs directory
      $message =  "Original Body:\n"    . print_r( $body, true )       . "\n\n" .
                  "Original Headers:\n" . print_r( $headers, true )    . "\n\n";

      save_log( $filename, $message );
      return null; 
    }
    $dateHeader = $headers["date"];
    $headerDatetime  = DateTime::createFromFormat('D, d M Y H:i:s T', $dateHeader);
    $currentDatetime = new DateTime();

    //  First, check if the message was sent no more than ± 1 hour
    //  https://github.com/mastodon/mastodon/blob/82c2af0356ff888e9665b5b08fda58c7722be637/app/controllers/concerns/signature_verification.rb#L11
    // Calculate the time difference in seconds
    $timeDifference = abs( $currentDatetime->getTimestamp() - $headerDatetime->getTimestamp() );
    if ( $timeDifference > 3600 ) { 
      //  Write a log detailing the error
      //  Filename for the log
      $filename  = "{$type}.Signature.Delay_Failure";

      //  Save headers and request data to the timestamped file in the logs directory
      $message =  
        "Header Date:\n"      . print_r( $dateHeader, true ) . "\n" .
        "Server Date:\n"      . print_r( $currentDatetime->format('D, d M Y H:i:s T'), true ) ."\n" .
        "Original Body:\n"    . print_r( $body, true )       . "\n\n" .
        "Original Headers:\n" . print_r( $headers, true )    . "\n\n";
      save_log( $filename, $message );
      return false; 
    }

    if (array_key_exists("published",$body)) {
      //  Is there a significant difference between the Date header and the published timestamp?
      //  Two minutes chosen because Friendica is frequently more than a minute skewed
      $published = $body["published"];
      $publishedDatetime = new DateTime($published);
      // Calculate the time difference in seconds
      $timeDifference = abs( $publishedDatetime->getTimestamp() - $headerDatetime->getTimestamp() );
      if ( $timeDifference > 120 ) { 
        //  Write a log detailing the error
        //  Filename for the log
        $filename  = "{$type}.Signature.Time_Failure";

        //  Save headers and request data to the timestamped file in the logs directory
        $message = 
          "Header Date:\n"      . print_r( $dateHeader, true ) . "\n" .
          "Published Date:\n"   . print_r( $publishedDatetime->format('D, d M Y H:i:s T'), true ) ."\n" .
          "Original Body:\n"    . print_r( $body, true )       . "\n\n" .
          "Original Headers:\n" . print_r( $headers, true )    . "\n\n";
        
        save_log( $filename, $message );
        return false; 
      }
    }

    //  Validate the Digest
    //  It is the hash of the raw input string, in binary, encoded as base64.
    $digestString = $headers["digest"];

    //  Usually in the form `SHA-256=Ofv56Jm9rlowLR9zTkfeMGLUG1JYQZj0up3aRPZgT0c=`
    //  The Base64 encoding may have multiple `=` at the end. So split this at the first `=`
    $digestData = explode( "=", $digestString, 2 );
    $digestAlgorithm = $digestData[0];
    $digestHash = $digestData[1];

    //  There might be many different hashing algorithms
    //  TODO: Find a way to transform these automatically
    //  See https://github.com/superseriousbusiness/gotosocial/issues/1186#issuecomment-1976166659 and https://github.com/snarfed/bridgy-fed/issues/430 for hs2019
    if ( "SHA-256" == $digestAlgorithm || "hs2019" == $digestAlgorithm ) {
      $digestAlgorithm = "sha256";
    } else if ( "SHA-384" == $digestAlgorithm ) {
      $digestAlgorithm = "sha384";
    } else if ( "SHA-512" == $digestAlgorithm ) {
      $digestAlgorithm = "sha512";
    }

    //  Manually calculate the digest based on the data sent
    $digestCalculated = base64_encode( hash( $digestAlgorithm, $input, true ) );

    //  Does our calculation match what was sent?
    if ( !( $digestCalculated == $digestHash ) ) { 
      //  Write a log detailing the error
      $filename  = "{$type}.Signature.Digest_Failure";

      //  Save headers and request data to the timestamped file in the logs directory
      $message = 
        "Original Input:\n"    . print_r( $input, true )    . "\n" .
        "Original Digest:\n"   . print_r( $digestString, true ) . "\n" .
        "Calculated Digest:\n" . print_r( $digestCalculated, true ) . "\n";

      save_log( $filename, $message );
      return false; 
    }

    //  Examine the signature
    $signatureHeader = $headers["signature"];

    // Extract key information from the Signature header
    $signatureParts = [];
    //  Converts 'a=b,c=d e f' into ["a"=>"b", "c"=>"d e f"]
                   // word="text"
    preg_match_all('/(\w+)="([^"]+)"/', $signatureHeader, $matches);
    foreach ( $matches[1] as $index => $key ) {
      $signatureParts[$key] = $matches[2][$index];
    }

    //  Manually reconstruct the header string
    $signatureHeaders = explode(" ", $signatureParts["headers"] );
    $signatureString = "";
    foreach ( $signatureHeaders as $signatureHeader ) {
      if ( "(request-target)" == $signatureHeader ) {
        $method = strtolower( $_SERVER["REQUEST_METHOD"] );
        $target =             $_SERVER["REQUEST_URI"];
        $signatureString .= "(request-target): {$method} {$target}\n";
      } else if ( "host" == $signatureHeader ) {
        $host = strtolower( $_SERVER["HTTP_HOST"] );  
        $signatureString .= "host: {$host}\n";
      } else {
        $signatureString .= "{$signatureHeader}: " . $headers[$signatureHeader] . "\n";
      }
    }

    //  Remove trailing newline
    $signatureString = trim( $signatureString );

    //  Get the Public Key
    //  The link to the key might be sent with the body, but is always sent in the Signature header.
    $publicKeyURL = $signatureParts["keyId"];

    //  This is usually in the form `https://example.com/user/username#main-key`
    //  This is to differentiate if the user has multiple keys
    //  TODO: Check the actual key
    $userData  = getDataFromURl( $publicKeyURL );
    $publicKey = $userData["publicKey"]["publicKeyPem"];

    //  Check that the actor's key is the same as the key used to sign the message
    //  Get the actor's public key
    $actorData = getDataFromURl( $body["actor"] );
    $actorPublicKey = $actorData["publicKey"]["publicKeyPem"];

    if ( $publicKey != $actorPublicKey ) {  
      //  Filename for the log
      $filename  = "{$type}.Signature.Mismatch_Failure";

      //  Save headers and request data to the timestamped file in the logs directory
      $message = 
        "Original Body:\n"              . print_r( $body, true )             . "\n\n" .
        "Original Headers:\n"           . print_r( $headers, true )          . "\n\n" .
        "Signature Headers:\n"          . print_r( $signatureHeaders, true ) . "\n\n" .
        "publicKeyURL:\n"               . print_r( $publicKeyURL, true )     . "\n\n" .
        "publicKey:\n"                  . print_r( $publicKey, true )        . "\n\n" .
        "actorPublicKey:\n"             . print_r( $actorPublicKey, true )   . "\n";

      save_log( $filename, $message );
      return false;
    }
    
    //  Get the remaining parts
    $signature = base64_decode( $signatureParts["signature"] );
    $algorithm = $signatureParts["algorithm"];

    //  There might be many different signing algorithms
    //  TODO: Find a way to transform these automatically
    //  See https://github.com/superseriousbusiness/gotosocial/issues/1186#issuecomment-1976166659 and https://github.com/snarfed/bridgy-fed/issues/430 for hs2019
    if ( "hs2019" == $algorithm || "rsa-sha256" == $algorithm ) {
      $algorithm = "sha256";
    } elseif ( "rsa-sha384" == $algorithm ) {
      $algorithm = "sha384";
    } elseif ( "rsa-sha512" == $algorithm ) {
      $algorithm = "sha512";
    }

    //  Finally! Calculate whether the signature is valid
    //  Returns 1 if verified, 0 if not, false or -1 if an error occurred
    $verified = openssl_verify(
      $signatureString, 
      $signature, 
      $publicKey, 
      $algorithm
    );

    //  Convert to boolean
    if ( $verified === 1 ) {
      $verified = true;
    } elseif ( $verified === 0 ) {
      $verified = false;
    } else {
      $verified = null;
    }
  
    //  Filename for the log
    $filename  = "{$type}.Signature.". json_encode( $verified );

    //  Save headers and request data to the timestamped file in the logs directory
    $message =
      "Original Body:\n"              . print_r( $body, true )             . "\n\n" .
      "Original Headers:\n"           . print_r( $headers, true )          . "\n\n" .
      "Signature Headers:\n"          . print_r( $signatureHeaders, true ) . "\n\n" .
      "Calculated signatureString:\n" . print_r( $signatureString, true )  . "\n\n" .
      "Calculated algorithm:\n"       . print_r( $algorithm, true )        . "\n\n" .
      "publicKeyURL:\n"               . print_r( $publicKeyURL, true )     . "\n\n" .
      "publicKey:\n"                  . print_r( $publicKey, true )        . "\n\n" .
      "actorPublicKey:\n"             . print_r( $actorPublicKey, true )   . "\n";
    save_log( $filename, $message );
    return $verified;
  }

  //  The NodeInfo Protocol is used to identify servers.
  //  It is looked up with `example.com/.well-known/nodeinfo`
  //  See https://nodeinfo.diaspora.software/
  function wk_nodeinfo() {
    global $server;

    $nodeinfo = array(
      "links" => array(
        array(
           "rel" => "self",
          "type" => "http://nodeinfo.diaspora.software/ns/schema/2.1",
          "href" => "https://{$server}/nodeinfo/2.1"
        )
      )
    );
    header( "Content-Type: application/json" );
    echo json_encode( $nodeinfo );
    die();
  }

  //  The NodeInfo Protocol is used to identify servers.
  //  It is looked up with `example.com/.well-known/nodeinfo` which points to this resource
  //  See http://nodeinfo.diaspora.software/docson/index.html#/ns/schema/2.0#$$expand
  function nodeinfo() {
    global $server, $directories, $userfolder;

    $nodeinfo = array(
      "version" => "2.1",  //  Version of the schema, not the software
      "software" => array(
        "name"       => "Single File ActivityPub Server in PHP",
        "version"    => "0.000000001",
        "repository" => "https://gitlab.com/edent/activitypub-single-php-file/"
      ),
      "protocols" => array( "activitypub"),
      "services" => array(
        "inbound"  => array(),
        "outbound" => array()
      ),
      "openRegistrations" => false,
      "usage" => array(
        "users" => array(
          "total" => count(glob("{$userfolder}/*"))
        ),
        "localPosts" => count(glob("{$userfolder}/*/posts/*.json"))
      ),
      "metadata"=> array(
        "nodeName" => "activitypub-single-php-file",
        "nodeDescription" => "This is a single PHP file which acts as an extremely basic ActivityPub server.",
        "spdx" => "AGPL-3.0-or-later"
      )
    );
    header( "Content-Type: application/json" );
    echo json_encode( $nodeinfo );
    die();
  }

  //  Perform the Undo action requested
  function undo( $message ) { 
    global $server, $directories;

    //  Get some basic data
    $type = $message["type"];
    $id   = $message["id"];
    //  The thing being undone
    $object = $message["object"];

    //  Does the thing being undone have its own ID or Type?
    if ( isset( $object["id"] ) ) {
      $object_id   = $object["id"];
    } else {
      $object_id = $id;
    }

    if ( isset( $object["type"] ) ) {
      $object_type   = $object["type"];
    } else {
      $object_type = $type;
    }

    //  Inbox items are stored as the hash of the original ID
    $object_id_hash = hash( "sha256", $object_id ).".{$object_type}";
    save_log("Info","obj $object_id_hash");
    //  Find all the inbox messages which have that ID
    $inbox_files = glob( $directories["inbox"] . "/*.json" );
    foreach ( $inbox_files as $inbox_file ) {
      //  Filenames are `data/inbox/[date]-[SHA256 hash0].[Type].json
      // Find the position of the first hyphen and the first dot
      $hyphenPosition = strpos( $inbox_file, '-' );
      $dotPosition    = strrpos( $inbox_file, '.' );
      
      if ( $hyphenPosition !== false && $dotPosition !== false ) {
        // Extract the text between the hyphen and the first dot
        $file_id_hash = substr( $inbox_file, $hyphenPosition + 1, $dotPosition - $hyphenPosition - 1);
      } else {
        //  Ignore the file and move to the next.
        continue;
      }

      //  If this has the same hash as the item being undone
      if ( $object_id_hash == $file_id_hash ) {
        //  Delete the file
        unlink( $inbox_file );

        //  If this was the undoing of a follow request, remove the external user from followers 😢
        if ( "Follow" == $object_type ) {
          $actor = $object["actor"];
          $follower_filename = urlencode( $actor );
          unlink( $directories["followers"] . "/{$follower_filename}.json" );
        } else if ( "Like" == $object_type ||  "Announce" == $object_type ) {
          if (!isset($object["object"])) die();

          $postfile=explode("/",$object["object"]);
          $postfile=end($postfile);
          if (strpos($postfile,".json")===false) $postfile="{$postfile}.json";
          if (!file_exists($directories["posts"]."/{$postfile}")) die();
    
          $note = json_decode(file_get_contents($directories["posts"]."/{$postfile}"),true);
      
          if (!$note) {
            header("HTTP/1.1 404 Not Found");
            die();
          }

          $action="Like" == $inbox_type ? "likes" : "shares";
  
          if (!property_exists($note,$action)) die();

          $note[$action]["totalItems"]--;
          if ($note[$action]["totalItems"]<0) $note[$action]["totalItems"]=0;

          $note_json = json_encode( $note );
          file_put_contents( $directories["posts"] . "/{$postfile}", print_r( $note_json, true ) );
        }

        //  Stop looping
        break;
      }
    }
  }

  //  Turn @user@example.com into
  //  Profile: https://example.com/users/user
  //  Inbox: https://example.com/system/SharedInbox
  function getUserDetails( $username ) {

    //  Split the user (@user@example.com) into username and server
    list( , $follow_name, $follow_server ) = explode( "@", $username );

    //  Get the Webfinger
    //  This request does not always need to be signed, but safest to do so anyway.
    $webfingerURl  = "https://{$follow_server}/.well-known/webfinger?resource=acct:{$follow_name}@{$follow_server}";
    $webfinger = getDataFromURl( $webfingerURl );

    //  Get the link to the user
    //  The WebFinger *may* contain a link to the user's Inbox or Shared Inbox
    $profileInbox = null;
    foreach( $webfinger["links"] as $link ) {
      if ( "inbox" == $link["rel"] ) {
        $profileInbox = $link["href"];
      }
      if ( "sharedInbox" == $link["rel"] ) {
        $profileInbox = $link["href"];
      }
      if ( "self" == $link["rel"] ) {
        $profileURl = $link["href"];
      }
    }

    //  Get the user's details
    $profileData = getDataFromURl( $profileURl );

    //  No inbox found directly.
    //  Try looking it up in the profile.
    if ( !isset( $profileInbox ) ) {
      //  No inbox and no profile? Something has gone wrong!
      if ( !isset( $profileURl ) ) { echo "No profile" . print_r( $webfinger, true ); die(); }

      // Get the user's inbox
      $profileInbox = $profileData["inbox"];
    }

    return array(
      "profile" => $profileURl,
      "inbox"   => $profileInbox
    );

  }

  //  This receives a request to do something with an external user
  //  It looks up the external user's details
  //  Then it sends a follow / unfollow / block / unblock request
  //  If the follow request is accepted, it saves the details in `data/following/` as a JSON file
  function action_users() {
    global $password, $server, $username, $key_private, $directories;

    //  Does the posted password match the stored password?
    if( $password != $_POST["password"] ) { echo "Wrong Password!"; die(); }

    //  Get the posted content
    $user   = $_POST["user"];
    $action = $_POST["action"];

    //  Is this a valid action?
    if ( match( $action ) {
      "Follow", "Unfollow", "Block", "Unblock" => false,
      default => true,
    } ) {
      //  Discard it, no further processing.
      echo "{$action} not supported";
      die();
    }

    //  Get users' details
    $details = getUserDetails( $user );
    $profileInbox = $details["inbox"];
    $profileURl   = $details["profile"];

    //  Create a user request
    $guid = uuid();

    //  Different user actions have subtly different messages to send.
    if ( "Follow" == $action ) {
      $message = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "https://{$server}/{$guid}",
        "type"     => "Follow",
        "actor"    => "https://{$server}/@{$username}",
        "object"   => $profileURl
      ];
    } else if ( "Unfollow" == $action ) {
      $message = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "https://{$server}/{$guid}",
        "type"     => "Undo",
        "actor"    => "https://{$server}/@{$username}",
        "object"   => array(
          //"id" => null,  //  Should be the original ID if possible, but not necessary https://www.w3.org/wiki/ActivityPub/Primer/Referring_to_activities
          "type" => "Follow",
          "actor" => "https://{$server}/@{$username}",
          "object" => $profileURl
        )
      ];
    } else if ( "Block" == $action ) {
      $message = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "https://{$server}/{$guid}",
        "type"     => "Block",
        "actor"    => "https://{$server}/@{$username}",
        "object"   =>  $profileURl,
        "to"       =>  $profileURl
      ];
    } else if ( "Unblock" == $action ) {
      $message = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "https://{$server}/{$guid}",
        "type"     => "Undo",
        "actor"    => "https://{$server}/@{$username}",
        "object"   => array(
          //"id" => null,  //  Should be the original ID if possible, but not necessary https://www.w3.org/wiki/ActivityPub/Primer/Referring_to_activities
          "type" => "Block",
          "actor" => "https://{$server}/@{$username}",
          "object" => $profileURl
        )
      ];
    }

    // Sign & send the request
    sendMessageToSingle( $profileInbox, $message );

    //  TODO: - unfollow should delete file
    if ( "Follow" == $action ) {
      //  Save the user's details
      $following_filename = urlencode( $profileURl );
      file_put_contents( $directories["following"] . "/{$following_filename}.json", json_encode( $profileData ) );

      //  Render the JSON so the user can see the POST has worked
      header( "Location: https://{$server}/data/following/" . urlencode( $following_filename ) . ".json" );
    } else if ( "Block" == $action || "Unfollow" == $action ) {
      //  Delete the user if they exist in the following directory.
      $following_filename = urlencode( $profileURl );
      unlink( $directories["following"] . "/{$following_filename}.json" );

      //  Let the user know it worked
      echo "{$user} {$action}ed!";
    } else if ( "Unblock" == $action ) {
      //  Let the user know it worked
      echo "{$user} {$action}ed!";
    }
    die();
  } 

  function save_log( $type, $message ) {
    global $directories;

    //  UUIDs that this server *saves* will be [timestamp]-[type]
    //  6e779797e49e7a-Error.txt

    //  Hex representation of the high-precision timestamp
    $filename = sprintf( "%14x", hrtime(true) ) . "-" . $type;

    file_put_contents( 
      $directories["logs"] . "/{$filename}.txt", 
      $message
    );

  }

  function http_headers_to_array( $content ) {
    $headers = [];

    //  Split by newline
    $lines = explode( "\r\n", trim( $content ));
    
    foreach ( $lines as $line ) {
      //  HTTP status line doesn't have a colon
      if ( stripos( $line, "HTTP/" ) === 0) {
        $headers["Status"] = $line;
      } else {
        $parts = explode( ":", $line, 2 );
        if ( count( $parts ) == 2) {
          $headers[trim( $parts[0] )] = trim( $parts[1] );
        }
      }
    }
    return $headers;
  }
//  "One to stun, two to kill, three to make sure"
die();
die();
die();
