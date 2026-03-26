<?php

/**
 * formbatchmanager – front/edit.php
 * Edição em lote de questões por nome.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}

$selfUrl = Plugin::getWebDir('formbatchmanager') . '/front/edit.php';
$ajaxUrl = Plugin::getWebDir('formbatchmanager') . '/ajax/edit.php';

Html::header('Form Batch Manager — Editar em Lote', $selfUrl, 'admin', 'PluginFormbatchmanagerMenu');
?>

<div class="container-fluid mt-4 mb-5" style="max-width: 1100px;">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="ti ti-edit fs-3 text-primary"></i>
        <div>
            <h4 class="mb-0">Editar Questões em Lote</h4>
            <small class="text-muted">
                Busque um campo pelo nome e aplique alterações em múltiplos formulários de uma vez.
            </small>
        </div>
        <a href="batch.php" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="ti ti-arrow-left me-1"></i>Voltar
        </a>
    </div>

    <!-- Alerta global -->
    <div id="edit-alert" class="alert d-none mb-3"></div>

    <!-- Card de busca -->
    <div class="card mb-4">
        <div class="card-header">
            <strong><i class="ti ti-search me-2"></i>Buscar campo</strong>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" for="field-name">Nome do campo</label>
                    <input type="text" id="field-name" class="form-control"
                           placeholder="Ex: Telefone, CPF, Descrição do problema..."
                           onkeydown="if(event.key==='Enter') runSearch()">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold">Correspondência</label>
                    <div class="d-flex gap-3 pt-1">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="match-type"
                                   id="match-exact" value="exact" checked>
                            <label class="form-check-label" for="match-exact">Exata</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="match-type"
                                   id="match-partial" value="partial">
                            <label class="form-check-label" for="match-partial">Parcial</label>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <button class="btn btn-primary w-100" id="btn-search" onclick="runSearch()">
                        <i class="ti ti-search me-1"></i>Buscar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Resultados + painel de mudanças -->
    <div id="results-section" class="d-none">
        <div class="row g-4">

            <!-- Coluna esquerda: tabela de resultados -->
            <div class="col-12 col-xl-7">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <strong>
                            <i class="ti ti-list me-2"></i>
                            Formulários encontrados
                            (<span id="result-count">0</span>)
                        </strong>
                        <div class="d-flex gap-2">
                            <button class="btn btn-xs btn-outline-secondary" onclick="toggleAll(true)">
                                Todos
                            </button>
                            <button class="btn btn-xs btn-outline-secondary" onclick="toggleAll(false)">
                                Nenhum
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 520px; overflow-y:auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light" style="position:sticky;top:0;z-index:1;">
                                    <tr>
                                        <th style="width:36px">
                                            <input type="checkbox" id="chk-all" class="form-check-input"
                                                   onchange="toggleAll(this.checked)">
                                        </th>
                                        <th>Formulário</th>
                                        <th>Nome atual</th>
                                        <th class="text-center">Obrig.</th>
                                        <th class="text-center">Regex</th>
                                        <th class="text-center">Layout</th>
                                    </tr>
                                </thead>
                                <tbody id="results-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-muted small">
                        <span id="selected-count">0</span> selecionado(s)
                    </div>
                </div>
            </div>

            <!-- Coluna direita: painel de alterações -->
            <div class="col-12 col-xl-5">
                <div class="card h-100">
                    <div class="card-header">
                        <strong><i class="ti ti-adjustments me-2"></i>Alterações a aplicar</strong>
                    </div>
                    <div class="card-body">

                        <!-- Renomear -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Novo nome do campo</label>
                            <input type="text" id="ch-name" class="form-control form-control-sm"
                                   placeholder="Deixe vazio para manter o nome atual">
                        </div>

                        <!-- Obrigatoriedade -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Obrigatoriedade</label>
                            <select id="ch-mandatory" class="form-select form-select-sm">
                                <option value="keep">Manter atual</option>
                                <option value="1">Tornar obrigatório</option>
                                <option value="0">Tornar opcional</option>
                            </select>
                        </div>

                        <!-- Descrição -->
                        <div class="mb-3">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="ch-desc-enable"
                                       onchange="document.getElementById('ch-description').disabled=!this.checked">
                                <label class="form-check-label fw-semibold" for="ch-desc-enable">
                                    Alterar descrição
                                </label>
                            </div>
                            <textarea id="ch-description" class="form-control form-control-sm"
                                      rows="2" disabled
                                      placeholder="Nova descrição (vazio = limpar)"></textarea>
                        </div>

                        <!-- Layout -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Layout</label>
                            <select id="ch-layout" class="form-select form-select-sm">
                                <option value="keep">Manter atual</option>
                                <option value="full">Largura total</option>
                                <option value="half_left">Metade esquerda</option>
                                <option value="half_right">Metade direita</option>
                            </select>
                        </div>

                        <!-- Validação (regex) -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Validação por regex</label>
                            <div class="d-flex flex-column gap-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="regex-action"
                                           id="ra-keep" value="keep" checked
                                           onchange="updateRegexFields()">
                                    <label class="form-check-label" for="ra-keep">Manter atual</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="regex-action"
                                           id="ra-clear" value="clear"
                                           onchange="updateRegexFields()">
                                    <label class="form-check-label" for="ra-clear">Remover validação</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="regex-action"
                                           id="ra-set" value="set"
                                           onchange="updateRegexFields()">
                                    <label class="form-check-label" for="ra-set">Definir nova regex</label>
                                </div>
                            </div>
                            <div id="regex-inputs" class="mt-2 d-none">
                                <select id="ch-regex-op" class="form-select form-select-sm mb-2">
                                    <option value="match_regex">Deve corresponder</option>
                                    <option value="not_match_regex">Não deve corresponder</option>
                                </select>
                                <input type="text" id="ch-regex" class="form-control form-control-sm"
                                       placeholder="Ex: ^\d{10,11}$">
                            </div>
                        </div>

                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary w-100" id="btn-apply"
                                onclick="runApply()" disabled>
                            <i class="ti ti-check me-1"></i>
                            Aplicar em <span id="apply-count">0</span> formulário(s)
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
var AJAX_URL   = <?= json_encode($ajaxUrl) ?>;
var CSRF_TOKEN = (document.querySelector('meta[property="glpi:csrf_token"]') || {})
                     .getAttribute('content') || '';
var _lastSearchName  = '';
var _lastSearchExact = true;

function post(body) {
    return fetch(AJAX_URL, {
        method  : 'POST',
        headers : {
            'Content-Type'     : 'application/json',
            'X-Requested-With' : 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': CSRF_TOKEN,
        },
        body: JSON.stringify(body),
    }).then(r => r.json());
}

// ── Busca ──────────────────────────────────────────────────────────────
function runSearch() {
    var name  = document.getElementById('field-name').value.trim();
    var exact = document.querySelector('input[name="match-type"]:checked').value === 'exact';

    if (!name) {
        showAlert('warning', 'Informe o nome do campo para buscar.');
        return;
    }

    _lastSearchName  = name;
    _lastSearchExact = exact;

    var btn = document.getElementById('btn-search');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Buscando...';

    post({ action: 'search', name: name, exact: exact })
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-search me-1"></i>Buscar';

            if (data.status !== 'ok') {
                showAlert('danger', data.error || 'Erro ao buscar.');
                return;
            }

            if (data.results.length === 0) {
                showAlert('warning', 'Nenhum formulário possui um campo com esse nome.');
                document.getElementById('results-section').classList.add('d-none');
                return;
            }

            hideAlert();
            renderResults(data.results);
            document.getElementById('results-section').classList.remove('d-none');
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-search me-1"></i>Buscar';
            showAlert('danger', 'Erro de comunicação.');
        });
}

function renderResults(results) {
    document.getElementById('result-count').textContent = results.length;

    var tbody = document.getElementById('results-tbody');
    tbody.innerHTML = '';

    results.forEach(function(r) {
        var layout = r.horizontal_rank === null ? 'full'
                   : (r.horizontal_rank === 0 ? 'half_left' : 'half_right');
        var layoutLabel = { full: 'Total', half_left: '◧ Esq.', half_right: '◨ Dir.' }[layout] || '—';
        var layoutClass = layout === 'full' ? 'bg-secondary' : 'bg-info text-dark';

        var regexCell = r.regex
            ? '<span class="badge bg-warning text-dark" title="' + escHtml(r.regex) + '">regex</span>'
            : '<span class="text-muted small">—</span>';

        var mandCell = r.is_mandatory
            ? '<span class="badge bg-danger">Sim</span>'
            : '<span class="badge bg-light text-dark border">Não</span>';

        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="checkbox" class="form-check-input fbm-e-chk"'
            + ' value="' + r.form_id + '" onchange="updateApplyCount()"></td>'
            + '<td class="small">' + escHtml(r.form_name)
            + ' <span class="text-muted">#' + r.form_id + '</span></td>'
            + '<td class="small">' + escHtml(r.question_name) + '</td>'
            + '<td class="text-center">' + mandCell + '</td>'
            + '<td class="text-center">' + regexCell + '</td>'
            + '<td class="text-center"><span class="badge ' + layoutClass + '">' + layoutLabel + '</span></td>';
        tbody.appendChild(tr);
    });

    updateApplyCount();
}

function toggleAll(checked) {
    document.querySelectorAll('.fbm-e-chk').forEach(cb => { cb.checked = checked; });
    document.getElementById('chk-all').checked = checked;
    document.getElementById('chk-all').indeterminate = false;
    updateApplyCount();
}

function updateApplyCount() {
    var count = document.querySelectorAll('.fbm-e-chk:checked').length;
    var total = document.querySelectorAll('.fbm-e-chk').length;
    document.getElementById('selected-count').textContent = count;
    document.getElementById('apply-count').textContent    = count;
    document.getElementById('btn-apply').disabled = (count === 0);
    document.getElementById('chk-all').indeterminate = count > 0 && count < total;
    if (total > 0) document.getElementById('chk-all').checked = count === total;
}

// ── Regex toggle ───────────────────────────────────────────────────────
function updateRegexFields() {
    var action = document.querySelector('input[name="regex-action"]:checked').value;
    document.getElementById('regex-inputs').classList.toggle('d-none', action !== 'set');
}

// ── Apply ──────────────────────────────────────────────────────────────
function runApply() {
    var formIds = Array.from(document.querySelectorAll('.fbm-e-chk:checked'))
                       .map(cb => parseInt(cb.value, 10));
    if (formIds.length === 0) return;

    var changes = {};

    var newName = document.getElementById('ch-name').value.trim();
    if (newName) changes.name = newName;

    changes.mandatory = document.getElementById('ch-mandatory').value;

    if (document.getElementById('ch-desc-enable').checked) {
        changes.description = document.getElementById('ch-description').value.trim();
    }

    changes.layout = document.getElementById('ch-layout').value;

    var regexAction = document.querySelector('input[name="regex-action"]:checked').value;
    changes.regex_action = regexAction;
    if (regexAction === 'set') {
        changes.regex    = document.getElementById('ch-regex').value.trim();
        changes.regex_op = document.getElementById('ch-regex-op').value;
        if (!changes.regex) {
            showAlert('warning', 'Informe a expressão regular ou escolha outra opção.');
            return;
        }
    }

    var hasChange = newName
        || changes.mandatory !== 'keep'
        || changes.description !== undefined
        || changes.layout !== 'keep'
        || regexAction !== 'keep';

    if (!hasChange) {
        showAlert('warning', 'Nenhuma alteração definida. Preencha ao menos um campo de mudança.');
        return;
    }

    var btn = document.getElementById('btn-apply');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aplicando...';

    post({
        action      : 'apply',
        search_name : _lastSearchName,
        exact       : _lastSearchExact,
        form_ids    : formIds,
        changes     : changes,
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Aplicar em '
            + '<span id="apply-count">' + formIds.length + '</span> formulário(s)';

        if (data.status !== 'ok') {
            showAlert('danger', data.error || 'Erro ao aplicar.');
            return;
        }

        var msg = '<i class="ti ti-circle-check me-1"></i>' + data.message;
        if (data.errors && data.errors.length > 0) {
            msg += '<br><small class="text-danger">' + data.errors.map(escHtml).join('<br>') + '</small>';
        }
        showAlert('success', msg);

        if (data.updated > 0) {
            setTimeout(runSearch, 1200);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Aplicar em ' + formIds.length + ' formulário(s)';
        showAlert('danger', 'Erro de comunicação ao aplicar.');
    });
}

// ── Utilidades ────────────────────────────────────────────────────────
function showAlert(type, msg) {
    var el = document.getElementById('edit-alert');
    el.className = 'alert alert-' + type;
    el.innerHTML = msg;
    el.classList.remove('d-none');
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideAlert() {
    document.getElementById('edit-alert').classList.add('d-none');
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<style>
.btn-xs { padding: .15rem .4rem; font-size: .75rem; }
</style>

<?php Html::footer(); ?>
