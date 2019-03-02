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
  
  // Check timer without repeat
  require_once ('qcEvents/Timer.php');
  
  $Timeout = new qcEvents_Timer ($Base);
  $Timeout->addTimer (
    3,
    false,
    function ($Time) {
      echo 'Timer::addTimer(3) took ', time () - $Time, " sec.\n";
    },
    $Time
  );
  
  // Check repeated timer
  $t = time ();
  $c = 0;
  $cb = function () use (&$t, &$c, &$cb, &$Timer) {
    echo 'Timer::addTimer(1, true) took ', time () - $t, " sec.\n";
    $t = time ();
    
    if ($c++ > 3) {
      $Timer->clearTimer (1, true, $cb);
      exit ();
    }
  };
  
  $Timer = new qcEvents_Timer ($Base);
  $Timer->addTimer (
    1,
    true,
    $cb
  );
  
  
  $Base->loop ();

?>