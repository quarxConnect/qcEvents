<?PHP

  set_include_path (dirname (dirname (__DIR__)) . ':' . get_include_path ());
  error_reporting (E_ALL);
  
  require_once ('qcEvents/Base.php');
  
  $Base = new qcEvents_Base;
  
  // Check Promise-based timeouts
  $Time = time ();
  $Base->addTimeout (2)->then (
    function () use ($Time) {
      echo 'addTimeout(2) took ', time () - $Time, " sec.\n";
    }
  );
  
  // Check repeated Promise-based timeouts
  $t = time ();
  $c = 0;
  
  $Timer = $Base->addTimeout (1, true);
  $Timer->then (
    function () use (&$t, &$c, &$cb, $Timer) {
      echo 'addTimeout(1,true) took ', time () - $t, ' sec. (c = ', $c, ")\n";
      $t = time ();
      
      if ($c++ > 3)
        $Timer->cancel ();
    },
    function ($e) {
      echo 'rejected: ', $e, "\n";
    }
  );
  
  $Base->loop ();

?>