<?php

/**
 * formbatchmanager – Main interface  v1.1.0
 *
 * Novas funcionalidades:
 *  - Múltiplos campos por operação
 *  - Posição: Início ou Fim da primeira seção
 *  - Layout: Largura total | Metade esquerda | Metade direita
 *  - Validação por expressão regular (por campo)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}

use GlpiPlugin\Formbatchmanager\BatchManager;

require_once __DIR__ . '/../src/BatchManager.php';

// URL canônica — PHP_SELF retorna '/' no GLPI 11 (Symfony front controller)
$selfUrl = Plugin::getWebDir('formbatchmanager') . '/front/batch.php';

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fbm_submit'])) {

    // O CheckCsrfListener do Symfony já validou e consumiu o token antes deste
    // arquivo carregar. NÃO chamar Session::checkCSRF() manualmente.
    $redirectTo = $selfUrl;
    $manager    = new BatchManager();

    // ── Coletar e validar questões ────────────────────────────────────────
    $rawQuestions = $_POST['questions'] ?? [];
    $questionsData = [];

    foreach ((array) $rawQuestions as $idx => $q) {
        $name    = trim(strip_tags($q['name'] ?? ''));
        $typeKey = $q['type'] ?? 'short_text';

        if ($name === '') {
            continue; // ignora linhas sem nome
        }
        if (!array_key_exists($typeKey, BatchManager::QUESTION_TYPES)) {
            continue;
        }

        // Opções para tipos selecionáveis (checkbox / dropdown)
        $rawOptions = array_filter(
            array_map('trim', (array) ($q['options'] ?? [])),
            fn($v) => $v !== ''
        );

        $questionsData[] = [
            'name'               => $name,
            'type_key'           => $typeKey,
            'is_mandatory'       => isset($q['mandatory']) ? 1 : 0,
            'description'        => trim(strip_tags($q['description'] ?? '')),
            'itemtype'           => trim($q['itemtype'] ?? ''),
            'layout'             => in_array($q['layout'] ?? '', ['full','half_left','half_right'])
                                        ? $q['layout']
                                        : 'full',
            'regex'              => trim($q['regex'] ?? ''),
            'regex_op'           => ($q['regex_op'] ?? '') === 'not_match_regex'
                                        ? 'not_match_regex'
                                        : 'match_regex',
            'options'            => array_values($rawOptions),
            'is_multiple_actors' => isset($q['is_multiple_actors']) ? true : false,
        ];
    }

    if (empty($questionsData)) {
        Session::addMessageAfterRedirect(
            'Defina pelo menos um campo com nome preenchido.',
            false, ERROR
        );
        Html::redirect($redirectTo);
        exit;
    }

    // ── Coletar formulários selecionados ──────────────────────────────────
    $formIds = array_filter(
        array_map('intval', (array) ($_POST['form_ids'] ?? [])),
        fn($id) => $id > 0
    );

    if (empty($formIds)) {
        Session::addMessageAfterRedirect(
            'Selecione pelo menos um formulario.',
            false, ERROR
        );
        Html::redirect($redirectTo);
        exit;
    }

    $position = $_POST['position'] ?? 'last';
    if (!in_array($position, ['first', 'last', 'after'], true)) {
        $position = 'last';
    }
    $afterName = trim(strip_tags($_POST['after_name'] ?? ''));
    $force     = isset($_POST['force']) && $_POST['force'] === '1';

    // ── Executar o lote ───────────────────────────────────────────────────
    $result = $manager->addQuestionToForms($questionsData, array_values($formIds), $position, $force, $afterName);

    if ($result['added'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf(
                '%d campo(s) adicionado(s) em %d formulario(s).',
                count($questionsData),
                (int) ($result['added'] / count($questionsData))
            ),
            false, INFO
        );
    }
    if ($result['skipped'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf('%d campo(s) ignorado(s) por ja existirem.', $result['skipped']),
            false, WARNING
        );
    }
    foreach ($result['errors'] as $err) {
        Session::addMessageAfterRedirect(htmlspecialchars($err), false, ERROR);
    }

    Html::redirect($redirectTo);
    exit;
}

// ── Saída HTML ────────────────────────────────────────────────────────────────

Html::header('Form Batch Manager', $selfUrl, 'admin', 'PluginFormbatchmanagerMenu');

$manager        = new BatchManager();
$allForms       = $manager->getActiveForms();
$allCategories  = $manager->getFormCategories();
$checkUrl       = Plugin::getWebDir('formbatchmanager') . '/ajax/check.php';

// Dropdown itemtypes para lista suspensa
$dropdownItemtypes = \Dropdown::getStandardDropdownItemTypes(check_rights: false);

// Gera HTML de <option> para o selector de tipo (reutilizado no template JS)
$typeOptionsHtml = '';
foreach (BatchManager::QUESTION_TYPE_LABELS as $key => $label) {
    $typeOptionsHtml .= '<option value="' . $key . '">' . htmlspecialchars($label) . '</option>';
}

// Gera HTML de <option> para o selector de itemtype
$itemtypeOptionsHtml = '<option value="">-- Selecione --</option>';
foreach ($dropdownItemtypes as $groupLabel => $items) {
    $itemtypeOptionsHtml .= '<optgroup label="' . htmlspecialchars($groupLabel) . '">';
    foreach ($items as $className => $displayName) {
        $itemtypeOptionsHtml .= '<option value="' . htmlspecialchars($className) . '">'
            . htmlspecialchars($displayName) . '</option>';
    }
    $itemtypeOptionsHtml .= '</optgroup>';
}

$regexSupportedTypes = json_encode(BatchManager::REGEX_SUPPORTED_TYPES);

?>

<div class="container-fluid mt-4 mb-5" id="fbm-main">

    <!-- ── Cabeçalho ─────────────────────────────────────────────────── -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="ti ti-copy fs-3 text-primary"></i>
        <div>
            <h4 class="mb-0">Form Batch Manager</h4>
            <small class="text-muted">
                <?= count($allForms) ?> formulario(s) ativo(s) disponivel(is)
            </small>
        </div>
        <div class="d-flex gap-2 ms-auto">
            <a href="edit.php" class="btn btn-sm btn-outline-primary">
                <i class="ti ti-edit me-1"></i>Editar em lote
            </a>
            <a href="actors.php" class="btn btn-sm btn-outline-success">
                <i class="ti ti-users me-1"></i>Definir atores
            </a>
            <a href="repair.php" class="btn btn-sm btn-outline-warning">
                <i class="ti ti-tool me-1"></i>Reparar corrompidos
            </a>
        </div>
    </div>

    <?php if (empty($allForms)): ?>
    <div class="alert alert-warning">
        <i class="ti ti-alert-triangle me-2"></i>
        Nenhum formulario ativo encontrado. Ative pelo menos um formulario nativo do GLPI.
    </div>
    <?php else: ?>

    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>" id="fbm-form">
        <!-- Token preenchido pelo JS com o valor do meta tag glpi:csrf_token antes de submeter -->
        <input type="hidden" name="_glpi_csrf_token" id="fbm-csrf-input" value="">
        <!-- form.submit() programático não envia o valor do submit button; este hidden garante o campo -->
        <input type="hidden" name="fbm_submit" value="1">
        <!-- Preenchido pelo JS antes de submeter: 0=pular duplicatas, 1=forçar inclusão -->
        <input type="hidden" name="force" id="fbm-force-input" value="0">

        <div class="row g-4">

            <!-- ══════════════════════════════════════════════════════════
                 PAINEL ESQUERDO – Definição dos campos + opções globais
            ═══════════════════════════════════════════════════════════ -->
            <div class="col-12 col-xl-6">

                <!-- Opções globais -->
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="badge bg-primary rounded-pill">1</span>
                        <strong>Opcoes globais</strong>
                    </div>
                    <div class="card-body">
                        <label class="form-label fw-semibold">
                            Posicao de insercao na secao
                        </label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="position" id="pos_last" value="last" checked
                                       onchange="fbmOnPositionChange()">
                                <label class="form-check-label" for="pos_last">
                                    <i class="ti ti-arrow-bar-down me-1 text-muted"></i>
                                    Fim da secao
                                    <small class="text-muted d-block">Adiciona apos a ultima questao existente</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="position" id="pos_first" value="first"
                                       onchange="fbmOnPositionChange()">
                                <label class="form-check-label" for="pos_first">
                                    <i class="ti ti-arrow-bar-up me-1 text-muted"></i>
                                    Inicio da secao
                                    <small class="text-muted d-block">Empurra questoes existentes para baixo</small>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       name="position" id="pos_after" value="after"
                                       onchange="fbmOnPositionChange()">
                                <label class="form-check-label" for="pos_after">
                                    <i class="ti ti-arrow-down-circle me-1 text-muted"></i>
                                    Apos pergunta ou secao especifica
                                    <small class="text-muted d-block">Insere imediatamente abaixo do item indicado</small>
                                </label>
                            </div>
                        </div>
                        <div id="fbm-after-name-wrapper" class="mt-2" style="display:none">
                            <input type="text" name="after_name" id="fbm-after-name"
                                   class="form-control form-control-sm"
                                   placeholder="Nome exato da pergunta ou secao de referencia"
                                   maxlength="255">
                            <div class="form-text">
                                <i class="ti ti-info-circle me-1"></i>
                                Se nao encontrado no formulario, insere ao fim da secao.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Lista de campos a adicionar -->
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary rounded-pill">2</span>
                            <strong>Campos a adicionar</strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                id="fbm-add-field">
                            <i class="ti ti-plus me-1"></i>Adicionar campo
                        </button>
                    </div>
                    <div class="card-body p-2" id="fbm-questions-container">
                        <!-- Primeira linha (gerada via JS clone do template) -->
                    </div>
                    <div class="card-footer">
                        <div class="text-muted small">
                            <i class="ti ti-info-circle me-1"></i>
                            Layout horizontal: campos "Metade esq." + "Metade dir." consecutivos
                            sao exibidos lado a lado no formulario.
                        </div>
                    </div>
                </div>

            </div>

            <!-- ══════════════════════════════════════════════════════════
                 PAINEL DIREITO – Seleção de formulários
            ═══════════════════════════════════════════════════════════ -->
            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary rounded-pill">3</span>
                            <strong>Selecionar formularios</strong>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="fbmSelectAll(true)">Todos</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="fbmSelectAll(false)">Nenhum</button>
                        </div>
                    </div>
                    <div class="card-body pb-0">
                        <?php if (!empty($allCategories)): ?>
                        <select id="fbm-category" class="form-select form-select-sm mb-2"
                                onchange="fbmApplyFilters()">
                            <option value="">Todas as categorias</option>
                            <?php foreach ($allCategories as $cat): ?>
                            <option value="<?= $cat['id'] ?>">
                                <?= htmlspecialchars($cat['completename']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <input type="text" id="fbm-search" class="form-control form-control-sm mb-2"
                               placeholder="Filtrar formularios..."
                               oninput="fbmApplyFilters()">
                        <div class="text-muted small mb-2">
                            <span id="fbm-count-selected">0</span> de
                            <span id="fbm-count-visible"><?= count($allForms) ?></span>
                            exibido(s) &middot; <?= count($allForms) ?> total
                        </div>
                    </div>
                    <div class="card-body pt-0" style="max-height:500px;overflow-y:auto">
                        <div id="fbm-form-list">
                        <?php foreach ($allForms as $form): ?>
                            <div class="fbm-form-row form-check py-1 border-bottom"
                                 data-name="<?= strtolower(htmlspecialchars($form['name'])) ?>"
                                 data-category="<?= $form['forms_categories_id'] ?>">
                                <input class="form-check-input fbm-checkbox"
                                       type="checkbox"
                                       name="form_ids[]"
                                       value="<?= $form['id'] ?>"
                                       id="form_<?= $form['id'] ?>"
                                       onchange="fbmUpdateSubmit()">
                                <label class="form-check-label" for="form_<?= $form['id'] ?>">
                                    <?= htmlspecialchars($form['name']) ?>
                                    <span class="text-muted small ms-1">#<?= $form['id'] ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-end gap-2">
                        <a href="<?= htmlspecialchars($selfUrl) ?>"
                           class="btn btn-outline-secondary">Limpar</a>
                        <button type="submit" name="fbm_submit" value="1"
                                class="btn btn-primary" id="fbm-submit-btn" disabled>
                            <i class="ti ti-plus me-1"></i>
                            Aplicar nos formularios selecionados
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /row -->
    </form>

    <!-- ══════════════════════════════════════════════════════════════════
         Template oculto de uma linha de campo (clonado pelo JS)
    ═══════════════════════════════════════════════════════════════════ -->
    <template id="fbm-question-template">
        <div class="fbm-q-row border rounded p-3 mb-2 bg-white position-relative">

            <!-- Botão remover (oculto na primeira linha) -->
            <button type="button"
                    class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 fbm-remove-btn"
                    onclick="fbmRemoveRow(this)" title="Remover este campo">
                <i class="ti ti-x"></i>
            </button>

            <div class="row g-2">

                <!-- Nome -->
                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        Nome <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="questions[__IDX__][name]"
                           class="form-control form-control-sm fbm-q-name"
                           placeholder="Ex: Telefone de contato"
                           maxlength="255" required
                           oninput="fbmUpdateSubmit()">
                </div>

                <!-- Tipo -->
                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm fw-semibold mb-1">Tipo</label>
                    <select name="questions[__IDX__][type]"
                            class="form-select form-select-sm fbm-q-type"
                            onchange="fbmOnTypeChange(this)">
                        <?= $typeOptionsHtml ?>
                    </select>
                </div>

                <!-- Itemtype (visível só para item_dropdown) -->
                <div class="col-12 fbm-itemtype-wrapper" style="display:none">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        Tipo de lista suspensa <span class="text-danger">*</span>
                    </label>
                    <select name="questions[__IDX__][itemtype]"
                            class="form-select form-select-sm fbm-q-itemtype">
                        <?= $itemtypeOptionsHtml ?>
                    </select>
                </div>

                <!-- Opções customizadas (visível para dropdown/checkbox) -->
                <div class="col-12 fbm-options-wrapper" style="display:none">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        Opcoes <span class="text-danger">*</span>
                    </label>
                    <div class="fbm-options-list d-flex flex-column gap-1 mb-1">
                        <!-- linhas geradas pelo JS -->
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary fbm-add-option">
                            <i class="ti ti-plus me-1"></i>Adicionar opcao
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary fbm-toggle-paste">
                            <i class="ti ti-clipboard-text me-1"></i>Colar do Excel
                        </button>
                    </div>
                    <div class="fbm-paste-area" style="display:none">
                        <textarea class="form-control form-control-sm fbm-paste-input" rows="5"
                            placeholder="Cole aqui as opcoes do Excel (uma por linha)&#10;Exemplo:&#10;Opcao A&#10;Opcao B&#10;Opcao C"></textarea>
                        <div class="d-flex gap-2 mt-1">
                            <button type="button" class="btn btn-sm btn-primary fbm-import-paste">
                                <i class="ti ti-check me-1"></i>Importar
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary fbm-cancel-paste">
                                Cancelar
                            </button>
                        </div>
                        <div class="form-text">Cole uma coluna do Excel — cada linha vira uma opcao.</div>
                    </div>
                </div>

                <!-- Múltiplos atores (visível para requester/observer/assignee) -->
                <div class="col-12 fbm-actors-wrapper" style="display:none">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox"
                               name="questions[__IDX__][is_multiple_actors]" value="1"
                               id="mult_IDX__">
                        <label class="form-check-label" for="mult_IDX__">
                            Permitir multiplos atores
                        </label>
                    </div>
                </div>

                <!-- Layout -->
                <div class="col-12 col-md-6">
                    <label class="form-label form-label-sm fw-semibold mb-1">Layout</label>
                    <select name="questions[__IDX__][layout]"
                            class="form-select form-select-sm">
                        <option value="full">&#9646; Largura total</option>
                        <option value="half_left">&#9647; Metade esquerda</option>
                        <option value="half_right">&#9647; Metade direita</option>
                    </select>
                </div>

                <!-- Obrigatório -->
                <div class="col-12 col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox"
                               name="questions[__IDX__][mandatory]" value="1"
                               id="mand_IDX__">
                        <label class="form-check-label" for="mand_IDX__">
                            Campo obrigatorio
                        </label>
                    </div>
                </div>

                <!-- Regex (visível só para tipos compatíveis) -->
                <div class="col-12 fbm-regex-wrapper" style="display:none">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        Validacao por expressao regular
                    </label>
                    <div class="input-group input-group-sm">
                        <select name="questions[__IDX__][regex_op]"
                                class="form-select" style="max-width:200px">
                            <option value="match_regex">Deve corresponder a</option>
                            <option value="not_match_regex">Nao deve corresponder a</option>
                        </select>
                        <input type="text" name="questions[__IDX__][regex]"
                               class="form-control fbm-q-regex"
                               placeholder="Ex: ^[0-9]{10,11}$ ou ^[\w.+-]+@[\w-]+\.[a-z]{2,}$">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="fbmTestRegex(this)" title="Testar regex">
                            <i class="ti ti-test-pipe"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        Deixe em branco para nao adicionar validacao.
                        Teste o padrao antes de aplicar.
                    </div>
                    <!-- Área de teste -->
                    <div class="fbm-regex-test mt-2" style="display:none">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">Valor de teste</span>
                            <input type="text" class="form-control fbm-regex-test-input"
                                   placeholder="Digite um valor para testar...">
                            <span class="fbm-regex-test-result input-group-text"></span>
                        </div>
                    </div>
                </div>

                <!-- Descrição -->
                <div class="col-12">
                    <label class="form-label form-label-sm fw-semibold mb-1">
                        Descricao / dica
                    </label>
                    <input type="text" name="questions[__IDX__][description]"
                           class="form-control form-control-sm"
                           placeholder="Texto auxiliar exibido abaixo do campo (opcional)">
                </div>

            </div><!-- /row -->
        </div><!-- /fbm-q-row -->
    </template>

    <?php endif; ?>

</div><!-- /container -->

<!-- ── Modal de confirmação de conflitos ────────────────────────────────── -->
<div class="modal fade" id="fbm-conflict-modal" tabindex="-1"
     aria-labelledby="fbm-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="fbm-modal-title">
                    <i class="ti ti-alert-triangle text-warning me-2"></i>
                    Campos ja existentes detectados
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p class="mb-2">
                    <strong id="fbm-conflict-count">0</strong> formulario(s) ja possuem
                    um ou mais dos campos solicitados:
                </p>
                <div id="fbm-conflict-list"
                     class="border rounded p-3 bg-light mb-3"
                     style="max-height:260px;overflow-y:auto"></div>

                <div id="fbm-clean-info" class="text-muted small"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">
                    <i class="ti ti-x me-1"></i>Cancelar
                </button>
                <!-- Pular: submete com force=0 → BatchManager ignora duplicatas -->
                <button type="button" class="btn btn-outline-warning" id="fbm-btn-skip">
                    <i class="ti ti-skip-forward me-1"></i>
                    Pular os formularios com duplicatas
                </button>
                <!-- Forcar: submete com force=1 → BatchManager insere mesmo assim -->
                <button type="button" class="btn btn-primary" id="fbm-btn-force">
                    <i class="ti ti-copy-plus me-1"></i>
                    Incluir em todos mesmo assim
                </button>
            </div>

        </div>
    </div>
</div>

<script>
/* ── Form Batch Manager – UI  v1.1 ─────────────────────────────────── */

