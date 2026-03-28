<?php

/**
 * formbatchmanager – BatchManager (business logic)  v1.1.0
 *
 * Suporta:
 *  - Múltiplas questões por operação em lote
 *  - Posição: first (início) ou last (fim) da primeira seção
 *  - Layout: full (largura total), half_left (coluna esquerda), half_right (coluna direita)
 *  - Validação por regex (valid_if MATCH_REGEX)
 *  - Deduplicação por nome dentro da seção
 */

namespace GlpiPlugin\Formbatchmanager;

use Glpi\Form\Form;
use Glpi\Form\Section;
use Glpi\Form\Question;
use Ramsey\Uuid\Uuid;

class BatchManager
{
    // ── Tipos de questão suportados ──────────────────────────────────────
    public const QUESTION_TYPES = [
        'short_text'    => \Glpi\Form\QuestionType\QuestionTypeShortText::class,
        'long_text'     => \Glpi\Form\QuestionType\QuestionTypeLongText::class,
        'number'        => \Glpi\Form\QuestionType\QuestionTypeNumber::class,
        'email'         => \Glpi\Form\QuestionType\QuestionTypeEmail::class,
        'item_dropdown' => \Glpi\Form\QuestionType\QuestionTypeItemDropdown::class,
        'dropdown'      => \Glpi\Form\QuestionType\QuestionTypeDropdown::class,
        'checkbox'      => \Glpi\Form\QuestionType\QuestionTypeCheckbox::class,
        'requester'     => \Glpi\Form\QuestionType\QuestionTypeRequester::class,
        'observer'      => \Glpi\Form\QuestionType\QuestionTypeObserver::class,
        'assignee'      => \Glpi\Form\QuestionType\QuestionTypeAssignee::class,
    ];

    public const QUESTION_TYPE_LABELS = [
        'short_text'    => 'Texto curto',
        'long_text'     => 'Texto longo',
        'number'        => 'Numero',
        'email'         => 'E-mail',
        'item_dropdown' => 'Lista suspensa (objeto GLPI)',
        'dropdown'      => 'Lista suspensa (opcoes customizadas)',
        'checkbox'      => 'Caixa de selecao (multipla escolha)',
        'requester'     => 'Ator — Requerente',
        'observer'      => 'Ator — Observador',
        'assignee'      => 'Ator — Atribuicao',
    ];

    // Tipos com opções editáveis (checkbox / dropdown custom)
    public const SELECTABLE_TYPES = ['dropdown', 'checkbox'];

    // Tipos de ator GLPI
    public const ACTOR_TYPES = ['requester', 'observer', 'assignee'];

    // Tipos que suportam validação por regex
    public const REGEX_SUPPORTED_TYPES = ['short_text', 'long_text', 'email'];

    /**
     * Adiciona múltiplas questões em cada formulário selecionado.
     *
     * @param array  $questionsData  Lista de definições de questão. Cada item:
     *   - name         (string)   Label da questão
     *   - type_key     (string)   Chave em QUESTION_TYPES
     *   - is_mandatory (bool)
     *   - description  (string)   Texto auxiliar
     *   - itemtype     (string)   Classe GLPI p/ item_dropdown (ex: 'Location')
     *   - layout       (string)   'full' | 'half_left' | 'half_right'
     *   - regex        (string)   Expressão regular (opcional)
     *   - regex_op     (string)   'match_regex' | 'not_match_regex'
     * @param int[]  $formIds
     * @param string $position   'first' | 'last' | 'after'
     * @param bool   $force      true = insere mesmo se já existir campo com mesmo nome
     * @param string $afterName  Nome da questão ou seção de referência (quando position='after')
     *
     * @return array{added:int, skipped:int, errors:string[]}
     */
    public function addQuestionToForms(
        array  $questionsData,
        array  $formIds,
        string $position  = 'last',
        bool   $force     = false,
        string $afterName = ''
    ): array {
        $result = ['added' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($formIds as $formId) {
            try {
                $this->processForm((int) $formId, $questionsData, $position, $force, $result, $afterName);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Formulario #%d: %s', $formId, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Retorna todos os formulários ativos e não-rascunho, ordenados por nome.
     *
     * @return array{id:int, name:string}[]
     */
    public function getActiveForms(): array
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['id', 'name', 'forms_categories_id'],
            'FROM'   => Form::getTable(),
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0, 'is_draft' => 0],
            'ORDER'  => 'name ASC',
        ]);

        $forms = [];
        foreach ($rows as $row) {
            $forms[] = [
                'id'                  => (int) $row['id'],
                'name'                => $row['name'],
                'forms_categories_id' => (int) $row['forms_categories_id'],
            ];
        }

        return $forms;
    }

