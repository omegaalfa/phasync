<?php

namespace phasync\Services;

use phasync;

/**
 * Provides asynchronous running of curl_handles within phasync. To
 * run the curl handle, use `CurlMulti::await($curlHandle)` from inside
 * a coroutine.
 */
final class CurlMulti
{
    private static ?\CurlMultiHandle $curlMulti = null;
    private static bool $isRunning              = false;
    /**
     * Stores all pending curl multi handles in case of error.
     *
     * @var \CurlHandle[]
     */
    private static array $curlHandles           = [];

    /**
     * Stores any exception messages to be thrown when the handle is resumed.
     *
     * @var array<int,array{0: string, 1: int}>
     */
    private static array $curlHandleErrors      = [];

    public static function await(\CurlHandle $ch)
    {
        $curlHandleId = \spl_object_id($ch);

        if (null === self::$curlMulti) {
            if (!\function_exists('curl_multi_init')) {
                throw new \RuntimeException('curl_multi_init is required');
            }
            self::$curlMulti = \curl_multi_init();
        }
        $addHandleResult = \curl_multi_add_handle(self::$curlMulti, $ch);
        if (\CURLM_OK !== $addHandleResult) {
            throw new \RuntimeException('Failed to add curl handle: ' . \curl_multi_strerror($addHandleResult));
        }
        self::$curlHandles[$curlHandleId] = $ch;
        self::runService();

        /*
         * This will ensure that the fiber that invoked CurlMulti::await() will
         * be blocked until the service coroutine raises the flag.
         */
        try {
            \phasync::awaitFlag($ch, \PHP_FLOAT_MAX);

            if (isset(self::$curlHandleErrors[$curlHandleId])) {
                throw new \RuntimeException(self::$curlHandleErrors[$curlHandleId][0], self::$curlHandleErrors[$curlHandleId][1]);
            }

            return \curl_multi_getcontent($ch) ?? false;
        } finally {
            // Ensure cleanup
            unset(self::$curlHandles[$curlHandleId]);
            unset(self::$curlHandleErrors[$curlHandleId]);
            \curl_multi_remove_handle(self::$curlMulti, $ch);
        }
    }

    /**
     * Launch a service coroutine which will process curl handles until there
     * are no more curl handles needing to be run. The coroutine terminates when
     * there are no curl handles to monitor.
     */
    private static function runService(): void
    {
        if (!self::$isRunning) {
            \phasync::service(static function () {
                try {
                    self::$isRunning = true;
                    do {
                        \phasync::sleep(0.02);
                        $status = \curl_multi_exec(self::$curlMulti, $active);
                        /*
                         * @var array
                         */
                        while (false !== ($info = \curl_multi_info_read(self::$curlMulti))) {
                            if (\CURLMSG_DONE === $info['msg']) {
                                // Activate the fiber that invoked the await() function
                                \phasync::raiseFlag($info['handle']);
                                unset(self::$curlHandles[\spl_object_id($info['handle'])]);
                            }
                        }
                    } while ($active && \CURLM_OK === $status);
                } catch (\Throwable $e) {
                    foreach (self::$curlHandles as $id => $ch) {
                        self::$curlHandleErrors[$id] = ['CurlMulti error: ' . $e->getMessage(), $e->getCode()];
                        unset(self::$curlHandles[$id]);
                        \phasync::raiseFlag($ch);
                    }
                } finally {
                    self::$isRunning = false;
                    foreach (self::$curlHandles as $id => $ch) {
                        self::$curlHandleErrors[$id] = ['The CurlMulti service terminated without resolving the curl handle', 1];
                        \phasync::raiseFlag($ch);
                    }
                }
            });
        }
    }
}
