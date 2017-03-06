<?php

/**
 * @file
 * Contains \Docker\Drupal\Command\DemoCommand.
 */

namespace Docker\Drupal\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Question\Question;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Docker\Drupal\Style\DockerDrupalStyle;


/**
 * Class DemoCommand
 * @package Docker\Drupal\ContainerAwareCommand
 */
class BuildCommand extends ContainerAwareCommand {
  protected function configure() {
    $this
      ->setName('build:init')
      ->setAliases(['init'])
      ->setDescription('Fetch and build Drupal apps')
      ->setHelp('This command will fetch and build Drupal apps')
      ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Specify app version [D7,D8,DEFAULT]');
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $application = $this->getApplication();
    $io = new DockerDrupalStyle($input, $output);

    // check if this folder is has APP config
    if (!file_exists('.config.yml')) {
      $io->error('You\'re not currently in an APP directory');
      return;
    }

    $fs = new Filesystem();

    $config = $application->getAppConfig($io);
    if ($config) {
      if (!$config['appname']) {
        $application->requireUpdate($io);
      }
      else {
        $appname = $config['appname'];
      }

      if (!$config['apptype']) {
        $application->requireUpdate($io);
      }
      else {
        $type = $config['apptype'];
      }

      if (!$config['host']) {
        $application->requireUpdate($io);
      }
      else {
        $apphost = $config['host'];
      }

    }

    $system_appname = strtolower(str_replace(' ', '', $appname));

    $application->setNginxHost($io);

    if ($application->getOs() == 'Darwin') {
      $application->addHostConfig($io, TRUE);
    }

    /**
     * Install specific APP type
     */
    if (isset($type) && $type == 'DEFAULT') {
      $this->setUpExampleApp($fs, $io, $system_appname);
      $this->initDocker($io, $system_appname);
      $message = 'Opening Default APP at http://' . $apphost;
    }
    if (isset($type) && $type == 'D7') {
      $this->setupD7($fs, $io, $system_appname, $input, $output);
      $this->initDocker($io, $system_appname);
      $this->installDrupal7($io);
      $message = 'Opening Drupal 7 base Installation at http://' . $apphost;
    }
    if (isset($type) && $type == 'D8') {
      $this->setupD8($fs, $io, $system_appname, $input, $output);
      $this->initDocker($io, $system_appname);
      $this->installDrupal8($io);
      $message = 'Opening Drupal 8 base Installation at http://' . $apphost;
    }

    $io->note($message);
    shell_exec('python -n -mwebbrowser http://' . $apphost);

  }

