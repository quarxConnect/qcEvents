<?php

  /**
   * quarxConnect Events - Call asynchronous functions in a synchronous manner
   *
   * Copyright (C) 2015-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use Exception;
  use InvalidArgumentException;
  use ReflectionMethod;
  use Throwable;

  class Synchronizer
  {
    /* Result-modes */
    public const RESULT_AS_ARRAY = 0;
    public const RESULT_FIRST = 1;

    /**
     * How to forward the result of the synchronized operation
     *
     * @var integer
     **/
    private int $resultMode = Synchronizer::RESULT_AS_ARRAY;

    /**
     * Store entire results on this class
     *
     * @var boolean
     **/
    private bool $storeResult = true;

    /**
     * Throw exception if one was received
     *
     * @var boolean
     **/
    private bool $throwExceptions = true;

    /**
     * The last result received
     *
     * @var array|null
     **/
    private array|null $lastResult = null;

    // {{{ do
    /**
     * Just a static alias for qcEvents_Synchronizer::__invoke()
     *
     * @param ...
     *
     * @see Synchronizer::__invoke()
     *
     * @access public
     * @return mixed The first parameter returned
     **/
    public static function do (): mixed
    {
      static $myInstance = null;

      if ($myInstance === null)
        $myInstance = new Synchronizer (static::RESULT_FIRST);

      return call_user_func_array ($myInstance, func_get_args ());
    }
    // }}}

    // {{{ doAsArray
    /**
     * Just a static alias for qcEvents_Synchronizer::__invoke()
     *
     * @param ...
     *
     * @see Synchronizer::__invoke()
     *
     * @access public
     * @return array
     **/
    public static function doAsArray (): array
    {
      static $myInstance = null;

      if ($myInstance === null)
        $myInstance = new Synchronizer (static::RESULT_AS_ARRAY);

      return call_user_func_array ($myInstance, func_get_args ());
    }
    // }}}

    // {{{ __construct
    /**
     * Create a new Synchronizer
     *
     * @param enum $resultMode (optional) Return results in this mode
     * @param bool $storeResult (optional) Store results on this class
     * @param bool $throwExceptions (optional) Throw exception if one was received
     *
     * @access friendly
     * @return void
     **/
    public final function __construct (int $resultMode = null, bool $storeResult = null, bool $throwExceptions = null)
    {
      if ($resultMode !== null)
        $this->resultMode = $resultMode;

      if ($storeResult !== null)
        $this->storeResult = !!$storeResult;

      if ($throwExceptions !== null)
        $this->throwExceptions = !!$throwExceptions;
      else
        $this->throwExceptions = (!defined ('QCEVENTS_EXCEPTIONS') || constant ('QCEVENTS_EXCEPTIONS'));
    }
    // }}}

    // {{{ __invoke
    /**
     * Do an asynchronous call in a synchronous way
     *
     * If $invokeObject features a getEventBase()-Call:
     *
     * @param Promise $eventPromise
     *
     * -- OR --
     *
     * @param object $invokeObject
     * @param string $invokeMethod
     * @param ...
     *
     * -- OR --
     *
     * @param Base $eventBase
     * @param Promise $eventPromise
     *
     * -- OR --
     *
     * @param Base $eventBase
     * @param object $invokeObject
     * @param string $invokeMethod
     * @param ...
     *
     * @access friendly
     * @return mixed
     **/
    public function __invoke (object $invokeObject, object|string $invokeMethod): mixed
    {
      // Extract parameters
      $invokeArguments = array_slice (func_get_args (), 2);

      // Try to find an event-base
      if ($invokeObject instanceof Base) {
        $eventBase = $invokeObject;
        $invokeObject = $invokeMethod;

        if ($invokeObject instanceof Promise)
          $invokeMethod = null;
        elseif (count ($invokeArguments) === 0)
          throw new InvalidArgumentException ('Asynchronous calls on event-base not supported');
        else
          $invokeMethod = array_shift ($invokeArguments);
      } elseif (!is_callable ([ $invokeObject, 'getEventBase' ])) {
        throw new InvalidArgumentException ('Unable to find event-base anywhere');
      } else {
        $eventBase = $invokeObject->getEventBase ();

        if (!is_object ($eventBase))
          throw new InvalidArgumentException ('Did not get an event-base from object');
      }

      // Prepare Callback
      $resultReady = false;
      $onLoop = false;
      $isPromise = false;
      $resultData = null;

      $resultFunction = function () use (&$onLoop, &$resultReady, &$resultData, $eventBase): void
      {
        // Store the result
        $resultReady = true;
        $resultData = func_get_args ();

        if (count ($resultData) === 0)
          $resultData [] = true;

        // Leave the loop
        if ($onLoop)
          $eventBase->loopBreak ();
      };

      // Analyze parameters of the call
      $isPromise = ($invokeObject instanceof Promise);

      if (!$isPromise) {
        $reflectedMethod = new ReflectionMethod ($invokeObject, $invokeMethod);

        $isPromise = (
          $reflectedMethod->hasReturnType () &&
          ($reflectedMethod->getReturnType ()->getName () === Promise::class)
        );

        // If no promise is returned, try to find a place to inject our callback-function
        if (!$isPromise) {
          $callbackIndex = null;

          foreach ($reflectedMethod->getParameters () as $parameterIndex=>$reflectedParameter)
            if ($reflectedParameter->isCallable ()) {
              $callbackIndex = $parameterIndex;

              break;
            }

          // Store the callback on parameters
          if ($callbackIndex !== null) {
            // Make sure parameters is big enough
            if (count ($invokeArguments) < $callbackIndex)
              for ($i = 0; $i < $callbackIndex; $i++)
                if (!isset ($invokeArguments [$i]))
                  $invokeArguments [$i] = null;

            // Set the callback
            $invokeArguments [$callbackIndex] = $resultFunction;
          } else {
            trigger_error ('No position for callback detected, just giving it a try', E_USER_NOTICE);

            $invokeArguments [] = $resultFunction;
          }
        }

        // Do the call
        $invokeResult = $reflectedMethod->invokeArgs (
          (is_object ($invokeObject) ? $invokeObject : null),
          $invokeArguments
        );
      } else
        $invokeResult = $invokeObject;

      // Check for a returned promise
      $caughtException = null;

      if ($invokeResult instanceof Promise) {
        // Check if this was expected
        if (!$isPromise) {
          trigger_error ('Got an unexpected Promise in return');

          $isPromise = true;
        }

        $invokeResult->then (
          $resultFunction,
          function (Throwable $invokeError) use (&$onLoop, &$resultReady, &$resultData, &$caughtException, $eventBase): void
          {
            // Store the result
            $resultReady = true;
            $resultData = [ false ];
            $caughtException = $invokeError;

            // Leave the loop
            if ($onLoop)
              $eventBase->loopBreak ();
          }
        );
      } elseif ($isPromise)
        throw new Exception ('Expected Promise as return, but did not get one. Things will be weird!');

      // Run the loop until ready
      $onLoop = true;

      while (!$resultReady) {
        $eventBase->loop (true, true);

        // Safe some CPU-Cycles...
        if (!$resultReady)
          usleep (125);
      }

      if (
        $caughtException &&
        $this->throwExceptions
      )
        throw $caughtException;

      // Process the result
      $resultCount = count ($resultData);

      if (
        !$isPromise &&
        ($resultCount > 0) &&
        ($resultData [0] === $invokeObject)
      ) {
        $resultCount--;
        array_shift ($resultData);
      }

      if ($this->storeResult)
        $this->lastResult = $resultData;

      if ($this->resultMode === self::RESULT_AS_ARRAY)
        return $resultData;

      elseif ($resultCount > 0)
        return $resultData [0];

      return null;
    }
    // }}}

    // {{{ getLastResult
    /**
     * Retrieve the entire last result
     *
     * @access public
     * @return array|null The last retrieved result, may be NULL if no result is available (or no results are stored at all)
     **/
    public function getLastResult (): ?array {
      return $this->lastResult;
    }
    // }}}
  }
