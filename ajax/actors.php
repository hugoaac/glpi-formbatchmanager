<?php

/**
 * formbatchmanager – ajax/actors.php
 * Verifica e define atores nas destinações de chamado.
 *
 * POST JSON actions:
 *   check  – verifica quais formulários têm as questões e destinações necessárias
 *   apply  – aplica a configuração de atores nos formulários elegíveis
 */

ob_start();
include('../../../inc/includes.php');
Session::checkLoginUser();
ob_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Session::haveRight('form', UPDATE)) {
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

$formIds = array_filter(
    array_map('intval', (array) ($input['form_ids'] ?? [])),
    fn($id) => $id > 0
);

$config = [
    'requester_question' => trim($input['requester_question'] ?? ''),
    'observer_question'  => trim($input['observer_question'] ?? ''),
];

switch ($action) {

    case 'check':
        if (empty($formIds)) {
            echo json_encode(['error' => 'Nenhum formulário selecionado.']);
            exit;
        }
        if ($config['requester_question'] === '' && $config['observer_question'] === '') {
            echo json_encode(['error' => 'Configure ao menos um ator (Requerente ou Observador).']);
            exit;
        }

        $result = $manager->checkActorConfiguration($config, array_values($formIds));
        echo json_encode(['status' => 'ok', 'result' => $result]);
        break;

    case 'apply':
        if (empty($formIds)) {
            echo json_encode(['error' => 'Nenhum formulário selecionado.']);
            exit;
        }
        if ($config['requester_question'] === '' && $config['observer_question'] === '') {
            echo json_encode(['error' => 'Configure ao menos um ator (Requerente ou Observador).']);
            exit;
        }

        $result = $manager->setActorsFromQuestions($config, array_values($formIds));

        $msg = $result['updated'] > 0
            ? "{$result['updated']} destinação(ões) atualizada(s) com sucesso."
            : 'Nenhuma destinação foi alterada.';

        echo json_encode([
            'status'  => 'ok',
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
            'message' => $msg,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Ação desconhecida: '$action'."]);
}