var FBM_REGEX_TYPES      = <?= $regexSupportedTypes ?>;
var FBM_SELECTABLE_TYPES = <?= json_encode(BatchManager::SELECTABLE_TYPES) ?>;
var FBM_ACTOR_TYPES      = <?= json_encode(BatchManager::ACTOR_TYPES) ?>;
var FBM_CHECK_URL        = <?= json_encode($checkUrl) ?>;

var fbmRowIndex = 0;

// ── Posição de inserção ───────────────────────────────────────────────

function fbmOnPositionChange() {
    var isAfter = document.getElementById('pos_after').checked;
    var wrapper = document.getElementById('fbm-after-name-wrapper');
    wrapper.style.display = isAfter ? '' : 'none';
    if (isAfter) {
        document.getElementById('fbm-after-name').focus();
    }
}

// ── Adicionar/remover linhas ─────────────────────────────────────────

function fbmAddRow() {
    var tmpl    = document.getElementById('fbm-question-template');
    var clone   = tmpl.content.cloneNode(true);
    var idx     = fbmRowIndex++;
    var wrapper = clone.querySelector('.fbm-q-row');

    // Substituir __IDX__ em todos os atributos name/id/for
    wrapper.innerHTML = wrapper.innerHTML
        .replace(/questions\[__IDX__\]/g, 'questions[' + idx + ']')
        .replace(/mand_IDX__/g,           'mand_' + idx)
        .replace(/for="mand_IDX__"/g,     'for="mand_' + idx + '"')
        .replace(/mult_IDX__/g,           'mult_' + idx)
        .replace(/for="mult_IDX__"/g,     'for="mult_' + idx + '"');

    document.getElementById('fbm-questions-container').appendChild(wrapper);

    // Ocultar "remover" na primeira linha
    var rows = document.querySelectorAll('.fbm-q-row');
    rows.forEach(function(r, i) {
        r.querySelector('.fbm-remove-btn').style.display = (rows.length === 1) ? 'none' : '';
    });

    // Inicializar visibilidade dos wrappers conforme o tipo padrão da nova linha
    var newRow = document.querySelector('.fbm-q-row:last-child');
    fbmOnTypeChange(newRow.querySelector('.fbm-q-type'));

    // Listener do botão "Adicionar opção"
    newRow.querySelector('.fbm-add-option').addEventListener('click', function() {
        fbmAddOptionRow(newRow);
    });

    // Listeners da área de colagem do Excel
    newRow.querySelector('.fbm-toggle-paste').addEventListener('click', function() {
        var area = newRow.querySelector('.fbm-paste-area');
        area.style.display = area.style.display === 'none' ? '' : 'none';
        if (area.style.display !== 'none') {
            newRow.querySelector('.fbm-paste-input').focus();
        }
    });
    newRow.querySelector('.fbm-import-paste').addEventListener('click', function() {
        fbmImportPastedOptions(newRow);
    });
    newRow.querySelector('.fbm-cancel-paste').addEventListener('click', function() {
        newRow.querySelector('.fbm-paste-area').style.display = 'none';
        newRow.querySelector('.fbm-paste-input').value = '';
    });

    // Adiciona 2 opções iniciais quando a linha é criada
    // (serão exibidas só quando o tipo for selecionável)
    fbmAddOptionRow(newRow);
    fbmAddOptionRow(newRow);

    // Registrar listener de teste de regex ao vivo
    var regexInput = newRow.querySelector('.fbm-q-regex');
    if (regexInput) {
        regexInput.addEventListener('input', function() {
            fbmRunLiveRegexTest(newRow);
        });
        newRow.querySelector('.fbm-regex-test-input').addEventListener('input', function() {
            fbmRunLiveRegexTest(newRow);
        });
    }
}

