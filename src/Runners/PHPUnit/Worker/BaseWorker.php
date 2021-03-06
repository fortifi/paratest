<?php

declare(strict_types=1);

namespace ParaTest\Runners\PHPUnit\Worker;

use Symfony\Component\Process\PhpExecutableFinder;

abstract class BaseWorker
{
  protected static $descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];
  protected $proc;
  protected $pipes;
  protected $inExecution = 0;
  private $exitCode = null;
  private $chunks = '';
  private $alreadyReadOutput = '';
  private $alreadyReadError = '';
  protected $fileHandle;

  public function start(string $wrapperBinary, $token = 1, $uniqueToken = null, array $parameters = [])
  {
    $this->fileHandle = fopen("paratestlog-{$token}.txt", "wr") or die("Unable to open file: paratestlog-{$token}.txt");
    $bin = 'PARATEST=1 ';
    if(is_numeric($token))
    {
      $bin .= "TEST_TOKEN=$token ";
    }
    if($uniqueToken)
    {
      $bin .= "UNIQUE_TEST_TOKEN=$uniqueToken ";
    }
    $finder = new PhpExecutableFinder();
    $bin .= $finder->find() . " \"$wrapperBinary\"";
    if($parameters)
    {
      $bin .= ' ' . implode(' ', array_map('escapeshellarg', $parameters));
    }
    $pipes = [];
    $process = proc_open($bin, self::$descriptorspec, $pipes);
    $this->proc = is_resource($process) ? $process : null;
    $this->pipes = $pipes;
  }

  public function isFree(): bool
  {
    $this->checkNotCrashed();
    $this->updateStateFromAvailableOutput();

    return $this->inExecution === 0;
  }

  public function isRunning(): bool
  {
    if($this->proc === null)
    {
      return false;
    }

    $status = proc_get_status($this->proc);

    return $status ? $status['running'] : false;
  }

  public function isStarted(): bool
  {
    return $this->proc !== null && $this->pipes !== null;
  }

  public function isCrashed(): bool
  {
    if(!$this->isStarted())
    {
      return false;
    }
    $status = proc_get_status($this->proc);

    $this->updateStateFromAvailableOutput();

    $this->setExitCode($status);
    if($this->exitCode === null)
    {
      return false;
    }

    return $this->exitCode !== 0;
  }

  public function checkNotCrashed()
  {
    if($this->isCrashed())
    {
      $lastCommand = isset($this->commands) ? ' Last executed command: ' . end($this->commands) : '';
      throw new \RuntimeException(
        'This worker has crashed.' . $lastCommand . PHP_EOL
        . 'Output:' . PHP_EOL
        . '----------------------' . PHP_EOL
        . $this->alreadyReadOutput . PHP_EOL
        . '----------------------' . PHP_EOL
        . $this->readAllStderr()
      );
    }
  }

  public function stop()
  {
    fclose($this->pipes[0]);
  }

  protected function setExitCode(array $status)
  {
    if(!$status['running'])
    {
      if($this->exitCode === null)
      {
        $this->exitCode = $status['exitcode'];
      }
    }
  }

  private function readAllStderr()
  {
    return $this->alreadyReadError;
  }

  /**
   * Have to read even incomplete lines to play nice with stream_select()
   * Otherwise it would continue to non-block because there are bytes to be read,
   * but fgets() won't pick them up.
   */
  private function updateStateFromAvailableOutput()
  {
    if(isset($this->pipes[1]))
    {
      stream_set_blocking($this->pipes[1], false);
      while($chunk = fread($this->pipes[1], 4096))
      {
        $this->chunks .= $chunk;
        $this->alreadyReadOutput .= $chunk;
        if($this->fileHandle)
        {
          fwrite($this->fileHandle, $chunk);
        }
      }
      $lines = explode("\n", $this->chunks);
      // last element is not a complete line,
      // becomes part of a line completed later
      $this->chunks = $lines[count($lines) - 1];
      unset($lines[count($lines) - 1]);
      // delivering complete lines to this Worker
      foreach($lines as $line)
      {
        $line .= "\n";
        if(strstr($line, "FiNiShEd\n"))
        {
          --$this->inExecution;
        }
      }
      stream_set_blocking($this->pipes[1], true);
    }
    if(isset($this->pipes[2]))
    {
      stream_set_blocking($this->pipes[2], false);
      while($chunk = fread($this->pipes[2], 4096))
      {
        $this->alreadyReadError .= $chunk;
        if($this->fileHandle)
        {
          fwrite($this->fileHandle, $chunk);
        }
      }
      stream_set_blocking($this->pipes[2], true);
    }
  }
}