  private function setupD7($fs, $io, $appname, $input, $output) {
    $app_dest = './app';
    $date = date('Y-m-d--H-i-s');

    $application = $this->getApplication();
    $utilRoot = $application->getUtilRoot();

    $config = $application->getAppConfig($io);
    if ($config) {
      $appname = $config['appname'];
      $appsrc = $config['appsrc'];
      $apprepo = $config['repo'];
      $reqs = $config['reqs'];
    }

    if (isset($appsrc) && $appsrc == 'Git' && !$fs->exists($app_dest)) {
      $command = 'git clone ' . $apprepo . ' app';
      $application->runcommand($command, $io);
      $io->info('Downloading app from repo.... This may take a few minutes....');

      $io->info(' ');
      $io->title("SET APP DOCROOT");
      $helper = $this->getHelper('question');
      $question = new Question('Please specify repository relative path to site docroot [./web/] [./docroot/] [./] : ', './');
      $root = $helper->ask($input, $output, $question);
      $fs->symlink($root, $app_dest . '/www', TRUE);
    }

    if (!$fs->exists($app_dest)) {

      try {
        $fs->mkdir($app_dest);

        $fs->mkdir($app_dest . '/repository/libraries/custom');
        $fs->mkdir($app_dest . '/repository/modules/custom');
        $fs->mkdir($app_dest . '/repository/scripts');
        $fs->mkdir($app_dest . '/repository/themes/custom');

        $fs->mkdir($app_dest . '/shared/files');
        $fs->mkdir($app_dest . '/builds');

      } catch (IOExceptionInterface $e) {
        //echo 'An error occurred while creating your directory at '.$e->getPath();
        $io->error(sprintf('An error occurred while creating your directory at ' . $e->getPath()));
      }

      // build repo content
      if (is_dir($utilRoot . '/bundles/d7') && is_dir($app_dest . '/repository')) {
        $d7files = $utilRoot . '/bundles/d7';
        // potential repo files
        $fs->copy($d7files . '/robots.txt', $app_dest . '/repository/robots.txt');
        $fs->copy($d7files . '/settings.php', $app_dest . '/repository/settings.php');
        $fs->copy($d7files . '/project.make.yml', $app_dest . '/repository/project.make.yml');
        $fs->copy($d7files . '/.gitignore', $app_dest . '/repository/.gitignore');
        //local shared files
        $fs->copy($d7files . '/settings.local.php', $app_dest . '/shared/settings.local.php');

        if (isset($reqs) && $reqs == 'Full') {
          $fs->mirror($utilRoot . '/bundles/behat/', $app_dest . '/behat/');
        }
      }

      //replace this with make.yml script
      $command = 'drush make ' . $app_dest . '/repository/project.make.yml ' . $app_dest . '/builds/' . $date . '/public';

      $io->info(' ');
      $io->note('Download and configure Drupal 7.... This may take a few minutes....');
      $application->runcommand($command, $io);

      $buildpath = 'builds/' . $date . '/public';
      $fs->symlink($buildpath, $app_dest . '/www', TRUE);

      $rel = $fs->makePathRelative($app_dest . '/repository/', $app_dest . '/' . $buildpath);

      $fs->remove([$app_dest . '/' . $buildpath . '/robots.txt']);
      $fs->symlink($rel . 'robots.txt', $app_dest . '/' . $buildpath . '/robots.txt', TRUE);

      $fs->remove([$app_dest . '/' . $buildpath . '/sites/default/settings.php']);
      $fs->symlink('../../' . $rel . 'settings.php', $app_dest . '/' . $buildpath . '/sites/default/settings.php', TRUE);
      $fs->remove([$app_dest . '/' . $buildpath . '/sites/default/files']);
      $fs->symlink('../../../../../shared/settings.local.php', $app_dest . '/' . $buildpath . '/sites/default/settings.local.php', TRUE);
      $fs->remove([$app_dest . '/' . $buildpath . '/sites/default/files']);
      $fs->symlink('../../../../../shared/files', $app_dest . '/' . $buildpath . '/sites/default/files', TRUE);

      $fs->symlink($rel . '/sites/default/modules/custom', $app_dest . '/' . $buildpath . '/modules/custom', TRUE);
      $fs->symlink($rel . '/profiles/custom', $app_dest . '/' . $buildpath . '/profiles/custom', TRUE);
      $fs->symlink($rel . '/sites/default/themes/custom', $app_dest . '/' . $buildpath . '/themes/custom', TRUE);

      $fs->chmod($app_dest . '/' . $buildpath . '/sites/default/files', 0777, 0000, TRUE);
      $fs->chmod($app_dest . '/' . $buildpath . '/sites/default/settings.php', 0777, 0000, TRUE);
      $fs->chmod($app_dest . '/' . $buildpath . '/sites/default/settings.local.php', 0777, 0000, TRUE);
    }

  }

