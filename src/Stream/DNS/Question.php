<?php

  /**
   * quarxConnect Events - DNS Question
   * Copyright (C) 2014-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023-2025 Bernd Holzmueller <bernd@innorize.gmbh>
   *
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   *
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/

  declare (strict_types=1);

  namespace quarxConnect\Events\Stream\DNS;
  
  use InvalidArgumentException;

  class Question {
    /**
     * [QName] The Label that is asked for
     **/
    public $Label = '';
    
    /**
     * [QType] Type of DNS-RRs that is asked for
     **/
    public $Type = Message::TYPE_ANY;
    
    /**
     * [QClass] Class of DNS-RRs that is asked for
     **/
    public $Class = Message::CLASS_INTERNET;
    
    // {{{ __construct
    /**
     * Create a new DNS-Question
     * 
     * @param string|null $Label (optional)
     * @param int|null $Type (optional)
     * @param int|null $Class (optional)
     **/
    function __construct (string $Label = null, $Type = null, $Class = null) {
      if ($Label !== null)
        $this->setLabel ($Label);
      
      if ($Type !== null)
        $this->setType ($Type);
      
      if ($Class !== null)
        $this->setClass ($Class);
    }
    // }}}
    
    // {{{ __toString
    /**
     * Convert this question-record into a human readable string
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return
        $this->getLabel () . ' ' .
        Message::getClassName ($this->getClass ()) . ' ' .
        Message::getTypeName ($this->getType ());
    }
    // }}}
    
    // {{{ getLabel
    /**
     * Retrive the label of this question
     * 
     * @access public
     * @return string
     **/
    public function getLabel () {
      return $this->Label;
    }
    // }}}
    
    // {{{ setLabel
    /**
     * Set the label for this question
     * 
     * @param string $Label
     * 
     * @access public
     * @return bool
     **/
    public function setLabel ($Label) {
      $this->Label = $Label;
      
      return true;
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrieve the type of this question
     *
     * @return int
     **/
    public function getType (): int
    {
      return $this->Type;
    }
    // }}}
    
    // {{{ setType
    /**
     * Set the type of this question
     * 
     * @param int $Type
     *
     * @return void
     **/
    public function setType (int $Type): void
    {
      $this->Type = $Type;
    }
    // }}}

    // {{{ getClass
    /**
     * Retrieve the class of this question
     *
     * @return int
     **/
    public function getClass (): int
    {
      return $this->Class;
    }
    // }}}
    
    // {{{ setClass
    /**
     * Set the class of this question
     *
     * @param int $Class
     *
     * @return void
     * @throws InvalidArgumentException if the given class is invalid
     **/
    public function setClass (int $Class): void
    {
      if (
        ($Class < 1) ||
        ($Class > 4)
      )
        throw new InvalidArgumentException ('Invalid class type');

      $this->Class = $Class;
    }
    // }}}
    
    // {{{ parse
    /**
     * Parse binary data into this object
     * 
     * @param string $dnsData
     * @param int $dataOffset
     * @param int $dataLength (optional)
     * 
     * @access public
     * @return void
     * @throws \LengthException
     **/
    public function parse ($dnsData, &$dataOffset, $dataLength = null) {
      // Get the length of input
      if ($dataLength === null)
        $dataLength = strlen ($dnsData);
      
      // Retrive the label
      $this->setLabel (Message::getLabel ($dnsData, $dataOffset));
      
      // Retrive type and class
      if ($dataLength < $dataOffset + 4)
        throw new \LengthException ('DNS-Question too short');
      
      $this->setType ((ord ($dnsData [$dataOffset++]) << 8) + ord ($dnsData [$dataOffset++]));
      $this->setClass ((ord ($dnsData [$dataOffset++]) << 8) + ord ($dnsData [$dataOffset++]));
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert this question into a string
     * 
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public function toString ($Offset, &$Labels) {
      return
        Message::setLabel ($this->Label, $Offset, $Labels) .
        chr (($this->Type & 0xFF00) >> 8) . chr ($this->Type & 0xFF) .
        chr (($this->Class & 0xFF00) >> 8) . chr ($this->Class & 0xFF);
    }
    // }}}
  }
