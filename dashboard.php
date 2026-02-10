<?php
// DASHBOARD.PHP - COM VISUALIZAÇÃO DE TEMPO DE AUSÊNCIA
session_start();
set_time_limit(300);
ini_set('memory_limit', '512M');

// 1. Segurança
if (!isset($_SESSION['logado']) || !$_SESSION['logado']) {
    header("Location: index.php");
    exit;
}

require_once 'classes.php';
date_default_timezone_set('America/Sao_Paulo');
$db = new Database('dados');

// =============================================================================
// 2. AJAX HANDLERS
// =============================================================================
if (isset($_POST['acao']) && $_POST['acao'] == 'ajax_acquire_lock') {
    $locksFile = 'dados/locks.json';
    $port = $_POST['port'] ?? '';
    $user = $_SESSION['nome_completo'] ?? 'Anonimo';
    
    if (!$port) exit(json_encode(['success'=>false]));

    $locks = file_exists($locksFile) ? json_decode(file_get_contents($locksFile), true) : [];
    if (!is_array($locks)) $locks = [];
    
    $now = time();

    // Limpeza de outros processos deste usuário
    foreach ($locks as $p => $info) {
        if (($info['user'] ?? '') === $user && $p != $port) {
            unset($locks[$p]);
        }
    }

    // LÓGICA DE PRESERVAÇÃO DA DATA DE ENTRADA
    if (isset($locks[$port]) && $locks[$port]['user'] === $user) {
        // É o mesmo usuário: MANTÉM O DATETIME ORIGINAL
        $datetimeEntrada = $locks[$port]['datetime']; 
    } else {
        // Novo usuário ou nova ficha
        if (isset($locks[$port])) {
            $lastSeen = $locks[$port]['timestamp'] ?? 0;
            if (($now - $lastSeen) < 120) {
                echo json_encode(['success'=>false, 'locked_by'=>$locks[$port]['user']]);
                exit;
            }
        }
        $datetimeEntrada = date('d/m/Y H:i');
    }

    $locks[$port] = [
        'user' => $user,
        'timestamp' => $now,            // Heartbeat (Atualizado constantemente)
        'datetime' => $datetimeEntrada  // Data de Entrada (Fixo)
    ];

    file_put_contents($locksFile, json_encode($locks, JSON_PRETTY_PRINT));
    echo json_encode(['success'=>true]);
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'ajax_release_lock') {
    $locksFile = 'dados/locks.json';
    $port = $_POST['port'] ?? '';
    $user = $_SESSION['nome_completo'];
    $locks = file_exists($locksFile) ? json_decode(file_get_contents($locksFile), true) : [];
    
    if (isset($locks[$port]) && $locks[$port]['user'] === $user) {
        unset($locks[$port]);
        file_put_contents($locksFile, json_encode($locks, JSON_PRETTY_PRINT));
    }
    echo json_encode(['status'=>'ok']);
    exit;
}

// =============================================================================
// 3. FUNÇÕES AUXILIARES E 4. MONITORAMENTO
// =============================================================================
function getAvailableYears() {
    $base = 'dados/Processos'; $years = [];
    if (is_dir($base)) { foreach (scandir($base) as $d) { if (is_numeric($d)) $years[] = $d; } }
    rsort($years); return $years;
}
function getTargetFiles($year, $month) {
    $base = 'dados/Processos'; $files = [];
    $monthNames = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
    $targetYears = ($year === 'all') ? getAvailableYears() : [$year];
    foreach ($targetYears as $y) {
        $path = "$base/$y"; if (!is_dir($path)) continue;
        if ($month === 'all') { $glob = glob("$path/*.json"); if ($glob) $files = array_merge($files, $glob); }
        else {
            $mInt = intval($month); $name = $monthNames[$mInt] ?? '';
            if ($name && file_exists("$path/$name.json")) $files[] = "$path/$name.json";
            elseif (file_exists("$path/$mInt.json")) $files[] = "$path/$mInt.json";
            elseif (file_exists("$path/".sprintf('%02d', $mInt).".json")) $files[] = "$path/".sprintf('%02d', $mInt).".json";
        }
    }
    return $files;
}

