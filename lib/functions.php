<?php

namespace Amp;

/**
 * Get the default event reactor instance
 *
 * @param \Amp\Reactor $assignReactor Optionally specify a new default event reactor instance
 * @return \Amp\Reactor Returns the default reactor instance
 */
function reactor(Reactor $assignReactor = null) {
    static $reactor;
    if ($assignReactor) {
        return ($reactor = $assignReactor);
    } elseif ($reactor) {
        return $reactor;
    } elseif (\extension_loaded("uv")) {
        return ($reactor = new UvReactor);
    } elseif (\extension_loaded("ev")) {
        return ($reactor = new EvReactor);
    } elseif (\extension_loaded("libevent")) {
        return ($reactor = new LibeventReactor);
    } else {
        return ($reactor = new NativeReactor);
    }
}

/**
 * Start the default event reactor and assume program flow control
 *
 * This is a shortcut function for invoking Reactor::run() on the global
 * default event reactor.
 *
 * @param callable $onStart An optional callback to invoke immediately when the Reactor starts
 * @return void
 */
function run(callable $onStart = null) {
    reactor()->run($onStart);
}

/**
 * Stop the default event reactor and return program flow control
 *
 * This is a shortcut function for invoking Reactor::stop() on the global
 * default event reactor.
 *
 * @return void
 */
function stop() {
    reactor()->stop();
}

/**
 * Flatten an array of promises into a single promise
 *
 * Upon resolution the returned promise's $result parameter is set to an array
 * whose keys match the original input array and whose values match the individual
 * resolution results of its component promises.
 *
 * If any one of the Promises fails the resulting Promise will immediately fail.
 *
 * @param array An array of promises to flatten into a single promise
 * @return \Amp\Promise
 */
function all(array $promises) {
    if (empty($promises)) {
        return new Success([]);
    }

    $struct = new \StdClass;
    $struct->remaining = count($promises);
    $struct->results = [];
    $struct->promisor = new Deferred;

    $onResolve = function($error, $result, $cbData) {
        list($struct, $key) = $cbData;
        if (empty($struct->remaining)) {
            // If the promisor already resolved we don't need to bother
            return;
        }
        if ($error) {
            $struct->results = null;
            $struct->remaining = 0;
            $struct->promisor->fail($error);
            return;
        }

        $struct->results[$key] = $result;
        if (--$struct->remaining === 0) {
            $struct->promisor->succeed($struct->results);
        }
    };

    foreach ($promises as $key => $promise) {
        if ($promise instanceof Promise) {
            $promise->when($onResolve, [$struct, $key]);
        } else {
            $struct->results[$key] = $promise;
            if (--$struct->remaining === 0) {
                $struct->promisor->succeed($struct->results);
            }
        }
    }

    return $struct->promisor->promise();
}

/**
 * Resolves with a two-item array delineating successful and failed Promise results.
 *
 * The resulting Promise will only fail if ALL of the Promise values fail or if the
 * Promise array is empty.
 *
 * The resulting Promise is resolved with an indexed two-item array of the following form:
 *
 *     [$arrayOfFailures, $arrayOfSuccesses]
 *
 * The individual keys in the resulting arrays are preserved from the initial Promise array
 * passed to the function for evaluation.
 *
 * @param array An array of promises to flatten into a single promise
 * @return \Amp\Promise
 */
function some(array $promises) {
    if (empty($promises)) {
        return new Failure(new \LogicException(
            "No promises or values provided for resolution"
        ));
    }

    $struct = new \StdClass;
    $struct->remaining = count($promises);
    $struct->errors = [];
    $struct->results = [];
    $struct->promisor = new Deferred;

    $onResolve = function($error, $result, $cbData) {
        list($struct, $key) = $cbData;
        if ($error) {
            $struct->errors[$key] = $error;
        } else {
            $struct->results[$key] = $result;
        }
        if (--$struct->remaining) {
            return;
        }
        if (empty($struct->results)) {
            array_unshift($struct->errors, "All promises passed to Amp\some() failed");
            $struct->promisor->fail(new \RuntimeException(
                implode("\n\n", $struct->errors)
            ));
        } else {
            $struct->promisor->succeed([$struct->errors, $struct->results]);
        }
    };

    foreach ($promises as $key => $promise) {
        if ($promise instanceof Promise) {
            $promise->when($onResolve, [$struct, $key]);
        } else {
            $struct->results[$key] = $promise;
            if (--$struct->remaining === 0) {
                $struct->promisor->succeed([$struct->errors, $struct->results]);
            }
        }
    }

    return $struct->promisor->promise();
}

