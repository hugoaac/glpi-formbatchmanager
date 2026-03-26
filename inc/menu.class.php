<?php

/**
 * formbatchmanager – inc/menu.class.php
 */
class PluginFormbatchmanagerMenu extends CommonGLPI
{
    public static $rightname = 'form';

    public static function getTypeName($nb = 0): string
    {
        return 'Form Batch Manager';
    }

    public static function getMenuContent(): array
    {
        $base = Plugin::getWebDir('formbatchmanager');

        return [
            'title'   => 'Form Batch Manager',
            'page'    => $base . '/front/batch.php',
            'icon'    => 'ti ti-copy',
            'options' => [
                'repair' => [
                    'title' => 'Reparar Formularios',
                    'page'  => $base . '/front/repair.php',
                    'icon'  => 'ti ti-tool',
                ],
            ],
        ];
    }

    public static function canView(): bool
    {
        return Session::haveRight('form', READ);
    }
}