$currentYear = date('Y'); $currentMonth = date('n');
$f_ano = $_GET['f_ano'] ?? $currentYear; $f_mes = $_GET['f_mes'] ?? $currentMonth;
$f_dt_ini = $_GET['f_dt_ini'] ?? ''; $f_dt_fim = $_GET['f_dt_fim'] ?? '';
$f_colaborador = $_GET['f_colaborador'] ?? '';
$f_status = $_GET['f_status'] ?? '';

// --- LÓGICA DE MONITORAMENTO (EM EDIÇÃO) ---
$locksFile = 'dados/locks.json';
$activeEdits = [];

if (file_exists($locksFile)) {
    $locksData = json_decode(file_get_contents($locksFile), true) ?? [];
    $now = time();
    $cleanLocks = [];
    $hasChanges = false;

    foreach ($locksData as $port => $info) {
        $lastSeen = $info['timestamp'] ?? 0;
        $strEntrada = $info['datetime'] ?? date('d/m/Y H:i'); 
        $dtObj = DateTime::createFromFormat('d/m/Y H:i', $strEntrada);
        $timestampEntrada = $dtObj ? $dtObj->getTimestamp() : $lastSeen; 
        $totalWorkTime = $now - $timestampEntrada;
        $idleTime = $now - $lastSeen;        

        if ($idleTime < 7200) { 
            $cleanLocks[$port] = $info;
            if ($idleTime <= 600) { 
                $statusLabel = "Online"; $statusClass = "st-online"; $badgeClass = "bg-success"; $rowClass = "border-start border-success border-4"; $sortOrder = 1; $pulseClass = "pulse-dot";
            } elseif ($idleTime <= 3600) { 
                $statusLabel = "Ausente"; $statusClass = "st-away"; $badgeClass = "bg-warning text-dark"; $rowClass = "border-start border-warning border-4"; $sortOrder = 2; $pulseClass = "";
            } else { 
                $statusLabel = "Offline"; $statusClass = "st-offline"; $badgeClass = "bg-secondary"; $rowClass = "border-start border-secondary border-4 opacity-75"; $sortOrder = 3; $pulseClass = "";
            }
            $h = floor($totalWorkTime / 3600); $m = floor(($totalWorkTime % 3600) / 60); $s = $totalWorkTime % 60;
            $timeStr = sprintf('%02d:%02d:%02d', $h, $m, $s);
            $ih = floor($idleTime / 3600); $im = floor(($idleTime % 3600) / 60); $is = $idleTime % 60;
            $idleStr = ($ih > 0) ? sprintf('%02dh %02dm', $ih, $im) : sprintf('%02dm %02ds', $im, $is);

            $activeEdits[] = ['port'=>$port, 'user'=>$info['user']??'Desconhecido', 'raw_seconds'=>$totalWorkTime, 'time_fmt'=>$timeStr, 'idle_seconds'=>$idleTime, 'idle_fmt'=>$idleStr, 'status_lbl'=>$statusLabel, 'badge_cls'=>$badgeClass, 'row_cls'=>$rowClass, 'pulse'=>$pulseClass, 'sort'=>$sortOrder];
        } else { $hasChanges = true; }
    }
    if ($hasChanges) file_put_contents($locksFile, json_encode($cleanLocks, JSON_PRETTY_PRINT));
}
usort($activeEdits, function($a, $b) { if ($a['sort'] === $b['sort']) return $b['raw_seconds'] <=> $a['raw_seconds']; return $a['sort'] <=> $b['sort']; });

// --- PROCESSAMENTO DE DADOS (KPIs, Charts) ---
$targetFiles = getTargetFiles($f_ano, $f_mes);
$totalValor = 0; $totalQtd = 0; 
$statusCount = []; $processosIDs = [];
$statsByUser = []; // [User => ['qtd'=>0, 'valor'=>0, 'tma'=>0]]
$uniqueColabs = [];
$uniqueStatus = [];
$userTimestamps = [];

