<?php

/**
 * @file
 * Contains \Docker\Drupal\Command\RedisPingCommand.
 */

namespace Docker\Drupal\Command\Redis;

use Docker\Drupal\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Docker\Drupal\Style\DruDockStyle;
use Docker\Drupal\Extension\ApplicationContainerExtension;

/**
 * Class RedisPingCommand
 *
 * @package Docker\Drupal\Command\redis
 */
class RedisPingCommand extends Command
{

    protected function configure()
    {
        $this
        ->setName('redis:ping')
        ->setDescription('Ping Redis')
        ->setHelp("This command will ping REDIS and respond with PONG if all is running well.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $application = new Application();
        $container_application = new ApplicationContainerExtension();
        $io = new DruDockStyle($input, $output);

        $io->section('REDIS ::: Ping');

        if ($config = $application->getAppConfig($io)) {
            $appname = $config['appname'];
        }

        if ($container_application->checkForAppContainers($appname, $io)) {
            $command = $container_application->getComposePath($appname, $io) . 'exec -T redis redis-cli ping';
            $application->runcommand($command, $io);
        }
    }
}
