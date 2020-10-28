<?PHP

  // Override include-path
  set_include_path ('../../:' . get_include_path ());
  
  // Expect a promise to be fullfilled
  require_once ('qcEvents/Promise.php');
  
  echo 'Expecting fullfillment...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve ();
    }
  );
  
  $Promise->then (
    function () {
      echo 'Promise was fullfilled (GOOD)', "\n\n";
    },
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect promise to be rejected
  require_once ('qcEvents/Promise.php');
  
  echo 'Expecting rejection...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Reject ();
    }
  );

  $Promise->then (
    function () {
      echo 'Promise was FULLFILLED (INVALID)', "\n";
      exit (1);
    },
    function () {
      echo 'Promise was rejected (GOOD)', "\n\n";
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect a promise to be fullfilled with a value
  require_once ('qcEvents/Promise.php');
  
  echo 'Expecting fullfillment with 42...', "\n";

  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve (42);
    }
  );

  $Promise->then (
    function ($Arg) {
      if ($Arg !== 42) {
        echo 'Promise was fullfilled with ', $Arg, ' (INVALID)', "\n";
        exit (1);
      }
      
      echo 'Promise was fullfilled with ', $Arg, ' (GOOD)', "\n\n";
    },
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect promise to be rejected with a value
  require_once ('qcEvents/Promise.php');
  
  echo 'Expecting rejection with 23...', "\n";

  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Reject (23);
    }
  );

  $Promise->then (
    function () {
      echo 'Promise was FULLFILLED (INVALID)', "\n";
      exit (1);
    },
    function (Throwable $Arg) {
      if ($Arg->getMessage () != 23) {
        echo 'Promise was rejected with ', $Arg, ' (INVALID)', "\n";
        exit (1);
      }
      
      echo 'Promise was rejected with ', $Arg->getMessage (), ' (GOOD)', "\n\n";
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect a promise to be fullfilled with number of values
  require_once ('qcEvents/Promise.php');

  echo 'Expecting fullfillment with 19, 23 and 42...', "\n";

  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve (19, 23, 42);
    }
  );

  $Promise->then (
    function () {
      $Args = func_get_args ();
      
      if ((count ($Args) != 3) || ($Args [0] !== 19) || ($Args [1] !== 23) || ($Args [2] !== 42)) {
        echo 'Promise was fullfilled with ', implode (', ', $Args), ' (INVALID)', "\n";
        exit (1);
      }
      
      echo 'Promise was fullfilled with ', implode (', ', $Args), ' (GOOD)', "\n\n";
    },
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect promise to be rejected with a number of values
  require_once ('qcEvents/Promise.php');

  echo 'Expecting rejection with 23, 42 and 19...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Reject (23, 42, 19);
    }
  );

  $Promise->then (
    function () {
      echo 'Promise was FULLFILLED (INVALID)', "\n";
      exit (1);
    },
    function () {
      $Args = func_get_args ();
      $Args [0] = $Args [0]->getMessage ();
      
      if ((count ($Args) != 3) || ($Args [0] != 23) || ($Args [1] !== 42) || ($Args [2] !== 19)) {
        echo 'Promise was rejected with ', implode (', ', $Args), ' (INVALID)', "\n";
        exit (1);
      }
      
      echo 'Promise was rejected with ', implode (', ', $Args), ' (GOOD)', "\n\n";
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect chained fullfillment
  echo 'Expecting chained fullfillment...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve ();
    }
  );
  
  $Promise->then (
    function () {
      return 2;
    },
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  )->then (
    function () {
      echo 'Chained fullfillment succeeded (GOOD)', "\n\n";
    },
    function () {
      echo 'Promise was REJECTED on chain (INVALID)', "\n";
      exit (1);
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect chained fullfillment
  echo 'Expecting chained rejection...', "\n";

  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve ();
    }
  );

  $Promise->then (
    function () {
      throw new exception ('Reject after fullfillment');
    },
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  )->then (
    function () {
      echo 'Promise was FULLFILLED on chain (INVALID)', "\n";
      exit (1);
    },
    function () {
      echo 'Promise was rejected after fullfillment (GOOD)', "\n\n";
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect fullfillments to pass the chain
  echo 'Expecting fullfillment to pass...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve ();
    }
  );

  $Promise->catch (
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  )->then (
    function () {
      echo 'Chained fullfillment succeeded (GOOD)', "\n\n";
    },
    function () {
      echo 'Promise was REJECTED on chain (INVALID)', "\n";
      exit (1);
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect rejections to pass the chain
  echo 'Expecting rejection to pass the chain...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Reject ();
    }
  );

  $Promise->then (
    function () {
      echo 'Promise was FULLFILLED (INVALID)', "\n";
      exit (1);
    }
  )->then (
    function () {
      echo 'Chain was FULLFILLED (INVALID)', "\n";
      exit (1);
    },
    function () {
      echo 'Rejection passed (GOOD)', "\n\n";
    }
  );
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect a promise to resolve once
  echo 'Expecting a promise to resolve once...', "\n";
  
  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Resolve ();
      $Resolve ();
      $Reject ();
    }
  );
  
  $Promise->then (
    function () {
      static $c = 0;
      
      echo 'Resolved... ', ++$c, "\n";
      
      if ($c > 1) {
        echo 'Limit reached (INVALID)', "\n";
        exit (1);
      }
    },
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  );
  
  echo "\n";
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Expect a promise to reject once
  echo 'Expecting a promise to reject once...', "\n";

  $Promise = new qcEvents_Promise (
    function (callable $Resolve, callable $Reject) {
      $Reject ();
      $Reject ();
      $Resolve ();
    }
  );

  $Promise->then (
    function () {
      echo 'Promise was REJECTED (INVALID)', "\n";
      exit (1);
    },
    function () {
      static $c = 0;
      
      echo 'Rejected... ', ++$c, "\n";

      if ($c > 1) {
        echo 'Limit reached (INVALID)', "\n";
        exit (1);
      }
    }
  );

  echo "\n";
  
  unset ($Promise);
  gc_collect_cycles ();
  
  // Resolve defered
  require_once ('qcEvents/Defered.php');
  
  echo 'Resolving defered promise...', "\n";
  
  $Defered = new qcEvents_Defered;
  
  $Defered->getPromise ()->then (
    function () {
      echo 'Defered promise was fullfilled (GOOD)', "\n\n";
    },
    function () {
      echo 'Defered promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  );
  
  $Defered->resolve ();
  
  unset ($Defered);
  gc_collect_cycles ();
  
  // Resolve defered with values
  require_once ('qcEvents/Defered.php');

  echo 'Resolving defered promise with values...', "\n";

  $Defered = new qcEvents_Defered;

  $Defered->getPromise ()->then (
    function () {
      $Args = func_get_args ();
      
      if ((count ($Args) != 3) || ($Args [0] !== 19) || ($Args [1] !== 23) || ($Args [2] !== 42)) {
        echo 'Defered promise was fullfilled with ', implode (', ', $Args), ' (INVALID)', "\n";
        exit (1);
      }
      
      echo 'Defered promise was fullfilled with ', implode (', ', $Args), ' (GOOD)', "\n\n";
    },
    function () {
      echo 'Defered promise was REJECTED (INVALID)', "\n";
      exit (1);
    }
  );

  $Defered->resolve (19, 23, 42);
  
  unset ($Defered);
  gc_collect_cycles ();
  
  // Resolve defered
  require_once ('qcEvents/Defered.php');

  echo 'Rejecting defered promise...', "\n";

  $Defered = new qcEvents_Defered;

  $Defered->getPromise ()->then (
    function () {
      echo 'Defered promise was FULLFILLED (INVALID)', "\n";
      exit (1);
    },
    function () {
      echo 'Defered promise was rejected (GOOD)', "\n\n";
    }
  );

  $Defered->reject ();
  
  unset ($Defered);
  gc_collect_cycles ();
  
  # TODO: Check with event-base
  
  echo 'All good.', "\n";

?>