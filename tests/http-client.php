<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());
  
  echo 'Started at ', $s = microtime (true), "\n";
  
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');
  
  $Base = new qcEvents_Base;
  
  // Create a new HTTP-Pool and a single request
  // At creation of the test the given URL would redirect twice
  require_once ('qcEvents/Client/HTTP.php');
  
  qcEvents_Client_HTTP::$debugHooks = true;
  
  $Pool = new qcEvents_Client_HTTP ($Base);
  $Pool->addHook ('httpRequestRediect', function (qcEvents_Client_HTTP $Pool, qcEvents_Stream_HTTP_Request $Request, $Location) {
    echo 'Redirecting HTTP-Request for ', $Request->getURL (), ' to ', $Location, "\n";
  });
  $Pool->addHook ('httpRequestResult', function (qcEvents_Client_HTTP $Pool, qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) {
    echo 'Received HTTP-Response for ', $Request->getURL (), ': ';
    
    if ($Header)
      echo "\n", $Header;
    else
      echo 'FAILED', "\n";
  });
  
  # $Request = $Pool->addNewRequest ('https://www.tiggerswelt.net/hosting');
  $Request = $Pool->addNewRequest ('https://mas.anno-online.com/mas/revisiondata/19?platform=ios%20ipad');
  
  // Enter main-loop
  $Base->loop ();
  
  echo 'Finished at ', $e = microtime (true), "\n";
  echo 'Test took ', number_format (($e - $s) * 1000, 2), " ms\n";

?>