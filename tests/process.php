<?PHP

  // Be verbose on errors
  error_reporting (E_ALL);
  ini_set ('display_errors', 'stderr');
  
  // Override include-path
  set_include_path ('../../:' . get_include_path ());
  
  // Create event-base
  require_once ('qcEvents/Base.php');
  
  $eventBase = new qcEvents_Base;
  
  // Create a process
  require_once ('qcEvents/Process.php');
  
  $eventProcess = new qcEvents_Process ($eventBase);
  $eventProcess->spawnCommand ('ping', [ '-c', '2', 'google.com' ])->then (
    function ($exitCode) use ($eventBase) {
      echo 'Exited with ', $exitCode, "\n";
    },
    function ($e) {
      echo $e;
    }
  );
  
  $eventProcess->addHook (
    'eventReadable',
    function () use ($eventProcess) {
      echo $eventProcess->read ();
    }
  );
  
  $eventProcess->addHook (
    'eventClosed',
    function () {
      echo 'Closed', "\n";
    }
  );
  
  $eventBase->loop ();

?>