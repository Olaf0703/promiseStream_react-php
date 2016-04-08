<?php

namespace Clue\React\Promise\Stream;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Promise\PromiseInterface;
use React\Stream\WritableStreamInterface;
use React\Stream\Util;
use React\Promise\CancellablePromiseInterface;

/**
 * @internal
 * @see unwrapReadable() instead
 */
class UnwrapReadableStream extends EventEmitter implements ReadableStreamInterface
{
    private $promise;
    private $closed = false;

    /**
     * unwrap a `Promise` which resolves with a `ReadableStreamInterface`.
     *
     * @param PromiseInterface $promise Promise<ReadableStreamInterface, Exception>
     * @return ReadableStreamInterface
     */
    public function __construct(PromiseInterface $promise)
    {
        $out = $this;

        $this->promise = $promise->then(
            function ($stream) {
                if (!($stream instanceof ReadableStreamInterface)) {
                    throw new \InvalidArgumentException('Not a readable stream');
                }
                return $stream;
            }
        )->then(
            function (ReadableStreamInterface $stream) use ($out) {
                if (!$stream->isReadable()) {
                    $out->close();
                    return $stream;
                }

                // stream any writes into output stream
                $stream->on('data', function ($data) use ($out) {
                    $out->emit('data', array($data, $out));
                });

                // forward end events and close
                $stream->on('end', function () use ($out) {
                    $out->emit('end', array($out));
                    $out->close();
                });

                // error events cancel output stream
                $stream->on('error', function ($error) use ($out) {
                    $out->emit('error', array($error, $out));
                    $out->close();
                });

                // close output stream once input closes
                $stream->on('close', function () use ($out) {
                    $out->close();
                });

                return $stream;
            },
            function ($e) use ($out) {
                $out->emit('error', array($e, $out));
                $out->close();
            }
        );
    }

    public function isReadable()
    {
        return !$this->closed;
    }

    public function pause()
    {
        $this->promise->then(function (ReadableStreamInterface $stream) {
            $stream->pause();
        });
    }

    public function resume()
    {
        $this->promise->then(function (ReadableStreamInterface $stream) {
            $stream->resume();
        });
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // try to cancel promise once the stream closes
        if ($this->promise instanceof CancellablePromiseInterface) {
            $this->promise->cancel();
        }

        $this->emit('close', array($this));
    }
}