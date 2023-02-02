<?php
namespace Clippy;

use Clippy\Exception\UserQuitException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `Taskr` ("tasker") is a small utility for writing adhoc scripts that run
 * a series of commands. It is similar to `Cmdr`, but it supports runtime options
 * that allow the user to monitor the tasks.
 *
 * For example:
 *
 * $c['app']->command('do-stuff [--step] [--dry-run]', function(Taskr $taskr) {
 *   $taskr->passthru('rm -f abc.txt');
 *   $taskr->passthru('touch def.txt');
 * });
 *
 * By default, the 'do-stuff' command will remove 'abc.txt' and create 'def.txt'.
 * If you call `do-stuff --step`, then it will prompt before executing each command.
 * If you call 'do-stuff --dry-run', then it will print a message about each command (but will not execute).
 */
class Taskr {

  public function __construct(Container $c) {
    $this->c = $c;
  }

  public static function register(Container $c): Container {
    $c->set('taskr', function () use ($c) {
      return new Taskr($c);
    });
    return $c;
  }

  /**
   * @var \Psr\Container\ContainerInterface
   */
  protected $c;

  public function assertConfigured(): void {
    $c = $this->c;

    $missingOptions = [];
    if (!$c['input']->hasOption('step')) {
      $missingOptions[] = '--step';
    }
    if (!$c['input']->hasOption('dry-run')) {
      $missingOptions[] = '--dry-run';
    }
    if (!empty($missingOptions)) {
      trigger_error("Command uses 'Taskr', but the expected options (" . implode(' ', $missingOptions) . ") have not been pre-defined.", E_USER_WARNING);
    }
  }

  /**
   * Call an external command and pass-through all inputs and outputs.
   *
   * @param string $cmd
   * @param array $params
   * @throws \Clippy\Exception\CmdrProcessException
   */
  public function passthru(string $cmd, array $params = []): void {
    $this->assertConfigured();

    // We resolve these variables as-needed because that works better with recursive command invocations.

    /**
     * @var \Symfony\Component\Console\Style\StyleInterface $io
     * @var \Symfony\Component\Console\Input\InputInterface $input
     * @var Cmdr $cmdr
     */
    $io = $this->c['io'];
    $input = $this->c['input'];
    $cmdr = $this->c['cmdr'];
    $extraVerbosity = 0;
    $cmdDesc = '<comment>$</comment> ' . $cmdr->escape($cmd, $params) . ' <comment>[[in ' . getcwd() . ']]</comment>';

    if ($input->hasOption('step') && $input->getOption('step')) {
      $extraVerbosity = OutputInterface::VERBOSITY_VERBOSE - $io->getVerbosity();
      $io->writeln('<comment>COMMAND</comment>' . $cmdDesc);
      $confirmation = ($io->ask('<info>Execute this command?</info> (<comment>[Y]</comment>es, <comment>[s]</comment>kip, <comment>[q]</comment>uit)', NULL, function ($value) {
        $value = ($value === NULL) ? 'y' : mb_strtolower($value);
        if (!in_array($value, ['y', 's', 'q'])) {
          throw new InvalidArgumentException("Invalid choice ($value)");
        }
        return $value;
      }));
      switch ($confirmation) {
        case 's':
          return;

        case 'y':
        case NULL:
          break;

        case 'q':
        default:
          throw new UserQuitException();
      }
    }

    if ($input->hasOption('dry-run') && $input->getOption('dry-run')) {
      $io->writeln('<comment>DRY-RUN</comment>' . $cmdDesc);
      return;
    }

    try {
      $io->setVerbosity($io->getVerbosity() + $extraVerbosity);
      $cmdr->passthru($cmd, $params);
    } finally {
      $io->setVerbosity($io->getVerbosity() - $extraVerbosity);
    }
  }

  /**
   * Call a different subcommand (within the same application / same process).
   *
   * Note: The $input will be temporarily replaced with a new $input, based on the requested command options.
   *
   * @param string $cmd
   *   Ex: 'greet --msg={{MSG|s}}'
   * @param array $params
   *   Ex: ['MSG' => 'hello world']
   * @throws \Exception
   */
  public function subcommand(string $cmd, array $params = []): void {

    // We resolve these variables as-needed because that works better with recursive command invocations.

    /**
     * @var \Clippy\Application $app
     * @var \Symfony\Component\Console\Input\InputInterface $origInput
     * @var \Symfony\Component\Console\Output\OutputInterface $output
     * @var \Clippy\Cmdr $cmdr
     */
    $app = $this->c['app'];
    $origInput = $this->c['input'];
    $output = $this->c['output'];
    $cmdr = $this->c['cmdr'];

    $cmdParts = explode(' ', $cmd, 2);
    foreach (['dry-run', 'step'] as $propagateOption) {
      if ($origInput->hasOption($propagateOption) && $origInput->getOption($propagateOption)) {
        array_splice($cmdParts, 1, 0, ["--{$propagateOption}"]);
      }
    }
    $fullCmd = $cmdr->escape(implode(' ', $cmdParts), $params);
    if ($output->isVerbose()) {
      $output->write('<comment>SUBCOMMAND$</comment> ');
      $output->writeln($fullCmd, OutputInterface::OUTPUT_RAW);
    }

    $stringInput = new StringInput($fullCmd);
    $command = $app->find($stringInput->getFirstArgument());
    try {
      $this->c['input'] = $stringInput;
      $result = $command->run($stringInput, $output);
      if (!empty($result)) {
        throw new \RuntimeException("Subcommand failed ($fullCmd) => ($result)");
      }
    } finally {
      $this->c['input'] = $origInput;
    }
  }

}