// 1. Scan for Options & Main Stats
foreach ($targetFiles as $file) {
    $content = file_get_contents($file); if (!$content) continue;
    $rows = json_decode($content, true); if (!is_array($rows)) continue;
    foreach ($rows as $r) {
        $atendente = trim($r['Nome_atendente'] ?? 'Desconhecido');
        if (!$atendente) $atendente = 'Desconhecido';
        
        $st = trim($r['STATUS'] ?? 'Outros');
        if (!$st) $st = 'Outros';
        
        $uniqueColabs[$atendente] = true;
        $uniqueStatus[$st] = true;

        // Date Filter (Enhanced with Time)
        $dataStr = $r['DATA'] ?? '';
        
        // Determine record timestamp for filtering
        $filterTs = 0;
        $luStr = $r['Ultima_Alteracao'] ?? '';

        // Try Ultima_Alteracao first (contains time)
        if ($luStr) {
            $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $luStr);
            if (!$dtObj) $dtObj = DateTime::createFromFormat('d/m/Y H:i', $luStr);
            if ($dtObj) $filterTs = $dtObj->getTimestamp();
        }

        // Fallback to DATA
        if (!$filterTs && $dataStr) {
            $dtObj = DateTime::createFromFormat('d/m/Y', $dataStr);
            if ($dtObj) $filterTs = $dtObj->getTimestamp();
        }

        if ($f_dt_ini || $f_dt_fim) {
            if ($filterTs) {
                if ($f_dt_ini && $filterTs < strtotime($f_dt_ini)) continue;
                if ($f_dt_fim && $filterTs > strtotime($f_dt_fim)) continue;
            }
        }
        
        // Filter By Collaborator
        if ($f_colaborador && $atendente != $f_colaborador) continue;
        
        // Filter By Status
        if ($f_status && $st != $f_status) continue;

        $port = $r['Numero_Portabilidade'] ?? '';
        $statusKey = mb_strtoupper($st, 'UTF-8');
        $vRaw = $r['VALOR DA PORTABILIDADE'] ?? '0';
        $vFloat = (float)str_replace(['.', ','], ['', '.'], str_replace(['R$', ' ', "\u{00a0}"], '', $vRaw));
        
        $totalValor += $vFloat; 
        $totalQtd++;
        
        if (!isset($statusCount[$statusKey])) $statusCount[$statusKey] = 0;
        $statusCount[$statusKey]++; 
        
        $processosIDs[$port] = true;

        // Stats per User
        if (!isset($statsByUser[$atendente])) {
            $statsByUser[$atendente] = ['qtd'=>0, 'valor'=>0, 'total_duration'=>0, 'count_duration'=>0];
        }
        $statsByUser[$atendente]['qtd']++;
        $statsByUser[$atendente]['valor'] += $vFloat;

        // TMA Setup: Coletar timestamps para cálculo diário (Max - Min)
        $lastUpdateStr = $r['Ultima_Alteracao'] ?? '';
        $recTs = 0;
        
        if ($lastUpdateStr) {
            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $lastUpdateStr);
            if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $lastUpdateStr);
            if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $lastUpdateStr);
            if ($dt) $recTs = $dt->getTimestamp();
        }
        
        // Fallback to DATA (Creation) if no Ultima_Alteracao
        if (!$recTs && $dataStr) {
             $dt = DateTime::createFromFormat('d/m/Y', $dataStr);
             if ($dt) $recTs = $dt->getTimestamp();
        }

        if ($recTs) {
            $dateKey = date('Y-m-d', $recTs);
            if (!isset($userTimestamps[$atendente][$dateKey])) {
                $userTimestamps[$atendente][$dateKey] = [];
            }
            $userTimestamps[$atendente][$dateKey][] = $recTs;
        }
    }
}
ksort($uniqueColabs);
ksort($uniqueStatus);

// Finalize TMA
foreach ($statsByUser as $u => &$s) {
    $totalDuration = 0;
    if (isset($userTimestamps[$u])) {
        foreach ($userTimestamps[$u] as $date => $stamps) {
            if (!empty($stamps)) {
                $min = min($stamps);
                $max = max($stamps);
                $totalDuration += ($max - $min);
            }
        }
    }
    
    // TMA = Total Duration / Total Processed Requests (qtd)
    if ($s['qtd'] > 0) {
        $s['tma'] = $totalDuration / $s['qtd'];
    } else {
        $s['tma'] = 0;
    }
    
    // Cleanup internal keys
    unset($s['total_duration']);
    unset($s['count_duration']);
}
unset($s);

