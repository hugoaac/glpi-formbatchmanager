<?php

/**
 * formbatchmanager – ajax/check.php
 *
 * Verifica quais formulários já possuem campos com os nomes informados.
 * Usado pelo front-end ANTES de submeter o lote, para alertar o usuário.
 *
 * Método: POST JSON
 * Body:   { "questions": [{"name": "..."}, ...], "form_ids": [1, 2, ...] }
 * Retorno:
 *   {
 *     "has_conflicts": bool,
 *     "conflicts": [{ "form_id": int, "form_name": str, "existing_fields": [str] }],
 *     "clean":     [{ "form_id": int, "form_name": str }]
 *   }
 *
 * CSRF: validado automaticamente pelo CheckCsrfListener do Symfony via
 *       header X-Glpi-Csrf-Token (preserve_token=true — token não é consumido,
 *       podendo ser reutilizado na submissão do formulário em seguida).
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

if (!Session::haveRight('form', READ)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../src/BatchManager.php';

use Glpi\Form\Form;
use Glpi\Form\Question;

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Extrair nomes das questões e IDs dos formulários
$questionNames = [];
foreach ((array) ($input['questions'] ?? []) as $q) {
    $name = trim($q['name'] ?? '');
    if ($name !== '') {
        $questionNames[] = $name;
    }
}

$formIds = array_filter(
    array_map('intval', (array) ($input['form_ids'] ?? [])),
    fn($id) => $id > 0
);

if (empty($questionNames) || empty($formIds)) {
    echo json_encode(['has_conflicts' => false, 'conflicts' => [], 'clean' => []]);
    exit;
}

global $DB;

$conflicts = [];
$clean     = [];

foreach ($formIds as $formId) {
    $form = new Form();
    if (!$form->getFromDB($formId)) {
        continue;
    }

    $sections = $form->getSections();
    if (empty($sections)) {
        // Sem seções = sem questões = sem conflito
        $clean[] = ['form_id' => $formId, 'form_name' => $form->fields['name']];
        continue;
    }

    // Verifica apenas a primeira seção (a mesma que o BatchManager usa)
    $section   = reset($sections);
    $sectionId = $section->getID();

    $existingFields = [];
    foreach ($questionNames as $qName) {
        // Usar SELECT + LIMIT 1 em vez de COUNT para evitar dependência
        // da chave retornada ('cpt' vs 'COUNT(id)' varia com a versão do GLPI)
        $dup = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Question::getTable(),
            'WHERE'  => [
                'forms_sections_id' => $sectionId,
                'name'              => $qName,
            ],
            'LIMIT'  => 1,
        ]);
        if (count($dup) > 0) {
            $existingFields[] = $qName;
        }
    }

    if (!empty($existingFields)) {
        $conflicts[] = [
            'form_id'         => $formId,
            'form_name'       => $form->fields['name'],
            'existing_fields' => $existingFields,
        ];
    } else {
        $clean[] = [
            'form_id'   => $formId,
            'form_name' => $form->fields['name'],
        ];
    }
}

echo json_encode([
    'has_conflicts' => !empty($conflicts),
    'conflicts'     => $conflicts,
    'clean'         => $clean,
]);
