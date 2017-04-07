<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());

  echo 'Started at ', $s = microtime (true), "\n";
  
  // Parse commandline
  $Mailserver = $Username = $Password = null;
  $Port = 587;
  
  for ($p = 1; $p < $argc; $p++)
    if ($argv [$p] == '--server')
      $Mailserver = $argv [++$p];
    elseif ($argv [$p] == '--username')
      $Username = $argv [++$p];
    elseif ($argv [$p] == '--password')
      $Password = $argv [++$p];
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');

  $Base = new qcEvents_Base;
  
  // Setup generic hooks
  require_once ('qcEvents/Socket.php');
  
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
  
  // Create a new SMTP-Client
  require_once ('qcEvents/Client/SMTP.php');
  
  $Client = new qcEvents_Client_SMTP ($Base);
  
  $Client->addHook ('smtpCommand', function ($Client, $Verb, $Params, $Command) {
    echo '> ', $Command, "\n";
  });
  $Client->addHook ('smtpResponse', function ($Client, $Code, $Lines) {
    foreach ($Lines as $Line)
      echo '< ', $Code, ' ', $Line, "\n";
  });
  
  // Try to create a connection with our mailserver
  $Client->connect ($Mailserver, $Port, $Username, $Password, function (qcEvents_Client_SMTP $Client, $Hostname, $Port, $Username = null, $Status) {
    // Check if the connection was established
    if (!$Status)
      die ('Failed to connect to ' . $Hostname . "\n");
    
    
  });

  // Meanwhile enter main-loop
  $Base->loop ();

?>