function fbmRemoveRow(btn) {
    var row = btn.closest('.fbm-q-row');
    row.remove();
    // Reexibir botão remover se só sobrou 1
    var rows = document.querySelectorAll('.fbm-q-row');
    if (rows.length === 1) {
        rows[0].querySelector('.fbm-remove-btn').style.display = 'none';
    }
    fbmUpdateSubmit();
}

// ── Reações a mudança de tipo ────────────────────────────────────────

function fbmOnTypeChange(select) {
    var row        = select.closest('.fbm-q-row');
    var typeKey    = select.value;
    var itWrapper  = row.querySelector('.fbm-itemtype-wrapper');
    var rgWrapper  = row.querySelector('.fbm-regex-wrapper');
    var optWrapper = row.querySelector('.fbm-options-wrapper');
    var actWrapper = row.querySelector('.fbm-actors-wrapper');
    var itSelect   = row.querySelector('.fbm-q-itemtype');

    // Itemtype só para item_dropdown
    var isItemDropdown = (typeKey === 'item_dropdown');
    itWrapper.style.display = isItemDropdown ? '' : 'none';
    itSelect.required = isItemDropdown;

    // Opções customizadas para dropdown/checkbox
    var isSelectable = FBM_SELECTABLE_TYPES.indexOf(typeKey) !== -1;
    optWrapper.style.display = isSelectable ? '' : 'none';

    // Toggle múltiplos atores
    var isActor = FBM_ACTOR_TYPES.indexOf(typeKey) !== -1;
    actWrapper.style.display = isActor ? '' : 'none';

    // Regex só para tipos compatíveis
    var supportsRegex = FBM_REGEX_TYPES.indexOf(typeKey) !== -1;
    rgWrapper.style.display = supportsRegex ? '' : 'none';
}

