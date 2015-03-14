<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Activity;
use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRestoreCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:restore')
            ->setDescription('Restore an environment backup')
            ->addArgument('backup', InputArgument::OPTIONAL, 'The name of the backup to restore. Defaults to the most recent one')
            ->addOption(
              'no-wait',
              null,
              InputOption::VALUE_NONE,
              "Do not wait for the operation to complete"
            );
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);

        $backupName = $input->getArgument('backup');
        if (!empty($backupName)) {
            // Find the specified backup.
            $backupActivities = $environment->getActivities(0, 'environment.backup');
            foreach ($backupActivities as $activity) {
                $payload = $activity->getProperty('payload');
                if ($payload['backup_name'] == $backupName) {
                    $selectedActivity = $activity;
                    break;
                }
            }
            if (empty($selectedActivity)) {
                $output->writeln("Backup not found: <error>$backupName</error>");
                return 1;
            }
        }
        else {
            // Find the most recent backup.
            $environmentId = $this->environment['id'];
            $output->writeln("Finding the most recent backup for the environment <info>$environmentId</info>");
            $backupActivities = $environment->getActivities(1, 'environment.backup');
            if (!$backupActivities) {
                $output->writeln("No backups found");
                return 1;
            }
            /** @var \CommerceGuys\Platform\Cli\Model\Activity $selectedActivity */
            $selectedActivity = reset($backupActivities);
        }

        if (!$selectedActivity->operationAvailable('restore')) {
            if (!$selectedActivity->isComplete()) {
                $output->writeln("The backup is not complete, so it cannot be restored");
            }
            else {
                $output->writeln("The backup cannot be restored");
            }
            return 1;
        }

        /** @var \CommerceGuys\Platform\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $payload = $selectedActivity->getProperty('payload');
        $name = $payload['backup_name'];
        $environmentId = $this->environment['id'];
        $date = $selectedActivity->getPropertyFormatted('created_at');
        if (!$questionHelper->confirm("Are you sure you want to restore the backup <comment>$name</comment> from <comment>$date</comment>?", $input, $output)) {
            return 1;
        }

        $output->writeln("Restoring backup <info>$name</info>");
        $response = $selectedActivity->restore();
        if (!$input->getOption('no-wait')) {
            $success = Activity::waitAndLog(
              $response,
              $client,
              $output,
              "The backup was successfully restored",
              "Restoring failed"
            );
            if ($success === false) {
                return 1;
            }
        }

        return 0;
    }
}
