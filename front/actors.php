<?php

/**
 * formbatchmanager – front/actors.php
 * Definir atores (Requerente / Observador) nas destinações de chamado de múltiplos formulários.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    Html::displayRightError();
    exit;
}

use GlpiPlugin\Formbatchmanager\BatchManager;

require_once __DIR__ . '/../src/BatchManager.php';

$selfUrl = Plugin::getWebDir('formbatchmanager') . '/front/actors.php';
$ajaxUrl = Plugin::getWebDir('formbatchmanager') . '/ajax/actors.php';

Html::header('Form Batch Manager — Definir Atores', $selfUrl, 'admin', 'PluginFormbatchmanagerMenu');

$manager       = new BatchManager();
$allForms      = $manager->getActiveForms();
$allCategories = $manager->getFormCategories();
?>

<div class="container-fluid mt-4 mb-5" style="max-width: 1200px;">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="ti ti-users fs-3 text-primary"></i>
        <div>
            <h4 class="mb-0">Definir Atores nas Destinações</h4>
            <small class="text-muted">
                Configure Requerente e Observador a partir de respostas de questões
                em múltiplos formulários de uma vez.
            </small>
        </div>
        <a href="batch.php" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="ti ti-arrow-left me-1"></i>Voltar
        </a>
    </div>

    <!-- Alerta global -->
    <div id="act-alert" class="alert d-none mb-3"></div>

    <?php if (empty($allForms)): ?>
    <div class="alert alert-warning">
        <i class="ti ti-alert-triangle me-2"></i>
        Nenhum formulário ativo encontrado.
    </div>
    <?php else: ?>

    <div class="row g-4">

        <!-- ── Coluna esquerda: seleção de formulários ── -->
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary rounded-pill">1</span>
                        <strong>Selecionar formulários</strong>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-xs btn-outline-secondary"
                                onclick="actSelectAll(true)">Todos</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary"
                                onclick="actSelectAll(false)">Nenhum</button>
                    </div>
                </div>
                <div class="card-body pb-0">
                    <?php if (!empty($allCategories)): ?>
                    <select id="act-category" class="form-select form-select-sm mb-2"
                            onchange="actApplyFilters()">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>">
                            <?= htmlspecialchars($cat['completename']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input type="text" id="act-search" class="form-control form-control-sm mb-2"
                           placeholder="Filtrar formulários..."
                           oninput="actApplyFilters()">
                    <div class="text-muted small mb-2">
                        <span id="act-count-selected">0</span> selecionado(s)
                        &middot; <span id="act-count-visible"><?= count($allForms) ?></span> exibido(s)
                        &middot; <?= count($allForms) ?> total
                    </div>
                </div>
                <div class="card-body pt-0" style="max-height: 480px; overflow-y: auto;">
                    <div id="act-form-list">
                    <?php foreach ($allForms as $form): ?>
                        <div class="act-form-row form-check py-1 border-bottom"
                             data-name="<?= strtolower(htmlspecialchars($form['name'])) ?>"
                             data-category="<?= $form['forms_categories_id'] ?>">
                            <input class="form-check-input act-checkbox"
                                   type="checkbox"
                                   value="<?= $form['id'] ?>"
                                   id="actf_<?= $form['id'] ?>"
                                   onchange="actUpdateCount()">
                            <label class="form-check-label small" for="actf_<?= $form['id'] ?>">
                                <?= htmlspecialchars($form['name']) ?>
                                <span class="text-muted">#<?= $form['id'] ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer text-muted small">
                    <span id="act-footer-count">0</span> formulário(s) selecionado(s)
                </div>
            </div>
        </div>

        <!-- ── Coluna direita: configuração de atores ── -->
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="badge bg-primary rounded-pill">2</span>
                    <strong>Configuração de Atores</strong>
                </div>
                <div class="card-body">

                    <!-- Requerente -->
                    <div class="mb-4">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-danger">Requerente</span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="act-req-enable"
                                       onchange="actToggleSection('req')">
                                <label class="form-check-label" for="act-req-enable">
                                    Configurar requerente
                                </label>
                            </div>
                        </div>
                        <div id="act-req-section" class="d-none">
                            <label class="form-label form-label-sm fw-semibold">
                                Nome da questão no formulário
                            </label>
                            <input type="text" id="act-req-question" class="form-control form-control-sm"
                                   placeholder="Ex: Requerente, Solicitante...">
                            <div class="form-text">
                                O chamado de destino terá o Requerente definido como a resposta
                                desta questão.
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- Observador -->
                    <div class="mb-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge bg-info text-dark">Observador</span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="act-obs-enable"
                                       onchange="actToggleSection('obs')">
                                <label class="form-check-label" for="act-obs-enable">
                                    Configurar observador
                                </label>
                            </div>
                        </div>
                        <div id="act-obs-section" class="d-none">
                            <label class="form-label form-label-sm fw-semibold">
                                Nome da questão no formulário
                            </label>
                            <input type="text" id="act-obs-question" class="form-control form-control-sm"
                                   placeholder="Ex: Observador, Gestor...">
                            <div class="form-text">
                                O chamado de destino terá o Observador definido como a resposta
                                desta questão.
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <i class="ti ti-info-circle me-1 text-primary"></i>
                        O plugin buscará a questão pelo <strong>nome exato</strong> em cada formulário
                        e configurará o chamado de destino para usar
                        <em>Resposta de pergunta específica</em>.
                        Formulários sem a questão ou sem destinação configurada serão informados antes.
                    </div>

                </div>
                <div class="card-footer">
                    <button class="btn btn-primary w-100" id="act-submit-btn"
                            onclick="actVerifyAndApply()" disabled>
                        <i class="ti ti-check me-1"></i>
                        Verificar e aplicar em <span id="act-submit-count">0</span> formulário(s)
                    </button>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

</div>

<!-- Modal de problemas detectados -->
<div class="modal fade" id="act-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ti ti-alert-triangle text-warning me-2"></i>
                    Formulários com problemas
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="act-modal-body">
                <!-- preenchido pelo JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="act-modal-apply-btn"
                        onclick="actApplyOk()">
                    <i class="ti ti-check me-1"></i>
                    Pular problemáticos e aplicar nos
                    <span id="act-modal-ok-count">0</span> restante(s)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
var ACT_AJAX_URL  = <?= json_encode($ajaxUrl) ?>;
var ACT_CSRF      = (document.querySelector('meta[property="glpi:csrf_token"]') || {})
                        .getAttribute('content') || '';
var _actOkFormIds       = [];
var _actPendingReqQ     = '';
var _actPendingObsQ     = '';

function actPost(body) {
    return fetch(ACT_AJAX_URL, {
        method  : 'POST',
        headers : {
            'Content-Type'     : 'application/json',
            'X-Requested-With' : 'XMLHttpRequest',
            'X-Glpi-Csrf-Token': ACT_CSRF,
        },
        body: JSON.stringify(body),
    }).then(function(r) { return r.json(); });
}

// ── Filtro e seleção de formulários ──────────────────────────────────────
function actApplyFilters() {
    var categoryEl = document.getElementById('act-category');
    var category   = categoryEl ? categoryEl.value : '';
    var search     = document.getElementById('act-search').value.toLowerCase().trim();
    var rows       = document.querySelectorAll('.act-form-row');
    var visible    = 0;

    rows.forEach(function(row) {
        var matchCat  = !category || row.dataset.category === category;
        var matchName = !search   || row.dataset.name.includes(search);
        var show      = matchCat && matchName;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('act-count-visible').textContent = visible;
}

function actSelectAll(checked) {
    document.querySelectorAll('.act-form-row:not([style*="display: none"]) .act-checkbox')
        .forEach(function(cb) { cb.checked = checked; });
    actUpdateCount();
}

function actUpdateCount() {
    var count = document.querySelectorAll('.act-checkbox:checked').length;
    document.getElementById('act-count-selected').textContent = count;
    document.getElementById('act-footer-count').textContent   = count;
    document.getElementById('act-submit-count').textContent   = count;
    document.getElementById('act-submit-btn').disabled        = (count === 0);
}

function actToggleSection(type) {
    var enable  = document.getElementById('act-' + type + '-enable').checked;
    var section = document.getElementById('act-' + type + '-section');
    section.classList.toggle('d-none', !enable);
}

// ── Fluxo principal: verificar → modal/aplicar ────────────────────────────
function actVerifyAndApply() {
    var formIds = Array.from(document.querySelectorAll('.act-checkbox:checked'))
                       .map(function(cb) { return parseInt(cb.value, 10); });

    if (formIds.length === 0) {
        actShowAlert('warning', 'Selecione pelo menos um formulário.');
        return;
    }

    var reqQuestion = '';
    var obsQuestion = '';

    if (document.getElementById('act-req-enable').checked) {
        reqQuestion = document.getElementById('act-req-question').value.trim();
        if (!reqQuestion) {
            actShowAlert('warning', 'Informe o nome da questão para o Requerente ou desative essa opção.');
            return;
        }
    }

    if (document.getElementById('act-obs-enable').checked) {
        obsQuestion = document.getElementById('act-obs-question').value.trim();
        if (!obsQuestion) {
            actShowAlert('warning', 'Informe o nome da questão para o Observador ou desative essa opção.');
            return;
        }
    }

    if (!reqQuestion && !obsQuestion) {
        actShowAlert('warning', 'Ative e configure ao menos um ator (Requerente ou Observador).');
        return;
    }

    var btn = document.getElementById('act-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verificando...';

    actPost({
        action             : 'check',
        requester_question : reqQuestion,
        observer_question  : obsQuestion,
        form_ids           : formIds,
    })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Verificar e aplicar em '
            + '<span id="act-submit-count">' + formIds.length + '</span> formulário(s)';

        if (data.status !== 'ok') {
            actShowAlert('danger', escHtml(data.error || 'Erro ao verificar.'));
            return;
        }

        var r         = data.result;
        var hasIssues = r.no_destination.length > 0
                     || r.missing_requester.length > 0
                     || r.missing_observer.length > 0;

        _actOkFormIds   = r.ok.map(function(f) { return f.form_id; });
        _actPendingReqQ = reqQuestion;
        _actPendingObsQ = obsQuestion;

        if (!hasIssues) {
            actDoApply(reqQuestion, obsQuestion, _actOkFormIds);
        } else {
            actShowModal(r, reqQuestion, obsQuestion);
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Verificar e aplicar em '
            + formIds.length + ' formulário(s)';
        actShowAlert('danger', 'Erro de comunicação ao verificar.');
    });
}

function actDoApply(reqQuestion, obsQuestion, formIds) {
    if (formIds.length === 0) {
        actShowAlert('warning', 'Nenhum formulário elegível para atualização após filtrar os problemáticos.');
        return;
    }

    var btn = document.getElementById('act-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Aplicando...';

    actPost({
        action             : 'apply',
        requester_question : reqQuestion,
        observer_question  : obsQuestion,
        form_ids           : formIds,
    })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Verificar e aplicar em '
            + '<span id="act-submit-count">'
            + document.querySelectorAll('.act-checkbox:checked').length
            + '</span> formulário(s)';

        if (data.status !== 'ok') {
            actShowAlert('danger', escHtml(data.error || 'Erro ao aplicar.'));
            return;
        }

        var msg = '<i class="ti ti-circle-check me-1"></i>' + escHtml(data.message);
        if (data.errors && data.errors.length > 0) {
            msg += '<br><small class="text-danger">'
                + data.errors.map(escHtml).join('<br>') + '</small>';
        }
        actShowAlert('success', msg);
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Verificar e aplicar em '
            + document.querySelectorAll('.act-checkbox:checked').length + ' formulário(s)';
        actShowAlert('danger', 'Erro de comunicação ao aplicar.');
    });
}

// ── Modal ─────────────────────────────────────────────────────────────────
function actShowModal(result, reqQuestion, obsQuestion) {
    var body = '';

    if (result.no_destination.length > 0) {
        body += actBuildIssueBlock('danger', 'ti-ban',
            'Sem destinação de chamado (' + result.no_destination.length + ')',
            'Estes formulários não possuem nenhuma destinação de chamado configurada e serão pulados.',
            result.no_destination);
    }

    if (result.missing_requester.length > 0) {
        body += actBuildIssueBlock('warning', 'ti-user-x',
            'Questão de Requerente não encontrada (' + result.missing_requester.length + ')',
            'A questão <strong>' + escHtml(reqQuestion) + '</strong> não existe nestes formulários.',
            result.missing_requester);
    }

    if (result.missing_observer.length > 0) {
        body += actBuildIssueBlock('warning', 'ti-user-x',
            'Questão de Observador não encontrada (' + result.missing_observer.length + ')',
            'A questão <strong>' + escHtml(obsQuestion) + '</strong> não existe nestes formulários.',
            result.missing_observer);
    }

    var okCount = result.ok.length;
    if (okCount > 0) {
        body += '<div class="alert alert-success mb-0">'
            + '<i class="ti ti-circle-check me-1"></i>'
            + '<strong>' + okCount + ' formulário(s)</strong> estão prontos e serão atualizados.'
            + '</div>';
    } else {
        body += '<div class="alert alert-secondary mb-0">'
            + '<i class="ti ti-info-circle me-1"></i>'
            + 'Nenhum formulário está pronto para atualização. Cancele e revise a configuração.'
            + '</div>';
    }

    document.getElementById('act-modal-body').innerHTML = body;
    document.getElementById('act-modal-ok-count').textContent = okCount;
    document.getElementById('act-modal-apply-btn').disabled = (okCount === 0);

    var modal = new bootstrap.Modal(document.getElementById('act-modal'));
    modal.show();
}

function actBuildIssueBlock(type, icon, title, desc, forms) {
    var html = '<div class="alert alert-' + type + ' mb-3">'
        + '<strong><i class="ti ' + icon + ' me-1"></i>' + title + '</strong>'
        + '<p class="mb-2 mt-1 small">' + desc + '</p>'
        + '<ul class="mb-0 small">';
    forms.forEach(function(f) {
        html += '<li>' + escHtml(f.form_name)
            + ' <span class="text-muted">#' + f.form_id + '</span></li>';
    });
    html += '</ul></div>';
    return html;
}

function actApplyOk() {
    bootstrap.Modal.getInstance(document.getElementById('act-modal')).hide();
    actDoApply(_actPendingReqQ, _actPendingObsQ, _actOkFormIds);
}

// ── Utilidades ────────────────────────────────────────────────────────────
function actShowAlert(type, msg) {
    var el = document.getElementById('act-alert');
    el.className = 'alert alert-' + type;
    el.innerHTML = msg;
    el.classList.remove('d-none');
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<style>
.btn-xs { padding: .15rem .4rem; font-size: .75rem; }
</style>

<?php Html::footer(); ?>