  private function setupD8($fs, $io, $appname, $input, $output) {

    $app_dest = './app';
    $application = $this->getApplication();
    $utilRoot = $application->getUtilRoot();

    $config = $application->getAppConfig($io);
    if ($config) {
      $appname = $config['appname'];
      $appsrc = $config['appsrc'];
      $apprepo = $config['repo'];
      $reqs = $config['reqs'];
    }

    if (isset($appsrc) && $appsrc == 'Git' && !$fs->exists($app_dest)) {
      $command = 'git clone ' . $apprepo . ' app';
      $application->runcommand($command, $io);
      $io->info('Downloading app from repo.... This may take a few minutes....');

      $io->info(' ');
      $io->title("SET APP DOCROOT");
      $helper = $this->getHelper('question');
      $question = new Question('Please specify repository relative path to site docroot [./web/] [./docroot/] [./] : ', './web/');
      $root = $helper->ask($input, $output, $question);
      $fs->symlink($root, $app_dest . '/www', TRUE);

      $command = 'cd app && composer install';
      $application->runcommand($command, $io);
    }

    if (!$fs->exists($app_dest)) {
      $command = sprintf('composer create-project drupal-composer/drupal-project:8.x-dev ' . $app_dest . ' -dir --stability dev --no-interaction');
      $io->info(' ');
      $io->note('Download and configure Drupal 8.... This may take a few minutes....');
      $application->runcommand($command, $io);
    }

    if ($fs->exists($app_dest)) {

      try {
        $fs->mkdir($app_dest . '/config/sync');
        $fs->mkdir($app_dest . '/web/sites/default/files');
        $fs->mkdir($app_dest . '/web/themes/custom');
        $fs->mkdir($app_dest . '/web/modules/custom');
        $fs->mkdir($app_dest . '/shared/files');

      } catch (IOExceptionInterface $e) {
        // Echo 'An error occurred while creating your directory at '.$e->getPath();
        $io->error(sprintf('An error occurred while creating your directory at ' . $e->getPath()));
      }

      if ($reqs == 'Prod') {
        $files_dir = 'd8prod';
      }
      else {
        $files_dir = 'd8';
      }

      // Move DockerDrupal Drupal 8 config files into install
      if (is_dir($utilRoot . '/bundles/' . $files_dir) && is_dir($app_dest)) {
        $d8files = $utilRoot . '/bundles/' . $files_dir;

        $fs->copy($d8files . '/composer.json', $app_dest . '/composer.json', TRUE);
        $fs->copy($d8files . '/development.services.yml', $app_dest . '/web/sites/development.services.yml', TRUE);
        $fs->copy($d8files . '/services.yml', $app_dest . '/web/sites/default/services.yml', TRUE);
        $fs->copy($d8files . '/robots.txt', $app_dest . '/web/robots.txt', TRUE);
        $fs->copy($d8files . '/settings.php', $app_dest . '/web/sites/default/settings.php', TRUE);
        $fs->copy($d8files . '/settings.local.php', $app_dest . '/web/sites/default/settings.local.php', TRUE);
        $fs->copy($d8files . '/drushrc.php', $app_dest . '/web/sites/default/drushrc.php', TRUE);

        if (isset($reqs) && $reqs == 'Full') {
          $fs->mirror($utilRoot . '/bundles/behat/', $app_dest . '/behat/');
        }

      }

      // Set perms
      $fs->chmod($app_dest . '/config/sync', 0777, 0000, TRUE);
      $fs->chmod($app_dest . '/web/sites/default/files', 0777, 0000, TRUE);
      $fs->chmod($app_dest . '/web/sites/default/settings.php', 0755, 0000, TRUE);
      $fs->chmod($app_dest . '/web/sites/default/settings.local.php', 0755, 0000, TRUE);

      // setup $VAR for redis cache_prefix in settings.local.php template
      $cache_prefix = "\$settings['cache_prefix'] = '" . $appname . "_';";
      $local_settings = $app_dest . '/web/sites/default/settings.local.php';
      $process = new Process(sprintf('echo %s | sudo tee -a %s >/dev/null', $cache_prefix, $local_settings));
      $process->run();

      $fs->symlink('./web', $app_dest . '/www', TRUE);

    }
  }