// Sorting for Charts
$rankingProducao = $statsByUser;
uasort($rankingProducao, function($a, $b) { return $b['qtd'] <=> $a['qtd']; });

$rankingValor = $statsByUser;
uasort($rankingValor, function($a, $b) { return $b['valor'] <=> $a['valor']; });

$rankingTMA = $statsByUser;
uasort($rankingTMA, function($a, $b) { return $b['tma'] <=> $a['tma']; });

// Helper Format
function fmtTime($s) {
    if ($s == 0) return '-'; if ($s < 60) return round($s).'s';
    $m = floor($s/60); $h = floor($m/60); return ($h>0) ? "{$h}h ".($m%60)."m" : "{$m}m";
}
$optYears = getAvailableYears();

// Calculate Team Average
$allTmas = array_column($statsByUser, 'tma');
$allTmas = array_filter($allTmas, function($v){ return $v > 0; }); // Only non-zero
$mediaTMA = (count($allTmas) > 0) ? array_sum($allTmas) / count($allTmas) : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wiz Dashboard - Monitoramento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        :root { --wiz-orange: #FF8C00; --wiz-navy: #003366; --bg-gray: #f0f2f5; }
        body { background-color: var(--bg-gray); font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .navbar-wiz { background: linear-gradient(90deg, var(--wiz-navy), #001f3f); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .card-wiz { border: none; border-radius: 12px; background: white; box-shadow: 0 3px 10px rgba(0,0,0,0.03); }
        .btn-refresh { background-color: #ffc107; color: var(--wiz-navy); font-weight: 700; border: none; }
        .btn-refresh:hover { background-color: #e0a800; }
        .btn-wiz { background-color: var(--wiz-orange); color: white; font-weight: 600; }
        .btn-wiz:hover { background-color: #e67e00; color: white; }
        .kpi-val { font-size: 1.8rem; font-weight: 800; color: var(--wiz-navy); }
        .text-navy { color: var(--wiz-navy); }
        .text-orange { color: var(--wiz-orange); }
        .live-timer { font-variant-numeric: tabular-nums; }
        .pulse-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; animation: pulse 1.5s infinite; }
        .bg-success .pulse-dot { background: #d1e7dd; }
        @keyframes pulse { 0% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<nav class="navbar navbar-dark navbar-wiz mb-4 sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-chart-line me-2"></i>WIZ DASHBOARD</a>
        <div class="d-flex align-items-center">
            <span class="text-white small me-3"><i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['nome_completo']) ?></span>
            <button onclick="window.location.reload()" class="btn btn-refresh btn-sm rounded-pill px-3 me-2 shadow-sm"><i class="fas fa-sync-alt me-1"></i> Atualizar</button>
            <a href="index.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Voltar</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

    <!-- LIVE MONITOR (Original) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-wiz border-start border-4 border-danger">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 text-danger fw-bold"><i class="fas fa-tower-broadcast me-2 live-timer text-danger"></i>EM EDIÇÃO (TEMPO REAL)</h6>
                    <span class="badge bg-danger rounded-pill"><?= count($activeEdits) ?> Ativos</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="bg-light text-secondary small">
                                <tr>
                                    <th>Colaborador</th>
                                    <th>Portabilidade</th>
                                    <th>Tempo em Edição</th>
                                    <th>Tempo Ocioso</th> <th>Status (Sinal)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($activeEdits as $edit): 
                                     $cls = $edit['raw_seconds'] > 600 ? 'text-danger fw-bold' : 'text-navy';
                                     $idleCls = $edit['idle_seconds'] > 600 ? 'text-warning fw-bold' : 'text-muted small';
                                ?>
                                <tr>
                                    <td class="fw-bold text-navy ps-4"><i class="fas fa-user me-2 opacity-50"></i><?= htmlspecialchars($edit['user']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($edit['port']) ?></span></td>
                                    <td class="<?= $cls ?> live-timer" data-seconds="<?= $edit['raw_seconds'] ?>">
                                        <i class="fas fa-clock me-1"></i> <span class="clock-display"><?= $edit['time_fmt'] ?></span>
                                    </td>
                                    <td class="<?= $idleCls ?>">
                                        <i class="fas fa-hourglass-half me-1"></i> <?= $edit['idle_fmt'] ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $edit['badge_cls'] ?> bg-opacity-75">
                                            <span class="ping-dot <?= $edit['pulse'] ?>"></span>
                                            <?= $edit['status_lbl'] ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($activeEdits)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted small"><i class="fas fa-check-circle me-1 text-success"></i> Nenhum processo em edição.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="card card-wiz mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Mês</label>
                    <select name="f_mes" class="form-select form-select-sm bg-light fw-bold text-navy">
                        <option value="all" <?= $f_mes=='all'?'selected':'' ?>>Todos</option>
                        <?php for($i=1; $i<=12; $i++) { $nm=$db->getPortugueseMonth($i); echo "<option value='$i' ".($f_mes==$i?'selected':'').">$nm</option>"; } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Ano</label>
                    <select name="f_ano" class="form-select form-select-sm bg-light fw-bold text-navy">
                        <option value="all" <?= $f_ano=='all'?'selected':'' ?>>Todos</option>
                        <?php foreach($optYears as $y) echo "<option value='$y' ".($f_ano==$y?'selected':'').">$y</option>"; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Colaborador</label>
                    <select name="f_colaborador" class="form-select form-select-sm bg-light text-navy">
                        <option value="">Todos</option>
                        <?php foreach(array_keys($uniqueColabs) as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $f_colaborador == $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Status</label>
                    <select name="f_status" class="form-select form-select-sm bg-light text-navy">
                        <option value="">Todos</option>
                        <?php foreach(array_keys($uniqueStatus) as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $f_status == $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Optional Date Range -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Data/Hora Início</label>
                    <input type="datetime-local" name="f_dt_ini" class="form-control form-control-sm" value="<?= $f_dt_ini ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Data/Hora Fim</label>
                    <input type="datetime-local" name="f_dt_fim" class="form-control form-control-sm" value="<?= $f_dt_fim ?>">
                </div>
                <div class="col-md-2 d-grid"><button type="submit" class="btn btn-wiz btn-sm"><i class="fas fa-filter me-1"></i> Filtrar</button></div>
            </form>
        </div>
    </div>

    <!-- KPIS -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card card-wiz h-100 border-start border-4 border-success p-3">
                <div class="small text-uppercase text-muted fw-bold">Volume Financeiro</div>
                <div class="fs-2 fw-bold text-success">R$ <?= number_format($totalValor, 2, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-wiz h-100 border-start border-4 border-primary p-3">
                <div class="small text-uppercase text-muted fw-bold">Quantidade Processos</div>
                <div class="fs-2 fw-bold text-navy"><?= number_format($totalQtd, 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-wiz h-100 border-start border-4 border-warning p-3">
                <div class="small text-uppercase text-muted fw-bold">TMA Médio (Equipe)</div>
                <div class="fs-2 fw-bold text-orange"><?= fmtTime($mediaTMA) ?></div>
            </div>
        </div>
    </div>

    <!-- CHARTS ROW 1 -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy">Ranking de Produção (Qtd)</h6></div>
                <div class="card-body"><canvas id="chartProducao" height="250"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy">Volume Financeiro (R$)</h6></div>
                <div class="card-body"><canvas id="chartValor" height="250"></canvas></div>
            </div>
        </div>
    </div>

    <!-- CHARTS ROW 2 -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy">Ranking TMA (Tempo Médio)</h6></div>
                <div class="card-body"><canvas id="chartTMA" height="250"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy">Status Global</h6></div>
                <div class="card-body"><canvas id="chartStatus" height="250"></canvas></div>
            </div>
        </div>
    </div>

</div>

<script>
    Chart.register(ChartDataLabels);
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    
    // Data Preparation
    const labelsProd = <?= json_encode(array_keys($rankingProducao)) ?>;
    const dataProd = <?= json_encode(array_column($rankingProducao, 'qtd')) ?>;
    
    const labelsVal = <?= json_encode(array_keys($rankingValor)) ?>;
    const dataVal = <?= json_encode(array_column($rankingValor, 'valor')) ?>;
    
    const labelsTMA = <?= json_encode(array_keys($rankingTMA)) ?>;
    const dataTMA = <?= json_encode(array_column($rankingTMA, 'tma')) ?>; // Seconds
    const dataTMAmin = dataTMA.map(s => (s/60).toFixed(1)); // Minutes for chart

    // 1. Chart Produção
    new Chart(document.getElementById('chartProducao'), {
        type: 'bar',
        data: {
            labels: labelsProd,
            datasets: [{
                label: 'Qtd Processos',
                data: dataProd,
                backgroundColor: '#003366',
                borderRadius: 4
            }]
        },
        options: { 
            responsive: true, 
            plugins: { 
                legend: { display: false },
                datalabels: { anchor: 'end', align: 'top', font: { weight: 'bold' } }
            }, 
            scales: { y: { beginAtZero: true } } 
        }
    });

    // 2. Chart Valor
    new Chart(document.getElementById('chartValor'), {
        type: 'bar',
        data: {
            labels: labelsVal,
            datasets: [{
                label: 'Volume (R$)',
                data: dataVal,
                backgroundColor: '#28a745',
                borderRadius: 4
            }]
        },
        options: { 
            responsive: true, 
            plugins: { 
                legend: { display: false },
                datalabels: { 
                    anchor: 'end', 
                    align: 'top', 
                    formatter: (value) => 'R$ ' + value.toLocaleString('pt-BR', {minimumFractionDigits: 2}),
                    font: { weight: 'bold' }
                }
            }, 
            scales: { y: { beginAtZero: true } } 
        }
    });

    // 3. Chart TMA
    new Chart(document.getElementById('chartTMA'), {
        type: 'bar',
        data: {
            labels: labelsTMA,
            datasets: [{
                label: 'Tempo Médio (min)',
                data: dataTMAmin,
                backgroundColor: '#FF8C00',
                borderRadius: 4
            }]
        },
        options: { 
            responsive: true, 
            plugins: { 
                legend: { display: false },
                datalabels: { anchor: 'end', align: 'top', font: { weight: 'bold' } }
            }, 
            scales: { y: { beginAtZero: true } } 
        }
    });

    // 4. Chart Status (Existing)
    new Chart(document.getElementById('chartStatus'), { 
        type: 'doughnut', 
        data: { 
            labels: <?= json_encode(array_keys($statusCount)) ?>, 
            datasets: [{ 
                data: <?= json_encode(array_values($statusCount)) ?>, 
                backgroundColor: ['#003366', '#FF8C00', '#28a745', '#dc3545', '#17a2b8', '#6610f2', '#6c757d'], 
                borderWidth: 0 
            }] 
        }, 
        options: { 
            responsive: true, 
            maintainAspectRatio: false, 
            layout: { padding: 20 },
            plugins: { 
                legend: { position: 'right' },
                datalabels: { anchor: 'end', align: 'end', offset: 4, font: { weight: 'bold' } }
            } 
        } 
    });

    // --- CRONÔMETRO VIVO ---
    function startLiveTimers() {
        setInterval(function() {
            var timers = document.querySelectorAll('.live-timer');
            timers.forEach(function(td) {
                var s = parseInt(td.getAttribute('data-seconds'));
                s++;
                td.setAttribute('data-seconds', s);
                
                var h = Math.floor(s/3600);
                var m = Math.floor((s%3600)/60);
                var sec = s%60;
                
                h = h < 10 ? '0'+h : h;
                m = m < 10 ? '0'+m : m;
                sec = sec < 10 ? '0'+sec : sec;
                
                var display = td.querySelector('.clock-display');
                if(display) display.innerText = h + ':' + m + ':' + sec;
                
                if(s > 600) { 
                    td.classList.remove('text-navy'); 
                    td.classList.add('text-danger', 'fw-bold'); 
                }
            });
        }, 1000);
    }
    document.addEventListener('DOMContentLoaded', startLiveTimers);

    // Auto-refresh a cada 60s
    setInterval(function(){ window.location.reload(); }, 60000);
</script>
</body>
</html>
