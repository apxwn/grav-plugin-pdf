<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Plugin\Pdf\Config;
use Grav\Plugin\Pdf\Generator;
use Grav\Plugin\Pdf\Items;
use Grav\Plugin\Pdf\State;
use RocketTheme\Toolbox\Event\Event;
use Twig\TwigFunction;

/**
 * PDF plugin.
 *
 * Two shapes of document, decided by the page the plugin is invoked on:
 *
 *  - a page that owns a collection (a listing) produces a whole book — cover,
 *    table of contents, and one article per item;
 *  - any other page produces a PDF of that single article.
 *
 * Requests are served from `<page-url>.pdf`. Nothing is emitted unless the page
 * (or one of its ancestors, when `inherit` is on) opted in, so the plugin can be
 * shipped enabled without changing a single existing URL.
 */
class PdfPlugin extends Plugin
{
    public const VERSION = '1.2.0';

    /** Plugin slug; also the frontmatter key and the translation namespace. */
    public const SLUG = 'pdf';

    /**
     * @return array<string, mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                // Load our own vendor/ before anything else gets a chance to.
                ['autoload', 100000],
                ['onPluginsInitialized', 0],
            ],
            // Page blueprint extension for the Admin editor.
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            // The Twig namespace must be registered however the request ends up
            // being handled — including a .pdf request, which renders our own
            // print templates through the same Twig environment.
            'onTwigLoader' => ['onTwigLoader', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
        ];
    }

    /**
     * Register the bundled mPDF autoloader.
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        // `<anything>.pdf` is ours. `.pdf` is not a Grav page type, so the route
        // we get still carries the extension and is stripped in onPagesInitialized().
        if ($uri->extension() === 'pdf') {
            $this->enable([
                'onPagesInitialized' => ['onPagesInitialized', 0],
            ]);

            return;
        }

        // Normal HTML request: expose the `pdf` Twig global so a theme can show
        // a link. Deliberately the only thing we add to a page render.
        $this->enable([
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    /**
     * Make the print templates available as `@pdf/...`.
     *
     * A Twig namespace keeps theme and plugin templates apart, so this works the
     * same whether or not the active theme ships anything.
     */
    public function onTwigLoader(): void
    {
        $this->grav['twig']->addPath(__DIR__ . '/templates/pdf', 'pdf');
    }

    /**
     * Add `pdf_url(page)` so a theme can build a link for any page.
     */
    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFunction(new TwigFunction(
            'pdf_url',
            fn ($page = null) => $this->context()->urlFor($page)
        ));
    }

    /**
     * Expose the `pdf` global for the page being rendered.
     */
    public function onTwigSiteVariables(): void
    {
        $page = $this->grav['page'] ?? null;

        $this->grav['twig']->twig_vars['pdf'] = State::forPage(
            $this->grav,
            $page instanceof PageInterface ? $page : null
        );
    }

    /**
     * Serve `<page-url>.pdf`.
     *
     * Runs before Grav decides the request is a 404, and streams the document
     * itself, so no page or template of the site is involved.
     */
    public function onPagesInitialized(): void
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        if ($uri->extension() !== 'pdf') {
            return;
        }

        // Drop the extension Grav kept, because `.pdf` is not a known page type.
        $route = '/' . ltrim((string) preg_replace('#\.pdf$#i', '', $uri->route()), '/');

        $pages = $this->grav['pages'];
        $page = $pages->find($route);

        if (!$page instanceof PageInterface || !$page->exists()) {
            return; // let Grav 404 normally
        }

        $generator = new Generator($this->grav, new Config($this->grav));

        if (!$generator->isEnabled($page)) {
            return; // opt-in only: nothing to see here
        }

        $generator->respond($page);
    }

    /**
     * Add the `pdf:` section to every page blueprint so it can be edited in
     * Admin. Fields are `toggleable`, which is what lets a page leave a value
     * unset and inherit the global one.
     */
    public function onBlueprintCreated(Event $event): void
    {
        static $inEvent = false;

        $blueprint = $event['blueprint'];

        if ($inEvent || !$blueprint->get('form/fields/tabs', null, '/')) {
            return;
        }

        $inEvent = true;

        $blueprints = new \Grav\Common\Data\Blueprints(__DIR__ . '/blueprints/');

        if ($extends = $blueprints->get('pdf')) {
            $blueprint->extend($extends, true);
        }

        $inEvent = false;
    }

    /**
     * Shared context so the Twig function and the event handler agree on how the
     * plugin is configured.
     */
    private function context(): Generator
    {
        static $generator;

        if (!$generator instanceof Generator) {
            $generator = new Generator($this->grav, new Config($this->grav));
        }

        return $generator;
    }
}
