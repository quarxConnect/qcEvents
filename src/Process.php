<?php

  /**
   * quarxConnect Events - Asynchronous Process/Application I/O
   * Copyright (C) 2012-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use Throwable;

  /**
   * Process
   * -------
   * Spawns a command (uni- or bidirectional) and waits for input
   *
   * @class Process
   * @extends IOStream
   * @package quarxConnect/Events
   * @revision 02
   **/
  class Process extends IOStream {
    /* Default size of read-buffer */
    protected const READ_BUFFER = 40960;

    /* Modes to spawn a command */
    private const MODE_PROC_OPEN = 1;
    private const MODE_POPEN = 2;
    private const MODE_FORKED = 3;

    /**
     * Internal method to spawn a command
     *
     * @var integer
     **/
    private int $processMode = Process::MODE_PROC_OPEN;

    /**
     * Resource returned by proc_open()
     *
     * @var resource
     **/
    private /* resource|null */ $processPointer = null;

    /**
     * Exit-Code of the process
     *
     * @var integer|null
     **/
    private int|null $exitCode = null;

    /**
     * Promise for process settlement
     *
     * @var Promise\Deferred|null
     **/
    private Promise\Deferred|null $processPromise = null;

    /**
     * PID of our child
     *
     * @var integer
     **/
    private int $childPID = 0;

    /**
     * Indicator if there was ever a read() on this process
     *
     * @var boolean|null
     **/
    private bool|null $hasRead = false;

    // {{{ __construct
    /**
     * Create an Event-Handler and spawn a process
     *
     * @param Base $eventBase
     * @param string|null $commandName (optional)
     * @param array|null $commandParams (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (Base $eventBase, string $commandName = null, array $commandParams = null)
    {
      // Inherit to parent first
      parent::__construct ($eventBase);

      // Spawn the command if requested
      if ($commandName !== null)
        $this->spawnCommand ($commandName, $commandParams);
    }
    // }}}

    // {{{ toCommandLine
    /**
     * Create a full command-line from separated args
     *
     * @param string $commandName
     * @param array|null $commandParams (optional)
     *
     * @access private
     * @return string
     **/
    private static function toCommandLine (string $commandName, array $commandParams = null): string
    {
      // Create the command-line
      $commandLine = escapeshellcmd ($commandName);

      // Append parameters
      if ($commandParams !== null)
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
     * @param array|null $commandParams (optional)
     *
     * @access public
     * @return Promise
     **/
    public function spawnCommand (string $commandName, array $commandParams = null): Promise
    {
      // Spawn using proc_open
      if (function_exists ('proc_open')) {
        // Try to spawn the command
        $processDescriptors = [
          0 => [ 'pipe', 'r' ],
          1 => [ 'pipe', 'w' ],
          2 => STDERR,
        ];

        $processPointer = proc_open (
          $this::toCommandLine ($commandName, $commandParams),
          $processDescriptors,
          $processPipes
        );

        if (is_resource ($processPointer) === false)
          return Promise::reject ('Could not spawn command (proc_open)');

        $this->processPointer = $processPointer;
        $this->processMode = self::MODE_PROC_OPEN;
        $this->exitCode = null;

        // Register FDs
        try {
          $this->setStreamFDs ($processPipes [1], $processPipes [0]);
        } catch (Throwable $streamError) {
          return Promise::reject ($streamError);
        }
      // Spawn using popen
      } elseif (function_exists ('popen')) {
        // Start the command
        $processPointer = popen ($this::toCommandLine ($commandName, $commandParams), 'r');

        if (is_resource ($processPointer) === false)
          return Promise::reject ('Could not spawn command (popen)');

        $this->processMode = self::MODE_POPEN;
        $this->exitCode = null;

        // Register FDs
        try {
          $this->setStreamFD ($processPointer);
        } catch (Throwable $streamError) {
          return Promise::reject ($streamError);
        }
      // Try to spawn forked
      } elseif (function_exists ('pcntl_fork')) {
        // Setup FIFOs
        $inputFilename = tempnam (sys_get_temp_dir (), 'qcEventsProcess');
        $outputFilename = tempnam (sys_get_temp_dir (), 'qcEventsProcess');

        if (function_exists ('posix_mkfifo')) {
          posix_mkfifo ($inputFilename, 600);
          posix_mkfifo ($outputFilename, 600);
        } else {
          touch ($inputFilename);
          touch ($outputFilename);
        }

        $standardInput = fopen ($inputFilename, 'r');
        $standardOutput = fopen ($outputFilename, 'w');

        if (
          (is_resource ($standardInput) === false) ||
          (is_resource ($standardOutput) === false)
        ) {
          unlink ($inputFilename);
          unlink ($outputFilename);

          return Promise::reject ('Failed to setup pipe for fork()');
        }

        // Try to fork
        $processId = pcntl_fork ();

        if ($processId < 0) {
          fclose ($standardInput);
          fclose ($standardOutput);

          unlink ($inputFilename);
          unlink ($outputFilename);

          return Promise::reject ('Failed to fork()');
        }

        // We are the child
        if ($processId === 0) {
          $exitCode = 0;

          // Redirect the output
          global $STDOUT, $STDIN;

          fclose ($standardInput);
          fclose ($standardOutput);

          fclose (STDOUT);
          $STDOUT = fopen ($inputFilename, 'w');

          fclose (STDIN);
          $STDIN = fopen ($outputFilename, 'r');

          unlink ($inputFilename);
          unlink ($outputFilename);

          if (
            (is_resource ($STDOUT) === false) ||
            (is_resource ($STDIN) === false)
          )
            exit (1);

          // Spawn the process
          if (function_exists ('pcntl_exec')) {
            pcntl_exec ($commandName, $commandParams);

            // If we get here pcntl_exec failed!
            $exitCode = 1;
          } elseif (function_exists ('passthru'))
            passthru ($this::toCommandLine ($commandName, $commandParams), $exitCode);
          elseif (function_exists ('system'))
            system ($this::toCommandLine ($commandName, $commandParams), $exitCode);

          exit ($exitCode);
        }

        // Set up the parent
        $this->processMode = self::MODE_FORKED;
        $this->childPID = $processId;

        try {
          $this->setStreamFDs ($standardInput, $standardOutput);
        } catch (Throwable $streamError) {
          fclose ($standardInput);
          fclose ($standardOutput);

          unlink ($inputFilename);
          unlink ($outputFilename);

          # TODO: Kill the child

          return Promise::reject ($streamError);
        }
      } else
        return Promise::reject ('Missing pcntl_fork()');

      // Create a new deferred promise
      $this->processPromise = new Promise\Deferred ();

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
    public function finishConsume (): void
    {
      /** @noinspection PhpUnhandledExceptionInspection */
      $this->addHook (
        'eventDrained',
        function (): void {
          $this->closeWriter ();
        },
        true
      );
    }
    // }}}

    // {{{ ___read
    /**
     * Read from the underlying stream
     *
     * @param int|null $readLength (optional)
     *
     * @access protected
     * @return string|null
     **/
    protected function ___read (int $readLength = null): ?string
    {
      // Try to read from process
      $readData = $this->___readGeneric ($readLength);

      if ($readData === null)
        return null;

      if (
        (strlen ($readData) === 0) &&
        feof ($this->getReadFD ())
      ) {
        if ($this->hasRead !== null)
          $this->close ();

        return null;
      }

      // Return the read data
      $this->hasRead = true;

      return $readData;
    }
    // }}}

    // {{{ ___write
    /**
     * Forward data for writing to our socket
     *
     * @param string $writeData
     *
     * @access private
     * @return int|null Number of bytes written
     **/
    protected function ___write (string $writeData): ?int
    {
      return $this->___writeGeneric ($writeData);
    }
    // }}}

    // {{{ closeWriter
    /**
     * Stop allowing to write to standard-input of the process
     *
     * @access public
     * @return void
     **/
    public function closeWriter (): void {
      $writerFD = $this->getWriteFD (true);

      if (is_resource ($writerFD))
        fclose ($writerFD);

      $this->setStreamFD ($this->getReadFD ());
    }
    // }}}

    // {{{ ___close
    /**
     * Close the stream at the handler
     *
     * @param resource $closeFD (optional)
     *
     * @access protected
     * @return bool
     *
     * @noinspection PhpComposerExtensionStubsInspection
     */
    protected function ___close ($closeFD = null): bool {
      if (
        ($this->processMode === self::MODE_PROC_OPEN) ||
        ($this->processMode === self::MODE_FORKED)
      ) {
        // Close our pipes first
        $readFD = $this->getReadFD ();
        $writeFD = $this->getWriteFD ();

        if (is_resource ($readFD))
          fclose ($readFD);

        if (is_resource ($writeFD))
          fclose ($writeFD);

        // Try to terminate normally
        if ($this->processMode === self::MODE_PROC_OPEN)
          proc_terminate ($this->processPointer);
        elseif (
          ($this->childPID > 0) &&
          function_exists ('posix_kill')
        )
          /** @noinspection PhpComposerExtensionStubsInspection */
          posix_kill ($this->childPID, SIGTERM);

        // Wait for the process to exit
        $eventBase = $this->getEventBase ();

        if (!is_object ($eventBase))
          return true;

        $closeTimer = $eventBase->addTimeout (0.25, true);
        $closeTimer->then (
          /* spell-checker: ignore WNOHANG WUNTRACED */
          function () use ($closeTimer): void {
            static $closeCounter = 0;

            // Retrieve the status of the process
            if ($this->processMode !== self::MODE_PROC_OPEN) {
              $terminatedProcessId = pcntl_waitpid ($this->childPID, $childStatus, WNOHANG | WUNTRACED);
              
              $childStatus = [
                'exitCode' => pcntl_wexitstatus ($childStatus),
                'running' => ($terminatedProcessId <= 0),
              ];
            } else {
              $childStatus = proc_get_status ($this->processPointer);
              $childStatus ['exitCode'] = $childStatus ['exitcode']; /* spell-checker: disable-line as we are not responsible for this */
            }

            // Try to remember an exit-code for this process
            if ($childStatus ['exitCode'] >= 0)
              $this->exitCode = $childStatus ['exitCode'];

            // Check if the process is still running
            if (
              $childStatus ['running'] &&
              ($closeCounter++ < 1000)
            )
              return;

            // Cancel the timer
            $closeTimer->cancel ();

            // Check if the process is still running
            if ($childStatus ['running']) {
              if ($this->processMode !== self::MODE_PROC_OPEN) {
                if (function_exists ('posix_kill'))
                  posix_kill ($this->childPID, SIGKILL);

                $childStatusCode = 0;
                $terminatedProcessId = pcntl_waitpid ($this->childPID, $childStatusCode, WNOHANG | WUNTRACED);

                $childStatus = [
                  'exitCode' => pcntl_wexitstatus ($childStatusCode),
                  'running' => ($terminatedProcessId <= 0),
                ];
              } else {
                // Send SIGKILL
                proc_terminate ($this->processPointer, SIGKILL);

                // Try to get an exit-code
                $childStatus = proc_get_status ($this->processPointer);
                $childStatus ['exitCode'] = $childStatus ['exitcode']; /* spell-checker: disable-line as we are not responsible for this */
              }

              if ($childStatus ['exitCode'] >= 0)
                $this->exitCode = $childStatus ['exitCode'];
            }

            // Resolve the promise
            $this->processPromise?->resolve ($this->exitCode);
          }
        );

        // Don't lose time
        $closeTimer->run ();

        return true;
      }

      // Stop popen()ed processes
      if ($this->processMode === self::MODE_POPEN) {
        $this->exitCode = pclose ($this->getReadFD ());

        // Resolve the promise
        $this->processPromise?->resolve ($this->exitCode);

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
     *
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function raiseRead (): void {
      // Check if we can detect a finished process
      if (
        ($this->processMode === self::MODE_PROC_OPEN) ||
        ($this->processMode === self::MODE_FORKED)
      ) {
        if ($this->processMode !== self::MODE_PROC_OPEN) {
           $terminatedProcessId = pcntl_waitpid ($this->childPID, $childStatus, WNOHANG | WUNTRACED);

          $childStatus = [
            'exitCode' => pcntl_wexitstatus ($childStatus),
            'running' => ($terminatedProcessId <= 0),
          ];
        } else {
          $childStatus = proc_get_status ($this->processPointer);
          $childStatus ['exitCode'] = $childStatus ['exitcode']; /* spell-checker: disable-line as we are not responsible for this */
        }

        // Exit if the process is not running anymore
        if ($childStatus ['running'] === false) {
          // Store exit-code if there is a valid one
          if ($childStatus ['exitCode'] >= 0)
            $this->exitCode = $childStatus ['exitCode'];

          // Make sure the read-buffer is empty
          $this->hasRead = null;

          parent::raiseRead ();

          // Don't close if the emitted event triggered a read()
          // The read()-Handler will trigger a close on its own when no data is available anymore
          if ($this->hasRead)
            return;

          $this->hasRead = false;

          // Close if nothing was read
          $this->close ();

          return;
        }
      }

      // Inherit to our parent (and emit events)
      parent::raiseRead ();
    }
    // }}}
  }
