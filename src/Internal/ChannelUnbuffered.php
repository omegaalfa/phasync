<?php
namespace phasync\Internal;

use Fiber;
use phasync;
use phasync\ChannelException;
use phasync\Debug;
use Serializable;
use SplQueue;
use Throwable;

/**
 * This is a highly optimized implementation of a bi-directional channel
 * for communication between coroutines running in the same memory space
 * without threading. It is not meant to be used directly since it has 
 * no protection against deadlocks. Instead channels should be created
 * via {@see phasync::channel()}. 
 * 
 * The implementation reaches around 500 000 reads and 500 000 writes
 * between a pair of coroutines on an AMD Ryzen 7 2700X.
 * 
 * @package phasync
 */
final class ChannelUnbuffered implements ChannelBackendInterface {

    const READY = 0;
    const BLOCKING_READS = 1;
    const BLOCKING_WRITES = 2;

    /**
     * Waiting readers must be stored here, because if the Channel becomes
     * garbage collected then the Fiber it is referencing will be destroyed.
     * Instead we will enqueue the suspended reader in the destructor.
     * 
     * @var array<int, SplQueue<Fiber>>
     */
    private static array $waiting = [];

    private int $id;
    private bool $closed = false;
    private mixed $value = null;
    private ?Fiber $creatingFiber;
    private int $state = self::READY;
    private ?Fiber $receiver;

    public function __construct() {
        $this->id = \spl_object_id($this);
        self::$waiting[$this->id] = new SplQueue();
        $this->creatingFiber = phasync::getFiber();
    }

    public function __destruct() {
        $this->closed = true;
        unset(self::$waiting[$this->id]);
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->state === self::BLOCKING_READS) {
            while (!self::$waiting[$this->id]->isEmpty()) {
                phasync::enqueue(self::$waiting[$this->id]->dequeue());
            }
        } else {
            while (!self::$waiting[$this->id]->isEmpty()) {
                phasync::enqueueWithException(self::$waiting[$this->id]->dequeue(), new ChannelException("Channel was closed"));
            }    
        }
    }

    public function isClosed(): bool {
        return $this->closed;
    }

    public function write(Serializable|array|string|float|int|bool $value): void {
        if ($this->closed) {
            throw new ChannelException("Channel is closed");
        }

        $fiber = phasync::getFiber();

        if ($this->creatingFiber) {
            if ($this->creatingFiber === $fiber) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            } else {
                $this->creatingFiber = null;
            }
        }

        if (self::$waiting[$this->id]->isEmpty()) {
            $this->state = self::BLOCKING_WRITES;
        }
        if ($this->state === self::BLOCKING_WRITES) {
            /**
             * I am a waiting writer. 
             */
            self::$waiting[$this->id]->enqueue($fiber);
            Fiber::suspend();
            $reader = $this->receiver;
            $this->receiver = null;
        } else {
            /**
             * Using a waiting reader.
             */
            $reader = self::$waiting[$this->id]->dequeue();
        }
        $this->value = $value;
        $value = null;
        phasync::enqueue($fiber);
        Fiber::suspend($reader);
    }

    public function read(): Serializable|array|string|float|int|bool|null {
        if ($this->closed) {
            return null;
        }

        $fiber = phasync::getFiber();

        if ($this->creatingFiber) {
            if ($this->creatingFiber === $fiber) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            } else {
                $this->creatingFiber = null;
            }
        }


        if (self::$waiting[$this->id]->isEmpty()) {
            $this->state = self::BLOCKING_READS;
        }
        if ($this->state === self::BLOCKING_READS) {
            /**
             * I am a waiting reader. I will be resumed with the result, and the
             * writer is responsible for allowing us to continue.
             */
            self::$waiting[$this->id]->enqueue($fiber);
            Fiber::suspend();
        } else {
            /**
             * I'm using a waiting writer.
             */
            $writer = self::$waiting[$this->id]->dequeue();
            $this->receiver = $fiber;
            Fiber::suspend($writer);
        }
        $result = $this->value;
        $this->value = null;
        return $result;
    }

    public function isReadable(): bool {
        return !$this->closed;
    }

    public function isWritable(): bool {
        return !$this->closed;
    }

    public function readWillBlock(): bool {
        if ($this->creatingFiber) {
            if ($this->creatingFiber === phasync::getFiber()) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            } else {
                $this->creatingFiber = null;
            }
        }
        return self::$waiting[$this->id]->isEmpty() || $this->state === self::BLOCKING_READS;
    }

    public function writeWillBlock(): bool {
        if ($this->creatingFiber) {
            if ($this->creatingFiber === phasync::getFiber()) {
                throw new ChannelException("Can't open a channel from the same coroutine that created it");
            } else {
                $this->creatingFiber = null;
            }
        }
        return self::$waiting[$this->id]->isEmpty() || $this->state === self::BLOCKING_WRITES;
    }

}