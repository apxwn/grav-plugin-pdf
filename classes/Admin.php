<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Grav;

/**
 * Values the Admin screens need that cannot be written into a blueprint.
 *
 * A blueprint is static YAML, but the fonts a site can choose are not: they
 * depend on the `font_dir` and `fonts` settings, which the administrator edits
 * in the same form. `data-options@` lets the field ask for them at the moment
 * the form is built, so the dropdown lists the built-in faces plus whatever the
 * site has added.
 */
final class Admin
{
    /**
     * The `Body font` and `Heading font` dropdowns: family => label.
     *
     * Called from `blueprints.yaml` through `data-options@`, so it has to be
     * static and take no arguments.
     *
     * @return array<string, string>
     */
    public static function fontOptions(): array
    {
        $grav = Grav::instance();

        return (new Generator($grav, new Config($grav)))->fontChoices();
    }
}