/**
 * Resolves with a two-item array delineating successful and failed Promise results.
 *
 * This function is the same as some() with the notable exception that it will never fail even
 * if all promises in the array resolve unsuccessfully.
 *
 * @param array An array of promises to flatten into a single promise
 * @return \Amp\Promise
 */
function any(array $promises) {
    if (empty($promises)) {
        return new Success([[], []]);
    }

    $struct = new \StdClass;
    $struct->remaining = count($promises);
    $struct->errors = [];
    $struct->results = [];
    $struct->promisor = new Deferred;

    $onResolve = function($error, $result, $cbData) {
        list($struct, $key) = $cbData;
        if ($error) {
            $struct->errors[$key] = $error;
        } else {
            $struct->results[$key] = $result;
        }
        if (--$struct->remaining === 0) {
            $struct->promisor->succeed([$struct->errors, $struct->results]);
        }
    };

    foreach ($promises as $key => $promise) {
        if ($promise instanceof Promise) {
            $promise->when($onResolve, [$struct, $key]);
        } else {
            $struct->results[$key] = $promise;
            if (--$struct->remaining === 0) {
                $struct->promisor->succeed([$struct->errors, $struct->results]);
            }
        }
    }

    return $struct->promisor->promise();
}

/**
 * Resolves with the first successful Promise value. The resulting Promise will only fail if all
 * Promise values in the group fail or if the initial Promise array is empty.
 *
 * @param array An array of promises to flatten into a single promise
 * @return \Amp\Promise
 */
function first(array $promises) {
    if (empty($promises)) {
        return new Failure(new \LogicException(
            "No promises or values provided"
        ));
    }

    $struct = new \StdClass;
    $struct->remaining = count($promises);
    $struct->promisor = new Deferred;

    $onResolve = function($error, $result, $cbData) {
        list($struct, $key) = $cbData;
        if (empty($struct->remaining)) {
            return;
        }
        if (empty($error)) {
            $struct->remaining = 0;
            $struct->promisor->succeed($result);
            return;
        }
        if (--$struct->remaining === 0) {
            $struct->promisor->fail(new \RuntimeException(
                "All promises failed"
            ));
        }
    };

    foreach ($promises as $key => $promise) {
        if ($promise instanceof Promise) {
            $promise->when($onResolve, [$struct, $key]);
        } else {
            $struct->remaining = 0;
            $struct->promisor->succeed($promise);
            break;
        }
    }

    return $struct->promisor->promise();
}

/**
 * Map promised deferred values using the specified functor
 *
 * @param array An array of promises whose values -- once resoved -- will be mapped by the functor
 * @param callable $functor The mapping function to apply to eventual promise results
 * @return \Amp\Promise
 */
function map(array $promises, callable $functor) {
    if (empty($promises)) {
        return new Success([]);
    }

    $struct = new \StdClass;
    $struct->remaining = count($promises);
    $struct->results = [];
    $struct->promisor = new Deferred;
    $struct->functor = $functor;

    $onResolve = function($error, $result, $cbData) {
        list($struct, $key) = $cbData;
        if (empty($struct->remaining)) {
            // If the promisor already resolved we don't need to bother
            return;
        }
        if ($error) {
            $struct->results = null;
            $struct->remaining = 0;
            $struct->promisor->fail($error);
            return;
        }
        $struct->remaining--;
        try {
            $struct->results[$key] = \call_user_func($struct->functor, $result);
        } catch (\Throwable $e) {
            $struct->remaining = 0;
            $struct->promisor->fail($e);
            return;
        } catch (\Exception $e) {
            /**
             * @TODO This extra catch block is necessary for PHP5; remove once PHP7 is required
             */
            $struct->remaining = 0;
            $struct->promisor->fail($e);
            return;
        }
        if ($struct->remaining === 0) {
            $struct->promisor->succeed($struct->results);
        }
    };

    foreach ($promises as $key => $promise) {
        if ($promise instanceof Promise) {
            $promise->when($onResolve, [$struct, $key]);
        } else {
            $struct->remaining--;
            try {
                $struct->results[$key] = \call_user_func($struct->functor, $promise);
            } catch (\Throwable $e) {
                $struct->remaining = 0;
                $struct->promisor->fail($e);
            } catch (\Exception $e) {
                /**
                 * @TODO This extra catch block is necessary for PHP5; remove once PHP7 is required
                 */
                $struct->remaining = 0;
                $struct->promisor->fail($e);
            }
            if ($struct->remaining === 0) {
                break;
            }
        }
    }

    return $struct->promisor->promise();
}

