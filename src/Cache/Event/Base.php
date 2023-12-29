<?php

  /**
   * quarxConnect Events - Base-Class for Cache-Events
   * Copyright (C) 2014-2023 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Cache\Event;

  use quarxConnect\Events\ABI\Event;
  
  abstract class Base implements Event {
    /**
     * The key used for lookups
     *
     * @var string
     **/
    protected string $lookupKey;

    /**
     * The latest value of the key
     *
     * @var mixed
     **/
    protected mixed $latestValue;

    // {{{ __construct
    /**
     * Create a new Cache-Event
     *
     * @param string $lookupKey
     * @param mixed $latestValue
     *
     * @access friendly
     * @return void
     */
    public function __construct (string $lookupKey, mixed $latestValue) {
        $this->lookupKey = $lookupKey;
        $this->latestValue = $latestValue;
    }
    // }}}

    // {{{ __get
    /**
     * Access read-only properties of this event
     *
     * @param string $propertyName
     *
     * @access public
     * @return mixed
     **/
    public function __get (string $propertyName): mixed {
        if (!property_exists ($this, $propertyName))
            throw new \OutOfRangeException ('Invalid property: ' . $propertyName);

        return $this->$propertyName;
    }
    // }}}
  }