  private function setupExampleApp($fs, $io, $appname) {

    $app_dest = './app';
    $application = $this->getApplication();
    $utilRoot = $application->getUtilRoot();

    $message = 'Setting up Example app';
    $io->section($message);
    // example app source and destination
    if (is_dir($utilRoot . '/bundles/default')) {
      $app_src = $utilRoot . '/bundles/default';
      try {
        $fs->mkdir($app_dest . '/repository');
        $fs->mirror($app_src, $app_dest . '/repository');
      } catch (IOExceptionInterface $e) {
        echo 'An error occurred while creating your directory at ' . $e->getPath();
      }
      $fs->symlink('repository', './app/www', TRUE);
    }
  }

  private function installDrupal8($io, $install_helpers = FALSE) {

    $application = $this->getApplication();
    if ($config = $application->getAppConfig($io)) {
      $reqs = $config['reqs'];
      $appname = $config['appname'];
    }

    if (isset($reqs) && $reqs == 'Full') {
      $command = $application->getComposePath($appname, $io) . 'exec -T behat composer update';
      $application->runcommand($command, $io);
    }

    $message = 'Run Drupal Installation.... This may take a few minutes....';
    $io->note($message);
    if ($application->checkForAppContainers($appname, $io)) {

      if ($reqs == 'Basic' || $reqs == 'Full') {
        $command = $application->getComposePath($appname, $io) . 'exec -T php chmod -R 777 ../vendor/';
        $application->runcommand($command, $io);

        $command = $application->getComposePath($appname, $io) . 'exec -T php drush site-install standard --account-name=dev --account-pass=admin --site-name=DockerDrupal --site-mail=drupalD8@docker.dev --db-url=mysql://dev:DEVPASSWORD@db:3306/dev_db --quiet -y';
        $application->runcommand($command, $io);
      }
      if ($reqs == 'Prod') {
        $command = $application->getComposePath($appname, $io) . 'exec -T php drush site-install standard --account-name=prod --account-pass=admin --site-name=DockerDrupal --site-mail=drupalD8@docker.prod --db-url=mysql://dev:DRUPALPASSENV@db:3306/prod --quiet -y';
        $application->runcommand($command, $io);
      }
      if ($reqs == 'Stage') {
        $command = $application->getComposePath($appname, $io) . 'exec -T php drush site-install standard --account-name=stage --account-pass=admin --site-name=DockerDrupal --site-mail=drupalD8@docker.prod --db-url=mysql://dev:DRUPALPASSENV@db:3306/prod --quiet -y';
        $application->runcommand($command, $io);
      }

      if ($install_helpers) {
        $message = 'Run APP composer update';
        $io->note($message);

        $command = $application->getComposePath($appname, $io) . 'exec -T php composer update';
        $application->runcommand($command, $io);

        $message = 'Enable useful starter contrib modules';
        $io->note($message);

        $command = $application->getComposePath($appname, $io) . 'exec -T php drush en admin_toolbar ctools redis token adminimal_admin_toolbar devel pathauto webprofiler -y';
        $application->runcommand($command, $io);

        $command = $application->getComposePath($appname, $io) . 'exec -T php drush entity-updates -y';
        $application->runcommand($command, $io);
      }
    }
  }

  private function installDrupal7($io) {

    $application = $this->getApplication();
    if ($config = $application->getAppConfig($io)) {
      $reqs = $config['reqs'];
      $appname = $config['appname'];
    }

    $message = 'Run Drupal Installation.... This may take a few minutes....';
    $io->note($message);

    $command = $command = $application->getComposePath($appname, $io) . 'exec -T php drush site-install standard --account-name=dev --account-pass=admin --site-name=DockerDrupal --site-mail=drupalD7@docker.dev --db-url=mysql://dev:DEVPASSWORD@db:3306/dev_db -y';
    $application->runcommand($command, $io);
  }