    /**
     * Retorna as categorias distintas utilizadas pelos formulários ativos,
     * ordenadas pelo nome completo (completename).
     *
     * @return array{id:int, completename:string}[]
     */
    public function getFormCategories(): array
    {
        global $DB;

        $formTable     = Form::getTable();
        $categoryTable = 'glpi_forms_categories';

        $res = $DB->doQuery(
            "SELECT DISTINCT c.id, c.completename
             FROM `$categoryTable` c
             JOIN `$formTable` f ON f.forms_categories_id = c.id
             WHERE f.is_active = 1 AND f.is_deleted = 0 AND f.is_draft = 0
             ORDER BY c.completename ASC"
        );

        $categories = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $categories[] = [
                    'id'           => (int) $row['id'],
                    'completename' => $row['completename'],
                ];
            }
        }

        return $categories;
    }

    // ── Processamento por formulário ────────────────────────────────────

    private function processForm(
        int    $formId,
        array  $questionsData,
        string $position,
        bool   $force,
        array  &$result,
        string $afterName = ''
    ): void {
        global $DB;

        $form = new Form();
        if (!$form->getFromDB($formId)) {
            $result['errors'][] = "Formulario #$formId nao encontrado.";
            return;
        }

        // ── Resolver seção alvo e rank de referência ──────────────────────
        $afterRank         = null;
        $effectivePosition = $position;

        if ($position === 'after' && $afterName !== '') {
            [$section, $afterRank] = $this->resolveAfterTarget($form, $afterName);
            if ($section === null) {
                // Referência não encontrada — recai para fim da primeira seção
                $section           = $this->resolveFirstSection($form);
                $afterRank         = null;
                $effectivePosition = 'last';
            }
        } else {
            $section = $this->resolveFirstSection($form);
        }

        if ($section === null) {
            $result['errors'][] = "Formulario #{$formId}: nao foi possivel obter/criar secao.";
            return;
        }

        $sectionId = $section->getID();

        // Verificar duplicatas — quando $force=true, ignora o check e insere mesmo assim
        $toInsert = [];
        foreach ($questionsData as $qd) {
            if (!$force) {
                $dup = $DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => Question::getTable(),
                    'WHERE'  => ['forms_sections_id' => $sectionId, 'name' => $qd['name']],
                    'LIMIT'  => 1,
                ]);
                if (count($dup) > 0) {
                    $result['skipped']++;
                    continue;
                }
            }
            $toInsert[] = $qd;
        }

        if (empty($toInsert)) {
            return;
        }

        // Calcular o bloco de ranks onde as novas questões serão inseridas
        if ($effectivePosition === 'first') {
            // Empurra TODAS as questões existentes para cima (shift +count)
            $shift = count($toInsert);
            $DB->doQueryOrDie(
                "UPDATE `" . Question::getTable() . "`
                    SET `vertical_rank` = `vertical_rank` + $shift
                  WHERE `forms_sections_id` = $sectionId"
            );
            $startRank = 0;
        } elseif ($effectivePosition === 'after' && $afterRank !== null) {
            // Empurra apenas as questões ABAIXO do rank de referência
            $shift = count($toInsert);
            $DB->doQueryOrDie(
                "UPDATE `" . Question::getTable() . "`
                    SET `vertical_rank` = `vertical_rank` + $shift
                  WHERE `forms_sections_id` = $sectionId
                    AND `vertical_rank` > " . (int) $afterRank
            );
            $startRank = (int) $afterRank + 1;
        } else {
            // last: append após a última questão existente
            $rankRow   = $DB->request([
                'SELECT' => ['vertical_rank'],
                'FROM'   => Question::getTable(),
                'WHERE'  => ['forms_sections_id' => $sectionId],
                'ORDER'  => 'vertical_rank DESC',
                'LIMIT'  => 1,
            ]);
            $maxRank   = $rankRow->count() > 0
                ? (int) $rankRow->current()['vertical_rank']
                : -1;
            $startRank = $maxRank + 1;
        }

        // Inserir as questões respeitando layout horizontal
        // Layout: full → nova linha, rank++; half_left → nova linha, rank++;
        //         half_right → MESMA linha do anterior, não avança rank
        $currentRank    = $startRank;
        $lastWasHalf    = false;    // true quando a questão anterior foi half_left

        foreach ($toInsert as $qd) {
            $layout = $qd['layout'] ?? 'full';

            // Determinar vertical_rank e horizontal_rank
            if ($layout === 'half_right' && $lastWasHalf) {
                // Emparelha com a questão anterior (mesma linha)
                $vRank = $currentRank - 1;
                $hRank = 1;
            } elseif ($layout === 'half_left') {
                $vRank = $currentRank++;
                $hRank = 0;
            } else {
                // full ou half_right sem par → trata como full
                $vRank = $currentRank++;
                $hRank = null;
                if ($layout === 'half_right') {
                    // Sem par anterior, coloca como esquerda mesmo
                    $hRank = 0;
                }
            }

            $lastWasHalf = ($layout === 'half_left');

            $newId = $this->insertQuestion($qd, $sectionId, $vRank, $hRank, $result);
            if ($newId) {
                $result['added']++;
            }
        }
    }

    /**
     * Insere uma questão e retorna o ID gerado (ou false em caso de falha).
     *
     * O UUID é pré-gerado aqui para poder referenciá-lo na validation_condition
     * de regex ANTES de a questão existir no banco.
     */
    private function insertQuestion(
        array  $qd,
        int    $sectionId,
        int    $vRank,
        ?int   $hRank,
        array  &$result
    ): int|false {
        $typeClass = self::QUESTION_TYPES[$qd['type_key']];
        $extraData = $this->buildExtraData($qd);

        // Pré-gera o UUID para poder usá-lo em validation_conditions
        $uuid = Uuid::uuid4()->toString();

        // Monta validation_conditions com regex se configurado
        $validationStrategy   = '';
        $validationConditions = json_encode([]);

        $regex = trim($qd['regex'] ?? '');
        if (
            $regex !== ''
            && in_array($qd['type_key'], self::REGEX_SUPPORTED_TYPES, true)
        ) {
            $regexOp = ($qd['regex_op'] ?? 'match_regex') === 'not_match_regex'
                ? 'not_match_regex'
                : 'match_regex';

            $validationStrategy   = 'valid_if';
            $validationConditions = json_encode([
                [
                    // "item" é o campo composto itemtype-uuid usado no editor do GLPI
                    'item'           => 'question-' . $uuid,
                    'item_uuid'      => $uuid,
                    'item_type'      => 'question',
                    'value_operator' => $regexOp,
                    'value'          => $regex,
                    'logic_operator' => null,
                ],
            ]);
        }

        $input = [
            'uuid'                  => $uuid,     // UUID pré-gerado
            'forms_sections_id'     => $sectionId,
            // forms_sections_uuid é auto-resolvido em Question::prepareInput()
            'name'                  => trim($qd['name']),
            'type'                  => $typeClass,
            'is_mandatory'          => (int) ($qd['is_mandatory'] ?? 0),
            'vertical_rank'         => $vRank,
            'horizontal_rank'       => $hRank,    // null → full-width
            'description'           => trim($qd['description'] ?? ''),
            'default_value'         => '',
            // Passa como string JSON '{}' quando vazio — array PHP [] seria
            // serializado como "Array" pelo DB layer quando GLPI pula o json_encode.
            'extra_data'            => !empty($extraData) ? $extraData : '{}',
            'validation_strategy'   => $validationStrategy,
            // Passa como string JSON; prepareInput() só sobrescreve se enviar _validation_conditions
            'validation_conditions' => $validationConditions,
        ];

        $question = new Question();
        $newId    = $question->add($input);

        if (!$newId) {
            $formId = '?';  // não temos o formId aqui, apenas logamos falha
            $result['errors'][] = "Secao #{$sectionId} / campo \"{$qd['name']}\": Question::add() retornou falso.";
        }

        return $newId ?: false;
    }

    // ── Reparação de formulários com extra_data corrompido ───────────────

    /**
     * Retorna formulários com questões corrompidas (extra_data='Array')
     * ou com opções no formato legado {uuid: {uuid, value, checked}}.
     *
     * @return array{id:int, name:string, bad_count:int}[]
     */
    public function findFormsWithCorruptedQuestions(): array
    {
        global $DB;

        $questionTable = Question::getTable();
        $sectionTable  = Section::getTable();
        $formTable     = Form::getTable();

        // Busca todas as questões com extra_data não-vazio para avaliar
        $res = $DB->doQuery(
            "SELECT f.id, f.name, q.id AS qid, q.extra_data
             FROM `$formTable` f
             JOIN `$sectionTable` s ON s.forms_forms_id = f.id
             JOIN `$questionTable` q ON q.forms_sections_id = s.id
             WHERE (q.extra_data = 'Array' OR q.extra_data LIKE '%\"uuid\"%' OR q.extra_data LIKE '%\"checked\"%')
               AND f.is_deleted = 0"
        );

        $counts = [];
        $names  = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $fid = (int) $row['id'];
                if ($this->isCorrupted($row['extra_data'])) {
                    $counts[$fid] = ($counts[$fid] ?? 0) + 1;
                    $names[$fid]  = $row['name'];
                }
            }
        }

        $forms = [];
        foreach ($counts as $fid => $cnt) {
            $forms[] = ['id' => $fid, 'name' => $names[$fid], 'bad_count' => $cnt];
        }
        usort($forms, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $forms;
    }

    /**
     * Verifica se extra_data está corrompido: string 'Array' ou formato legado de opções.
     */
    private function isCorrupted(string $raw): bool
    {
        if ($raw === 'Array') {
            return true;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['options'])) {
            return false;
        }
        // Formato legado: valores são arrays {uuid, value, checked}
        $first = reset($data['options']);
        return is_array($first) && isset($first['value']);
    }

    /**
     * Repara questões corrompidas nos formulários informados.
     * Corrige: extra_data='Array' e formato legado {uuid: {uuid,value,checked}}.
     *
     * @param int[] $formIds  IDs dos formulários a reparar (vazio = todos)
     * @return array{fixed:int, forms:int|string}
     */
    public function repairForms(array $formIds = []): array
    {
        global $DB;

        $questionTable = Question::getTable();
        $sectionTable  = Section::getTable();
        $formTable     = Form::getTable();

        $formFilter = '';
        if (!empty($formIds)) {
            $ids        = implode(',', array_map('intval', $formIds));
            $formFilter = "AND f.id IN ($ids)";
        }

        // Busca questões candidatas
        $res = $DB->doQuery(
            "SELECT q.id, q.extra_data
             FROM `$questionTable` q
             JOIN `$sectionTable` s ON s.id = q.forms_sections_id
             JOIN `$formTable` f    ON f.id = s.forms_forms_id
             WHERE (q.extra_data = 'Array' OR q.extra_data LIKE '%\"uuid\"%' OR q.extra_data LIKE '%\"checked\"%')
               AND f.is_deleted = 0
               $formFilter"
        );

        if (!$res || $res->num_rows === 0) {
            return ['fixed' => 0, 'forms' => count($formIds)];
        }

        $fixed = 0;
        while ($row = $res->fetch_assoc()) {
            $raw = $row['extra_data'];

            if ($raw === 'Array') {
                // Corrompido como string — reseta para vazio
                $DB->doQuery(
                    "UPDATE `$questionTable` SET `extra_data` = '{}' WHERE `id` = " . (int) $row['id']
                );
                $fixed++;
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['options'])) {
                continue;
            }

            $first = reset($data['options']);
            if (!is_array($first) || !isset($first['value'])) {
                continue;
            }

            // Converte formato legado {uuid: {uuid,value,checked}} → {uuid: text}
            $newOptions = [];
            foreach ($data['options'] as $uuid => $obj) {
                $newOptions[$uuid] = (string) ($obj['value'] ?? '');
            }
            $data['options'] = $newOptions;

            $escaped = $DB->escape(json_encode($data));
            $DB->doQuery(
                "UPDATE `$questionTable` SET `extra_data` = '$escaped' WHERE `id` = " . (int) $row['id']
            );
            $fixed++;
        }

        return ['fixed' => $fixed, 'forms' => count($formIds) ?: 'todos'];
    }

    // ── Edição em lote de questões ──────────────────────────────────────

    /**
     * Busca formulários que possuem questões com o nome informado.
     *
     * @param string $name        Nome (ou parte do nome) da questão
     * @param bool   $exactMatch  true = igualdade exata, false = LIKE %name%
     * @return array[]
     */
    public function findFormsWithQuestion(string $name, bool $exactMatch = true): array
    {
        global $DB;

        $questionTable = Question::getTable();
        $sectionTable  = Section::getTable();
        $formTable     = Form::getTable();

        $escaped  = $DB->escape($name);
        $condition = $exactMatch
            ? "q.name = '$escaped'"
            : "q.name LIKE '%$escaped%'";

        $res = $DB->doQuery(
            "SELECT f.id AS form_id, f.name AS form_name,
                    q.id AS question_id, q.name AS question_name,
                    q.type, q.is_mandatory, q.description,
                    q.validation_strategy, q.validation_conditions,
                    q.horizontal_rank
             FROM `$formTable` f
             JOIN `$sectionTable` s ON s.forms_forms_id = f.id
             JOIN `$questionTable` q ON q.forms_sections_id = s.id
             WHERE $condition
               AND f.is_deleted = 0
             ORDER BY f.name ASC"
        );

        $results = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'form_id'               => (int) $row['form_id'],
                    'form_name'             => $row['form_name'],
                    'question_id'           => (int) $row['question_id'],
                    'question_name'         => $row['question_name'],
                    'type'                  => $row['type'],
                    'is_mandatory'          => (int) $row['is_mandatory'],
                    'description'           => $row['description'] ?? '',
                    'validation_strategy'   => $row['validation_strategy'] ?? '',
                    'validation_conditions' => $row['validation_conditions'] ?? '[]',
                    'horizontal_rank'       => $row['horizontal_rank'] === null
                                                ? null
                                                : (int) $row['horizontal_rank'],
                ];
            }
        }

        return $results;
    }

    /**
     * Aplica alterações em lote nas questões encontradas pelo nome.
     *
     * @param string $searchName  Nome original da questão
     * @param int[]  $formIds     Formulários onde aplicar
     * @param array  $changes     Alterações:
     *   - name         (string)  Novo nome (vazio = não altera)
     *   - mandatory    (string)  'keep' | '1' | '0'
     *   - description  (string|null)  null = não altera
     *   - regex_action (string)  'keep' | 'clear' | 'set'
     *   - regex        (string)  Expressão regular (quando regex_action='set')
     *   - regex_op     (string)  'match_regex' | 'not_match_regex'
     *   - layout       (string)  'keep' | 'full' | 'half_left' | 'half_right'
     * @param bool   $exactMatch
     * @return array{updated:int, errors:string[]}
     */
    public function editQuestionsInForms(
        string $searchName,
        array  $formIds,
        array  $changes,
        bool   $exactMatch = true
    ): array {
        global $DB;

        $questionTable = Question::getTable();
        $sectionTable  = Section::getTable();
        $formTable     = Form::getTable();

        $escaped  = $DB->escape($searchName);
        $condition = $exactMatch
            ? "q.name = '$escaped'"
            : "q.name LIKE '%$escaped%'";

        $ids        = implode(',', array_map('intval', $formIds));
        $formFilter = "AND f.id IN ($ids)";

        $res = $DB->doQuery(
            "SELECT q.id, q.uuid
             FROM `$questionTable` q
             JOIN `$sectionTable` s ON s.id = q.forms_sections_id
             JOIN `$formTable` f    ON f.id = s.forms_forms_id
             WHERE $condition
               AND f.is_deleted = 0
               $formFilter"
        );

        if (!$res || $res->num_rows === 0) {
            return ['updated' => 0, 'errors' => []];
        }

        $updated = 0;
        $errors  = [];

        while ($row = $res->fetch_assoc()) {
            $qId  = (int) $row['id'];
            $uuid = $row['uuid'];

            $setClauses = [];

            // Renomear
            $newName = trim($changes['name'] ?? '');
            if ($newName !== '') {
                $setClauses[] = "name = '" . $DB->escape($newName) . "'";
            }

            // Obrigatoriedade
            $mandatory = $changes['mandatory'] ?? 'keep';
            if ($mandatory === '1' || $mandatory === '0') {
                $setClauses[] = "is_mandatory = " . (int) $mandatory;
            }

            // Descrição
            if (array_key_exists('description', $changes) && $changes['description'] !== null) {
                $setClauses[] = "description = '" . $DB->escape($changes['description']) . "'";
            }

            // Layout
            $layout = $changes['layout'] ?? 'keep';
            if ($layout === 'full') {
                $setClauses[] = "horizontal_rank = NULL";
            } elseif ($layout === 'half_left') {
                $setClauses[] = "horizontal_rank = 0";
            } elseif ($layout === 'half_right') {
                $setClauses[] = "horizontal_rank = 1";
            }

            // Regex
            $regexAction = $changes['regex_action'] ?? 'keep';
            if ($regexAction === 'clear') {
                $setClauses[] = "validation_strategy = ''";
                $setClauses[] = "validation_conditions = '[]'";
            } elseif ($regexAction === 'set' && !empty($changes['regex'])) {
                $regexOp = ($changes['regex_op'] ?? 'match_regex') === 'not_match_regex'
                    ? 'not_match_regex'
                    : 'match_regex';
                $conditions = json_encode([[
                    'item'           => 'question-' . $uuid,
                    'item_uuid'      => $uuid,
                    'item_type'      => 'question',
                    'value_operator' => $regexOp,
                    'value'          => $changes['regex'],
                    'logic_operator' => null,
                ]]);
                $setClauses[] = "validation_strategy = 'valid_if'";
                $setClauses[] = "validation_conditions = '" . $DB->escape($conditions) . "'";
            }

            if (empty($setClauses)) {
                continue;
            }

            $setStr = implode(', ', $setClauses);
            $ok     = $DB->doQuery("UPDATE `$questionTable` SET $setStr WHERE id = $qId");
            if ($ok) {
                $updated++;
            } else {
                $errors[] = "Questao #$qId: falha ao atualizar.";
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    // ── Atores nas Destinações de Chamado ────────────────────────────────────

    private const DEST_TABLE           = 'glpi_forms_destinations_formdestinations';
    private const DEST_REQUESTER_KEY   = 'glpi-form-destination-commonitilfield-requesterfield';
    private const DEST_OBSERVER_KEY    = 'glpi-form-destination-commonitilfield-observerfield';
    private const DEST_STRATEGY_ANSWER = 'specific_answer';

    /**
     * Busca o ID de uma questão pelo nome (exato) em qualquer seção do formulário.
     */
    private function findQuestionIdByName(int $formId, string $questionName): ?int
    {
        global $DB;

        $questionTable = Question::getTable();
        $sectionTable  = Section::getTable();
        $escaped       = $DB->escape($questionName);

        $res = $DB->doQuery(
            "SELECT q.id
             FROM `$questionTable` q
             JOIN `$sectionTable` s ON s.id = q.forms_sections_id
             WHERE s.forms_forms_id = $formId
               AND q.name = '$escaped'
             LIMIT 1"
        );

        if ($res && $res->num_rows > 0) {
            return (int) $res->fetch_assoc()['id'];
        }

        return null;
    }

    /**
     * Verifica quais formulários estão prontos para receber a configuração de atores.
     *
     * @param array $config
     *   - requester_question (string) Nome da questão para Requerente (vazio = não configurar)
     *   - observer_question  (string) Nome da questão para Observador (vazio = não configurar)
     * @param int[] $formIds
     * @return array{
     *   ok: list<array{form_id:int,form_name:string}>,
     *   no_destination: list<array{form_id:int,form_name:string}>,
     *   missing_requester: list<array{form_id:int,form_name:string}>,
     *   missing_observer: list<array{form_id:int,form_name:string}>,
     * }
     */
    public function checkActorConfiguration(array $config, array $formIds): array
    {
        global $DB;

        $requesterQ = trim($config['requester_question'] ?? '');
        $observerQ  = trim($config['observer_question'] ?? '');

        $result = [
            'ok'                => [],
            'no_destination'    => [],
            'missing_requester' => [],
            'missing_observer'  => [],
        ];

        foreach ($formIds as $rawId) {
            $formId = (int) $rawId;

            $form = new Form();
            if (!$form->getFromDB($formId)) {
                continue;
            }
            $formName = $form->fields['name'];

            // Verificar se tem destinação de chamado
            $destRes = $DB->doQuery(
                "SELECT id FROM `" . self::DEST_TABLE . "`
                 WHERE forms_forms_id = $formId
                 LIMIT 1"
            );

            if (!$destRes || $destRes->num_rows === 0) {
                $result['no_destination'][] = ['form_id' => $formId, 'form_name' => $formName];
                continue;
            }

            $problems = [];

            if ($requesterQ !== '' && $this->findQuestionIdByName($formId, $requesterQ) === null) {
                $result['missing_requester'][] = ['form_id' => $formId, 'form_name' => $formName];
                $problems[] = 'requester';
            }

            if ($observerQ !== '' && $this->findQuestionIdByName($formId, $observerQ) === null) {
                $result['missing_observer'][] = ['form_id' => $formId, 'form_name' => $formName];
                $problems[] = 'observer';
            }

            if (empty($problems)) {
                $result['ok'][] = ['form_id' => $formId, 'form_name' => $formName];
            }
        }

        return $result;
    }

    /**
     * Define atores (Requerente / Observador) a partir de questões em todas as
     * destinações de chamado dos formulários informados.
     *
     * @param array $config
     *   - requester_question (string)
     *   - observer_question  (string)
     * @param int[] $formIds  IDs já validados (sem problemas de questão/destino)
     * @return array{updated:int, errors:string[]}
     */
    public function setActorsFromQuestions(array $config, array $formIds): array
    {
        global $DB;

        $requesterQ = trim($config['requester_question'] ?? '');
        $observerQ  = trim($config['observer_question'] ?? '');

        $result = ['updated' => 0, 'errors' => []];

        foreach ($formIds as $rawId) {
            $formId = (int) $rawId;

            $destRes = $DB->doQuery(
                "SELECT id, config FROM `" . self::DEST_TABLE . "`
                 WHERE forms_forms_id = $formId"
            );

            if (!$destRes || $destRes->num_rows === 0) {
                $result['errors'][] = "Formulário #$formId: sem destinação de chamado.";
                continue;
            }

            $requesterQId = $requesterQ !== '' ? $this->findQuestionIdByName($formId, $requesterQ) : null;
            $observerQId  = $observerQ  !== '' ? $this->findQuestionIdByName($formId, $observerQ)  : null;

            while ($dest = $destRes->fetch_assoc()) {
                $destId = (int) $dest['id'];
                $cfg    = json_decode($dest['config'] ?? '{}', true) ?: [];

                if ($requesterQId !== null) {
                    $cfg[self::DEST_REQUESTER_KEY] = [
                        'strategies'              => [self::DEST_STRATEGY_ANSWER],
                        'specific_itilactors_ids' => [],
                        'specific_question_ids'   => [$requesterQId],
                    ];
                }

                if ($observerQId !== null) {
                    $cfg[self::DEST_OBSERVER_KEY] = [
                        'strategies'              => [self::DEST_STRATEGY_ANSWER],
                        'specific_itilactors_ids' => [],
                        'specific_question_ids'   => [$observerQId],
                    ];
                }

                $escaped = $DB->escape(json_encode($cfg));
                $ok      = $DB->doQuery(
                    "UPDATE `" . self::DEST_TABLE . "`
                     SET `config` = '$escaped'
                     WHERE `id` = $destId"
                );

                if ($ok) {
                    $result['updated']++;
                } else {
                    $result['errors'][] = "Destino #$destId (formulário #$formId): falha ao atualizar.";
                }
            }
        }

        return $result;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Localiza a seção e o rank de referência para inserção "after".
     *
     * Ordem de busca:
     *  1. Questão com esse nome (exato) em qualquer seção do formulário
     *     → retorna [seção da questão, vertical_rank da questão]
     *  2. Seção com esse nome (exato)
     *     → retorna [seção encontrada, max vertical_rank da seção]
     *  3. Não encontrado → retorna [null, -1]
     *
     * @return array{0: ?Section, 1: int}
     */
    private function resolveAfterTarget(Form $form, string $afterName): array
    {
        global $DB;

        $sectionTable  = Section::getTable();
        $questionTable = Question::getTable();
        $formId        = (int) $form->getID();
        $escaped       = $DB->escape($afterName);

        // 1. Busca questão pelo nome
        $res = $DB->doQuery(
            "SELECT q.forms_sections_id, q.vertical_rank
             FROM `$questionTable` q
             JOIN `$sectionTable` s ON s.id = q.forms_sections_id
             WHERE s.forms_forms_id = $formId
               AND q.name = '$escaped'
             ORDER BY s.rank ASC, q.vertical_rank ASC
             LIMIT 1"
        );

        if ($res && $res->num_rows > 0) {
            $row     = $res->fetch_assoc();
            $section = new Section();
            if ($section->getFromDB((int) $row['forms_sections_id'])) {
                return [$section, (int) $row['vertical_rank']];
            }
        }

        // 2. Busca seção pelo nome — insere ao fim dela
        $res = $DB->doQuery(
            "SELECT id FROM `$sectionTable`
             WHERE forms_forms_id = $formId
               AND name = '$escaped'
             ORDER BY rank ASC
             LIMIT 1"
        );

        if ($res && $res->num_rows > 0) {
            $row     = $res->fetch_assoc();
            $section = new Section();
            if ($section->getFromDB((int) $row['id'])) {
                $rankRow = $DB->request([
                    'SELECT' => ['vertical_rank'],
                    'FROM'   => $questionTable,
                    'WHERE'  => ['forms_sections_id' => (int) $row['id']],
                    'ORDER'  => 'vertical_rank DESC',
                    'LIMIT'  => 1,
                ]);
                $maxRank = $rankRow->count() > 0
                    ? (int) $rankRow->current()['vertical_rank']
                    : -1;
                return [$section, $maxRank];
            }
        }

        // 3. Não encontrado
        return [null, -1];
    }

    private function resolveFirstSection(Form $form): ?Section
    {
        $sections = $form->getSections();
        if (!empty($sections)) {
            return reset($sections);
        }

        $section = new Section();
        $newId   = $section->add([
            'forms_forms_id' => $form->getID(),
            'name'           => 'Secao 1',
            'rank'           => 0,
        ]);

        if (!$newId) {
            return null;
        }

        $section->getFromDB($newId);
        return $section;
    }

    private function buildExtraData(array $qd): array
    {
        if ($qd['type_key'] === 'item_dropdown') {
            return [
                'itemtype'             => $qd['itemtype'] ?? '',
                'root_items_id'        => 0,
                'subtree_depth'        => 0,
                'selectable_tree_root' => false,
                'categories_filter'    => [],
            ];
        }

        if (in_array($qd['type_key'], self::SELECTABLE_TYPES, true)) {
            $options = [];
            foreach ((array) ($qd['options'] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                // GLPI armazena options como {uuid: "texto"} — a estrutura
                // {uuid, value, checked} é montada pelo getValues() na exibição
                $options[Uuid::uuid4()->toString()] = $value;
            }
            // Pelo menos uma opção para passar na validação
            if (empty($options)) {
                $options[Uuid::uuid4()->toString()] = 'Opcao 1';
            }
            return ['options' => $options];
        }

        if (in_array($qd['type_key'], self::ACTOR_TYPES, true)) {
            return ['is_multiple_actors' => (bool) ($qd['is_multiple_actors'] ?? false)];
        }

        return [];
    }
}
