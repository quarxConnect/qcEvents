<?PHP

  require_once ('qcEvents/Event.php');
  
  /**
   * Process
   * -------
   * Spawns a command (uni- or bi-directional) and waits for input
   * 
   * @class qcEvents_Process
   * @extends qcEvents_Event
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * @license http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons Attribution-Share Alike 3.0 Germany
   **/
  class qcEvents_Process extends qcEvents_Event {
    const READ_BUFFER = 4096;
    
    const MODE_PROCOPEN = 1;
    const MODE_POPEN = 2;
    const MODE_FORKED = 3;
    
    private $Mode = qcEvents_Process::MODE_PROCOPEN;
    private $Process = null;
        
    // PID of our child
    private $CPID = 0;
    private $CFifo = '';
    
    // {{{ __construct
    /**
     * Create an Event-Handler and spawn a process
     * 
     * @param string $Command
     * @param mixed $Params (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Command = null, $Params = null) {
      if ($Command === null)
        return;
      
      $this->spawnCommand ($Command, $Params);
    }
    // }}}
    
    // {{{ toCommandline
    /**
     * Create a full commandline from separated args
     * 
     * @param string $Command
     * @param mixed $Params
     * 
     * @access private
     * @return string
     **/
    private function toCommandline ($Command, $Params = null) {
      // Create the command-line
      $cmdline = escapeshellcmd ($Command);
      
      // Append parameters
      if (is_array ($Params))
        foreach ($Params as $P)
          $cmdline .= ' ' . escapeshellarg ($P);
      
      elseif (is_string ($Params))
        $cmdline .= ' ' . escapeshellarg ($Params);
      
      return $cmdline;
    }
    // }}}
    
    // {{{ spawnCommand
    /**
     * Execute the command
     * 
     * @param string $Command
     * @param array $Params (optional)
     * 
     * @access public
     * @return bool
     **/
    public function spawnCommand ($Command, $Params = null) {
      // Spawn using proc_open
      if (function_exists ('proc_open')) {
        // Start the command
        $Descriptors = array (
          0 => array ('pipe', 'r'),
          1 => array ('pipe', 'w'),
          2 => STDERR,
        );
        
        if (!is_resource ($this->Process = proc_open ($this->toCommandline ($Command, $Params), $Descriptors, $Pipes)))
          return false;
        
        $this->Mode = self::MODE_PROCOPEN;
        
        // Setup the event-handler
        if ($this->setFD ($Pipes [1], true, false))
          return true;
        
        $this->close ();
      
      // Spawn using popen
      } elseif (function_exists ('popen')) {
        // Start the command
        if (!is_resource ($fd = popen ($this->toCommandline ($Command, $Params), 'r')))
          return false;
        
        $this->Mode = self::MODE_POPEN;
        
        // Setup the event-handler
        if ($this->setFD ($fd, true, false))
          return true;
        
        pclose ($fd);
      
      // Try to spawn forked
      } else
        return $this->spawnCommandForked ($Command, $Params);
      
      // We should never get here
      return false;
    }
    // }}}
    
    // {{{ spawnCommandForked
    /**
     * Execute a command using fork()
     * 
     * @param string $Command
     * @param array $Params (optional)
     * 
     * @access private
     * @return bool
     **/
    private function spawnCommandForked ($Command, $Params = null) {
      // Check if we are able to fork
      if (!function_exists ('pcntl_fork'))
        return false;
      
      // Setup a FIFO
      # TODO: Don't hardcode /tmp here
      $fifo = tempnam ('/tmp', 'qcEventProcess');
      
      if (function_exists ('posix_mkfifo'))
        posix_mkfifo ($fifo, 600);
      
      if (!is_resource ($fd = fopen ($fifo, 'r'))) {
        @unlink ($fifo);
        
        return false;
      }
      
      // Do the fork
      if (($pid = pcntl_fork ()) < 0) {
        fclose ($fd);
        @unlink ($fifo);
        
        return false;
      }
      
      // We are the child
      if ($pid == 0) {
        $rc = 0;
        
        // Redirect the output
        global $STDOUT;
        
        fclose ($fd);
        fclose (STDOUT);
        
        if (!is_resource ($STDOUT = fopen ($fifo, 'w')))
          exit (1);
        
        // Spawn the process
        if (function_exists ('pcntl_exec')) {
          pcntl_exec ($Command, $Params);
          $rc = 1; // If we get here pcntl_exec failed!
        
        } elseif (function_exists ('passthru'))
          passthru ($this->toCommandline ($Command, $Params), $rc);
        
        elseif (function_exists ('system'))
          system ($this->toCommandline ($Command, $Params), $rc);
        
        exit ($rc);
      }
      
      // Setup the parent
      $this->Mode = self::MODE_FORKED;
      $this->CPID = $pid;
      $this->CFifo = $fifo;
      
      return $this->setFD ($fd, true, false);
    }
    // }}}
    
    // {{{ close
    /**
     * Terminate this process
     * 
     * @access public
     * @return bool
     **/
    public function close () {
      if ($this->Mode == self::MODE_PROCOPEN)
        # REMARK: Use pcntl_wexitstatus() to retrive the return-code
        return (proc_close ($this->Process) >= 0);
      
      if ($this->Mode == self::MODE_POPEN)
        return (pclose ($this->getFD ()) >= 0);
      
      if ($this->Mode == self::MODE_FORKED) {
        if (($this->CPID > 0) && function_exists ('posix_kill'))
          posix_kill ($this->CPID);
        
        fclose ($this->getFD ());
        @unlink ($this->CFifo);
      }
    }
    // }}}
    
    // {{{ readEvent
    /**
     * Process writes data
     * 
     * @access public
     * @return void  
     **/
    public function readEvent () {
      if (!($Buffer = fread ($this->getFD (), self::READ_BUFFER)) || (strlen ($Buffer) == 0)) {
        $this->close ();
        $this->closed ();
        
        return;
      }
      
      $this->receive ($Buffer);
    }
    // }}}
    
    // {{{ receive
    /**
     * Callback: Invoked whenever incoming data is received
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function receive ($Data) { }
    // }}}
    
    // {{{ closed
    /**
     * Callback: The process was closed
     * 
     * @access protected
     * @return void
     **/
    protected function closed () { }
    // }}}
  }

?>