// ── Opções customizadas (checkbox / dropdown) ─────────────────────────

function fbmImportPastedOptions(row) {
    var textarea = row.querySelector('.fbm-paste-input');
    var lines    = textarea.value.split(/\r?\n/);
    var added    = 0;
    lines.forEach(function(line) {
        var val = line.trim();
        if (!val) return;
        var optRow = fbmAddOptionRow(row);
        optRow.querySelector('input[type="text"]').value = val;
        added++;
    });
    textarea.value = '';
    row.querySelector('.fbm-paste-area').style.display = 'none';
    if (added > 0) {
        // Remove as 2 linhas vazias iniciais se ainda estiverem em branco
        row.querySelectorAll('.fbm-option-row input[type="text"]').forEach(function(inp) {
            if (!inp.value.trim()) {
                inp.closest('.fbm-option-row').remove();
            }
        });
    }
}

function fbmAddOptionRow(row) {
    var list = row.querySelector('.fbm-options-list');
    // Descobrir o índice da questão pelo name do input de nome
    var nameInput = row.querySelector('.fbm-q-name');
    var match = nameInput ? nameInput.name.match(/questions\[(\d+)\]/) : null;
    var qIdx  = match ? match[1] : '0';

    var div = document.createElement('div');
    div.className = 'input-group input-group-sm fbm-option-row';
    div.innerHTML =
        '<input type="text" name="questions[' + qIdx + '][options][]" '
        + 'class="form-control" placeholder="Texto da opcao">'
        + '<button type="button" class="btn btn-outline-danger" '
        + 'onclick="this.closest(\'.fbm-option-row\').remove()" title="Remover">'
        + '<i class="ti ti-x"></i></button>';
    list.appendChild(div);
    return div;
}

