#!/usr/bin/php
<?php
	//@todo handle response_uri not existing
	//@todo add more robust error handling
	date_default_timezone_set( "America/Los_Angeles" );
	define( 'TEST_MODE', true );
	define( 'MAX_POSTS_RETURNED', 20 );

	// run via cron * * * * * 
	$ini = parse_ini_file( 'alchemist.ini', true );
	if( time() > strtotime( $ini['tumblr']['end'] ) || time() < strtotime( $ini['tumblr']['start'] ) ) {
		exit(0);
	}

	$tumblr_uri =  sprintf( 'http://api.tumblr.com/v2/blog/%s/posts/?api_key=%s&limit=%d', urlencode( $ini['tumblr']['url'] ), $ini['tumblr']['api_key'], MAX_POSTS_RETURNED );
	$response_uri = sprintf( 'http://www.wired.com/someplace-we-can-get-to/%s', md5( $ini['tumblr']['url']));
	$ch = curl_init();
	curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1) ;

	// grab the file locally if we're in test mode
	if( TEST_MODE ) {
		if(! file_exists( 'test.json' ) ) {
			touch( 'test.json' );
			$server_response = new stdClass;
			$server_response->posts = array();
		} else {
			$json = file_get_contents( 'test.json' );
			$server_response = json_decode( $json );
		}
	} else {
		//get the response_uri
		curl_setopt( $ch, CURLOPT_URL, $response_uri );
		$raw = curl_exec( $ch );
		$server_response = json_decode( $raw['body'] );
	}

	//get the latest tumbl
	curl_setopt( $ch, CURLOPT_URL, $tumblr_uri );
	$raw = curl_exec( $ch );
	$tumblr_response = json_decode( $raw );
	
	// nothing new?  bail
	if(  ( $offset = ( $tumblr_response->response->blog->posts - count( $server_response->posts ) ) ) == 0 ) {
		exit(0);
	}


	// handle the case where there are more posts in offset then the max # of posts returned.
	// if that happens, since we don't know where we are in the array, we need to rebuild the
	// whole shebang.  This will also occur if the # of server_response->posts is 0 (or doesn't exist)
	if( $offset > MAX_POSTS_RETURNED ) {
		$posts = $tumblr_response->response->posts;
		for( $i=MAX_POSTS_RETURNED; $i<$tumblr_response->response->blog->posts; $i=$i+MAX_POSTS_RETURNED ) {
			$url = sprintf( 'http://api.tumblr.com/v2/blog/%s/posts/?api_key=%s&limit=%d&offset=%d', urlencode( $ini['tumblr']['url'] ), $ini['tumblr']['api_key'], MAX_POSTS_RETURNED, $i );
			curl_setopt( $ch, CURLOPT_URL, $url );
			$raw = curl_exec( $ch );
			$res = json_decode( $raw );
			$posts = array_merge( $posts, $res->response->posts );
		}
		$server_response->posts = $posts;
	} else {
		// step through the offset and add them to the front of the page
		// note we're stepping through this in reverse to shift them
		// onto the front in order
		for( $i=$offset-1; $i >= 0; $i-- ) {
			array_unshift( $server_response->posts, $tumblr_response->response->posts[$i] );
		}
	}

	curl_close( $ch );


	// write the file locally if we're in test mode
	if( TEST_MODE ) {
		file_put_contents( 'test.json', json_encode( $server_response ) );
	} else {
		file_put_contents( 'remote_server', json_encode( $server_response ) );
	}	
	
	exit(0);
?>