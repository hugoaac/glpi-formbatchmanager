<?php

/**
 * formbatchmanager – front/repair.php
 * Detecta e repara formulários com extra_data corrompido.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('form', UPDATE)) {
    Html::displayRightError();
    exit;
}

$selfUrl   = Plugin::getWebDir('formbatchmanager') . '/front/repair.php';
$ajaxUrl   = Plugin::getWebDir('formbatchmanager') . '/ajax/repair.php';

Html::header('Form Batch Manager — Reparar', $selfUrl, 'admin', 'PluginFormbatchmanagerMenu');
?>

<div class="container-fluid mt-4 mb-5" style="max-width: 860px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="ti ti-tool fs-3 text-warning"></i>
        <div>
            <h4 class="mb-0">Reparar Formulários</h4>
            <small class="text-muted">
                Detecta e corrige campos com <code>extra_data</code> corrompido
                (causado por versões anteriores do Form Batch Manager).
            </small>
        </div>
        <a href="batch.php" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="ti ti-arrow-left me-1"></i>Voltar
        </a>
    </div>

    <!-- Alerta global -->
    <div id="repair-alert" class="alert d-none mb-3"></div>

    <!-- Card de scan -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <strong><i class="ti ti-search me-2"></i>Diagnóstico</strong>
            <button class="btn btn-sm btn-outline-primary" id="btn-scan" onclick="runScan()">
                <i class="ti ti-refresh me-1"></i>Verificar formulários
            </button>
        </div>
        <div class="card-body">
            <div id="scan-placeholder" class="text-muted text-center py-3">
                <i class="ti ti-player-play fs-3 d-block mb-2 opacity-50"></i>
                Clique em <strong>Verificar formulários</strong> para detectar problemas.
            </div>

            <div id="scan-results" class="d-none">
                <div id="scan-ok" class="alert alert-success d-none">
                    <i class="ti ti-circle-check me-2"></i>
                    Nenhum formulário com campo corrompido encontrado. Tudo certo!
                </div>

                <div id="scan-list" class="d-none">
                    <p class="mb-2">
                        <span id="scan-count" class="fw-bold text-danger">0</span>
                        formulário(s) com campos corrompidos:
                    </p>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px">
                                        <input type="checkbox" id="chk-all"
                                               class="form-check-input"
                                               onchange="toggleAll(this.checked)">
                                    </th>
                                    <th>Formulário</th>
                                    <th class="text-center">Campos com problema</th>
                                    <th class="text-center">Editor</th>
                                </tr>
                            </thead>
                            <tbody id="forms-tbody"></tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-muted small">
                            <span id="selected-count">0</span> selecionado(s)
                        </span>
                        <button class="btn btn-warning" id="btn-repair"
                                onclick="runRepair()" disabled>
                            <i class="ti ti-tool me-1"></i>Reparar selecionados
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Informações técnicas -->
    <div class="card border-0 bg-light">
        <div class="card-body small text-muted">
            <strong>O que esta ferramenta faz:</strong>
            Localiza questões onde o campo <code>extra_data</code> foi armazenado como a string
            <code>"Array"</code> (em vez de JSON <code>{}</code>) e corrige o valor para
            <code>{}</code> diretamente no banco. O GLPI passa a conseguir renderizar o editor
            do formulário normalmente.
            <br><br>
            <strong>Causa:</strong> Versões anteriores do Form Batch Manager passavam um array PHP
            vazio para campos não-dropdown, que era serializado incorretamente pelo DB layer do GLPI.
        </div>
    </div>

</div>

<script>
var AJAX_URL   = <?= json_encode($ajaxUrl) ?>;
var FORMS_URL  = <?= json_encode(rtrim(Plugin::getWebDir('formbatchmanager'), '/')) ?>;
var CSRF_TOKEN = (document.querySelector('meta[property="glpi:csrf_token"]') || {})
                     .getAttribute('content') || '';

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

// ── Scan ──────────────────────────────────────────────────────────────
function runScan() {
    var btn = document.getElementById('btn-scan');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando...';

    document.getElementById('scan-placeholder').classList.add('d-none');
    document.getElementById('scan-results').classList.remove('d-none');
    document.getElementById('scan-ok').classList.add('d-none');
    document.getElementById('scan-list').classList.add('d-none');

    post({ action: 'scan' })
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-refresh me-1"></i>Verificar formulários';

            if (data.status !== 'ok') {
                showAlert('danger', data.error || 'Erro ao verificar.');
                return;
            }

            if (data.forms.length === 0) {
                document.getElementById('scan-ok').classList.remove('d-none');
                return;
            }

            renderForms(data.forms);
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-refresh me-1"></i>Verificar formulários';
            showAlert('danger', 'Erro de comunicação ao verificar.');
        });
}

function renderForms(forms) {
    document.getElementById('scan-count').textContent = forms.length;
    document.getElementById('scan-list').classList.remove('d-none');

    var tbody = document.getElementById('forms-tbody');
    tbody.innerHTML = '';

    forms.forEach(function(f) {
        var tr = document.createElement('tr');
        tr.id  = 'row-' + f.id;
        tr.innerHTML =
            '<td><input type="checkbox" class="form-check-input fbm-r-chk" value="' + f.id + '"'
            + ' onchange="updateSelectedCount()"></td>'
            + '<td>' + escHtml(f.name) + ' <span class="text-muted small">#' + f.id + '</span></td>'
            + '<td class="text-center"><span class="badge bg-danger">' + f.bad_count + '</span></td>'
            + '<td class="text-center">'
            + '<a href="/glpi/Form/' + f.id + '" target="_blank" class="btn btn-xs btn-outline-secondary"'
            + ' title="Abrir editor">'
            + '<i class="ti ti-external-link"></i></a></td>';
        tbody.appendChild(tr);
    });

    updateSelectedCount();
}

function toggleAll(checked) {
    document.querySelectorAll('.fbm-r-chk').forEach(cb => { cb.checked = checked; });
    updateSelectedCount();
}

function updateSelectedCount() {
    var count = document.querySelectorAll('.fbm-r-chk:checked').length;
    document.getElementById('selected-count').textContent = count;
    document.getElementById('btn-repair').disabled = (count === 0);
    document.getElementById('chk-all').indeterminate =
        count > 0 && count < document.querySelectorAll('.fbm-r-chk').length;
}

// ── Repair ────────────────────────────────────────────────────────────
function runRepair() {
    var formIds = Array.from(document.querySelectorAll('.fbm-r-chk:checked'))
                       .map(cb => parseInt(cb.value, 10));
    if (formIds.length === 0) return;

    var btn = document.getElementById('btn-repair');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Reparando...';

    post({ action: 'repair', form_ids: formIds })
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-tool me-1"></i>Reparar selecionados';

            if (data.status !== 'ok') {
                showAlert('danger', data.error || 'Erro ao reparar.');
                return;
            }

            showAlert('success', '<i class="ti ti-check me-1"></i>' + data.message
                + ' Recarregando diagnóstico...');

            // Remove linhas reparadas e rescan após 1.5s
            setTimeout(runScan, 1500);
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-tool me-1"></i>Reparar selecionados';
            showAlert('danger', 'Erro de comunicação ao reparar.');
        });
}

// ── Utilidades ────────────────────────────────────────────────────────
function showAlert(type, msg) {
    var el = document.getElementById('repair-alert');
    el.className = 'alert alert-' + type;
    el.innerHTML = msg;
    el.classList.remove('d-none');
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