// ── Teste de regex ───────────────────────────────────────────────────

function fbmTestRegex(btn) {
    var row       = btn.closest('.fbm-q-row');
    var testArea  = row.querySelector('.fbm-regex-test');
    testArea.style.display = testArea.style.display === 'none' ? '' : 'none';
}

function fbmRunLiveRegexTest(row) {
    var regexVal  = row.querySelector('.fbm-q-regex').value.trim();
    var testInput = row.querySelector('.fbm-regex-test-input');
    var result    = row.querySelector('.fbm-regex-test-result');

    if (!regexVal || !testInput || !testInput.value) {
        if (result) result.textContent = '';
        return;
    }

    try {
        var re      = new RegExp(regexVal);
        var matches = re.test(testInput.value);
        result.textContent = matches ? '✓ Corresponde' : '✗ Nao corresponde';
        result.className   = 'fbm-regex-test-result input-group-text '
                           + (matches ? 'text-success' : 'text-danger');
    } catch (e) {
        result.textContent = 'Regex invalida';
        result.className   = 'fbm-regex-test-result input-group-text text-warning';
    }
}

// ── Seleção de formulários ────────────────────────────────────────────

function fbmSelectAll(checked) {
    document.querySelectorAll('.fbm-checkbox').forEach(function(cb) {
        if (cb.closest('.fbm-form-row').style.display !== 'none') {
            cb.checked = checked;
        }
    });
    fbmUpdateSubmit();
}

