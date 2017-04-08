<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());
  
  echo 'Started at ', $s = microtime (true), "\n";
  
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');
  
  $Base = new qcEvents_Base;
  
  // Hack in DNS64-Prefix
  require_once ('qcEvents/Client/DNS.php');
  
  qcEvents_Client_DNS::$DNS64_Prefix = '64:ff9b::';
  
  // Create a new HTTP-Pool and a single request
  // At creation of the test the given URL would redirect twice
  require_once ('qcEvents/Client/HTTP.php');
  
  # qcEvents_Client_HTTP::$debugHooks = true;
  
  qcEvents_Socket::registerHook ('socketResolve', function ($Socket, $Hostnames, $Types) {
    echo '[RESOLVE] Resolving ', implode (', ', $Hostnames), ' Types ', implode (', ', $Types), "\n";
  });
  
  qcEvents_Socket::registerHook ('socketResolved', function ($Socket, $Hostname, $Addresses) {
    echo '[RESOLVE] Resolved ', $Hostname, ' to ', implode (', ', $Addresses), "\n";
  });
  
  qcEvents_Socket::registerHook ('socketTryConnect', function ($Socket, $Host, $Addr, $Port) {
    echo '[CONNECT] Try to connect to ', $Host, ' via ', $Addr, ' on port ', $Port, "\n";
  });
  
  qcEvents_Socket::registerHook ('socketTryConnectFailed', function ($Socket, $Host, $Addr, $Port) {
    echo '[CONNECT] Connection to ', $Host, ' via ', $Addr, ' on port ', $Port, " failed\n";
  });
  
  qcEvents_Socket::registerHook ('socketConnected', function ($Socket) {
    echo '[CONNECT] Socket connected to ', $Socket->getRemoteName (), "\n";
  });
  
  $Pool = new qcEvents_Client_HTTP ($Base);
  $Pool->addHook ('httpRequestRediect', function (qcEvents_Client_HTTP $Pool, qcEvents_Stream_HTTP_Request $Request, $Location) {
    echo '[HTTP   ] Redirecting HTTP-Request for ', $Request->getURL (), ' to ', $Location, "\n";
  });
  $Pool->addHook ('httpRequestResult', function (qcEvents_Client_HTTP $Pool, qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) use ($s) {
    echo '[HTTP   ] Received HTTP-Response for ', $Request->getURL (), ': ';
    
    if ($Header)
      echo "\n", $Header;
    else
      echo 'FAILED', "\n";
    
    $e = microtime (true);
    echo '[HTTP   ] Result after ', number_format (($e - $s) * 1000, 2), " ms\n";
  });
  
  if ($argc > 1) 
    for ($i = 1; $i < $argc; $i++)
      $Pool->addNewRequest ($argv [$i]);
  else
    $Request = $Pool->addNewRequest ('https://www.tiggerswelt.net/hosting');
  
  // Enter main-loop
  $Base->loop ();
  
  echo 'Finished at ', $e = microtime (true), "\n";
  echo 'Test took ', number_format (($e - $s) * 1000, 2), " ms\n";

?>