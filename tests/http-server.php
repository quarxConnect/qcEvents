<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());
  
  echo 'Started at ', $s = microtime (true), "\n";
  
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');
  
  $Base = new qcEvents_Base;
  
  // Create a new Server-Pool
  require_once ('qcEvents/Socket/Server.php');
  
  $Pool = new qcEvents_Socket_Server ($Base, null, 8080, qcEvents_Socket_Server::TYPE_TCP);
  
  // Setup the Server-Pool as HTTP-Server
  require_once ('qcEvents/Server/HTTP.php');
  require_once ('qcEvents/Stream/HTTP/Header.php');
  
  if (!$Pool->setChildClass ('qcEvents_Server_HTTP', true))
    die ('Could not set HTTP-Child-Class on server' . "\n");
  
  $Pool->addChildHook ('httpdRequestReceived', function (qcEvents_Server_HTTP $Server, qcEvents_Stream_HTTP_Header $Request, $Body = null) {
    echo 'Request received for ', $Request->getURI (), "\n";
    
    // Create Response-Header
    $Response = new qcEvents_Stream_HTTP_Header (array ('HTTP/' . $Request->getVersion (true) . ' 200 Ok', 'Server: quarxConnect httpd/0.1', 'Content-Type: text/plain'));
    
    // Set the response
    # $Server->httpdSetResponse ($Request, $Response, 'Hallo Welt!');
    $Server->httpdStartResponse ($Request, $Response);
    $Server->httpdWriteResponse ($Request, 'Hallo ');
    $Server->httpdWriteResponse ($Request, 'Welt!' . "\r\n");
    $Server->httpdFinishResponse ($Request);
  });
  
  // Enter main-loop
  $Base->loop ();
  
  echo 'Finished at ', $e = microtime (true), "\n";
  echo 'Test took ', number_format (($e - $s) * 1000, 2), " ms\n";

?>