  private function initDocker($io, $appname) {

    $application = $this->getApplication();

    $message = 'Creating and configure DockerDrupal containers.... This may take a moment....';
    $io->note($message);

    if ($config = $application->getAppConfig($io)) {
      $appreqs = $config['reqs'];
      if (is_array($config['builds'])) {
        $build = end($config['builds']);
      }
    }

    if (isset($appreqs) && ($appreqs == 'Basic' || $appreqs == 'Full')) {

      // Run Unison APP SYNC so that PHP working directory is ready to go with DATA stored in the Docker Volume.
      // When 'Synchronization complete' kill this temp run container and start DockerDrupal.

      $command = 'until ' . $application->getComposePath($appname, $io) .
        'run app 2>&1 | grep -m 1 -e "Synchronization complete" -e "finished propagating changes" ; do : ; done ;' .
        'docker kill $(docker ps -q) 2>&1; ' .
        $application->getComposePath($appname, $io) . 'up -d';

      $application->runcommand($command, $io);

      // Check for running mySQL container before launching Drupal Installation
      $message = 'Waiting for mySQL service.';
      $io->warning($message);
      while (!@mysqli_connect('127.0.0.1', 'dev', 'DEVPASSWORD', 'dev_db')) {
        sleep(1);
        echo '.';
      }
      $io->text(' ');
      $message = 'mySQL CONNECTED';
      $io->success($message);
    }

    // Production option specific build.
    if (isset($appreqs) && $appreqs == 'Prod') {

      $appname = $config['appname'];
      $system_appname = strtolower(str_replace(' ', '', $appname));

      $io->section("Docker ::: Build prod environment");

      // Setup proxy network.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose-nginx-proxy.yml --project-name=proxy up -d';
      $application->runcommand($command, $io);

      // Setup data service.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose-data.yml --project-name=data up -d';
      $application->runcommand($command, $io);

      // RUN APP BUILD.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose.yml --project-name=' . $system_appname . '--' . $build . ' build --no-cache';
      $application->runcommand($command, $io);

      //RUN APP.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose.yml --project-name=' . $system_appname . '--' . $build . ' up -d app';
      $application->runcommand($command, $io);

      //START PROJECT.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose.yml --project-name=' . $system_appname . '--' . $build . ' up -d';
      $application->runcommand($command, $io);

      // Check for running mySQL container before launching Drupal Installation
      $message = 'Waiting for mySQL service.';

      $command = exec('docker port mysql 3306');
      $port = explode(':', $command);

      $io->warning($message);

      while (!@mysqli_connect('127.0.0.1', 'dev', 'DRUPALPASSENV', 'prod', $port[1])) {
        sleep(1);
        echo '.';
      }
      sleep(2);
      $io->text(' ');
      $message = 'mySQL CONNECTED';
      $io->success($message);

    }

    // Production option specific build.
    if (isset($appreqs) && $appreqs == 'Stage') {

      $appname = $config['appname'];
      $system_appname = strtolower(str_replace(' ', '', $appname));

      $io->section("Docker ::: Build staging environment");

      // Setup data service.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose-data.yml --project-name=' . $system_appname . '_data up -d';
      $application->runcommand($command, $io);

      // RUN APP BUILD.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose.yml --project-name=' . $system_appname . '--' . $build . ' build --no-cache';
      $application->runcommand($command, $io);

      //RUN APP.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose.yml --project-name=' . $system_appname . '--' . $build . ' up -d app';
      $application->runcommand($command, $io);

      //START PROJECT.
      $command = 'docker-compose -f docker_' . $system_appname . '/docker-compose.yml --project-name=' . $system_appname . '--' . $build . ' up -d';
      $application->runcommand($command, $io);

      // Check for running mySQL container before launching Drupal Installation
      $message = 'Waiting for mySQL service.';

      $command = "docker-compose -f docker_" . $system_appname . "/docker-compose-data.yml --project-name=" . $system_appname . "_data port db 3306";
      $port_info = exec($command);
      $port = explode(':', $port_info);

      $io->warning($message);

      while (!@mysqli_connect('127.0.0.1', 'dev', 'DRUPALPASSENV', 'stage', $port[1])) {
        sleep(1);
        echo '.';
      }
      sleep(2);
      $io->text(' ');
      $message = 'mySQL CONNECTED';
      $io->success($message);

    }
  }

}
