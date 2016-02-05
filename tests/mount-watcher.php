<?PHP

  // Setup test-environment
  define ('QCEVENTS_DEBUG_HOOKS', true);
  error_reporting (E_ALL);
  date_default_timezone_set ('Europe/Berlin');
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());
  
  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');
  
  $Base = new qcEvents_Base;
  
  // Create a new Mount-Watcher
  require_once ('qcEvents/Watchdog/Mount.php');
  
  $Watcher = new qcEvents_Watchdog_Mount ($Base);
  $Watcher->addHook ('mountpointAdded', function (qcEvents_Watchdog_Mount $Watcher, $fsSpec, $fsFile, $fsType, array $fsOpts) {
    echo 'Added ', $fsSpec, ' to ', $fsFile, "\n";
  });
  
  $Watcher->addHook ('mountpointRemoved', function (qcEvents_Watchdog_Mount $Watcher, $fsSpec, $fsFile, $fsType, array $fsOpts) {    
    echo 'Removed ', $fsSpec, ' from ', $fsFile, "\n";
  });
  
  $Watcher->addHook ('mountpointChanged', function (qcEvents_Watchdog_Mount $Watcher, $fsSpec, $fsFile, $fsType, array $fsOpts) {    
    echo 'Changed ', $fsSpec, ' on ', $fsFile, "\n";
  });
  
  // Enter main-loop
  $Base->loop ();

?>