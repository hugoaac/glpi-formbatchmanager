<?php

/**
 * formbatchmanager – ajax/repair.php
 * Detecta e corrige questões com extra_data corrompido ('Array').
 */

ob_start();
include('../../../inc/includes.php');
Session::checkLoginUser();
ob_clean();

header('Content-Type: application/json');

if (!Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../src/BatchManager.php';

use GlpiPlugin\Formbatchmanager\BatchManager;

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido.']);
    exit;
}

$action  = $input['action'] ?? '';
$manager = new BatchManager();

switch ($action) {

    // Lista formulários com problemas
    case 'scan':
        $forms = $manager->findFormsWithCorruptedQuestions();
        echo json_encode([
            'status' => 'ok',
            'forms'  => $forms,
            'total'  => count($forms),
        ]);
        break;

    // Repara formulários selecionados
    case 'repair':
        $formIds = array_filter(
            array_map('intval', (array) ($input['form_ids'] ?? [])),
            fn($id) => $id > 0
        );
        $result = $manager->repairForms($formIds);
        echo json_encode([
            'status'  => 'ok',
            'fixed'   => $result['fixed'],
            'message' => $result['fixed'] > 0
                ? "{$result['fixed']} campo(s) reparado(s) com sucesso."
                : 'Nenhum campo corrompido encontrado nos formulários selecionados.',
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Ação desconhecida: '$action'."]);
}
