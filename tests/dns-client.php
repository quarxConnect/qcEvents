<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());
  
  echo 'Started at ', $s = microtime (true), "\n";
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');
  
  $Base = new qcEvents_Base;
  
  // Create a new DNS-Pool and a single request
  require_once ('qcEvents/Client/DNS.php');
  
  qcEvents_Client_DNS::$debugHooks = true;
  
  $Pool = new qcEvents_Client_DNS ($Base);
  $Pool->resolve ('www.tiggerswelt.net')->then (
    function (qcEvents_Stream_DNS_Recordset $Answers, qcEvents_Stream_DNS_Recordset $Authorities, qcEvents_Stream_DNS_Recordset $Additional, qcEvents_Stream_DNS_Message $Response) {
      echo 'Response', "\n";
      
      var_dump ($Response);
    },
    function () {
      echo 'Query failed', "\n";
    }
  )->then (function () { exit (); });
  
  // Enter main-loop
  $Base->loop ();
  
  echo 'Finished at ', $e = microtime (true), "\n";
  echo 'Test took ', number_format (($e - $s) * 1000, 2), " ms\n";

?>