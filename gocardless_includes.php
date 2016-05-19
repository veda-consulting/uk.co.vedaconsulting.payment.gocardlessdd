<?php

/*************************************************************
  Send a post request with cURL
    $url = URL to send request to
    $data = POST data to send (in URL encoded Key=value pairs)
*************************************************************/
function requestPostGocardless($url, $path, $header, $data){
  // Set a one-minute timeout for this script
  set_time_limit(160);

  // Initialise output variable
  $output = array();

  $options = array(
                       CURLOPT_RETURNTRANSFER => true, // return web page
                       CURLOPT_HEADER => false, // don't return headers
                       CURLOPT_POST => true,
                       CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                       CURLOPT_USERAGENT => "XYZ Co's PHP iDD Client", // Let Gocardless see who we are
                       CURLOPT_SSL_VERIFYHOST => false,
                       CURLOPT_SSL_VERIFYPEER => false,
                     );
  
  $session = curl_init( $url . $path );
  
  curl_setopt_array( $session, $options );
  

  // Tell curl that this is the body of the POST && header of post
  curl_setopt ($session, CURLOPT_POSTFIELDS, $data);
  curl_setopt ($session, CURLOPT_HTTPHEADER, $header);
  
  // $output contains the output string
  $output = curl_exec($session);
  $header = curl_getinfo( $session );

  
  if(curl_errno($session)) {
    $resultsArray["Status"] = "FAIL";  
    $resultsArray['StatusDetail'] = curl_error($session);
  }
  else {
    // Results are XML so turn this into a PHP Array
    $resultsArray = json_decode($output, TRUE);

    // Determine if the call failed or not
    switch ($header["http_code"]) {
      case 200:
      case 201://https://developer.gocardless.com/2015-07-06/#api-usage-response-codes - Created
        $resultsArray["Status"] = "OK";
        break;
      default:
        $resultsArray["Status"] = "INVALID";
        //echo "HTTP Error: " . $header["http_code"];
    }
  }
   
  // Return the output
  return $resultsArray;
  
} // END function requestPost()

?>