<?php

/**
 * formbatchmanager – setup.php  v1.1.0
 * Adiciona campos em lote a múltiplos formulários nativos do GLPI 11.
 */

define('PLUGIN_FORMBATCHMANAGER_VERSION', '1.1.0');
define('PLUGIN_FORMBATCHMANAGER_MIN_GLPI', '11.0.0');

function plugin_version_formbatchmanager(): array
{
    return [
        'name'         => 'Form Batch Manager',
        'version'      => PLUGIN_FORMBATCHMANAGER_VERSION,
        'author'       => 'Hugo',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_FORMBATCHMANAGER_MIN_GLPI],
        ],
    ];
}

function plugin_formbatchmanager_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_FORMBATCHMANAGER_MIN_GLPI, '>=');
}

function plugin_formbatchmanager_check_config(): bool
{
    return true;
}

function plugin_init_formbatchmanager(): void
{
    global $PLUGIN_HOOKS;

    // CheckCsrfListener valida automaticamente — não chamar Session::checkCSRF() manualmente
    $PLUGIN_HOOKS['csrf_compliant']['formbatchmanager'] = true;

    if (!Session::getLoginUserID()) {
        return;
    }

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['menu_toadd']['formbatchmanager'] = [
            'admin' => 'PluginFormbatchmanagerMenu',
        ];
    }
}