/**
 * Filter deferred values using the specified functor
 *
 * If the functor returns a truthy value the resolved promise result is retained, otherwise it is
 * discarded. Array keys are retained for any results not filtered out by the functor.
 *
 * @param array An array of promises whose values -- once resoved -- will be filtered by the functor
 * @param callable $functor The filtering function to apply to eventual promise results
 * @return \Amp\Promise
 */
function filter(array $promises, callable $functor) {
    if (empty($promises)) {
        return new Success([]);
    }

    $struct = new \StdClass;
    $struct->remaining = count($promises);
    $struct->results = [];
    $struct->promisor = new Deferred;
    $struct->functor = $functor;

    $onResolve = function($error, $result, $cbData) {
        list($struct, $key) = $cbData;
        if (empty($struct->remaining)) {
            // If the promisor already resolved we don't need to bother
            return;
        }
        if ($error) {
            $struct->results = null;
            $struct->remaining = 0;
            $struct->promisor->fail($error);
            return;
        }
        $struct->remaining--;
        try {
            if (\call_user_func($struct->functor, $result)) {
                $struct->results[$key] = $result;
            }
        } catch (\Throwable $e) {
            $struct->remaining = 0;
            $struct->promisor->fail($e);
            return;
        } catch (\Exception $e) {
            /**
             * @TODO This extra catch block is necessary for PHP5; remove once PHP7 is required
             */
            $struct->remaining = 0;
            $struct->promisor->fail($e);
            return;
        }
        if ($struct->remaining === 0) {
            $struct->promisor->succeed($struct->results);
        }
    };

    foreach ($promises as $key => $promise) {
        if ($promise instanceof Promise) {
            $promise->when($onResolve, [$struct, $key]);
        } else {
            $struct->remaining--;
            try {
                if (\call_user_func($struct->functor, $promise)) {
                    $struct->results[$key] = $promise;
                }
            } catch (\Throwable $e) {
                $struct->remaining = 0;
                $struct->promisor->fail($e);
            } catch (\Exception $e) {
                /**
                 * @TODO This extra catch block is necessary for PHP5; remove once PHP7 is required
                 */
                $struct->remaining = 0;
                $struct->promisor->fail($e);
            }
            if ($struct->remaining === 0) {
                break;
            }
        }
    }

    return $struct->promisor->promise();
}

/**
 * Pipe the promised value through the specified functor once it resolves
 *
 * @param mixed $promise Any value is acceptable -- non-promises are normalized to promise form
 * @param callable $functor The functor through which to pipe the resolved promise value
 * @return \Amp\Promise
 */
function pipe($promise, callable $functor) {
    if (!$promise instanceof Promise) {
        try {
            return new Success(\call_user_func($functor, $promise));
        } catch (\Throwable $e) {
            return new Failure($e);
        } catch (\Exception $e) {
            /**
             * @TODO This extra catch block is necessary for PHP5; remove once PHP7 is required
             */
            return new Failure($e);
        }
    }

    $promisor = new Deferred;
    $promise->when(function($error, $result) use ($promisor, $functor) {
        if ($error) {
            $promisor->fail($error);
            return;
        }
        try {
            $promisor->succeed(\call_user_func($functor, $result));
        } catch (\Throwable $error) {
            $promisor->fail($error);
        } catch (\Exception $error) {
             /**
             * @TODO This extra catch block is necessary for PHP5; remove once PHP7 is required
             */
            $promisor->fail($error);
        }
    });

    return $promisor->promise();
}

/**
 * Normalize an array of mixed values/Promises/Promisors to array<Promise>
 *
 * @param array $values
 * @return array Returns an array of Promise instances
 */
