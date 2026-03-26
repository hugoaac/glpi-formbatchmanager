<?php

/**
 * formbatchmanager – ajax/edit.php
 * Busca e edita questões em lote por nome.
 */

ob_start();
include('../../../inc/includes.php');
Session::checkLoginUser();
ob_clean();

header('Content-Type: application/json');

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

switch ($action) {

    // Busca formulários que possuem o campo
    case 'search':
        $name       = trim($input['name'] ?? '');
        $exactMatch = (bool) ($input['exact'] ?? true);

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Nome não informado.']);
            exit;
        }

        $results = $manager->findFormsWithQuestion($name, $exactMatch);

        // Mapa de tipos legíveis
        $typeMap = array_flip(BatchManager::QUESTION_TYPES);
        $labels  = BatchManager::QUESTION_TYPE_LABELS;

        foreach ($results as &$r) {
            $typeKey         = $typeMap[$r['type']] ?? $r['type'];
            $r['type_key']   = $typeKey;
            $r['type_label'] = $labels[$typeKey] ?? $typeKey;

            // Extrai regex do JSON de conditions
            $conds      = json_decode($r['validation_conditions'] ?? '[]', true);
            $r['regex'] = '';
            $r['regex_op'] = 'match_regex';
            if (is_array($conds) && !empty($conds)) {
                $r['regex']    = $conds[0]['value'] ?? '';
                $r['regex_op'] = $conds[0]['value_operator'] ?? 'match_regex';
            }
        }
        unset($r);

        echo json_encode([
            'status'  => 'ok',
            'results' => $results,
            'total'   => count($results),
        ]);
        break;

    // Aplica alterações nas questões selecionadas
    case 'apply':
        $searchName = trim($input['search_name'] ?? '');
        $exactMatch = (bool) ($input['exact'] ?? true);
        $formIds    = array_filter(
            array_map('intval', (array) ($input['form_ids'] ?? [])),
            fn($id) => $id > 0
        );
        $changes = $input['changes'] ?? [];

        if ($searchName === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Nome da questão não informado.']);
            exit;
        }

        if (empty($formIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nenhum formulário selecionado.']);
            exit;
        }

        // Sanitiza changes
        $safeChanges = [];
        if (isset($changes['name'])) {
            $safeChanges['name'] = trim($changes['name']);
        }
        if (isset($changes['mandatory']) && in_array($changes['mandatory'], ['keep', '0', '1'], true)) {
            $safeChanges['mandatory'] = $changes['mandatory'];
        }
        if (array_key_exists('description', $changes)) {
            $safeChanges['description'] = trim($changes['description']);
        }
        if (isset($changes['layout']) && in_array($changes['layout'], ['keep','full','half_left','half_right'], true)) {
            $safeChanges['layout'] = $changes['layout'];
        }
        if (isset($changes['regex_action']) && in_array($changes['regex_action'], ['keep','clear','set'], true)) {
            $safeChanges['regex_action'] = $changes['regex_action'];
            if ($safeChanges['regex_action'] === 'set') {
                $safeChanges['regex']    = $changes['regex'] ?? '';
                $safeChanges['regex_op'] = ($changes['regex_op'] ?? '') === 'not_match_regex'
                    ? 'not_match_regex'
                    : 'match_regex';
            }
        }

        $result = $manager->editQuestionsInForms(
            $searchName,
            array_values($formIds),
            $safeChanges,
            $exactMatch
        );

        $msg = $result['updated'] > 0
            ? "{$result['updated']} questão(ões) atualizada(s) com sucesso."
            : 'Nenhuma questão foi alterada.';

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
