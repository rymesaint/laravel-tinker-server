<?php

namespace BeyondCode\LaravelTinkerServer;

use BeyondCode\LaravelTinkerServer\Shell\ExecutionClosure;
use Clue\React\Stdio\Stdio;
use Psy\Shell;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\Console\Output\BufferedOutput;

class Server
{
    protected $host;

    /** @var LoopInterface */
    protected $loop;

    /** @var BufferedOutput */
    protected $shellOutput;

    /** @var Shell */
    protected $shell;

    /** @var BufferedOutput */
    protected $output;

    /** @var Stdio */
    protected $stdio;

    public function __construct($host, Shell $shell, BufferedOutput $output, array $context = [], LoopInterface $loop = null, Stdio $stdio = null)
    {
        $this->host = $host;
        $this->context = $context;
        $this->loop = $loop ?? Loop::get();
        $this->shell = $shell;
        $this->output = $output;
        $this->shellOutput = new BufferedOutput();
        $this->stdio = $stdio ?? $this->createStdio();
    }

    public function start()
    {
        $this->shell->setOutput($this->shellOutput);

        $this->createSocketServer();

        $this->loop->run();
    }

    protected function createSocketServer()
    {
        $socket = new SocketServer($this->host, $this->context, $this->loop);

        $socket->on('connection', function (ConnectionInterface $connection) {
            $connection->on('data', function ($data) use ($connection) {
                $unserializedData = unserialize(base64_decode($data));

                $this->shell->setScopeVariables(array_merge($unserializedData, $this->shell->getScopeVariables()));

                $this->stdio->write(PHP_EOL);

                collect($unserializedData)->keys()->map(function ($variableName) {
                    $this->output->writeln('>> $'.$variableName);

                    $this->executeCode('$'.$variableName);

                    $this->output->write($this->shellOutput->fetch());

                    $this->stdio->write($this->output->fetch());
                });
            });
        });
    }

    protected function createStdio(): Stdio
    {
        $stdio = new Stdio($this->loop);

        $stdio->setPrompt('>> ');

        $stdio->on('data', function ($line) use ($stdio) {
            $line = rtrim($line, "\r\n");

            $stdio->addHistory($line);
            $this->executeCode($line);

            $this->output->write(PHP_EOL.$this->shellOutput->fetch());

            $this->stdio->write($this->output->fetch());
        });

        return $stdio;
    }

    protected function executeCode($code)
    {
        (new ExecutionClosure($this->shell, $code))->execute();
    }
}
