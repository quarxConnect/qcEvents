<?php

  /**
   * quarxConnect Events - Asyncronous Process/Application I/O
   * Copyright (C) 2012-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events;
  
  /**
   * Process
   * -------
   * Spawns a command (uni- or bi-directional) and waits for input
   * 
   * @class Process
   * @extends IOStream
   * @package quarxConnect/Events
   * @revision 02
   **/
  class Process extends IOStream {
    /* Default size of read-buffer */
    protected const READ_BUFFER = 40960;
    
    /* Internal method to spawn a command */
    private const MODE_PROCOPEN = 1;
    private const MODE_POPEN = 2;
    private const MODE_FORKED = 3;
    
    private $processMode = Process::MODE_PROCOPEN;
    
    /* Internal process-handle */
    private $Process = null;
    
    /* Exit-Code to process */
    private $exitCode = null;
    
    /* Promise for process settlement */
    private $processPromise = null;
    
    /* PID of our child */
    private $childPID = 0;
    
    private $hasRead = false;
    
    // {{{ __construct
    /**
     * Create an Event-Handler and spawn a process
     * 
     * @param Base $eventBase
     * @param string $commandName (optional)
     * @param array $commandParams (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase, $commandName = null, array $commandParams = null) {
      // Set our handler
      $this->setEventBase ($eventBase);
      
      // Spawn the command if requested
      if ($commandName !== null)
        $this->spawnCommand ($commandName, $commandParams);
    }
    // }}}
    
    // {{{ toCommandline
    /**
     * Create a full commandline from separated args
     * 
     * @param string $commandName
     * @param array $commandParams (optional)
     * 
     * @access private
     * @return string
     **/
    private static function toCommandline ($commandName, array $commandParams = null) {
      // Create the command-line
      $commandLine = escapeshellcmd ($commandName);
      
      // Append parameters
      if ($commandParams)
        foreach ($commandParams as $commandParam)
          $commandLine .= ' ' . escapeshellarg ($commandParam);
      
      return $commandLine;
    }
    // }}}
    
    // {{{ spawnCommand
    /**
     * Execute the command
     * 
     * @param string $commandName
     * @param array $commandParams (optional)
     * 
     * @access public
     * @return Promise
     * @throws Error
     **/
    public function spawnCommand ($commandName, array $commandParams = null) : Promise {
      // Spawn using proc_open
      if (function_exists ('proc_open')) {
        // Try to spawn the command
        $Descriptors = array (
          0 => array ('pipe', 'r'),
          1 => array ('pipe', 'w'),
          2 => STDERR,
        );
        
        if (!is_resource ($this->Process = proc_open ($this::toCommandline ($commandName, $commandParams), $Descriptors, $Pipes)))
          return Promise::reject ('Could not spawn command (proc_open)');
        
        $this->processMode = self::MODE_PROCOPEN;
        $this->exitCode = null;
        
        // Register FDs
        if (!$this->setStreamFDs ($Pipes [1], $Pipes [0]))
          return Promise::reject ('Failed to set stream-fds');
      
      // Spawn using popen
      } elseif (function_exists ('popen')) {
        // Start the command
        if (!is_resource ($fd = popen ($this::toCommandline ($commandName, $commandParams), 'r')))
          return Promise::reject ('Could not spawn command (popen)');
        
        $this->processMode = self::MODE_POPEN;
        $this->exitCode = null;
        
        // Register FDs
        if (!$this->setStreamFD ($fd))
          return Promise::reject ('Failed to set stream-fd');
      
      // Try to spawn forked
      } elseif (function_exists ('pcntl_fork')) {
        // Setup FIFOs
        $fifo_in = tempnam (sys_get_temp_dir (), 'qcEventsProcess');
        $fifo_out = tempnam (sys_get_temp_dir (), 'qcEventsProcess');
        
        if (function_exists ('posix_mkfifo')) {
          posix_mkfifo ($fifo_in, 600);
          posix_mkfifo ($fifo_out, 600);
        } else {
          touch ($fifo_in);
          touch ($fifo_out);
        }
        
        if (!is_resource ($fd_in = fopen ($fifo_in, 'r')) ||
            !is_resource ($fd_out = fopen ($fifo_out, 'w'))) {
          @unlink ($fifo_in);
          @unlink ($fifo_out);
          
          return Promise::reject ('Failed to setup fifo');
        }
        
        // try to fork
        if (($pid = pcntl_fork ()) < 0) {
          fclose ($fd_in);
          fclose ($fd_out);
        
          @unlink ($fifo_in);
          @unlink ($fifo_out);
          
          return Promise::reject ('Failed to fork()');
        }
        
        // We are the child
        if ($pid == 0) {
          $rc = 0;
          
          // Redirect the output
          global $STDOUT, $STDIN;
          
          fclose ($fd_in);
          fclose ($fd_out);
          
          fclose (STDOUT);
          $STDOUT = fopen ($fifo_in, 'w');
          
          fclose (STDIN);
          $STDIN = fopen ($fifo_out, 'r');
          
          @unlink ($fifo_in);
          @unlink ($fifo_out);
          
          if (!is_resource ($STDOUT) ||
              !is_resource ($STDIN))
            exit (1);
          
          // Spawn the process
          if (function_exists ('pcntl_exec')) {
            pcntl_exec ($commandName, $commandParams);
            $rc = 1; // If we get here pcntl_exec failed!
          
          } elseif (function_exists ('passthru'))
            passthru ($this::toCommandline ($commandName, $commandParams), $rc);
          
          elseif (function_exists ('system'))
            system ($this::toCommandline ($commandName, $commandParams), $rc);
          
          exit ($rc);
        }
        
        // Setup the parent
        $this->processMode = self::MODE_FORKED;
        $this->childPID = $pid;
        
        if (!$this->setStreamFDs ($fd_in, $fd_out)) {
          fclose ($fd_in);
          fclose ($fd_out);
          
          @unlink ($fifo_in);
          @unlink ($fifo_out);
          
          return Promise::reject ('Failed to set stream-fds');
        }
      } else
        return Promise::reject ('Missing pcntl_fork()');
      
      // Create a new defered promise
      $this->processPromise = new Promise\Defered ();
      
      return $this->processPromise->getPromise ();
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
      $this->addHook (
        'eventDrained',
        function () {
          $this->closeWriter ();
        },
        null,
        true
      );
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
      if (is_string ($Data = fread ($fd, $Length)) && (strlen ($Data) == 0) && feof ($fd)) {
        if ($this->hasRead !== null)
          $this->close ();
        
        return false;
      }
      
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
      if (($this->processMode == self::MODE_PROCOPEN) ||
          ($this->processMode == self::MODE_FORKED)) {
        // Close our pipes first
        if (is_resource ($fd = $this->getReadFD ()))
          fclose ($fd);
        
        if (is_resource ($fd = $this->getWriteFD ()))
          fclose ($fd);
        
        // Try to terminate normally
        if ($this->processMode == self::MODE_PROCOPEN)
          proc_terminate ($this->Process);
        
        elseif (($this->childPID > 0) && function_exists ('posix_kill'))
          posix_kill ($this->childPID, SIGTERM);
        
        // Wait for the process to exit
        if (!is_object ($eventBase = $this->getEventBase ()))
          return true;
        
        $closeTimer = $eventBase->addTimeout (0.25, true);
        $closeTimer->then (
          function () use ($closeTimer) {
            static $closeCounter = 0;
            
            // Retrive the status of the process
            if ($this->processMode != self::MODE_PROCOPEN) {
              $childRC = pcntl_waitpid ($this->childPID, $childStatus, WNOHANG|WUNTRACED);
              
              $childStatus = array (
                'exitcode' => pcntl_wexitstatus ($childStatus),
                'running' => ($childRC <= 0),
              );
            } else
              $childStatus = proc_get_status ($this->Process);
            
            // Try to remember an exit-code for this process
            if ($childStatus ['exitcode'] >= 0)
              $this->exitCode = $childStatus ['exitcode'];
            
            // Check if the process is still running
            if ($childStatus ['running'] && ($closeCounter++ < 1000))
              return;
            
            // Cancel the timer
            $closeTimer->cancel ();
            
            // Check if the process is still running
            if ($childStatus ['running']) {
              if ($this->processMode != self::MODE_PROCOPEN) {
                if (function_exists ('posix_kill'))
                  posix_kill ($this->childPID, SIGKILL);
                
                $childRC = pcntl_waitpid ($this->childPID, $childStatus, WNOHANG|WUNTRACED);
                $childStatus = array (
                  'exitcode' => pcntl_wexitstatus ($childStatus),
                  'running' => ($childRC <= 0),
                );
              } else {
                // Send SIGKILL
                proc_terminate ($this->Process, SIGKILL);
                
                // Try to get an exit-code
                $childStatus = proc_get_status ($this->Process);
              }
              
              if ($childStatus ['exitcode'] >= 0)
                $this->exitCode = $childStatus ['exitcode'];
            }
            
            // Resolve the promise
            if ($this->processPromise)
              $this->processPromise->resolve ($this->exitCode);
          }
        );
        
        // Don't loose time
        $closeTimer->run ();
        
        return true;
      }
      
      // Stop popen()ed processes
      if ($this->processMode == self::MODE_POPEN) {
        $this->exitCode = pclose ($this->getReadFD ());
        
        // Resolve the promise
        if ($this->processPromise)
          $this->processPromise->resolve ($this->exitCode);
        
        return ($this->exitCode >= 0);
      }
      
      // Nothing to do
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
      if (($this->processMode == self::MODE_PROCOPEN) ||
          ($this->processMode == self::MODE_FORKED)) {
        if ($this->processMode != self::MODE_PROCOPEN) {
           $childRC = pcntl_waitpid ($this->childPID, $childStatus, WNOHANG|WUNTRACED);
        
          $childStatus = array (
            'exitcode' => pcntl_wexitstatus ($childStatus),
            'running' => ($childRC <= 0),
          );
        } else
          $childStatus = proc_get_status ($this->Process);
        
        // Exit if the process is not running anymore
        if (!$childStatus ['running']) {
          // Store exit-code if there is a valid one
          if ($childStatus ['exitcode'] >= 0)
            $this->exitCode = $childStatus ['exitcode'];
          
          // Make sure the read-buffer is empty
          $this->hasRead = null;
          
          parent::raiseRead ();
          
          // Don't close if the emited event triggered a read()
          // The read()-Handler will trigger a close on its own when no data is available any more
          if ($this->hasRead)
            return;
          
          $this->hasRead = false;
          
          // Close if nothing was read
          return $this->close ();
        }
      }
      
      // Inherit to our parent (and emit events)
      parent::raiseRead ();
    }
    // }}}
  }