function fbmApplyFilters() {
    var q    = (document.getElementById('fbm-search').value || '').toLowerCase().trim();
    var catEl = document.getElementById('fbm-category');
    var cat  = catEl ? catEl.value : '';
    var visible = 0;

    document.querySelectorAll('.fbm-form-row').forEach(function(row) {
        var matchName = !q || row.dataset.name.includes(q);
        var matchCat  = !cat || row.dataset.category === cat;
        var show      = matchName && matchCat;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    var countVisEl = document.getElementById('fbm-count-visible');
    if (countVisEl) countVisEl.textContent = visible;

    fbmUpdateSubmit();
}

// ── Controle do botão submit ──────────────────────────────────────────

function fbmUpdateSubmit() {
    var hasName  = Array.from(document.querySelectorAll('.fbm-q-name'))
                        .some(function(i) { return i.value.trim() !== ''; });
    var hasForm  = document.querySelectorAll('.fbm-checkbox:checked').length > 0;
    var count    = document.querySelectorAll('.fbm-checkbox:checked').length;

    document.getElementById('fbm-count-selected').textContent = count;
    document.getElementById('fbm-submit-btn').disabled = !(hasName && hasForm);
}

// ── Verificação prévia de conflitos antes de submeter ────────────────

document.getElementById('fbm-submit-btn').addEventListener('click', function (e) {
    e.preventDefault();

    // Coletar nomes de questões definidas
    var questionNames = Array.from(document.querySelectorAll('.fbm-q-name'))
        .map(function (i) { return { name: i.value.trim() }; })
        .filter(function (q) { return q.name !== ''; });

    // Coletar IDs de formulários selecionados
    var formIds = Array.from(document.querySelectorAll('.fbm-checkbox:checked'))
        .map(function (cb) { return parseInt(cb.value, 10); });

    if (questionNames.length === 0 || formIds.length === 0) return;

    // Indicador de loading no botão
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando...';

    // Ler CSRF token do meta tag (preserve_token=true no CheckCsrfListener:
    // o token NÃO é consumido e permanece válido para a submissão do form em seguida)
    var csrfToken = (document.querySelector('meta[property="glpi:csrf_token"]') || {})
                        .getAttribute('content') || '';

    fetch(FBM_CHECK_URL, {
        method      : 'POST',
        credentials : 'same-origin',
        headers     : {
            'Content-Type'      : 'application/json',
            'X-Requested-With'  : 'XMLHttpRequest',
            'X-Glpi-Csrf-Token' : csrfToken
        },
        body: JSON.stringify({ questions: questionNames, form_ids: formIds })
    })
    .then(function (resp) { return resp.json(); })
    .then(function (data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-plus me-1"></i>Aplicar nos formularios selecionados';

        if (!data.has_conflicts) {
            // Sem conflitos: submete direto
            document.getElementById('fbm-force-input').value = '0';
            fbmDoSubmit();
            return;
        }

        // Exibir modal com detalhes dos conflitos
        fbmShowConflictModal(data);
    })
    .catch(function () {
        // Em caso de falha na verificação, submete com comportamento padrão (skip)
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-plus me-1"></i>Aplicar nos formularios selecionados';
        document.getElementById('fbm-force-input').value = '0';
        fbmDoSubmit();
    });
});

function fbmShowConflictModal(data) {
    // Montar lista de conflitos
    var html = '<ul class="list-unstyled mb-0">';
    data.conflicts.forEach(function (c) {
        html += '<li class="d-flex align-items-start gap-2 py-1 border-bottom">'
              + '<i class="ti ti-alert-circle text-warning mt-1 flex-shrink-0"></i>'
              + '<div>'
              + '<strong>' + fbmEscape(c.form_name) + '</strong> <span class="text-muted small">#' + c.form_id + '</span>'
              + '<br><small class="text-muted">Campo(s) existente(s): '
              + c.existing_fields.map(fbmEscape).join(', ')
              + '</small></div></li>';
    });
    html += '</ul>';
    document.getElementById('fbm-conflict-list').innerHTML = html;

    // Contadores
    document.getElementById('fbm-conflict-count').textContent = data.conflicts.length;

    var cleanInfo = document.getElementById('fbm-clean-info');
    if (data.clean.length > 0) {
        cleanInfo.innerHTML = '<i class="ti ti-circle-check text-success me-1"></i>'
            + data.clean.length + ' formulario(s) nao possuem os campos e serao alterados normalmente.';
    } else {
        cleanInfo.innerHTML = '<i class="ti ti-info-circle me-1"></i>'
            + 'Todos os formularios selecionados ja possuem os campos solicitados.';
    }

    var modal = new bootstrap.Modal(document.getElementById('fbm-conflict-modal'));
    modal.show();
}

// Botão "Pular duplicatas" → force=0 (BatchManager ignora os que já existem)
document.getElementById('fbm-btn-skip').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('fbm-conflict-modal')).hide();
    document.getElementById('fbm-force-input').value = '0';
    fbmDoSubmit();
});

// Botão "Incluir em todos mesmo assim" → force=1 (BatchManager insere mesmo com duplicata)
document.getElementById('fbm-btn-force').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('fbm-conflict-modal')).hide();
    document.getElementById('fbm-force-input').value = '1';
    fbmDoSubmit();
});

/**
 * Injeta o CSRF token do meta tag no hidden field e então submete o form.
 * Necessário porque form.submit() programático não inclui o botão submit,
 * e porque o token do meta tag (preserve_token=true) é o token que sobrevive
 * à chamada AJAX de pré-verificação — ao contrário de um token separado gerado
 * pelo PHP (Session::getNewCSRFToken) que pode ser outro objeto na sessão.
 */
function fbmDoSubmit() {
    var meta = document.querySelector('meta[property="glpi:csrf_token"]');
    document.getElementById('fbm-csrf-input').value = meta ? meta.getAttribute('content') : '';
    document.getElementById('fbm-form').submit();
}

function fbmEscape(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── Init ──────────────────────────────────────────────────────────────

document.getElementById('fbm-add-field').addEventListener('click', fbmAddRow);

// Adiciona a primeira linha ao carregar
fbmAddRow();
</script>

<?php Html::footer(); ?>
