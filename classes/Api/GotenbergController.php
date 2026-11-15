<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Api;

use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Pdf\Engine\Gotenberg\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Admin-only connection test for the configured Gotenberg service. */
final class GotenbergController extends AbstractApiController
{
    public function __construct(Grav $grav, Config $config)
    {
        parent::__construct($grav, $config);
    }

    public function test(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        $probe = Client::fromOptions((array) $this->config->get('plugins.pdf.gotenberg', []))->probe();

        return ApiResponse::create($probe);
    }
}
