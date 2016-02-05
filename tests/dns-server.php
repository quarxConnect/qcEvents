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

  $Pool = new qcEvents_Socket_Server ($Base);
  
  if (!$Pool->listen (qcEvents_Socket_Server::TYPE_UDP, 5353))
    die ('Could not create server-socket' . "\n");
  
  // Setup the Server-Pool as DNS-Server
  require_once ('qcEvents/Server/DNS.php');
  
  if (!$Pool->setChildClass ('qcEvents_Server_DNS', true))
    die ('Cold not set DNS-Child-Class on server' . "\n");
  
  $Pool->addChildHook ('dnsQueryReceived', function (qcEvents_Stream_DNS $Stream, qcEvents_Stream_DNS_Message $Query) {
    $Response = $Query->createResponse ();
    
    if ($Query->getQuestions ()[0]->Label == 'localhost.localnet.stuttgart.') {
      $Response->addAnswer ($Answer = new qcEvents_Stream_DNS_Record_A ('localhost.localnet.stuttgart.', 192342));
      $Answer->setAddress ('127.0.0.1');
      
      $Response->addAnswer ($Answer = new qcEvents_Stream_DNS_Record_A ('localhost.localnet.stuttgart.', 192342));
      $Answer->setAddress ('127.0.0.2');
    } else
      $Response->setError ($Response::ERROR_SERVER);
    
    $Stream->dnsQueryReply ($Response);
  });
  
  // Enter main-loop
  $Base->loop ();
  
  echo 'Finished at ', $e = microtime (true), "\n";
  echo 'Test took ', number_format (($e - $s) * 1000, 2), " ms\n";

?>