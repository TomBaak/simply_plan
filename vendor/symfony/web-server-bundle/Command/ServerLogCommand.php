<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\WebServerBundle\Command;

use Monolog\Formatter\FormatterInterface;
use Monolog\Logger;
use Symfony\Bridge\Monolog\Formatter\ConsoleFormatter;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 *
 * @deprecated since Symfony 4.4, to be removed in 5.0; the new Symfony local server has more features, you can use it instead.
 */
class ServerLogCommand extends Command
{
    private static $bgColor = ['black', 'blue', 'cyan', 'green', 'magenta', 'red', 'white', 'yellow'];

    private $el;
    private $handler;

    protected static $defaultName = 'server:log';

    public function isEnabled()
    {
        if (!class_exists(ConsoleFormatter::class)) {
            return false;
        }

        // based on a symfony/symfony package, it crashes due a missing FormatterInterface from monolog/monolog
        if (!interface_exists(FormatterInterface::class)) {
            return false;
        }

        return parent::isEnabled();
    }

    protected function configure()
    {
        if (!class_exists(ConsoleFormatter::class)) {
            return;
        }

        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The server host', '0.0.0.0:9911')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The line format', ConsoleFormatter::SIMPLE_FORMAT)
            ->addOption('date-format', null, InputOption::VALUE_REQUIRED, 'The date format', ConsoleFormatter::SIMPLE_DATE)
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'An expression to filter log. Example: "level > 200 or channel in [\'app\', \'doctrine\']"')
            ->setDescription('Starts a log server that displays logs in real time')
            ->setHelp(<<<'EOF'
<info>%command.name%</info> starts a log server to display in real time the log
messages generated by your application:

  <info>php %command.full_name%</info>

To get the information as a machine readable format, use the
<comment>--filter</> option:

<info>php %command.full_name% --filter=port</info>
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        @trigger_error('Using the WebserverBundle is deprecated since Symfony 4.4. The new Symfony local server has more features, you can use it instead.', E_USER_DEPRECATED);

        $filter = $input->getOption('filter');
        if ($filter) {
            if (!class_exists(ExpressionLanguage::class)) {
                throw new LogicException('Package "symfony/expression-language" is required to use the "filter" option.');
            }
            $this->el = new ExpressionLanguage();
        }

        $this->handler = new ConsoleHandler($output, true, [
            OutputInterface::VERBOSITY_NORMAL => Logger::DEBUG,
        ]);

        $this->handler->setFormatter(new ConsoleFormatter([
            'format' => str_replace('\n', "\n", $input->getOption('format')),
            'date_format' => $input->getOption('date-format'),
            'colors' => $output->isDecorated(),
            'multiline' => OutputInterface::VERBOSITY_DEBUG <= $output->getVerbosity(),
        ]));

        if (false === strpos($host = $input->getOption('host'), '://')) {
            $host = 'tcp://'.$host;
        }

        if (!$socket = stream_socket_server($host, $errno, $errstr)) {
            throw new RuntimeException(sprintf('Server start failed on "%s": %s %s.', $host, $errstr, $errno));
        }

        foreach ($this->getLogs($socket) as $clientId => $message) {
            $record = unserialize(base64_decode($message));

            // Impossible to decode the message, give up.
            if (false === $record) {
                continue;
            }

            if ($filter && !$this->el->evaluate($filter, $record)) {
                continue;
            }

            $this->displayLog($output, $clientId, $record);
        }

        return 0;
    }

    private function getLogs($socket): iterable
    {
        $sockets = [(int) $socket => $socket];
        $write = [];

        while (true) {
            $read = $sockets;
            stream_select($read, $write, $write, null);

            foreach ($read as $stream) {
                if ($socket === $stream) {
                    $stream = stream_socket_accept($socket);
                    $sockets[(int) $stream] = $stream;
                } elseif (feof($stream)) {
                    unset($sockets[(int) $stream]);
                    fclose($stream);
                } else {
                    yield (int) $stream => fgets($stream);
                }
            }
        }
    }

    private function displayLog(OutputInterface $output, int $clientId, array $record)
    {
        if (isset($record['log_id'])) {
            $clientId = unpack('H*', $record['log_id'])[1];
        }
        $logBlock = sprintf('<bg=%s> </>', self::$bgColor[$clientId % 8]);
        $output->write($logBlock);

        $this->handler->handle($record);
    }
}
