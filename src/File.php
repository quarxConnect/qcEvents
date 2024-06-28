<?php

  /**
   * quarxConnect Events - File
   * Copyright (C) 2019-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use RuntimeException;
  use Throwable;

  class File extends IOStream {
    /**
     * The filename of this stream
     *
     * @var string
     **/
    private string $fileName;

    /**
     * Close stream on EOF
     *
     * @var boolean
     **/
    private bool $closeOnEOF = true;

    /**
     * Modification-time to set on close
     *
     * @var int|null
     **/
    private int|null $modificationTime = null;

    // {{{ readFileContents
    /**
     * Read full content of a file and pass to a given callback
     *
     * @param Base $eventBase Event-Base to use
     * @param string $fileName Path to file
     *
     * @access public
     * @return Promise
     **/
    public static function readFileContents (Base $eventBase, string $fileName): Promise
    {
      // Try to create a file-stream
      try {
        $fileInstance = new File ($eventBase, $fileName, true, false, false);
      } catch (Throwable $fileError) {
        return Promise::reject ($fileError);
      }

      // Read all contents of the file
      $readBuffer = '';

      /** @noinspection PhpUnhandledExceptionInspection */
      $fileInstance->addHook (
        'eventReadable',
        function (File $fileInstance) use (&$readBuffer): void
        {
          // Try to read from stream
          $readData = $fileInstance->read ();

          if ($readData === false)
            return;

          // Push to our buffer
          $readBuffer .= $readData;
        }
      );

      // Wait for end-of-file
      return $fileInstance->once ('eventClosed')->then (
        function () use (&$readBuffer): string
        {
          return $readBuffer;
        }
      );
    }
    // }}}

    // {{{ writeFileContents
    /**
     * Write full content of a file
     *
     * @param Base $eventBase Event-Base to use
     * @param string $fileName Path to file
     * @param string $fileContent Bytes to write to that file
     *
     * @access public
     * @return Promise
     **/
    public static function writeFileContents (Base $eventBase, string $fileName, string $fileContent): Promise
    {
      // Try to create a file-stream
      try {
        $fileInstance = new File ($eventBase, $fileName, false, true, true);
      } catch (Throwable $fileError) {
        return Promise::reject ($fileError);
      }

      // Enqueue write
      return $fileInstance->write ($fileContent)->then (
        fn (): Promise => $fileInstance->close ()
      );
    }
    // }}}

    // {{{ __construct
    /**
     * Create a new File I/O-Stream
     *
     * @param Base $eventBase
     * @param string $fileName
     * @param bool $forReading (optional) Prepare for reading
     * @param bool $forWriting (optional) Prepare for direct writing
     * @param bool $truncateFile (optional) Truncate the file
     *
     * @access friendly
     * @return void
     *
     * @throws RuntimeException
     **/
    public function __construct (Base $eventBase, string $fileName, bool $forReading = true, bool $forWriting = false, bool $truncateFile = false)
    { 
      // Handle stream-wrappers
      if (!$forWriting)
        $fileMode = 'r';
      else
        $fileMode = 'c' . ($forReading ? '+' : '');

      # ZLIB does not support select()-calls
      #if (($p = strpos ($fileName, '://')) !== false) {
      #  $Wrapper = substr ($fileName, 0, $p);
      #  
      #  // Wrappers the are read-xor-write
      #  if (($Wrapper == 'compress.zlib') || ($Wrapper == 'compress.bzip2')) {
      #    if ($Read)
      #      $Mode = 'r';
      #    elseif ($Write)
      #      $Mode = 'w';
      #  }
      #}

      // Try to open the file
      $fileDescriptor = fopen ($fileName, $fileMode);

      if (!is_resource ($fileDescriptor))
        throw new RuntimeException ('Could not open file');

      if (stream_set_blocking ($fileDescriptor, false) !== true)
        trigger_error ('Failed to set file to non-blocking', E_USER_WARNING);

      // Store the filename
      $this->fileName = $fileName;

      // Forward the event-base
      parent::__construct ($eventBase);

      // Forward the stream-fd
      $this->setStreamFD ($fileDescriptor);

      // Forward the read/write-status
      $this->watchRead ($forReading);

      // Check whether to truncate
      if (
        $forWriting &&
        $truncateFile
      )
        $this->truncate ();
    }
    // }}}

    // {{{ getFilename
    /**
     * Retrieve the filename of this stream
     *
     * @access public
     * @return string
     **/
    public function getFilename (): string
    {
      return $this->fileName;
    }
    // }}}

    // {{{ closeOnEnd
    /**
     * Raise close on the stream if End-of-File was reached
     *
     * @param bool $setState (optional) Change the behavior
     *
     * @access public
     * @return bool
     **/
    public function closeOnEnd (bool $setState = null): bool
    {
      if ($setState !== null)
        $this->closeOnEOF = $setState;

      return $this->closeOnEOF;
    }
    // }}}

    // {{{ truncate
    /**
     * Truncate this file to a given size or to the size of the actual position
     *
     * @param int|null $fileSize (optional) Truncate to a given length
     *
     * @access public
     * @return void
     *
     * @throws RuntimeException
     **/
    public function truncate (int $fileSize = null): void
    {
      // Try to access the descriptor
      $fileDescriptor = $this->getWriteFD (true);

      if (!is_resource ($fileDescriptor))
        throw new RuntimeException ('Failed to get file-descriptor');

      // Truncate the file
      $truncateResult = ftruncate (
        $fileDescriptor,
        ($fileSize === null ? ftell ($fileDescriptor) : $fileSize)
      );

      if ($truncateResult !== true)
        throw new RuntimeException ('Failed to truncate');
    }
    // }}}

    // {{{ setModificationTime
    /**
     * Set the modification-time of this file
     *
     * @param int $modificationTime
     *
     * @access public
     * @return void
     *
     * @throws RuntimeException
     **/
    public function setModificationTime (int $modificationTime): void
    {
      // Store the timestamp here
      $this->modificationTime = $modificationTime;

      // Try to set the timestamp initially
      if (touch ($this->getFilename (), $modificationTime) !== true)
        throw new RuntimeException ('Failed to set modification-time');
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
      return $this->___readGeneric ($readLength);
    }
    // }}}

    // {{{ ___write
    /**
     * Write to the underlying stream 
     *
     * @param string $writeData
     *
     * @access protected
     * @return int|null The number of bytes that have been written
     **/
    protected function ___write (string $writeData): ?int
    {
      return $this->___writeGeneric ($writeData);
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
     **/
    protected function ___close ($closeFD = null): bool
    {
      // Retrieve our descriptor and close it
      if (
        $closeFD &&
        !fclose ($closeFD)
      )
        return false;

      // Check whether to set modification-time
      if ($this->modificationTime !== null)
        touch ($this->getFilename (), $this->modificationTime);

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
    public function raiseRead (): void
    {
      // Fire callbacks first
      parent::raiseRead ();

      // Check if we reached end-of-file
      if (!$this->closeOnEOF)
        return;
      
      $readDescriptor = $this->getReadFD ();

      if (
        is_resource ($readDescriptor) &&
        feof ($readDescriptor)
      )
        $this->close ();
    }
    // }}}
  }
