<?PHP

  /**
   * qcEvents - Asyncronous Process/Application I/O
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  require_once ('qcEvents/IOStream.php');
  
  /**
   * Process
   * -------
   * Spawns a command (uni- or bi-directional) and waits for input
   * 
   * @class qcEvents_Process
   * @extends qcEvents_IOStream
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Process extends qcEvents_IOStream {
    const READ_BUFFER = 40960;
    
    /* Internal method to spawn a command */
    const MODE_PROCOPEN = 1;
    const MODE_POPEN = 2;
    const MODE_FORKED = 3;
    
    private $Mode = qcEvents_Process::MODE_PROCOPEN;
    
    /* Internal process-handle */
    private $Process = null;
    
    /* Exit-Code to process */
    private $exitCode = null;
    
    /* Callback after process finished */
    private $Callback = null;
    private $Private = null;
    
    /* PID of our child */
    private $childPID = 0;
    
    /* Path to child-fifos */
    private $childReadPath = '';
    private $childWritePath = '';
    
    private $hasRead = false;
    
    // {{{ __construct
    /**
     * Create an Event-Handler and spawn a process
     * 
     * @param qcEvents_Base $Base (optional)
     * @param string $Command (optional)
     * @param mixed $Params (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null, $Command = null, $Params = null, callable $Callback = null, $Private = null) {
      // Don't do anything withour an events-base
      if ($Base === null)
        return;
      
      // Set our handler
      $this->setEventBase ($Base);
      
      // Spawn the command if requested
      if ($Command !== null)
        $this->spawnCommand ($Command, $Params, $Callback, $Private);
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
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return bool
     **/
    public function spawnCommand ($Command, $Params = null, callable $Callback = null, $Private = null) {
      // Spawn using proc_open
      if (function_exists ('proc_open')) {
        // Try to spawn the command
        $Descriptors = array (
          0 => array ('pipe', 'r'),
          1 => array ('pipe', 'w'),
          2 => STDERR,
        );
        
        if (!is_resource ($this->Process = proc_open ($this->toCommandline ($Command, $Params), $Descriptors, $Pipes))) {
          trigger_error ('Could not spawn command');
          
          return false;
        }
        
        $this->Mode = self::MODE_PROCOPEN;
        $this->exitCode = null;
        $this->Callback = $Callback;
        $this->Private = $Private;
        
        // Register FDs
        if (!$this->setStreamFDs ($Pipes [1], $Pipes [0]))
          return false;
      
      // Spawn using popen
      } elseif (function_exists ('popen')) {
        // Start the command
        if (!is_resource ($fd = popen ($this->toCommandline ($Command, $Params), 'r')))
          return false;
        
        $this->Mode = self::MODE_POPEN;
        $this->exitCode = null;
        
        // Register FDs
        if (!$this->setStreamFD ($fd))
          return false;
      
      // Try to spawn forked
      } elseif (!$this->spawnCommandForked ($Command, $Params))
        return false;
      
      return true;
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
      
      // Setup FIFOs
      # TODO: Don't hardcode /tmp here
      $fifo_in = tempnam ('/tmp', 'qcEventProcess');
      $fifo_out = tempnam ('/tmp', 'qcEventProcess');
      
      if (function_exists ('posix_mkfifo')) {
        posix_mkfifo ($fifo_in, 600);
        posix_mkfifo ($fifo_out, 600);
      }
      
      if (!is_resource ($fd_in = @fopen ($fifo_in, 'r')) ||
          !is_resource ($fd_out = @fopen ($fifo_out, 'w'))) {
        @unlink ($fifo_in);
        @unlink ($fifo_out);
        
        return false;
      }
      
      // Do the fork
      if (($pid = pcntl_fork ()) < 0) {
        fclose ($fd_in);
        fclose ($fd_out);
        
        @unlink ($fifo_in);
        @unlink ($fifo_out);
        
        return false;
      }
      
      // We are the child
      if ($pid == 0) {
        $rc = 0;
        
        // Redirect the output
        global $STDOUT, $STDIN;
        
        fclose ($fd_in);
        fclose ($fd_out);
        fclose (STDOUT);
        fclose (STDIN);
        
        if (!is_resource ($STDOUT = fopen ($fifo_in, 'w')))
          exit (1);
        
        if (!is_resource ($STDIN = fopen ($fifo_out, 'r')))
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
      
      if (!$this->setStreamFDs ($fd_in, $fd_out)) {
        fclose ($fd_in);
        fclose ($fd_out);
        
        @unlink ($fifo_in);
        @unlink ($fifo_out);
        
        return false;
      }
      
      $this->childPID = $pid;
      $this->childReadPath = $fifo_in;
      $this->childWritePath = $fifo_out;
      
      return true;
    }
    // }}}
    
    // {{{ finishConsume
    /**
     * Stop being piped from somewhere and "exit"
     * 
     * @access public
     * @return void
     **/
    public function finishConsume () {
      $this->addHook ('eventDrained', function () {
        $this->closeWriter ();
      }, null, true);
    }
    // }}}
    
    // {{{ ___read
    /**
     * Read from the underlying stream
     * 
     * @param int $Length (optional)
     * 
     * @access protected
     * @return string
     **/
    protected function ___read ($Length = null) {
      // Determine how many bytes to read
      if ($Length === null)
        $Length = $this::DEFAULT_READ_LENGTH;
      
      // Try to access our read-fd
      if (!is_resource ($fd = $this->getReadFD ()))
        return false;
      
      // Try to read from process
      if (is_string ($Data = fread ($fd, $Length)) && (strlen ($Data) == 0) && feof ($fd))
        return $this->close ();
      
      // Return the read data
      $this->hasRead = true;
      
      return $Data;
    }
    // }}}
    
    // {{{ ___write
    /**
     * Forward data for writing to our socket
     * 
     * @param string $Data
     * 
     * @access private
     * @return int Number of bytes written
     **/
    protected function ___write ($Data) {
      return fwrite ($this->getWriteFD (), $Data);
    }
    // }}}
    
    public function closeWriter () {
      if (is_resource ($fd = $this->getWriteFD (true)))
        fclose ($fd);
      
      $this->setStreamFD ($this->getReadFD ());
    }
    
    // {{{ ___close
    /**
     * Close the stream at the handler
     * 
     * @param resource $closeFD (optional)
     * 
     * @access protected
     * @return bool
     **/
    protected function ___close ($closeFD = null) {
      if ($this->Mode == self::MODE_PROCOPEN) {
        // Close our pipes first
        if (is_resource ($fd = $this->getReadFD ()))
          fclose ($fd);
        
        if (is_resource ($fd = $this->getWriteFD ()))
          fclose ($fd);
        
        // Try to terminate normally
        proc_terminate ($this->Process);
        
        // Wait for the process to exit
        # TODO: Make this async
        for ($i = 0; $i < 1000; $i++) {
          // Retrive the status of the process
          $status = proc_get_status ($this->Process);
          
          // Try to remember an exit-code for this process
          if ($status ['exitcode'] >= 0)
            $this->exitCode = $status ['exitcode'];
          
          // Check if the process is still running
          if (!$status ['running'])
            break;
          
          // Spend a little time waiting
          usleep (2000);
        }
        
        // Check if the process is still running
        if ($status ['running']) {
          proc_terminate ($this->Process, SIGKILL);
          
          // Try to get an exit-code
          $status = proc_get_status ($this->Process);
          
          if ($status ['exitcode'] >= 0)
            $this->exitCode = $status ['exitcode'];
        }
        
        if ($this->Callback)
          call_user_func ($this->Callback, $this, $this->exitCode, $this->Private);
        
        return true;
      }
      
      if ($this->Mode == self::MODE_POPEN) {
        $this->exitCode = pclose ($this->getReadFD ());
        
        if ($this->Callback)
          call_user_func ($this->Callback, $this, $this->exitCode, $this->Private);
        
        return ($this->exitCode >= 0);
      }
    
      if ($this->Mode == self::MODE_FORKED) {
        if (($this->childPID > 0) && function_exists ('posix_kill'))
          posix_kill ($this->childPID);
        
        // Close our pipes first
        if (is_resource ($fd = $this->getReadFD ()))
          fclose ($fd);
          
        if (is_resource ($fd = $this->getWriteFD ()))
          fclose ($fd);
        
        @unlink ($this->childReadPath); 
        @unlink ($this->childWritePath);
      }
      
      return true;
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Callback: The Event-Loop detected a read-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseRead () {
      // Check if we can detect a finished process
      if ($this->Mode == self::MODE_PROCOPEN) {
        $Status = proc_get_status ($this->Process);
        
        // Exit if the process is not running anymore
        if (!$Status ['running']) {
          // Store exit-code if there is a valid one
          if ($Status ['exitcode'] >= 0)
            $this->exitCode = $Status ['exitcode'];
          
          // Make sure the read-buffer is empty
          $this->hasRead = false;
          
          parent::raiseRead ();
          
          // Don't close if the emited event triggered a read()
          // The read()-Handler will trigger a close on its own when no data is available any more
          if ($this->hasRead)
            return;
          
          // Close if nothing was read
          return $this->close ();
        }
      }
      
      // Inherit to our parent (and emit events)
      parent::raiseRead ();
    }
    // }}}
  }

?>