function promises(array $values) {
    foreach ($values as $key => $value) {
        if ($value instanceof Promise) {
            continue;
        } elseif ($value instanceof Promisor) {
            $values[$key] = $value->promise();
        } else {
            $values[$key] = new Success($value);
        }
    }

    return $values;
}

/**
 * Create an artificial timeout for any Promise instance
 *
 * If the timeout expires prior to promise resolution the returned
 * promise is failed.
 *
 * @param \Amp\Promise $promise The promise to which the timeout applies
 * @param int $msTimeout The timeout in milliseconds
 * @param \Amp\Reactor $reactor Optional reactor instance -- defaults to the global reactor
 * @return \Amp\Promise
 */
function timeout(Promise $promise, $msTimeout, Reactor $reactor = null) {
    $reactor = $reactor ?: reactor();
    $resolved = false;
    $promisor = new Deferred;
    $watcherId = $reactor->once(function() use ($promisor, &$resolved) {
        $resolved = true;
        $promisor->fail(new \RuntimeException(
            "Promise resolution timed out"
        ));
    }, $msTimeout);
    $promise->when(function($error = null, $result = null) use ($reactor, $promisor, $watcherId, &$resolved) {
        if ($resolved) {
            return;
        }
        $resolved = true;
        $reactor->cancel($watcherId);
        if ($error) {
            $promisor->fail($error);
        } else {
            $promisor->succeed($result);
        }
    });

    return $promisor->promise();
}

/**
 * Block script execution indefinitely until the specified Promise resolves
 *
 * In the event of promise failure this method will throw the exception responsible for the failure.
 * Otherwise the promise's resolved value is returned.
 *
 * If the optional event reactor instance is not specified then the global default event reactor
 * is used. Applications should be very careful to avoid instantiating multiple event reactors as
 * this can lead to hard-to-debug failures. If the async value producer uses a different event
 * reactor instance from that specified in this method the wait() call will never return.
 *
 * @param \Amp\Promise $promise The promise on which to wait
 * @param \Amp\Reactor $reactor
 * @throws \Exception if the promise fails
 * @return mixed Returns the eventual resolution result for the specified promise
 */
function wait(Promise $promise, Reactor $reactor = null) {
    $isWaiting = true;
    $resolvedError = null;
    $resolvedResult = null;

    $promise->when(function($error, $result) use (&$isWaiting, &$resolvedError, &$resolvedResult) {
        $isWaiting = false;
        $resolvedError = $error;
        $resolvedResult = $result;
    });

    $reactor = $reactor ?: reactor();
    while ($isWaiting) {
        $reactor->tick();
    }

    if ($resolvedError) {
        throw $resolvedError;
    }

    return $resolvedResult;
}

/**
 * This function is deprecated; please use Coroutine::resolve() instead
 */
function resolve(\Generator $generator, Reactor $reactor = null) {
    trigger_error(
        "Amp\\resolve() is deprecated; please use Amp\\Coroutine::resolve() instead",
        E_USER_DEPRECATED
    );
    return Coroutine::resolve($generator, $reactor);
}

/**
 * This function is deprecated; please use Coroutine::resolve() instead
 */
function coroutine(callable $func, Reactor $reactor = null) {
    trigger_error(
        "Amp\\coroutine() is deprecated; please use Amp\\Coroutine::wrap() instead",
        E_USER_DEPRECATED
    );
    return Coroutine::wrap($func, $reactor);
}

/**
 * A general purpose function for creating error messages from generator yields
 *
 * @param \Generator $generator
 * @param string $prefix
 * @return string
 */
function makeGeneratorError(\Generator $generator, $prefix = "Generator error") {
    if (PHP_MAJOR_VERSION < 7 || !$generator->valid()) {
        return $prefix;
    }

    $reflGen = new \ReflectionGenerator($generator);
    $exeGen = $reflGen->getExecutingGenerator();
    if ($isSubgenerator = ($exeGen !== $generator)) {
        $reflGen = new \ReflectionGenerator($exeGen);
    }

    return sprintf(
        "{$prefix} on line %s in %s",
        $reflGen->getExecutingLine(),
        $reflGen->getExecutingFile()
    );
}
