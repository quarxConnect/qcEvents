<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());
  
  echo 'Started at ', $s = microtime (true), "\n";
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');
  
  $Base = qcEvents_Base::singleton ();
  
  // Create the listener
  require_once ('qcEvents/Socket/Multicast/Listener.php');
  require_once ('qcEvents/Server/DNS.php');
  
  $Listener = new qcEvents_Socket_Multicast_Listener ($Base);
  $Listener->setChildClass ('qcEvents_Server_DNS', true);
  $Listener->multicastJoinGroup ('224.0.0.251');
  $Listener->bind (5353);
  
  $Listener->addChildHook ('dnsQueryReceived', function (qcEvents_Stream_DNS $Stream, qcEvents_Stream_DNS_Message $Query) {
    $Response = $Query->createResponse ();
    
    if ($Query->getQuestions ()[0]->Label != 'test-mdns.local.')
      return $Stream->dnsQueryDiscard ($Query);
    
    $Response->addAnswer ($Answer = new qcEvents_Stream_DNS_Record_A ('test-mdns.local.', 192342));
    $Answer->setAddress ('127.0.0.1');
    
    $Stream->dnsQueryReply ($Response);
  });
  
  $Base->loop ();

?>