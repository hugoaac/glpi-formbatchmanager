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
     * @param string $position  'first' | 'last'
     *
     * @return array{added:int, skipped:int, errors:string[]}
     */
    public function addQuestionToForms(
        array  $questionsData,
        array  $formIds,
        string $position = 'last',
        bool   $force    = false   // true = insere mesmo se já existir campo com mesmo nome
    ): array {
        $result = ['added' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($formIds as $formId) {
            try {
                $this->processForm((int) $formId, $questionsData, $position, $force, $result);
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
        array  &$result
    ): void {
        global $DB;

        $form = new Form();
        if (!$form->getFromDB($formId)) {
            $result['errors'][] = "Formulario #$formId nao encontrado.";
            return;
        }

        $section = $this->resolveFirstSection($form);
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
        if ($position === 'first') {
            // Empurra TODAS as questões existentes para cima (shift +count)
            $shift = count($toInsert);
            $DB->doQueryOrDie(
                "UPDATE `" . Question::getTable() . "`
                    SET `vertical_rank` = `vertical_rank` + $shift
                  WHERE `forms_sections_id` = $sectionId"
            );
            $startRank = 0;
        } else {
            // last: append após a última questão existente
            // ORDER BY + LIMIT 1 DESC é mais portável que MAX() com chave incerta
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
     * Retorna formulários que possuem questões com extra_data corrompido ('Array').
     *
     * @return array{id:int, name:string, bad_count:int}[]
     */
    public function findFormsWithCorruptedQuestions(): array
    {
        global $DB;

        $questionTable = Question::getTable();
        $sectionTable  = Section::getTable();
        $formTable     = Form::getTable();

        $res = $DB->doQuery(
            "SELECT f.id, f.name, COUNT(q.id) AS bad_count
             FROM `$formTable` f
             JOIN `$sectionTable` s ON s.forms_forms_id = f.id
             JOIN `$questionTable` q ON q.forms_sections_id = s.id
             WHERE q.extra_data = 'Array'
               AND f.is_deleted = 0
             GROUP BY f.id, f.name
             ORDER BY f.name ASC"
        );

        $forms = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $forms[] = [
                    'id'        => (int) $row['id'],
                    'name'      => $row['name'],
                    'bad_count' => (int) $row['bad_count'],
                ];
            }
        }

        return $forms;
    }

    /**
     * Repara questões com extra_data = 'Array' nos formulários informados.
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

        // Coleta IDs das questões corrompidas
        $res = $DB->doQuery(
            "SELECT q.id
             FROM `$questionTable` q
             JOIN `$sectionTable` s ON s.id = q.forms_sections_id
             JOIN `$formTable` f    ON f.id = s.forms_forms_id
             WHERE q.extra_data = 'Array'
               AND f.is_deleted = 0
               $formFilter"
        );

        if (!$res || $res->num_rows === 0) {
            return ['fixed' => 0, 'forms' => count($formIds)];
        }

        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }

        // Corrige diretamente — contorna prepareInputForUpdate que poderia re-serializar
        $placeholders = implode(',', $ids);
        $DB->doQuery(
            "UPDATE `$questionTable`
             SET `extra_data` = '{}'
             WHERE `id` IN ($placeholders)"
        );

        return ['fixed' => count($ids), 'forms' => count($formIds) ?: 'todos'];
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

    // ── Helpers ─────────────────────────────────────────────────────────

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
