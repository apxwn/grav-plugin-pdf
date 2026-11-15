<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\Pdf\Engine\Gotenberg\Client;

/** Check connectivity and version of the configured Gotenberg service. */
final class GotenbergTestCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('gotenberg:test')
            ->setAliases(['gotenberg-test'])
            ->setDescription('Test the configured Gotenberg connection')
            ->setHelp('Reads the configured Gotenberg URL and credentials, then checks its version and health endpoints.');
    }

    protected function serve(): int
    {
        $this->initializePlugins();
        $grav = Grav::instance();
        $options = (array) $grav['config']->get('plugins.pdf.gotenberg', []);
        $result = Client::fromOptions($options)->probe();

        if ($result['ok']) {
            $this->output->success(sprintf(
                'Gotenberg %s at %s is reachable (%d ms).',
                $result['version'],
                $result['url'],
                $result['ms']
            ));

            if ($result['modules'] !== []) {
                foreach ($result['modules'] as $module => $state) {
                    $this->output->writeln(sprintf('  %s: %s', $module, $state));
                }
            }

            return 0;
        }

        $this->output->error($result['error'] !== '' ? $result['error'] : 'Gotenberg connection failed.');
        if ($result['hint'] !== '') {
            $this->output->writeln($result['hint']);
        }

        return 1;
    }
}
