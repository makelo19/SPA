<?php
// SISTEMA DE PORTABILIDADE - VERSÃO FINAL COMPLETA E RESTAURADA
set_time_limit(60000);
session_start();

if (!isset($_SESSION['base_context'])) {
    $_SESSION['base_context'] = 'atual'; // Keeping for legacy compat if needed, but we rely on arrays now
}
if (!isset($_SESSION['selected_years'])) {
    $_SESSION['selected_years'] = [date('Y')];
}
if (!isset($_SESSION['selected_months'])) {
    $_SESSION['selected_months'] = [(int)date('n')];
}

require_once 'classes.php';

// Configurações
date_default_timezone_set('America/Sao_Paulo');

$db = new Database('dados');
// Ensure current period exists on startup
$db->ensurePeriodStructure(date('Y'), date('n'));

$indexer = new ProcessIndexer();
$indexer->ensureIndex($db);

$config = new Config($db);
// Ensure Data_Ultima_Cobranca exists in Processos
$config->ensureField('Base_processos_schema', ['key'=>'Data_Ultima_Cobranca', 'label'=>'Data da Última Cobrança', 'type'=>'date']);
// Ensure Ultima_Alteracao exists and is manual
$config->ensureField('Base_processos_schema', ['key'=>'Ultima_Alteracao', 'label'=>'Data da Última Atualização', 'type'=>'datetime-local']);
// Ensure Data_Lembrete exists
$config->ensureField('Base_processos_schema', ['key'=>'Data_Lembrete', 'label'=>'Data e Hora (Lembrete)', 'type'=>'datetime-local']);

$templates = new Templates();
$lockManager = new LockManager('dados');
$uploadDir = __DIR__ . '/uploads/';

// ===================================================================================
// AJAX HANDLERS
// ===================================================================================
if (isset($_POST['acao']) && strpos($_POST['acao'], 'ajax_') === 0) {
    header('Content-Type: application/json');
    
    // Security Check
    if (!isset($_SESSION['logado']) || !$_SESSION['logado']) {
        echo json_encode(['status' => 'error', 'message' => 'Sessão expirada. Por favor, faça login novamente.']);
        exit;
    }

    $act = $_POST['acao'];
    
    if ($act == 'ajax_set_base_selection') {
        $years = $_POST['years'] ?? [];
        $months = $_POST['months'] ?? [];
        
        if (!is_array($years)) $years = [];
        if (!is_array($months)) $months = [];

        $_SESSION['selected_years'] = array_unique(array_filter($years));
        $_SESSION['selected_months'] = array_unique(array_filter($months));

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_reactivate_field') {
        $file = $_POST['file'];
        $key = $_POST['key'];
        $config->reactivateField($file, $key);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // 1. Busca Cliente (Now searches in Processos)
    if ($act == 'ajax_search_client') {
        $cpf = $_POST['cpf'] ?? '';
        $data = null;
        
        $files = $db->getAllProcessFiles();
        // Sort files to search newest first? getAllProcessFiles usually returns alphabetical (date order).
        // We reverse to get newest first.
        rsort($files);
        
        foreach ($files as $f) {
            $rec = $db->find($f, 'CPF', $cpf);
            if ($rec) {
                $data = $rec;
                break;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !!$data, 'data' => $data]);
        exit;
    }

    // 2. Busca Processos (Modal)
    if ($act == 'ajax_check_cpf_processes') {
        $cpf = $_POST['cpf'] ?? '';
        
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $files = $db->getProcessFiles($years, $months);

        $res = $db->select($files, ['CPF' => $cpf], 1, 1000);
        $rows = $res['data'];
        
        $result = [];
        foreach($rows as $r) {
            $result[] = [
                'port' => get_value_ci($r, 'Numero_Portabilidade'),
                'data' => get_value_ci($r, 'DATA'),
                'status' => get_value_ci($r, 'STATUS')
            ];
        }
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !empty($result), 'processes' => $result]);
        exit;
    }
    
    if ($act == 'ajax_search_agency') {
        $ag = $_POST['ag'] ?? '';
        $data = null;
        
        $files = $db->getAllProcessFiles();
        rsort($files);
        
        foreach ($files as $f) {
            // Check if file contains this agency
            // Ideally we want the record to have populated Agency data (e.g. UF, SR).
            // Since we save these in the process record now, we look for any record with this AG.
            // Using findReverse on each file to get latest in that file.
            $rec = $db->findReverse($f, 'AG', $ag);
            if ($rec) {
                // Only return if it has useful agency data? Or just any record?
                // User said "Caso a Agência seja localizada em outro cadastro válido".
                // We assume any record with this AG is valid.
                $data = $rec;
                break;
            }
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !!$data, 'data' => $data]);
        exit;
    }

    if ($act == 'ajax_check_process') {
        $port = trim($_POST['port'] ?? '');
        
        $data = null;
        $file = $indexer->get($port);
        
        if ($file) {
            $res = $db->select($file, ['Numero_Portabilidade' => $port], 1, 1);
            if (!empty($res['data'])) $data = $res['data'][0];
        }

        // Fallback: If not found in index or index was stale
        if (!$data) {
            $files = $db->getAllProcessFiles();
            $foundFile = $db->findFileForRecord($files, 'Numero_Portabilidade', $port);
            
            if ($foundFile) {
                $res = $db->select($foundFile, ['Numero_Portabilidade' => $port], 1, 1);
                if (!empty($res['data'])) {
                    $data = $res['data'][0];
                    // Update Index to prevent future scans
                    $indexer->set($port, $foundFile);
                }
            }
        }

        $creditData = null;
        if (!$data) {
             // Search in Credit Base if not found in Process Base
             $creditData = $db->find('Identificacao_cred.json', 'PORTABILIDADE', $port);
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !!$data, 'port' => $port, 'credit_data' => $creditData]);
        exit;
    }

    if ($act == 'ajax_check_cert') {
        $cert = $_POST['cert'] ?? '';
        
        $files = $db->getAllProcessFiles();

        $res = $db->select($files, ['callback' => function($row) use ($cert) {
            $val = get_value_ci($row, 'Certificado');
            return $val !== '' && stripos($val, $cert) !== false;
        }], 1, 100);

        $rows = $res['data'];

        $results = [];
        foreach($rows as $r) {
            $results[] = ['port' => get_value_ci($r, 'Numero_Portabilidade'), 'data' => get_value_ci($r, 'DATA'), 'status' => get_value_ci($r, 'STATUS'), 'source' => 'Processo'];
        }
        
        // Also check Credits
        $resCred = $db->select('Identificacao_cred.json', ['callback' => function($row) use ($cert) {
            // Check CERTIFICADO (or fallback case-insensitive if needed, but file has CERTIFICADO)
            $val = get_value_ci($row, 'CERTIFICADO');
            return $val !== '' && stripos($val, $cert) !== false;
        }], 1, 100);
        $rowsCred = $resCred['data'];
        
        foreach($rowsCred as $r) {
            // Avoid duplicates if possible, though structure differs
            $exists = false;
            $rPort = get_value_ci($r, 'PORTABILIDADE');
            foreach($results as $ex) { if($ex['port'] == $rPort) $exists = true; }
            if(!$exists) {
                $results[] = ['port' => $rPort, 'data' => get_value_ci($r, 'DATA_DEPOSITO') ?: 'N/A', 'status' => get_value_ci($r, 'STATUS') ?: 'Crédito', 'source' => 'Crédito'];
            }
        }

        ob_clean(); header('Content-Type: application/json');
        if (!empty($results)) {
            echo json_encode(['found' => true, 'count' => count($results), 'processes' => $results]);
        } else {
            echo json_encode(['found' => false]);
        }
        exit;
    }

    if ($act == 'ajax_delete_credit_bulk') {
        $ports = $_POST['ports'] ?? [];
        ob_clean(); header('Content-Type: application/json');
        if (!empty($ports)) {
            if ($db->deleteMany('Identificacao_cred.json', 'PORTABILIDADE', $ports)) {
                echo json_encode(['status'=>'ok', 'message'=>'Registros excluídos.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir registros.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Nenhum registro selecionado.']);
        }
        exit;
    }

    if ($act == 'ajax_reorder_fields') {
        $file = $_POST['file'];
        $order = $_POST['order']; 
        $config->reorderFields($file, $order);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_get_process_filter_options') {
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $files = $db->getProcessFiles($years, $months);
        
        $status = [];
        $atendentes = [];
        
        // Try to get Status from Schema options first
        $fields = $config->getFields('Base_processos_schema');
        foreach ($fields as $f) {
            if ($f['key'] === 'STATUS' && !empty($f['options'])) {
                $status = array_map('trim', explode(',', $f['options']));
            }
        }
        
        // If not found or empty, get from data
        if (empty($status)) {
             foreach ($files as $f) {
                 $s = $db->getUniqueValues($f, 'STATUS');
                 $status = array_merge($status, $s);
             }
             $status = array_unique($status);
             sort($status);
        }
        
        // Atendentes always from data
        foreach ($files as $f) {
             $a = $db->getUniqueValues($f, 'Nome_atendente');
             $atendentes = array_merge($atendentes, $a);
        }
        $atendentes = array_unique($atendentes);
        sort($atendentes);

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'status_opts'=>$status, 'atendentes_opts'=>$atendentes]);
        exit;
    }

    // 3. GERAÇÃO DE TEXTO
    if ($act == 'ajax_generate_text') {
        $tplId = $_POST['tpl_id'];
        $formData = json_decode($_POST['data'], true); 
        
        // Busca manual para garantir compatibilidade
        $allTemplates = $templates->getAll();
        $textoBase = '';
        foreach($allTemplates as $t) {
            if($t['id'] == $tplId) {
                $textoBase = $t['corpo'];
                break;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        if ($textoBase) {
            // 1. Build Normalized Data Map with Formatting
            $normalizedData = [];
            foreach ($formData as $key => $val) {
                $upperKey = mb_strtoupper($key, 'UTF-8');
                
                // Prioritize non-empty values in case of collision
                if (isset($normalizedData[$upperKey])) {
                    if (trim((string)$normalizedData[$upperKey]) === '' && trim((string)$val) !== '') {
                        // overwrite with new value
                    } else {
                        continue; // keep existing non-empty
                    }
                }
                
                // Formatting: Date YYYY-MM-DD -> DD/MM/YYYY
                if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $val);
                    if ($dt) $val = $dt->format('d/m/Y');
                }
                
                // Formatting: Empty -> _______
                if (trim((string)$val) === '') {
                    $val = '_______';
                }
                
                $normalizedData[$upperKey] = $val;
            }

            // 2. Replace using Callback (Case Insensitive Matching of Placeholders)
            // This ensures {Certificado}, {CERTIFICADO}, {certificado} are all matched against the data.
            $textoBase = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($normalizedData) {
                $original = $matches[0];
                $key = trim($matches[1]);
                $upperKey = mb_strtoupper($key, 'UTF-8');
                
                if (isset($normalizedData[$upperKey])) {
                    return $normalizedData[$upperKey];
                }
                return $original;
            }, $textoBase);
            
            echo json_encode(['status' => 'ok', 'text' => $textoBase]);
        } else {
            echo json_encode(['status' => 'error', 'text' => 'Modelo não encontrado.']);
        }
        exit;
    }

    // 4. Salvar Histórico
    if ($act == 'ajax_save_history') {
        $extra = isset($_POST['extra']) ? json_decode($_POST['extra'], true) : [];
        $templates->recordHistory($_SESSION['nome_completo'], $_POST['cliente'], $_POST['cpf'], $_POST['port'], $_POST['modelo'], $_POST['texto'], $_POST['destinatarios'] ?? '', $extra);
        
        // Update Timestamp
        $port = $_POST['port'];
        $file = $indexer->get($port);
        if (!$file) {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $files = $db->getProcessFiles($years, $months);
             $file = $db->findFileForRecord($files, 'Numero_Portabilidade', $port);
        }
        if ($file) {
             $db->update($file, 'Numero_Portabilidade', $port, ['Ultima_Alteracao' => date('d/m/Y H:i:s'), 'Nome_atendente' => $_SESSION['nome_completo']]);
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => date('d/m/Y H:i'), 'usuario' => $_SESSION['nome_completo']]);
        exit;
    }

    // 5. Locking
    if ($act == 'ajax_acquire_lock') {
        $res = $lockManager->acquireLock($_POST['port'], $_SESSION['nome_completo']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }
    if ($act == 'ajax_release_lock') {
        $lockManager->releaseLock($_POST['port'], $_SESSION['nome_completo']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_save_credit') {
        ob_clean(); header('Content-Type: application/json');
        $headers = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', 'PORTABILIDADE', 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
        $data = [];
        foreach ($headers as $h) {
             $data[$h] = $_POST[$h] ?? '';
        }

        $port = $data['PORTABILIDADE'];
        $originalPort = $_POST['original_port'] ?? '';
        
        if (!$port) {
            echo json_encode(['status'=>'error', 'message'=>'Portabilidade é obrigatória.']);
            exit;
        }

        // Date Conversion YYYY-MM-DD -> d/m/Y
        if ($data['DATA_DEPOSITO']) {
             $dt = DateTime::createFromFormat('Y-m-d', $data['DATA_DEPOSITO']);
             if ($dt) $data['DATA_DEPOSITO'] = $dt->format('d/m/Y');
        }
        
        if ($originalPort) {
             // Updating
             if ($port != $originalPort) {
                 $exists = $db->find('Identificacao_cred.json', 'PORTABILIDADE', $port);
                 if ($exists) {
                     echo json_encode(['status'=>'error', 'message'=>'Nova Portabilidade já existe na base.']);
                     exit;
                 }
             }
             $res = $db->update('Identificacao_cred.json', 'PORTABILIDADE', $originalPort, $data);
             $msg = "Registro atualizado com sucesso!";
        } else {
             // Inserting
             $exists = $db->find('Identificacao_cred.json', 'PORTABILIDADE', $port);
             if ($exists) {
                 echo json_encode(['status'=>'error', 'message'=>'Portabilidade já existe.']);
                 exit;
             }
             $res = $db->insert('Identificacao_cred.json', $data);
             $msg = "Registro criado com sucesso!";
        }

        if ($res) {
            echo json_encode(['status'=>'ok', 'message'=>$msg]);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Erro ao salvar registro.']);
        }
        exit;
    }

    if ($act == 'ajax_delete_credit') {
        $port = $_POST['port'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        if ($port) {
            if ($db->delete('Identificacao_cred.json', 'PORTABILIDADE', $port)) {
                echo json_encode(['status'=>'ok', 'message'=>'Registro excluído.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Identificador inválido.']);
        }
        exit;
    }

    if ($act == 'ajax_save_process_data_record') {
        $port = $_POST['port'] ?? '';
        $usuario = $_SESSION['nome_completo'];
        $data = date('d/m/Y H:i');
        
        $fields = $config->getFields('Base_registros_schema');
        $errors = [];
        $record = [
            'DATA' => $data,
            'USUARIO' => $usuario,
            'PORTABILIDADE' => $port
        ];
        
        foreach($fields as $f) {
            if (isset($f['type']) && $f['type'] === 'title') continue;
            $key = $f['key'];
            // PHP mangle spaces/dots to underscores in $_POST keys
            $postKey = str_replace([' ', '.'], '_', $key);
            
            $val = $_POST[$postKey] ?? ($_POST[$key] ?? '');
            
            if (is_array($val)) {
                $val = implode(', ', $val);
            }

            if ($f['type'] == 'date' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d', $val);
                if ($dtObj) $val = $dtObj->format('d/m/Y');
            }
            $record[$f['key']] = $val;
            
            // Validation
            if (isset($f['required']) && $f['required'] && (!isset($f['deleted']) || !$f['deleted'])) {
                if (trim((string)$val) === '') {
                    $errors[] = "Campo obrigatório não preenchido: " . ($f['label'] ?: $f['key']);
                }
            }
        }
        
        if (!empty($errors)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => implode("<br>", $errors)]);
            exit;
        }
        
        $targetFile = 'Base_registros_dados.json';
        if (!file_exists($db->getPath($targetFile))) {
            $db->writeJSON($db->getPath($targetFile), []);
        }
        
        if ($db->insert($targetFile, $record)) {
            // Update Parent Timestamp
            $file = $indexer->get($port);
            if (!$file) {
                 $years = $_SESSION['selected_years'] ?? [date('Y')];
                 $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                 $files = $db->getProcessFiles($years, $months);
                 $file = $db->findFileForRecord($files, 'Numero_Portabilidade', $port);
            }
            if ($file) {
                 $db->update($file, 'Numero_Portabilidade', $port, ['Ultima_Alteracao' => date('d/m/Y H:i:s'), 'Nome_atendente' => $_SESSION['nome_completo']]);
            }

            $registros = $db->findMany($targetFile, 'PORTABILIDADE', [$port]);
            $registros = array_reverse($registros);
            
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'history' => $registros]);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar.']);
        }
        exit;
    }

    if ($act == 'ajax_salvar_processo') { 
        ob_clean(); header('Content-Type: application/json');

        // Pre-populate Process fields from Client/Agency inputs for storage
        if(isset($_POST['client_Nome'])) $_POST['Nome'] = $_POST['client_Nome'];
        
        $agMap = ['UF', 'SR', 'NOME_SR', 'FILIAL', 'E-MAIL_AG', 'E-MAILS_SR', 'E-MAILS_FILIAL', 'E-MAIL_GERENTE'];
        foreach($agMap as $k) {
            if(isset($_POST['agency_' . $k])) $_POST[$k] = $_POST['agency_' . $k];
        }

        $fields = $config->getFields('Base_processos_schema'); 
        $data = [];
        $errors = [];
        
        foreach ($fields as $f) {
            if (isset($f['type']) && $f['type'] === 'title') continue;

            $key = $f['key'];
            $postKey = str_replace(' ', '_', $key);
            
            // Deleted field traceability: skip if deleted and not present in POST
            if ((isset($f['deleted']) && $f['deleted']) && !isset($_POST[$postKey]) && !isset($_POST[$key])) {
                continue;
            }

            $val = $_POST[$postKey] ?? ($_POST[$key] ?? '');
            
            if (is_array($val)) {
                $val = implode(', ', $val);
            }

            if ($f['type'] == 'date' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d', $val);
                if ($dtObj) {
                    $val = $dtObj->format('d/m/Y');
                }
            }
            if ($f['type'] == 'datetime-local' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if ($dtObj) {
                    $val = $dtObj->format('d/m/Y H:i:s');
                }
            }
            $data[$key] = $val;
            
            // Validation
            if ($f['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                $errors[] = "O campo " . ($f['label'] ?: $key) . " deve conter apenas números.";
            }

            if ($f['type'] === 'custom') {
                if (($f['custom_case'] ?? '') === 'upper') $val = mb_strtoupper($val, 'UTF-8');
                if (($f['custom_case'] ?? '') === 'lower') $val = mb_strtolower($val, 'UTF-8');
                $data[$key] = $val;
            }

            if (isset($f['required']) && $f['required'] && (!isset($f['deleted']) || !$f['deleted'])) {
                if (trim((string)$val) === '') {
                    $errors[] = "Campo obrigatório do processo não preenchido: " . ($f['label'] ?: $key);
                }
            }
        }

        $cpf = $_POST['CPF'] ?? '';
        $port = $_POST['Numero_Portabilidade'] ?? '';
        $ag = $_POST['AG'] ?? '';

        if (!$port) {
            echo json_encode(['status'=>'error', 'message'=>"Erro: Número da Portabilidade é obrigatório."]);
            exit;
        } 

        // Lock Check
        $lockInfo = $lockManager->checkLock($port, $_SESSION['nome_completo']);
        if ($lockInfo['locked']) {
            echo json_encode(['status'=>'error', 'message'=>"Este processo está bloqueado por {$lockInfo['by']} e não pode ser salvo."]);
            exit;
        }
        
        $data['Nome_atendente'] = $_SESSION['nome_completo'] ?? 'Desconhecido';
        if (empty($data['Ultima_Alteracao'])) {
            $data['Ultima_Alteracao'] = date('d/m/Y H:i:s');
        }
        if (empty($data['DATA'])) $data['DATA'] = date('d/m/Y');

        // Client Validation (Removed as Base_clientes is deprecated)
        // if ($cpf) { ... }
        
        // Agency Validation (Removed as Base_agencias is deprecated)
        // if ($ag) { ... }

        if (!empty($errors)) {
             echo json_encode(['status'=>'error', 'message'=>implode("<br>", array_unique($errors))]);
             exit;
        }

        // Determine correct storage file based on date
        $dt = DateTime::createFromFormat('d/m/Y', $data['DATA']);
        if (!$dt) $dt = new DateTime();
        $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));

        // Check for existing record via Index
        $foundFile = $indexer->get($port);

        if ($foundFile) {
            // Check if date changed such that it belongs to a different file
            if ($foundFile !== $targetFile) {
                // Move record: Delete from old, Insert into new
                $oldData = $db->find($foundFile, 'Numero_Portabilidade', $port);
                $fullData = $oldData ? array_merge($oldData, $data) : $data;

                $db->delete($foundFile, 'Numero_Portabilidade', $port);
                $db->insert($targetFile, $fullData);
                $indexer->set($port, $targetFile); // Update Index
                $msg = "Processo atualizado e movido para o período correto!";
            } else {
                $db->update($foundFile, 'Numero_Portabilidade', $port, $data);
                $msg = "Processo atualizado com sucesso!";
            }
        } else {
            $db->insert($targetFile, $data);
            $indexer->set($port, $targetFile); // Add to Index
            $msg = "Processo criado com sucesso!";
        }

        // Updates to Base_clientes/Base_agencias removed as requested (all data in Processos)
        
        echo json_encode(['status'=>'ok', 'message'=>$msg]);
        exit;
    }

    if ($act == 'ajax_render_dashboard_table') {
        $fAtendente = $_POST['fAtendente'] ?? '';
        $fStatus = $_POST['fStatus'] ?? '';
        $fDataIni = $_POST['fDataIni'] ?? '';
        $fDataFim = $_POST['fDataFim'] ?? '';
        $fMes = $_POST['fMes'] ?? '';
        $fAno = $_POST['fAno'] ?? '';
        $fBusca = $_POST['fBusca'] ?? '';
        $pPagina = $_POST['pag'] ?? 1;
        
        $sortCol = $_POST['sortCol'] ?? 'DATA';
        $sortDir = $_POST['sortDir'] ?? 'desc';
        $desc = ($sortDir === 'desc');

        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $targetFile = $db->getProcessFiles($years, $months);

        $filters = [];
        if ($fAtendente) $filters['Nome_atendente'] = $fAtendente;
        if ($fStatus) $filters['STATUS'] = $fStatus;
        
        $checkDate = function($row) use ($fDataIni, $fDataFim, $fMes, $fAno) {
            if (!$fDataIni && !$fDataFim && !$fMes && !$fAno) return true;
            $d = get_value_ci($row, 'DATA'); 
            if (!$d) return false;
            $dt = DateTime::createFromFormat('d/m/Y', $d);
            if (!$dt) return false;
            
            if ($fDataIni) {
                $di = DateTime::createFromFormat('Y-m-d', $fDataIni);
                if ($dt < $di) return false;
            }
            if ($fDataFim) {
                $df = DateTime::createFromFormat('Y-m-d', $fDataFim);
                if ($dt > $df) return false;
            }
            if ($fMes && $fAno) {
                if ($dt->format('m') != $fMes || $dt->format('Y') != $fAno) return false;
            }
            return true;
        };

        if ($fBusca) {
             $foundCpfs = []; $foundPorts = []; $foundAgs = [];
             if (!preg_match('/^\d+$/', $fBusca)) {
                 $resCli = $db->select('Base_clientes.json', ['global' => $fBusca], 1, 1000); 
                 $foundCpfs = array_column($resCli['data'], 'CPF');
             }
             $resCred = $db->select('Identificacao_cred.json', ['global' => $fBusca], 1, 1000);
             $foundPorts = array_column($resCred['data'], 'PORTABILIDADE');
             $resAg = $db->select('Base_agencias.json', ['global' => $fBusca], 1, 1000);
             $foundAgs = array_column($resAg['data'], 'AG');

             $filters['callback'] = function($row) use ($fBusca, $foundCpfs, $foundPorts, $foundAgs, $checkDate) {
                  if (!$checkDate($row)) return false;
                  foreach ($row as $val) if (stripos($val, $fBusca) !== false) return true;
                  $cpf = get_value_ci($row, 'CPF');
                  if (!empty($foundCpfs) && $cpf && in_array($cpf, $foundCpfs)) return true;
                  $port = get_value_ci($row, 'Numero_Portabilidade');
                  if (!empty($foundPorts) && $port && in_array($port, $foundPorts)) return true;
                  $ag = get_value_ci($row, 'AG');
                  if (!empty($foundAgs) && $ag && in_array($ag, $foundAgs)) return true;
                  return false;
             };
        } else {
            if ($fDataIni || $fDataFim || ($fMes && $fAno)) $filters['callback'] = $checkDate;
        }

        if ($sortCol === 'Nome' || $sortCol === 'Nome_atendente') {
            // Fetch ALL (limit 1M) to handle custom sorting
            $res = $db->select($targetFile, $filters, 1, 1000000, null, false);
            $processos = $res['data'];

            $cpfs = [];
            foreach($processos as $p) { $cpfs[] = get_value_ci($p, 'CPF'); }
            // We need clients for 'Nome' sort
            $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
            $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c['Nome'];
            
 usort($processos, function($a, $b) use ($sortCol, $desc, $clientMap) {
                if ($sortCol === 'Nome') {
                    // CORREÇÃO: Tenta buscar no Mapa de Clientes, se não achar, usa o Nome salvo no próprio processo
                    $cpfA = get_value_ci($a, 'CPF') ?: '';
                    $nameA = $clientMap[$cpfA] ?? (get_value_ci($a, 'Nome') ?: '');
                    
                    $cpfB = get_value_ci($b, 'CPF') ?: '';
                    $nameB = $clientMap[$cpfB] ?? (get_value_ci($b, 'Nome') ?: '');

                    $cmp = strnatcasecmp($nameA, $nameB);
                    return $desc ? -$cmp : $cmp;
                }
                if ($sortCol === 'Nome_atendente') {
                    $atA = trim(get_value_ci($a, 'Nome_atendente') ?: '');
                    $atB = trim(get_value_ci($b, 'Nome_atendente') ?: '');
                    $cmp = strnatcasecmp($atA, $atB);
                    
                    if ($cmp !== 0) {
                        return $desc ? -$cmp : $cmp;
                    }
                    
                    // Ordenação secundária: Ultima_Alteracao DESC (Mais recente primeiro)
                    $parseDate = function($val) {
                        $val = trim((string)$val);
                        if (!$val) return 0;
                        
                        $dt = DateTime::createFromFormat('d/m/Y H:i:s', $val);
                        if ($dt) return $dt->getTimestamp();
                        
                        $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
                        if ($dt) return $dt->getTimestamp();

                        $dt = DateTime::createFromFormat('d/m/Y', $val);
                        if ($dt) return $dt->getTimestamp();
                        
                        return 0;
                    };
                    
                    $tA = $parseDate(get_value_ci($a, 'Ultima_Alteracao') ?: (get_value_ci($a, 'DATA') ?: ''));
                    $tB = $parseDate(get_value_ci($b, 'Ultima_Alteracao') ?: (get_value_ci($b, 'DATA') ?: ''));
                    
                    if ($tA == $tB) return 0;
                    return ($tA < $tB) ? 1 : -1; 
                }
                return 0;
            });
            
            $total = count($processos);
            $limit = 20;
            $pages = ceil($total / $limit);
            if ($pPagina > $pages && $pages > 0) $pPagina = $pages;
            if ($pPagina < 1) $pPagina = 1;
            
            $offset = ($pPagina - 1) * $limit;
            $processos = array_slice($processos, $offset, $limit);
            
            $res = [
                'data' => $processos,
                'total' => $total,
                'page' => $pPagina,
                'pages' => $pages
            ];
        } else {
            $res = $db->select($targetFile, $filters, $pPagina, 20, $sortCol, $desc);
            $processos = $res['data'];
        }
        
        $cpfs = []; $ports = [];
        foreach($processos as $p) {
            $cpfs[] = get_value_ci($p, 'CPF');
            $ports[] = get_value_ci($p, 'Numero_Portabilidade');
        }
        $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
        $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c['Nome'];
        $creditos = $db->findMany('Identificacao_cred.json', 'PORTABILIDADE', $ports);
        $creditoMap = []; foreach ($creditos as $c) $creditoMap[$c['PORTABILIDADE']] = $c;

        ob_start();
        foreach($processos as $proc) {
            $cpf = get_value_ci($proc, 'CPF');
            $port = get_value_ci($proc, 'Numero_Portabilidade');
            // Fix: Fallback to stored Name if Client lookup fails
            $nome = $clientMap[$cpf] ?? (get_value_ci($proc, 'Nome') ?: 'N/A');
            $cred = isset($creditoMap[$port]);
            
            $l = $lockManager->checkLock($port, '');
            $rowClass = $l['locked'] ? 'table-warning' : '';

            echo '<tr class="' . $rowClass . '">';
            echo '<td class="dashboard-text">' . htmlspecialchars(get_value_ci($proc, 'DATA') ?: '') . '</td>';
            echo '<td class="dashboard-text">' . htmlspecialchars($nome) . '</td>';
            echo '<td class="dashboard-text">' . htmlspecialchars($cpf ?: '') . '</td>';
            echo '<td class="dashboard-text">' . htmlspecialchars($port ?: '');
            if($cred) echo ' <i class="fas fa-sack-dollar money-bag ms-2" title="Crédito Identificado!"></i>';
            echo '</td>';
            echo '<td class="dashboard-text">' . htmlspecialchars(get_value_ci($proc, 'VALOR DA PORTABILIDADE') ?: '') . '</td>';
            echo '<td><span class="badge bg-secondary status-badge">' . htmlspecialchars(get_value_ci($proc, 'STATUS') ?: '') . '</span></td>';
            echo '<td class="dashboard-text">' . htmlspecialchars(get_value_ci($proc, 'Nome_atendente') ?: '');
            $lastUpd = get_value_ci($proc, 'Ultima_Alteracao');
            if(!empty($lastUpd)) {
                echo '<div class="small text-muted" style="font-size:0.75rem"><i class="fas fa-clock me-1"></i> ' . htmlspecialchars($lastUpd) . '</div>';
            }
            if($l['locked']) echo '<div class="small text-danger fw-bold"><i class="fas fa-lock me-1"></i> Em uso por: ' . $l['by'] . '</div>';
            echo '</td>';
            echo '<td><a href="javascript:void(0)" onclick="loadProcess(\'' . htmlspecialchars($port) . '\', this)" class="btn btn-sm btn-outline-dark border-0"><i class="fas fa-folder-open fa-lg text-warning"></i> Abrir</a></td>';
            echo '</tr>';
        }
        if(empty($processos)) echo '<tr><td colspan="8" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>';
        $html = ob_get_clean();

        $paginationHtml = '';
        if ($res['pages'] > 1) {
             $paginationHtml .= '<ul class="pagination justify-content-center">';
             if($res['page'] > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, '.($res['page']-1).')">Anterior</a></li>';
             $paginationHtml .= '<li class="page-item disabled"><a class="page-link">Página '.$res['page'].' de '.$res['pages'].'</a></li>';
             if($res['page'] < $res['pages']) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, '.($res['page']+1).')">Próxima</a></li>';
             $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml, 'page' => $res['page'], 'count' => $res['total']]);
        exit;
    }

    if ($act == 'ajax_render_lembretes_table') {
        $pPagina = $_POST['pag'] ?? 1;
        $fLembreteIni = $_POST['fLembreteIni'] ?? '';
        $fLembreteFim = $_POST['fLembreteFim'] ?? '';
        $fBuscaGlobal = $_POST['fBuscaGlobal'] ?? '';
        
        $sortCol = $_POST['sortCol'] ?? 'Data_Lembrete';
        $sortDir = $_POST['sortDir'] ?? 'asc';

        // Column Filters (passed as JSON)
        $colFilters = isset($_POST['colFilters']) ? json_decode($_POST['colFilters'], true) : [];
        
        // 1. Identify Flagged Fields
        $processFields = $config->getFields('Base_processos_schema');
        $recordFields = $config->getFields('Base_registros_schema');
        
        $flaggedFields = [];
        
        foreach($processFields as $f) {
            if(($f['show_reminder'] ?? false) == true && (!isset($f['deleted']) || !$f['deleted'])) {
                $f['source'] = 'process';
                $flaggedFields[] = $f;
            }
        }

        // Consolidated Display Columns
        $displayColumns = [
            ['key'=>'Ultima_Alteracao', 'label'=>'Ultima Atualização', 'source'=>'process'],
            ['key'=>'Data_Lembrete', 'label'=>'Data Lembrete', 'source'=>'process']
        ];
        foreach($flaggedFields as $f) {
            $displayColumns[] = $f;
        }

        // Apply Custom Order if provided
        $columnOrder = isset($_POST['columnOrder']) ? json_decode($_POST['columnOrder'], true) : [];
        if (!empty($columnOrder) && is_array($columnOrder)) {
            $orderedCols = [];
            // Map existing columns by key
            $colMap = [];
            foreach($displayColumns as $c) $colMap[$c['key']] = $c;
            
            // Add in order
            foreach($columnOrder as $k) {
                if(isset($colMap[$k])) {
                    $orderedCols[] = $colMap[$k];
                    unset($colMap[$k]);
                }
            }
            // Add remaining (new ones or those not in order list)
            foreach($colMap as $c) {
                $orderedCols[] = $c;
            }
            $displayColumns = $orderedCols;
        }
        foreach($recordFields as $f) {
            if(($f['show_reminder'] ?? false) == true && (!isset($f['deleted']) || !$f['deleted'])) {
                $f['source'] = 'record';
                $flaggedFields[] = $f;
            }
        }
        
        // 2. Fetch Processes
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $targetFiles = $db->getProcessFiles($years, $months);
        
        // Main Filters
        $filters = [];
        
        // Date Logic for Data_Lembrete
        $checkLembrete = function($row) use ($fLembreteIni, $fLembreteFim) {
            if (!$fLembreteIni && !$fLembreteFim) return true;
            $val = get_value_ci($row, 'Data_Lembrete');
            if (!$val) return false;
            
            // Format can be d/m/Y H:i or d/m/Y or Y-m-dTH:i
            // Input is Y-m-d
            $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
            if(!$dt) $dt = DateTime::createFromFormat('d/m/Y', $val);
            if(!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
            if(!$dt) return false;
            
            if ($fLembreteIni) {
                $di = DateTime::createFromFormat('Y-m-d', $fLembreteIni);
                $di->setTime(0,0,0);
                if ($dt < $di) return false;
            }
            if ($fLembreteFim) {
                $df = DateTime::createFromFormat('Y-m-d', $fLembreteFim);
                $df->setTime(23,59,59);
                if ($dt > $df) return false;
            }
            return true;
        };
        
        if ($fBuscaGlobal) {
             $filters['global'] = $fBuscaGlobal;
        }
        
        // Combine Date Check with Global
        $filters['callback'] = function($row) use ($checkLembrete) {
            if (!$checkLembrete($row)) return false;
            
            // Filter Empty Fields
            $ua = get_value_ci($row, 'Ultima_Alteracao');
            $dl = get_value_ci($row, 'Data_Lembrete');
            if (trim((string)$ua) === '' || trim((string)$dl) === '') return false;

            return true;
        };

        // Fetch All Matches (pagination done later to handle record merging)
        $res = $db->select($targetFiles, $filters, 1, 100000, $sortCol, ($sortDir === 'desc')); 
        $rows = $res['data'];
        
        // 3. Enrich with Records if needed
        $needsRecords = false;
        foreach($flaggedFields as $f) { if($f['source'] == 'record') $needsRecords = true; }
        
        $recordMap = [];
        if ($needsRecords && !empty($rows)) {
            // Load Records (Optimize: Load only for found ports?)
            $ports = array_column($rows, 'Numero_Portabilidade');
            if(!empty($ports)) {
                $allRecords = $db->readJSON($db->getPath('Base_registros_dados.json'));
                // Group by Port, sort by date desc
                foreach($allRecords as $rec) {
                    $p = get_value_ci($rec, 'PORTABILIDADE');
                    if(in_array($p, $ports)) {
                        $currDate = get_value_ci($rec, 'DATA'); // d/m/Y H:i
                        
                        if(!isset($recordMap[$p])) {
                            $recordMap[$p] = $rec;
                        } else {
                            // Compare
                            $oldDate = get_value_ci($recordMap[$p], 'DATA');
                            $dtOld = DateTime::createFromFormat('d/m/Y H:i', $oldDate);
                            $dtNew = DateTime::createFromFormat('d/m/Y H:i', $currDate);
                            if ($dtNew > $dtOld) {
                                $recordMap[$p] = $rec;
                            }
                        }
                    }
                }
            }
        }
        
        // 4. Apply Column Filters (In-Memory)
        if (!empty($colFilters)) {
            $rows = array_filter($rows, function($row) use ($colFilters, $recordMap, $flaggedFields) {
                foreach ($colFilters as $key => $filterVal) {
                    if (trim((string)$filterVal) === '') continue;
                    
                    // Determine where value comes from
                    $val = '';
                    $source = 'process';
                    foreach($flaggedFields as $ff) { if($ff['key'] == $key) { $source = $ff['source']; break; } }
                    
                    if ($key == 'Ultima_Alteracao' || $key == 'Data_Lembrete') $val = get_value_ci($row, $key);
                    elseif ($source == 'process') {
                        $val = get_value_ci($row, $key);
                    } else {
                        $port = get_value_ci($row, 'Numero_Portabilidade');
                        $rec = $recordMap[$port] ?? [];
                        $val = get_value_ci($rec, $key);
                    }
                    
                    if (stripos((string)$val, $filterVal) === false) return false;
                }
                return true;
            });
        }
        
        // 5. Pagination
        $total = count($rows);
        $limit = 20;
        $pages = ceil($total / $limit);
        if ($pPagina > $pages && $pages > 0) $pPagina = $pages;
        if ($pPagina < 1) $pPagina = 1;
        $offset = ($pPagina - 1) * $limit;
        $rows = array_slice($rows, $offset, $limit);
        
        // 6. Render
        ob_start();
        
        // Header
        echo '<thead><tr>';
        
        // Filter Helper (Simplified)
        $renderFilter = function($key, $label) use ($colFilters) {
             $val = isset($colFilters[$key]) ? htmlspecialchars($colFilters[$key]) : '';
             return '<input class="form-control form-control-sm" value="'.$val.'" onkeyup="filterLembretesCol(this, \''.htmlspecialchars($key).'\')" placeholder="Filtro...">';
        };

        foreach($displayColumns as $f) {
            $key = $f['key'];
            $lbl = ($key == 'Numero_Portabilidade') ? 'Portabilidade' : $f['label'];
            
            $icon = '<i class="fas fa-sort text-muted ms-1" style="font-size:0.8em; opacity:0.3"></i>';
            if ($sortCol === $key) {
                $icon = ($sortDir === 'asc') 
                    ? '<i class="fas fa-sort-up text-dark ms-1"></i>' 
                    : '<i class="fas fa-sort-down text-dark ms-1"></i>';
            }

            echo '<th data-key="' . htmlspecialchars($key) . '" onclick="sortLembretes(\''.htmlspecialchars($key).'\')" style="cursor:pointer">' . htmlspecialchars($lbl) . $icon . '</th>';
        }
        echo '<th>Ações <button class="btn btn-sm btn-light ms-2" onclick="clearLembretesFilters()" title="Limpar Filtros e Restaurar"><i class="fas fa-sync-alt"></i></button></th>';
        echo '</tr>';
        
        // Filters Row
        echo '<tr class="bg-light">';
        foreach($displayColumns as $f) {
            echo '<td>' . $renderFilter($f['key'], $f['label']) . '</td>';
        }
        echo '<td></td>';
        echo '</tr>';
        echo '</thead><tbody>';
        
        foreach($rows as $proc) {
            $port = get_value_ci($proc, 'Numero_Portabilidade');
            $rec = $recordMap[$port] ?? [];
            
            // Format Date Lembrete
            $dlVal = get_value_ci($proc, 'Data_Lembrete');
            $dtDL = null;
            if ($dlVal) {
                // Prioritize Brazilian format
                $dtDL = DateTime::createFromFormat('d/m/Y H:i:s', $dlVal);
                if (!$dtDL) $dtDL = DateTime::createFromFormat('d/m/Y H:i', $dlVal);
                
                // Fallback to standard parsing if needed
                if (!$dtDL) {
                    try {
                        $dtDL = new DateTime($dlVal);
                    } catch(Exception $e) { $dtDL = null; }
                }
            }
            $dlDisplay = $dtDL ? $dtDL->format('d/m/Y H:i:s') : $dlVal;
            
            // Bell Logic
            $bell = '';
            if ($dtDL && $dtDL < new DateTime()) {
                $bell = ' <i class="fas fa-bell text-danger fa-beat-fade ms-2" title="Lembrete Vencido"></i>';
            }

            echo '<tr>';
            
            foreach($displayColumns as $f) {
                $key = $f['key'];
                if ($key == 'Ultima_Alteracao') {
                    echo '<td>' . htmlspecialchars(get_value_ci($proc, 'Ultima_Alteracao') ?: '') . '</td>';
                } elseif ($key == 'Data_Lembrete') {
                    echo '<td>' . htmlspecialchars($dlDisplay) . $bell . '</td>';
                } else {
                    $val = '';
                    if ($f['source'] == 'process') $val = get_value_ci($proc, $f['key']);
                    else $val = get_value_ci($rec, $f['key']);
                    echo '<td>' . htmlspecialchars($val) . '</td>';
                }
            }
            
            echo '<td><a href="javascript:void(0)" onclick="loadProcess(\'' . htmlspecialchars($port) . '\')" class="btn btn-sm btn-outline-dark border-0"><i class="fas fa-folder-open fa-lg text-warning"></i> Abrir</a></td>';
            echo '</tr>';
        }
        
        if(empty($rows)) echo '<tr><td colspan="100" class="text-center py-4 text-muted">Nenhum lembrete encontrado.</td></tr>';
        
        echo '</tbody>';
        $html = ob_get_clean();
        
        $paginationHtml = '';
        if ($pages > 1) {
             $paginationHtml .= '<ul class="pagination justify-content-center">';
             if($pPagina > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterLembretes(null, '.($pPagina-1).')">Anterior</a></li>';
             $paginationHtml .= '<li class="page-item disabled"><a class="page-link">'.$pPagina.' / '.$pages.'</a></li>';
             if($pPagina < $pages) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterLembretes(null, '.($pPagina+1).')">Próxima</a></li>';
             $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml]);
        exit;
    }

    if ($act == 'ajax_render_credit_table') {
        $cfBusca = $_POST['cfBusca'] ?? '';
        $cpPagina = $_POST['cpPagina'] ?? 1;
        $cfDataIni = $_POST['cfDataIni'] ?? '';
        $cfDataFim = $_POST['cfDataFim'] ?? '';

        $cFilters = [];
        if ($cfBusca) $cFilters['global'] = $cfBusca;
        
        if ($cfDataIni || $cfDataFim) {
            $cFilters['callback'] = function($row) use ($cfDataIni, $cfDataFim) {
                $d = get_value_ci($row, 'DATA_DEPOSITO');
                if (!$d) return false;
                $dt = DateTime::createFromFormat('d/m/Y', $d);
                if (!$dt) return false;
                
                if ($cfDataIni) {
                    $di = DateTime::createFromFormat('Y-m-d', $cfDataIni);
                    if ($dt < $di) return false;
                }
                if ($cfDataFim) {
                    $df = DateTime::createFromFormat('Y-m-d', $cfDataFim);
                    if ($dt > $df) return false;
                }
                return true;
            };
        }
 
        $cRes = $db->select('Identificacao_cred.json', $cFilters, $cpPagina, 50, 'DATA_DEPOSITO', true);
        $creditos = $cRes['data'];

        ob_start();
        foreach($creditos as $c) {
            $port = get_value_ci($c, 'PORTABILIDADE');
            echo '<tr>';
            echo '<td><input type="checkbox" class="credit-checkbox" value="' . htmlspecialchars($port ?: '') . '"></td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'STATUS') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'NUMERO_DEPOSITO') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'DATA_DEPOSITO') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'VALOR_DEPOSITO_PRINCIPAL') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars($port ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'CERTIFICADO') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'CPF') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'AG') ?: '') . '</td>';
            echo '<td>
                <button class="btn btn-sm btn-link text-primary p-0 me-2" onclick=\'openCreditModal(' . json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ')\' title="Editar"><i class="fas fa-pen"></i></button>
                <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteCredit(\'' . htmlspecialchars($port) . '\')" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>';
            echo '</tr>';
        }
        if(empty($creditos)) echo '<tr><td colspan="9" class="text-center py-3">Nenhum registro encontrado.</td></tr>';
        $html = ob_get_clean();

        $paginationHtml = '';
        if ($cRes['pages'] > 1) {
            $paginationHtml .= '<ul class="pagination justify-content-center pagination-sm">';
            if($cRes['page'] > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterCredits(event, '.($cRes['page']-1).')">Anterior</a></li>';
            $paginationHtml .= '<li class="page-item disabled"><a class="page-link">'.$cRes['page'].' / '.$cRes['pages'].'</a></li>';
            if($cRes['page'] < $cRes['pages']) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterCredits(event, '.($cRes['page']+1).')">Próxima</a></li>';
            $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml, 'count' => $cRes['total']]);
        exit;
    }

    if ($act == 'ajax_get_process_full') {
        $port = $_POST['port'] ?? '';
        
        $process = null;
        $file = $indexer->get($port);
        
        if ($file) {
            $res = $db->select($file, ['Numero_Portabilidade' => $port], 1, 1);
            if (!empty($res['data'])) $process = $res['data'][0];
        }

        // Fallback: If not in index, search in current selection
        if (!$process) {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $files = $db->getProcessFiles($years, $months);
             
             $foundFile = $db->findFileForRecord($files, 'Numero_Portabilidade', $port);
             if ($foundFile) {
                 $res = $db->select($foundFile, ['Numero_Portabilidade' => $port], 1, 1);
                 if (!empty($res['data'])) {
                     $process = $res['data'][0];
                     // Update Index
                     $indexer->set($port, $foundFile);
                 }
             }
        }

        $client = null; $agency = null; $credit = null;
        
        if ($process) {
            $client = $db->find('Base_clientes.json', 'CPF', get_value_ci($process, 'CPF') ?: '');
            $agency = $db->find('Base_agencias.json', 'AG', get_value_ci($process, 'AG') ?: '');
        }
        $credit = $db->find('Identificacao_cred.json', 'PORTABILIDADE', $port);
        
        $registrosHistory = [];
        if (!file_exists($db->getPath('Base_registros_dados.json'))) {
             $db->writeJSON($db->getPath('Base_registros_dados.json'), []);
        }
        $registrosHistory = $db->findMany('Base_registros_dados.json', 'PORTABILIDADE', [$port]);
        $registrosHistory = array_reverse($registrosHistory);

        $emailHistory = $templates->getHistory($port);

        $lockInfo = $lockManager->checkLock($port, $_SESSION['nome_completo']);

        ob_clean(); header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok', 
            'process' => $process, 
            'client' => $client, 
            'agency' => $agency,
            'credit' => $credit,
            'registros_history' => $registrosHistory,
            'email_history' => $emailHistory,
            'lock' => $lockInfo
        ]);
        exit;
    }

    if ($act == 'ajax_confirm_upload') {
        if (isset($_SESSION['upload_preview']) && !empty($_SESSION['upload_preview'])) {
            $data = $_SESSION['upload_preview'];
            $base = $_SESSION['upload_preview_base'] ?? 'Identificacao_cred.json';
            
            // Clear session to free memory and state
            unset($_SESSION['upload_preview']);
            unset($_SESSION['upload_preview_base']);
            
            session_write_close();
            
            $cleanData = [];
            foreach($data as $row) {
                if (isset($row['DATA_ERROR'])) continue;
                unset($row['DATA_ERROR']);
                $cleanData[] = $row;
            }
            
            try {
                $res = $db->importExcelData($base, $cleanData);
                ob_clean(); header('Content-Type: application/json');
                if ($res) {
                    echo json_encode(['status'=>'ok', 'message'=>"Base ($base) atualizada com sucesso! (Inseridos: {$res['inserted']}, Atualizados: {$res['updated']})"]);
                } else {
                    echo json_encode(['status'=>'error', 'message'=>"Erro: Falha ao atualizar a base de dados."]);
                }
            } catch (Exception $e) {
                ob_clean(); header('Content-Type: application/json');
                echo json_encode(['status'=>'error', 'message'=>"Erro Crítico: " . $e->getMessage()]);
            }
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>"Erro: Sessão de upload expirada."]);
        }
        exit;
    }

    if ($act == 'ajax_cancel_upload') {
        unset($_SESSION['upload_preview']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if ($act == 'ajax_paste_data') {
        $text = $_POST['paste_content'] ?? '';
        $base = $_POST['base'] ?? 'Identificacao_cred.json';

        if ($text) {
            // Ensure UTF-8
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            }

            $rows = [];
            $lines = explode("\n", $text);
            $delimiter = "\t"; 
            
            if (!empty($lines[0])) {
                if (strpos($lines[0], "\t") !== false) $delimiter = "\t";
                elseif (strpos($lines[0], ";") !== false) $delimiter = ";";
                elseif (strpos($lines[0], ",") !== false) $delimiter = ",";
            }
            
            $confFields = $config->getFields($base);
            $headers = [];
            foreach($confFields as $f) {
                if(isset($f['deleted']) && $f['deleted']) continue;
                if(isset($f['type']) && $f['type'] === 'title') continue;
                $headers[] = $f['key'];
            }
            
            // Fallbacks if headers empty
            if (empty($headers)) {
                if (stripos($base, 'client') !== false) {
                    $headers = ['Nome', 'CPF'];
                } elseif (stripos($base, 'agenc') !== false) {
                    $headers = ['AG', 'UF', 'SR', 'Nome SR', 'Filial', 'E-mail AG', 'E-mails SR', 'E-mails Filial', 'E-mail Gerente'];
                } else {
                    $headers = ['Status', 'Número Depósito', 'Data Depósito', 'Valor Depósito Principal', 'Texto Pagamento', 'Portabilidade', 'Certificado', 'Status 2', 'CPF', 'AG'];
                }
            }

            $isHeader = true;
            $headerMap = [];
            
            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line, $delimiter);
                $cols = array_map('trim', $cols);

                if ($isHeader) {
                    $isHeader = false;
                    $matches = 0;
                    $tempMap = [];
                    foreach ($cols as $idx => $colVal) {
                        $matchedKey = null;
                        foreach ($confFields as $f) {
                             if (isset($f['type']) && $f['type'] === 'title') continue;
                             $key = $f['key'];
                             $lbl = isset($f['label']) ? $f['label'] : $key;
                             $valUpper = mb_strtoupper($colVal, 'UTF-8');
                             
                             if ($valUpper === mb_strtoupper($key, 'UTF-8') || $valUpper === mb_strtoupper($lbl, 'UTF-8')) {
                                 $matchedKey = $key;
                                 break;
                             }
                        }
                        
                        if (!$matchedKey) {
                            foreach ($headers as $h) {
                                if (mb_strtoupper($colVal, 'UTF-8') === mb_strtoupper($h, 'UTF-8')) {
                                    $matchedKey = $h;
                                    break;
                                }
                            }
                        }

                        if ($matchedKey) {
                            $matches++;
                            $tempMap[$idx] = $matchedKey; 
                        }
                    }
                    
                    if ($matches >= 2 || ($matches > 0 && count($cols) <= 3)) {
                        $headerMap = $tempMap;
                        continue; 
                    }
                }
                $rows[] = $cols;
            }
            
            $mappedData = [];
            foreach ($rows as $cols) {
                $newRow = [];
                if (!empty($headerMap)) {
                    foreach ($headers as $h) {
                        $foundIdx = array_search($h, $headerMap);
                        if ($foundIdx !== false && isset($cols[$foundIdx])) {
                            $newRow[$h] = $cols[$foundIdx];
                        } else {
                            $newRow[$h] = '';
                        }
                    }
                } else {
                    foreach($headers as $i => $h) {
                        $newRow[$h] = isset($cols[$i]) ? $cols[$i] : '';
                    }
                }
                
                if (stripos($base, 'client') !== false) {
                    if (empty(get_value_ci($newRow, 'CPF'))) continue;
                } elseif (stripos($base, 'agenc') !== false) {
                    if (empty(get_value_ci($newRow, 'AG'))) continue;
                } elseif (stripos($base, 'Processos') !== false || stripos($base, 'Base_processos') !== false) {
                    if (empty(get_value_ci($newRow, 'Numero_Portabilidade'))) continue;
                } else {
                    if (empty(get_value_ci($newRow, 'PORTABILIDADE'))) continue;
                }
                $mappedData[] = $newRow;
            }
            
            $validatedData = [];
            foreach ($mappedData as $row) {
                foreach ($confFields as $f) {
                     $val = get_value_ci($row, $f['key']);
                     if (isset($f['type']) && $f['type'] == 'date' && $val !== '') {
                         $validDate = normalizeDate($val);
                         if ($validDate === false) {
                             $row['DATA_ERROR'] = true;
                         } else {
                             $row[$f['key']] = $validDate;
                         }
                     }
                }
                $coreDates = ['DATA_DEPOSITO', 'DATA'];
                foreach($coreDates as $cd) {
                    $val = get_value_ci($row, $cd);
                    if ($val !== '') {
                         $validDate = normalizeDate($val);
                         if ($validDate === false) {
                             $row['DATA_ERROR'] = true;
                         } else {
                             $row[$cd] = $validDate;
                         }
                    }
                }
                $validatedData[] = $row;
            }
            
            if (empty($validatedData)) {
                ob_clean(); header('Content-Type: application/json');
                echo json_encode(['status'=>'error', 'message'=>"Erro: Nenhum dado válido identificado."]);
            } else {
                $_SESSION['upload_preview'] = $validatedData;
                $_SESSION['upload_preview_base'] = $base;
                
                // Build HTML Table for Preview
                $previewFields = $confFields;
                $previewHeaders = [];
                foreach($previewFields as $f) {
                    if(!isset($f['deleted']) || !$f['deleted']) {
                        if (isset($f['type']) && $f['type'] === 'title') continue;
                        $previewHeaders[] = $f['key'];
                    }
                }
                if(empty($previewHeaders)) $previewHeaders = $headers; // Fallback

                ob_start();
                echo '<table class="table table-sm table-bordered table-striped small"><thead><tr class="bg-light"><th>#</th>';
                foreach($previewHeaders as $h) {
                    $lbl = $h;
                    foreach($previewFields as $f) { if($f['key'] == $h) { $lbl = $f['label']; break; } }
                    echo '<th>' . htmlspecialchars($lbl) . '</th>';
                }
                echo '<th>Validação</th></tr></thead><tbody>';
                foreach($validatedData as $idx => $row) {
                    $err = isset($row['DATA_ERROR']) ? 'table-danger' : '';
                    echo '<tr class="'.$err.'"><td>'.($idx + 1).'</td>';
                    foreach($previewHeaders as $h) {
                        echo '<td>' . htmlspecialchars($row[$h] ?? '') . '</td>';
                    }
                    echo '<td>';
                    if(isset($row['DATA_ERROR'])) echo '<span class="badge bg-danger">Data Inválida</span>';
                    else echo '<span class="badge bg-success">OK</span>';
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
                $html = ob_get_clean();

                ob_clean(); header('Content-Type: application/json');
                echo json_encode(['status'=>'ok', 'html'=>$html]);
            }
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Conteúdo vazio.']);
        }
        exit;
    }

    // --- GENERIC BASE HANDLERS ---

    if ($act == 'ajax_get_base_schema') {
        $base = $_POST['base'];
        $fields = $config->getFields($base);
        
        $activeFields = [];
        foreach($fields as $f) {
            if (!isset($f['deleted']) || !$f['deleted']) {
                $activeFields[] = $f;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'fields'=>$activeFields]);
        exit;
    }

    if ($act == 'ajax_render_base_table') {
        $base = $_POST['base']; // e.g., 'Base_clientes.json'
        $fBusca = $_POST['cfBusca'] ?? '';
        $page = $_POST['cpPagina'] ?? 1;
        $sortColReq = $_POST['sortCol'] ?? null;
        $sortDirReq = $_POST['sortDir'] ?? null;
        
        $filters = [];
        if ($fBusca) $filters['global'] = $fBusca;

        if ($base === 'Processos') {
            if (!empty($_POST['fStatus'])) $filters['STATUS'] = $_POST['fStatus'];
            if (!empty($_POST['fAtendente'])) $filters['Nome_atendente'] = $_POST['fAtendente'];
        }
        
        // Date filtering if applicable
        $fDataIni = $_POST['cfDataIni'] ?? '';
        $fDataFim = $_POST['cfDataFim'] ?? '';
        
        if ($fDataIni || $fDataFim) {
             $dateCol = null;

             if (stripos($base, 'cred') !== false) $dateCol = 'DATA_DEPOSITO';
             elseif ($base === 'Processos' || stripos($base, 'processo') !== false) $dateCol = 'DATA';
             
             if (!$dateCol) {
                 $baseFields = $config->getFields($base);
                 foreach ($baseFields as $f) {
                     if (isset($f['type']) && $f['type'] == 'date') {
                         $dateCol = $f['key'];
                         break;
                     }
                 }
             }
             
             if ($dateCol) {
                 $di = $fDataIni ? DateTime::createFromFormat('!Y-m-d', $fDataIni) : null;
                 $df = $fDataFim ? DateTime::createFromFormat('!Y-m-d', $fDataFim) : null;

                 $filters['callback'] = function($row) use ($di, $df, $dateCol) {
                    $d = get_value_ci($row, $dateCol);
                    if (!$d) return false;
                    $dt = DateTime::createFromFormat('!d/m/Y', $d);
                    if (!$dt) return false;
                    
                    if ($di && $dt < $di) return false;
                    if ($df && $dt > $df) return false;
                    return true;
                 };
             }
        }

        // Determine sort column
        $headers = $db->getHeaders($base);
        $sortCol = $headers[0] ?? null;
        $desc = true;

        if (stripos($base, 'cred') !== false) $sortCol = 'DATA_DEPOSITO';
        if (stripos($base, 'client') !== false) {
            $sortCol = 'Nome'; 
            $desc = false; 
        }

        if ($sortColReq) {
            $sortCol = $sortColReq;
            $desc = ($sortDirReq === 'desc');
        }

        if ($base === 'Processos') {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $targetFiles = $db->getProcessFiles($years, $months);
             // Default if not requested
             if (!$sortColReq) { $sortCol = 'DATA'; $desc = true; }
             
             $res = $db->select($targetFiles, $filters, $page, 50, $sortCol, $desc);
        } else {
             $res = $db->select($base, $filters, $page, 50, $sortCol, $desc);
        }

        $rows = $res['data'];
        
        // Fix: Inject Client Names if viewing Processos base
        if ($base === 'Processos') {
             $cpfs = [];
             foreach($rows as $r) {
                 $val = get_value_ci($r, 'CPF');
                 if ($val) $cpfs[] = $val;
             }
             
             if (!empty($cpfs)) {
                 $cpfs = array_unique($cpfs);
                 $clients = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
                 $clientMap = [];
                 foreach ($clients as $c) {
                     $cKey = get_value_ci($c, 'CPF');
                     if ($cKey) $clientMap[$cKey] = get_value_ci($c, 'Nome');
                 }
                 
                 foreach ($rows as &$r) {
                     $cKey = get_value_ci($r, 'CPF');
                     if ($cKey && isset($clientMap[$cKey])) {
                         $r['Nome'] = $clientMap[$cKey];
                     }
                 }
                 unset($r);
             }
        }

        $allFields = $config->getFields($base);
        $confFields = [];
        
        if ($base === 'Processos') {
             $wantedKeys = ['DATA', 'Nome', 'CPF', 'Numero_Portabilidade', 'Ocorrencia', 'STATUS', 'Nome_atendente'];
             $fieldMap = [];
             foreach ($allFields as $f) {
                 $fieldMap[strtoupper($f['key'])] = $f;
             }
             
             foreach ($wantedKeys as $k) {
                 $uk = strtoupper($k);
                 if (isset($fieldMap[$uk])) {
                     $confFields[] = $fieldMap[$uk];
                 } else {
                     $confFields[] = ['key' => $k, 'label' => $k, 'type' => 'text']; 
                 }
             }
        } else {
            foreach($allFields as $f) {
                if(!isset($f['deleted']) || !$f['deleted']) {
                    if (($f['type'] ?? '') === 'title') continue;
                    $confFields[] = $f;
                }
            }
        }
        
        if (empty($confFields)) {
            foreach($headers as $h) $confFields[] = ['key'=>$h, 'label'=>$h];
        }

        // Determine PK
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = 'PORTABILIDADE';
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = 'Numero_Portabilidade';

        ob_start();
        echo '<thead><tr>';
        echo '<th><input type="checkbox" onchange="toggleSelectAll(this)"></th>';
        foreach($confFields as $f) {
            $colKey = $f['key'];
            $icon = '<i class="fas fa-sort text-muted ms-1" style="font-size:0.8em; opacity:0.5"></i>';
            if ($sortCol === $colKey) {
                $icon = ($desc) ? '<i class="fas fa-sort-down text-dark ms-1"></i>' : '<i class="fas fa-sort-up text-dark ms-1"></i>';
            }
            echo '<th class="sortable-header" onclick="setBaseSort(\''.htmlspecialchars($colKey).'\')" style="cursor:pointer">' . htmlspecialchars($f['label']) . ' ' . $icon . '</th>';
        }
        echo '<th>Ações</th>';
        echo '</tr></thead><tbody>';

        foreach($rows as $r) {
            echo '<tr>';
            $idVal = get_value_ci($r, $pk);
            echo '<td><input type="checkbox" class="base-checkbox" value="' . htmlspecialchars($idVal) . '"></td>';
            
            foreach($confFields as $f) {
                 $val = get_value_ci($r, $f['key']);
                 echo '<td>' . htmlspecialchars($val) . '</td>';
            }
            
            echo '<td>
                <button class="btn btn-sm btn-link text-primary p-0 me-2" onclick=\'openBaseModal(' . json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ', this)\' title="Editar"><i class="fas fa-pen"></i></button>
                <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteBaseRecord(\'' . htmlspecialchars($idVal) . '\')" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>';
            echo '</tr>';
        }
        
        if(empty($rows)) echo '<tr><td colspan="10" class="text-center py-3">Nenhum registro encontrado.</td></tr>';
        
        echo '</tbody>';
        $html = ob_get_clean();
        
        $paginationHtml = '';
        if ($res['pages'] > 1) {
            $paginationHtml .= '<ul class="pagination justify-content-center pagination-sm">';
            if($res['page'] > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="renderBaseTable('.($res['page']-1).')">Anterior</a></li>';
            $paginationHtml .= '<li class="page-item disabled"><a class="page-link">'.$res['page'].' / '.$res['pages'].'</a></li>';
            if($res['page'] < $res['pages']) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="renderBaseTable('.($res['page']+1).')">Próxima</a></li>';
            $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml, 'count' => $res['total']]);
        exit;
    }

    if ($act == 'ajax_save_base_record') {
        $base = $_POST['base'];
        $originalId = $_POST['original_id'] ?? '';
        
        $confFields = $config->getFields($base);
        $data = [];
        $errors = [];
        foreach($confFields as $f) {
            if (($f['type'] ?? '') === 'title') continue;
            $key = $f['key'];
            if(isset($_POST[$key])) {
                $val = $_POST[$key];
                if ($f['type'] == 'date' && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $val);
                    if ($dt && $dt->format('Y-m-d') === $val) {
                        $val = $dt->format('d/m/Y');
                    }
                }
                $data[$key] = $val;
                
                // Validation
                if ($f['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                    $errors[] = "O campo " . ($f['label'] ?: $key) . " deve conter apenas números.";
                }

                if ($f['type'] === 'custom') {
                    if (($f['custom_case'] ?? '') === 'upper') $val = mb_strtoupper($val, 'UTF-8');
                    if (($f['custom_case'] ?? '') === 'lower') $val = mb_strtolower($val, 'UTF-8');
                    $data[$key] = $val;
                }

                if (isset($f['required']) && $f['required'] && (!isset($f['deleted']) || !$f['deleted'])) {
                    if (trim((string)$val) === '') {
                        $errors[] = "Campo obrigatório não preenchido: " . ($f['label'] ?: $key);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
             ob_clean(); header('Content-Type: application/json');
             echo json_encode(['status'=>'error', 'message'=>implode("<br>", $errors)]);
             exit;
        }
        
        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = 'PORTABILIDADE';
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = 'Numero_Portabilidade';

        $newId = $data[$pk] ?? '';
        if (!$newId) {
             ob_clean(); header('Content-Type: application/json');
             echo json_encode(['status'=>'error', 'message'=>'Identificador ('.$pk.') é obrigatório.']);
             exit;
        }

        if ($base === 'Processos') {
             $data['Ultima_Alteracao'] = date('d/m/Y H:i:s');
             // Allow overriding Name if provided, else default to session
             if (!isset($data['Nome_atendente']) || !$data['Nome_atendente']) {
                 $data['Nome_atendente'] = $_SESSION['nome_completo'];
             }
             
             $dt = DateTime::createFromFormat('d/m/Y', $data['DATA'] ?? '');
             if (!$dt) $dt = new DateTime(); 
             $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));
             
             if ($originalId) {
                 $oldFile = $indexer->get($originalId);
                 if (!$oldFile) {
                     $years = $_SESSION['selected_years'] ?? [date('Y')];
                     $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                     $files = $db->getProcessFiles($years, $months);
                     
                     $oldFile = $db->findFileForRecord($files, $pk, $originalId);
                 }
                 
                 if ($oldFile) {
                     if ($oldFile !== $targetFile) {
                         // Preserve old data
                         $oldData = $db->find($oldFile, $pk, $originalId);
                         $fullData = $oldData ? array_merge($oldData, $data) : $data;

                         $db->delete($oldFile, $pk, $originalId);
                         $db->insert($targetFile, $fullData);

                         // Update Indexer
                         if ($originalId != $newId) {
                             // Preserve Related History and Records
                             foreach (['Base_registros.json', 'Base_registros_dados.json'] as $relFile) {
                                 $relPath = $db->getPath($relFile);
                                 if (file_exists($relPath)) {
                                     $relData = $db->readJSON($relPath);
                                     $updatedRel = false;
                                     foreach ($relData as &$rItem) {
                                         $rPort = get_value_ci($rItem, 'PORTABILIDADE');
                                         if ($rPort == $originalId) {
                                             $rItem['PORTABILIDADE'] = $newId;
                                             $updatedRel = true;
                                         }
                                     }
                                     if ($updatedRel) $db->writeJSON($relPath, $relData);
                                 }
                             }
                             $indexer->delete($originalId);
                         }
                         $indexer->set($newId, $targetFile);

                         $msg = "Processo atualizado e movido para o período correto!";
                     } else {
                         $db->update($oldFile, $pk, $originalId, $data);
                         
                         // Update Indexer if ID changed
                         if ($originalId != $newId) {
                             // Preserve Related History and Records
                             foreach (['Base_registros.json', 'Base_registros_dados.json'] as $relFile) {
                                 $relPath = $db->getPath($relFile);
                                 if (file_exists($relPath)) {
                                     $relData = $db->readJSON($relPath);
                                     $updatedRel = false;
                                     foreach ($relData as &$rItem) {
                                         $rPort = get_value_ci($rItem, 'PORTABILIDADE');
                                         if ($rPort == $originalId) {
                                             $rItem['PORTABILIDADE'] = $newId;
                                             $updatedRel = true;
                                         }
                                     }
                                     if ($updatedRel) $db->writeJSON($relPath, $relData);
                                 }
                             }
                             $indexer->delete($originalId);
                             $indexer->set($newId, $oldFile);
                         }
                         $msg = "Processo atualizado!";
                     }
                     $res = true;
                 } else {
                     $res = false;
                     $msg = "Processo original não encontrado na seleção atual.";
                 }
             } else {
                 $res = $db->insert($targetFile, $data);
                 if ($res) $indexer->set($newId, $targetFile);
                 $msg = "Processo criado!";
             }
        } elseif ($originalId) {
             if ($newId != $originalId) {
                 if ($db->find($base, $pk, $newId)) {
                     ob_clean(); header('Content-Type: application/json');
                     echo json_encode(['status'=>'error', 'message'=>'Novo ID já existe.']);
                     exit;
                 }
             }
             $res = $db->update($base, $pk, $originalId, $data);
             $msg = "Registro atualizado!";
        } else {
             if ($db->find($base, $pk, $newId)) {
                 ob_clean(); header('Content-Type: application/json');
                 echo json_encode(['status'=>'error', 'message'=>'Registro já existe.']);
                 exit;
             }
             $res = $db->insert($base, $data);
             $msg = "Registro criado!";
        }

        ob_clean(); header('Content-Type: application/json');
        if($res) echo json_encode(['status'=>'ok', 'message'=>$msg]);
        else echo json_encode(['status'=>'error', 'message'=>'Erro ao salvar.']);
        exit;
    }

    if ($act == 'ajax_delete_base_record') {
        $base = $_POST['base'];
        $id = $_POST['id'];
        
        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = 'PORTABILIDADE';
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = 'Numero_Portabilidade';
        
        $res = false;
        if ($base === 'Processos') {
             $oldFile = $indexer->get($id);
             if (!$oldFile) {
                 $years = $_SESSION['selected_years'] ?? [date('Y')];
                 $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                 $files = $db->getProcessFiles($years, $months);
                 $oldFile = $db->findFileForRecord($files, $pk, $id);
             }

             if ($oldFile) {
                 $res = $db->delete($oldFile, $pk, $id);
                 if ($res) $indexer->delete($id);
             }
        } else {
             $res = $db->delete($base, $pk, $id);
        }

        if($res) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>'Excluído.']);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
        }
        exit;
    }
    
    if ($act == 'ajax_delete_base_bulk') {
        $base = $_POST['base'];
        $ids = $_POST['ids'] ?? [];
        
        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = 'PORTABILIDADE';
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = 'Numero_Portabilidade';

        if ($base === 'Processos') {
             $success = true;
             foreach ($ids as $id) {
                 $oldFile = $indexer->get($id);
                 if (!$oldFile) {
                    $years = $_SESSION['selected_years'] ?? [date('Y')];
                    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                    $files = $db->getProcessFiles($years, $months);
                    $oldFile = $db->findFileForRecord($files, $pk, $id);
                 }

                 if ($oldFile) {
                     if ($db->delete($oldFile, $pk, $id)) {
                         $indexer->delete($id);
                     } else {
                         $success = false;
                     }
                 }
             }
             // Assume success if loop finishes
             $res = $success;
        } else {
             $res = $db->deleteMany($base, $pk, $ids);
        }

        if($res) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>'Registros excluídos.']);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
        }
        exit;
    }

    if ($act == 'ajax_prepare_bulk_edit') {
        $base = $_POST['base'];
        $ids = $_POST['ids'] ?? [];

        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = 'PORTABILIDADE';
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = 'Numero_Portabilidade';

        $selectedRecords = [];
        if ($base === 'Processos') {
            $years = $_SESSION['selected_years'] ?? [date('Y')];
            $months = $_SESSION['selected_months'] ?? [(int)date('n')];
            $currentFiles = $db->getProcessFiles($years, $months);
            
            foreach ($ids as $id) {
                $file = $indexer->get($id);
                if (!$file) {
                    $file = $db->findFileForRecord($currentFiles, $pk, $id);
                }
                
                if ($file) {
                    $rec = $db->find($file, $pk, $id);
                    if ($rec) $selectedRecords[] = $rec;
                }
            }
        } else {
            $selectedRecords = $db->findMany($base, $pk, $ids);
        }
        
        $fields = $config->getFields($base);
        
        $responseFields = [];
        foreach ($fields as $f) {
            if (isset($f['deleted']) && $f['deleted']) continue;
            if (($f['type'] ?? '') === 'title') continue;
            
            $key = $f['key'];
            $firstVal = null;
            $isCommon = true;
            $first = true;
            
            if (empty($selectedRecords)) {
                $isCommon = false;
            } else {
                foreach ($selectedRecords as $r) {
                    $val = get_value_ci($r, $key);
                    if ($first) {
                        $firstVal = $val;
                        $first = false;
                    } else {
                        if ($val != $firstVal) {
                            $isCommon = false;
                            break;
                        }
                    }
                }
            }
            
            $f['value'] = $isCommon ? $firstVal : '';
            $f['is_common'] = $isCommon;
            $responseFields[] = $f;
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'fields'=>$responseFields]);
        exit;
    }

    if ($act == 'ajax_save_base_bulk') {
        $base = $_POST['base'];
        $ids = $_POST['ids'] ?? [];
        $data = [];
        
        // Parse data from POST. Since we are bulk editing, we only receive fields that were enabled.
        // We need to match with schema to ensure proper handling (dates, etc).
        $confFields = $config->getFields($base);
        foreach($confFields as $f) {
            $key = $f['key'];
            if(isset($_POST[$key])) {
                $val = $_POST[$key];
                if ($f['type'] == 'date' && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $val);
                    if ($dt && $dt->format('Y-m-d') === $val) {
                        $val = $dt->format('d/m/Y');
                    }
                }
                $data[$key] = $val;
            }
        }
        
        if (empty($data)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Nenhuma informação para atualizar.']);
            exit;
        }

        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = 'PORTABILIDADE';
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = 'Numero_Portabilidade';

        if ($base === 'Processos') {
            $data['Ultima_Alteracao'] = date('d/m/Y H:i:s');
            // Do NOT force overwrite Nome_atendente here to allow bulk assignment
            
            $targetFile = null;
            if (isset($data['DATA']) && !empty($data['DATA'])) {
                $dt = DateTime::createFromFormat('d/m/Y', $data['DATA']);
                if ($dt) {
                    $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));
                }
            }

            $years = $_SESSION['selected_years'] ?? [date('Y')];
            $months = $_SESSION['selected_months'] ?? [(int)date('n')];
            $currentFiles = $db->getProcessFiles($years, $months);

            foreach ($ids as $id) {
                $file = $indexer->get($id);
                if (!$file) {
                    $file = $db->findFileForRecord($currentFiles, $pk, $id);
                }
                
                if ($file) {
                     if ($targetFile && $file !== $targetFile) {
                         // Move Record
                         $oldData = $db->find($file, $pk, $id);
                         if ($oldData) {
                             $fullData = array_merge($oldData, $data);
                             $db->delete($file, $pk, $id);
                             $db->insert($targetFile, $fullData);
                             $indexer->set($id, $targetFile);
                         }
                     } else {
                         $db->update($file, $pk, $id, $data);
                     }
                }
            }
        } else {
            foreach ($ids as $id) {
                $db->update($base, $pk, $id, $data);
            }
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Registros atualizados com sucesso!']);
        exit;
    }

    if ($act == 'ajax_salvar_campo') {
        $file = $_POST['arquivo_base'];
        $oldKey = $_POST['old_key'] ?? '';
        $required = isset($_POST['required']) ? true : false;
        $showReminder = isset($_POST['show_reminder']) ? true : false;
        
        $key = trim($_POST['key']);
        $fieldData = [
            'key' => $key, 
            'label' => $_POST['label'], 
            'type' => $_POST['type'], 
            'options' => $_POST['options'] ?? '', 
            'required' => $required,
            'show_reminder' => $showReminder,
            'custom_mask' => $_POST['custom_mask'] ?? '',
            'custom_case' => $_POST['custom_case'] ?? '',
            'custom_allowed' => $_POST['custom_allowed'] ?? ''
        ];
        
        ob_clean(); header('Content-Type: application/json');
        
        if ($oldKey) {
            $config->updateField($file, $oldKey, $fieldData);
            echo json_encode(['status'=>'ok', 'message'=>'Campo atualizado com sucesso!']);
        } else {
            // Check for duplicates
            $existing = $config->getFields($file);
            $exists = false;
            $deletedKey = null;

            foreach ($existing as $f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                    $exists = true; 
                    if (isset($f['deleted']) && $f['deleted']) {
                         $deletedKey = $f['key'];
                    }
                    break;
                }
            }
            
            if ($exists) {
                if ($deletedKey) {
                    $config->updateField($file, $deletedKey, $fieldData);
                    $config->reactivateField($file, $deletedKey);
                    echo json_encode(['status'=>'ok', 'message'=>'Campo restaurado e atualizado com sucesso!']);
                } else {
                    echo json_encode(['status'=>'error', 'message'=>"Erro: O campo '$key' já existe!"]);
                }
            } else {
                $config->addField($file, $fieldData);
                echo json_encode(['status'=>'ok', 'message'=>'Campo adicionado com sucesso!']);
            }
        }
        exit;
    }

    if ($act == 'ajax_remover_campo') {
        $file = $_POST['arquivo_base'];
        $key = $_POST['key'];
        $config->removeField($file, $key);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Campo removido com sucesso!']);
        exit;
    }

    if ($act == 'ajax_salvar_template') {
        $templates->save($_POST['id_template'] ?? '', $_POST['titulo'], $_POST['corpo']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Modelo salvo com sucesso!']);
        exit;
    }

    if ($act == 'ajax_excluir_template') {
        $templates->delete($_POST['id_exclusao']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Modelo excluído!']);
        exit;
    }

    if ($act == 'ajax_excluir_processo') {
        $port = $_POST['id_exclusao'];
        if ($port) {
            $file = $indexer->get($port);
            if ($file) {
                $db->delete($file, 'Numero_Portabilidade', $port);
                $indexer->delete($port);
                $msg = "Processo excluído com sucesso.";
            } else {
                // Fallback (e.g. legacy check)
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $files = $db->getProcessFiles($years, $months);
                foreach ($files as $f) {
                    if ($db->delete($f, 'Numero_Portabilidade', $port)) {
                         // Found by brute force
                         break;
                    }
                }
                $msg = "Processo excluído (se encontrado).";
            }
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>$msg]);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Identificador inválido.']);
        }
        exit;
    }

    if ($act == 'ajax_render_config') {
        ob_start();
        ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-navy mb-0">Configurações de Campos</h3>
        </div>
        <div class="row mb-5">
            <?php foreach(['Base_processos_schema' => 'Processos', 'Base_registros_schema' => 'Campos de Registros', 'Identificacao_cred.json' => 'Identificação de Crédito'] as $file => $label): ?>
            <div class="col-md-3">
                <div class="card card-custom p-3 h-100">
                    <h5 class="text-navy"><?= $label ?> <small class="text-muted fs-6">(Arraste para ordenar)</small></h5>
                    <ul class="list-group list-group-flush mb-3 sortable-list" data-file="<?= $file ?>">
                        <?php foreach($config->getFields($file) as $f): 
                            if(isset($f['deleted']) && $f['deleted']) continue;
                            $lockedFields = ['Numero_Portabilidade', 'CPF', 'AG', 'Nome', 'STATUS', 'DATA', 'VALOR DA PORTABILIDADE', 'Nome_atendente', 'PORTABILIDADE']; 
                            $isLocked = ($file !== 'Base_registros_schema') && in_array($f['key'], $lockedFields);
                            $isTitle = ($f['type'] === 'title');
                            $liClass = $isTitle ? 'list-group-item-secondary fw-bold' : '';
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center <?= $liClass ?>" data-key="<?= htmlspecialchars($f['key']) ?>">
                            <div>
                                <i class="fas fa-grip-vertical text-muted me-2 handle"></i> 
                                <?= htmlspecialchars($f['label']) ?> 
                                <?php if(!$isTitle): ?>
                                    <small class="text-muted">(<?= htmlspecialchars($f['type']) ?>)</small> 
                                    <?php if($f['required'] ?? false): ?><span class="text-danger">*</span><?php endif; ?>
                                <?php else: ?>
                                    <small class="text-muted fst-italic">(Título/Seção)</small>
                                <?php endif; ?>
                                <?php if($f['show_reminder'] ?? false): ?>
                                    <span class="badge bg-warning text-dark ms-2" title="Exibido em Lembretes"><i class="fas fa-bell"></i></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-link text-info" onclick='editField(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "<?= htmlspecialchars($file) ?>")'><i class="fas fa-pen"></i></button>
                                <?php if(!$isLocked): ?>
                                <button class="btn btn-sm btn-link text-danger" onclick="removeField('<?= $file ?>', '<?= $f['key'] ?>')"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-link text-muted" disabled title="Campo Protegido"><i class="fas fa-lock"></i></button>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="addFieldModal('<?= $file ?>')">Add Campo</button>
                        <button class="btn btn-sm btn-outline-secondary flex-grow-1" onclick="addTitleModal('<?= $file ?>')">Add Título</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <h3 class="text-navy mb-4">Modelos de Textos</h3>
        <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 class="text-navy">Modelos Cadastrados</h5>
                <button class="btn btn-navy btn-sm" onclick="modalTemplate()">Novo Modelo</button>
            </div>
            <table class="table table-hover">
                <thead><tr><th>Título</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach($templates->getAll() as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['titulo']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-link text-info" onclick='editTemplate(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button>
                            <button class="btn btn-sm btn-link text-danger" onclick="confirmTemplateDelete('<?= htmlspecialchars($t['id']) ?>')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        $html = ob_get_clean();
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'html'=>$html]);
        exit;
    }
    
    exit;
    exit;
}

// LOGIN
if (isset($_POST['acao']) && $_POST['acao'] == 'login') {
    $user = trim($_POST['usuario']);
    if (!empty($user)) {
        $_SESSION['logado'] = true;
        $_SESSION['nome_completo'] = $user;
        header("Location: index.php");
        exit;
    } else {
        $erroLogin = "Informe seu nome.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['logado'])) {
    ?>


    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - SPF</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: linear-gradient(135deg, #FF4500, #FF8C00, #FFA500); height: 100vh; display: flex; align-items: center; justify-content: center; }
            .login-card { background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); width: 100%; max-width: 400px; text-align: center; }
            .btn-navy { background-color: #003366; color: white; border-radius: 20px; width: 100%; padding: 10px; }
            .btn-navy:hover { background-color: #002244; color: white; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h3 class="mb-4" style="color: #003366;">SPA Login</h3>
            <?php if(isset($erroLogin)) echo "<div class='alert alert-danger'>$erroLogin</div>"; ?>
            <form method="POST">
                <input type="hidden" name="acao" value="login">
                <div class="mb-3">
                    <input type="text" name="usuario" class="form-control" placeholder="Seu Nome Completo" required>
                </div>
                <button class="btn btn-navy">Entrar</button>
            </form>
        </div>
        <script>
            // Global listener to prevent leading whitespace in inputs
            document.addEventListener('input', function(e) {
                var target = e.target;
                if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
                    var type = target.type;
                    // Exclude non-text inputs
                    if (['checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'image', 'hidden', 'range', 'color'].indexOf(type) !== -1) {
                        return;
                    }
                    
                    var val = target.value;
                    if (val && val.length > 0 && /^\s/.test(val)) {
                        var start = target.selectionStart;
                        var end = target.selectionEnd;
                        var newVal = val.replace(/^\s+/, '');
                        
                        if (val !== newVal) {
                            target.value = newVal;
                            // Adjust cursor position
                            if (type !== 'email' && type !== 'number') { 
                                try {
                                    var diff = val.length - newVal.length;
                                    if (start >= diff) {
                                        target.setSelectionRange(start - diff, end - diff);
                                    } else {
                                        target.setSelectionRange(0, 0);
                                    }
                                } catch(err) {
                                    // Ignore errors for input types that don't support selection
                                }
                            }
                        }
                    }
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- UI HELPERS FOR NAVIGATION ---
$availableYears = [];
$baseDir = 'dados/Processos';
if (is_dir($baseDir)) {
    $dirs = scandir($baseDir);
    foreach ($dirs as $d) {
        if ($d != '.' && $d != '..' && is_dir($baseDir . '/' . $d) && is_numeric($d)) {
            // Remove future years
            if ((int)$d <= (int)date('Y')) {
                $availableYears[] = $d;
            }
        }
    }
}
// Ensure Current Year is always available option
$currentYear = date('Y');
if (!in_array($currentYear, $availableYears)) $availableYears[] = $currentYear;
rsort($availableYears);

$selYears = $_SESSION['selected_years'] ?? [$currentYear];
$selMonths = $_SESSION['selected_months'] ?? [(int)date('n')];

// DOWNLOAD CREDITO TEMPLATE
if (isset($_GET['acao']) && $_GET['acao'] == 'download_credito_template') {
    $filename = "Modelo_Importacao_Credito.csv";
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "Status;Número Depósito;Data Depósito;Valor Depósito Principal;Texto Pagamento;Portabilidade;Certificado;Status 2\n";
    exit;
}

// DOWNLOAD CREDITO FULL OR HEADERS
if (isset($_GET['acao']) && ($_GET['acao'] == 'download_base' || $_GET['acao'] == 'download_credito_full')) {
    $base = $_GET['base'] ?? 'Identificacao_cred.json';
    $filename = "Base_" . str_replace('.json', '', $base) . "_" . date('d-m-Y') . ".xls";
    
    $headers = $db->getHeaders($base);
    // Fallback if empty
    if (empty($headers)) {
        $confFields = $config->getFields($base);
        foreach($confFields as $f) {
            if (isset($f['type']) && $f['type'] === 'title') continue;
            $headers[] = $f['key'];
        }
    }
    
    if ($base === 'Processos') {
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $targetFiles = $db->getProcessFiles($years, $months);
        $res = $db->select($targetFiles, [], 1, 1000000, 'DATA', true);
        $rows = $res['data'];
    } else {
        $rows = $db->readJSON($db->getPath($base));
    }
    
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    
    echo '<?xml version="1.0"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    echo '<Styles><Style ss:ID="Header"><Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/><Interior ss:Color="#003366" ss:Pattern="Solid"/></Style></Styles>' . "\n";
    echo '<Worksheet ss:Name="Sheet1"><Table>' . "\n";
    
    echo '<Row>' . "\n";
    foreach($headers as $h) echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    
    foreach($rows as $row) {
        echo '<Row>' . "\n";
        foreach($headers as $h) {
            $val = get_value_ci($row, $h);
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($val) . '</Data></Cell>' . "\n";
        }
        echo '</Row>' . "\n";
    }
    
    echo '</Table></Worksheet></Workbook>';
    exit;
}

// EXPORT EXCEL (RESTAURADO COMPLETO)
if (isset($_GET['acao']) && $_GET['acao'] == 'exportar_excel') {
    $filename = "Relatorio_SPA_" . date('d-m-Y_His') . ".xls";
    $fAtendente = $_GET['fAtendente'] ?? '';
    $fStatus = $_GET['fStatus'] ?? '';
    $fBusca = $_GET['fBusca'] ?? '';
    $fDataIni = $_GET['fDataIni'] ?? '';
    $fDataFim = $_GET['fDataFim'] ?? '';
    $fMes = $_GET['fMes'] ?? '';
    $fAno = $_GET['fAno'] ?? '';
    
    $filters = [];
    if ($fAtendente) $filters['Nome_atendente'] = $fAtendente;
    if ($fStatus) $filters['STATUS'] = $fStatus;
    
    $checkDate = function($row) use ($fDataIni, $fDataFim, $fMes, $fAno) {
        if (!$fDataIni && !$fDataFim && !$fMes && !$fAno) return true;
        $d = $row['DATA'] ?? ''; 
        if (!$d) return false;
        $dt = DateTime::createFromFormat('d/m/Y', $d);
        if (!$dt) return false;
        
        if ($fDataIni) {
            $di = DateTime::createFromFormat('Y-m-d', $fDataIni);
            if ($dt < $di) return false;
        }
        if ($fDataFim) {
            $df = DateTime::createFromFormat('Y-m-d', $fDataFim);
            if ($dt > $df) return false;
        }
        if ($fMes && $fAno) {
            if ($dt->format('m') != $fMes || $dt->format('Y') != $fAno) return false;
        }
        return true;
    };

    if ($fBusca) {
         $foundCpfs = []; $foundPorts = []; $foundAgs = [];
         
         if (!preg_match('/^\d+$/', $fBusca)) {
             $resCli = $db->select('Base_clientes.json', ['global' => $fBusca], 1, 1000); 
             $foundCpfs = array_column($resCli['data'], 'CPF');
         }
         $resCred = $db->select('Identificacao_cred.json', ['global' => $fBusca], 1, 1000);
         $foundPorts = array_column($resCred['data'], 'PORTABILIDADE');
         $resAg = $db->select('Base_agencias.json', ['global' => $fBusca], 1, 1000);
         $foundAgs = array_column($resAg['data'], 'AG');

         $filters['callback'] = function($row) use ($fBusca, $foundCpfs, $foundPorts, $foundAgs, $checkDate) {
              if (!$checkDate($row)) return false;
              foreach ($row as $val) if (stripos($val, $fBusca) !== false) return true;
              if (!empty($foundCpfs) && isset($row['CPF']) && in_array($row['CPF'], $foundCpfs)) return true;
              if (!empty($foundPorts) && isset($row['Numero_Portabilidade']) && in_array($row['Numero_Portabilidade'], $foundPorts)) return true;
              if (!empty($foundAgs) && isset($row['AG']) && in_array($row['AG'], $foundAgs)) return true;
              return false;
         };
    } else {
        if ($fDataIni || $fDataFim || ($fMes && $fAno)) $filters['callback'] = $checkDate;
    }
    
    $years = $_SESSION['selected_years'] ?? [date('Y')];
    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
    $targetFile = $db->getProcessFiles($years, $months);

    $res = $db->select($targetFile, $filters, 1, 100000); 
    $processos = $res['data'];
    
    $cpfs = array_column($processos, 'CPF');
    $ports = array_column($processos, 'Numero_Portabilidade');
    $ags = array_column($processos, 'AG');

    $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
    $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c;
    
    $creditos = $db->findMany('Identificacao_cred.json', 'PORTABILIDADE', $ports);
    $creditoMap = []; foreach ($creditos as $c) $creditoMap[$c['PORTABILIDADE']] = $c;

    $agencias = $db->findMany('Base_agencias.json', 'AG', $ags);
    $agenciaMap = []; foreach ($agencias as $c) $agenciaMap[$c['AG']] = $c;
    
    $hProc = $config->getFields('Base_processos_schema'); // Get fields config
    // Filter titles
    $hProc = array_filter($hProc, function($f) { return ($f['type'] ?? '') !== 'title'; });
    // Map to simple array of keys
    $hProc = array_map(function($f){ return $f['key']; }, $hProc);
    if (!in_array('Ultima_Alteracao', $hProc)) $hProc[] = 'Ultima_Alteracao';

    $hCli = $db->getHeaders('Base_clientes.json');
    $hAg = $db->getHeaders('Base_agencias.json');
    $hCred = $db->getHeaders('Identificacao_cred.json');
    
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");

    // Start XML Output
    echo "<?xml version=\"1.0\"?>\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";
    
    // Styles
    echo "<Styles>\n";
    echo " <Style ss:ID=\"Default\" ss:Name=\"Normal\">\n";
    echo "  <Alignment ss:Vertical=\"Bottom\"/>\n";
    echo "  <Borders/>\n";
    echo "  <Font ss:FontName=\"Calibri\" x:Family=\"Swiss\" ss:Size=\"11\" ss:Color=\"#000000\"/>\n";
    echo "  <Interior/>\n";
    echo "  <NumberFormat/>\n";
    echo "  <Protection/>\n";
    echo " </Style>\n";
    echo " <Style ss:ID=\"Header\">\n";
    echo "  <Font ss:FontName=\"Calibri\" x:Family=\"Swiss\" ss:Size=\"11\" ss:Color=\"#FFFFFF\" ss:Bold=\"1\"/>\n";
    echo "  <Interior ss:Color=\"#003366\" ss:Pattern=\"Solid\"/>\n";
    echo " </Style>\n";
    echo " <Style ss:ID=\"Text\">\n";
    echo "  <NumberFormat ss:Format=\"@\"/>\n";
    echo " </Style>\n";
    echo "</Styles>\n";

    // --- SHEET 1: PROCESSOS ---
    echo "<Worksheet ss:Name=\"Processos\">\n";
    echo " <Table>\n";
    
    // Header Row
    echo "  <Row>\n";
    foreach($hProc as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCli as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hAg as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCred as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    echo "  </Row>\n";
    
    $clean = function($str) {
        return htmlspecialchars(str_replace(["\r", "\n", "\t"], " ", $str ?? ''), ENT_XML1, 'UTF-8');
    };
    
    // Helper to determine type
    $getCell = function($h, $val) use ($clean) {
         $val = $clean($val);
         // Force text format for specific number columns
         $textCols = ['Numero_Portabilidade', 'PORTABILIDADE', 'PROPOSTA', 'CPF', 'AG', 'NUMERO_DEPOSITO', 'Certificado'];
         $style = "";
         $type = "String";
         
         // If numeric and not in textCols, could be Number, but CSV is loose. Let's stick to String to match behavior or Number if safe.
         // Given "money" types, maybe good to use Number? But currency symbols break it.
         // Stick to String with Text style for ID columns.
         if (in_array($h, $textCols)) {
             $style = "ss:StyleID=\"Text\"";
         }
         return "   <Cell $style><Data ss:Type=\"$type\">$val</Data></Cell>\n";
    };

    foreach ($processos as $proc) {
        $cpf = get_value_ci($proc, 'CPF');
        $port = get_value_ci($proc, 'Numero_Portabilidade');
        $ag = get_value_ci($proc, 'AG');

        $cliData = $clientMap[$cpf] ?? array_fill_keys($hCli, '');
        $agData = $agenciaMap[$ag] ?? array_fill_keys($hAg, '');
        $credData = $creditoMap[$port] ?? array_fill_keys($hCred, '');
        
        echo "  <Row>\n";
        foreach($hProc as $h) echo $getCell($h, get_value_ci($proc, $h));
        foreach($hCli as $h) echo $getCell($h, get_value_ci($cliData, $h));
        foreach($hAg as $h) echo $getCell($h, get_value_ci($agData, $h));
        foreach($hCred as $h) echo $getCell($h, get_value_ci($credData, $h));
        echo "  </Row>\n";
    }
    echo " </Table>\n";
    echo "</Worksheet>\n";

    // --- SHEET 2: HISTÓRICO DE ENVIOS ---
    echo "<Worksheet ss:Name=\"Histórico de Envios\">\n";
    echo " <Table>\n";
    
    // Headers
    $histHeaders = ['Data e Hora', 'Número da Portabilidade', 'Nome do Cliente', 'Usuário', 'Modelo'];
    echo "  <Row>\n";
    foreach($histHeaders as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    echo "  </Row>\n";

    // Filter History
    // Read Base_registros.json
    $histFile = 'dados/Base_registros.json';
    if (file_exists($histFile)) {
        $rows = json_decode(file_get_contents($histFile), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                // DATA, USUARIO, CLIENTE, CPF, PORTABILIDADE, MODELO, TEXTO, DESTINATARIOS
                $pPort = trim($row['PORTABILIDADE'] ?? '');
                
                // Check if this history belongs to exported processes
                if (in_array($pPort, $ports)) {
                    $valData = $clean($row['DATA'] ?? '');
                    $valPort = $clean($row['PORTABILIDADE'] ?? '');
                    $valCli  = $clean($row['CLIENTE'] ?? '');
                    $valUser = $clean($row['USUARIO'] ?? '');
                    $valMod  = $clean($row['MODELO'] ?? '');

                    echo "  <Row>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valData</Data></Cell>\n";
                    echo "   <Cell ss:StyleID=\"Text\"><Data ss:Type=\"String\">$valPort</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valCli</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valUser</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valMod</Data></Cell>\n";
                    echo "  </Row>\n";
                }
            }
        }
    }

    echo " </Table>\n";
    echo "</Worksheet>\n";

    // --- SHEET 3: REGISTROS DE PROCESSO ---
    echo "<Worksheet ss:Name=\"Registros de Processo\">\n";
    echo " <Table>\n";
    
    $regFields = $config->getFields('Base_registros_schema');
    $regFields = array_filter($regFields, function($f) { return ($f['type'] ?? '') !== 'title'; });
    echo "  <Row>\n";
    echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Data e Hora</Data></Cell>\n";
    echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Usuário</Data></Cell>\n";
    echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Portabilidade</Data></Cell>\n";
    foreach($regFields as $f) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">" . htmlspecialchars($f['label']) . "</Data></Cell>\n";
    echo "  </Row>\n";

    $regFile = 'dados/Base_registros_dados.json';
    if (file_exists($regFile)) {
        $rows = json_decode(file_get_contents($regFile), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pPort = trim(get_value_ci($row, 'PORTABILIDADE'));
                if (in_array($pPort, $ports)) {
                    $valData = $clean(get_value_ci($row, 'DATA'));
                    $valUser = $clean(get_value_ci($row, 'USUARIO'));
                    $valPort = $clean(get_value_ci($row, 'PORTABILIDADE'));
                    
                    echo "  <Row>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valData</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valUser</Data></Cell>\n";
                    echo "   <Cell ss:StyleID=\"Text\"><Data ss:Type=\"String\">$valPort</Data></Cell>\n";
                    
                    foreach($regFields as $f) {
                        $val = $clean(get_value_ci($row, $f['key']));
                        echo "   <Cell><Data ss:Type=\"String\">$val</Data></Cell>\n";
                    }
                    echo "  </Row>\n";
                }
            }
        }
    }
    echo " </Table>\n";
    echo "</Worksheet>\n";

    echo "</Workbook>";
    exit;
}

// EXPORT EXCEL LEMBRETES (NOVO)
if (isset($_GET['acao']) && $_GET['acao'] == 'exportar_lembretes_excel') {
    $filename = "Lembretes_SPA_" . date('d-m-Y_His') . ".xls";
    
    $fLembreteIni = $_GET['fLembreteIni'] ?? '';
    $fLembreteFim = $_GET['fLembreteFim'] ?? '';
    $fBuscaGlobal = $_GET['fBuscaGlobal'] ?? '';
    
    // 1. Identify Flagged Fields (to include context, though user asked for "Complete Export")
    // "Todos os dados vinculados ao processo; Todas as informações relacionadas ao lembrete"
    // So we basically do a full export of filtered processes + reminders context.
    
    // 2. Fetch Processes
    $years = $_SESSION['selected_years'] ?? [date('Y')];
    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
    $targetFiles = $db->getProcessFiles($years, $months);
    
    // Main Filters
    $filters = [];
    
    // Date Logic for Data_Lembrete
    $checkLembrete = function($row) use ($fLembreteIni, $fLembreteFim) {
        if (!$fLembreteIni && !$fLembreteFim) return true;
        $val = get_value_ci($row, 'Data_Lembrete');
        if (!$val) return false;
        
        $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
        if(!$dt) $dt = DateTime::createFromFormat('d/m/Y', $val);
        if(!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
        if(!$dt) return false;
        
        if ($fLembreteIni) {
            $di = DateTime::createFromFormat('Y-m-d', $fLembreteIni);
            $di->setTime(0,0,0);
            if ($dt < $di) return false;
        }
        if ($fLembreteFim) {
            $df = DateTime::createFromFormat('Y-m-d', $fLembreteFim);
            $df->setTime(23,59,59);
            if ($dt > $df) return false;
        }
        return true;
    };
    
    if ($fBuscaGlobal) {
         $filters['global'] = $fBuscaGlobal;
    }
    
    // Combine Date Check with Global
    $filters['callback'] = function($row) use ($checkLembrete) {
        if (!$checkLembrete($row)) return false;
        
        // Filter Empty Fields
        $ua = get_value_ci($row, 'Ultima_Alteracao');
        $dl = get_value_ci($row, 'Data_Lembrete');
        if (trim((string)$ua) === '' || trim((string)$dl) === '') return false;

        return true;
    };

    // Fetch All Matches
    $res = $db->select($targetFiles, $filters, 1, 100000); 
    $processos = $res['data'];
    
    // Prepare Data
    $cpfs = array_column($processos, 'CPF');
    $ports = array_column($processos, 'Numero_Portabilidade');
    $ags = array_column($processos, 'AG');

    $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
    $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c;
    
    $creditos = $db->findMany('Identificacao_cred.json', 'PORTABILIDADE', $ports);
    $creditoMap = []; foreach ($creditos as $c) $creditoMap[$c['PORTABILIDADE']] = $c;

    $agencias = $db->findMany('Base_agencias.json', 'AG', $ags);
    $agenciaMap = []; foreach ($agencias as $c) $agenciaMap[$c['AG']] = $c;
    
    // Headers
    $hProc = $config->getFields('Base_processos_schema');
    $hProc = array_filter($hProc, function($f) { return ($f['type'] ?? '') !== 'title'; });
    $hProcKeys = array_map(function($f){ return $f['key']; }, $hProc);
    if (!in_array('Ultima_Alteracao', $hProcKeys)) array_unshift($hProcKeys, 'Ultima_Alteracao');
    if (!in_array('Data_Lembrete', $hProcKeys)) array_unshift($hProcKeys, 'Data_Lembrete');

    $hCli = $db->getHeaders('Base_clientes.json');
    $hAg = $db->getHeaders('Base_agencias.json');
    $hCred = $db->getHeaders('Identificacao_cred.json');
    
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");

    // Start XML Output
    echo "<?xml version=\"1.0\"?>\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";
    
    echo "<Styles>\n";
    echo " <Style ss:ID=\"Header\">\n";
    echo "  <Font ss:FontName=\"Calibri\" x:Family=\"Swiss\" ss:Size=\"11\" ss:Color=\"#FFFFFF\" ss:Bold=\"1\"/>\n";
    echo "  <Interior ss:Color=\"#003366\" ss:Pattern=\"Solid\"/>\n";
    echo " </Style>\n";
    echo " <Style ss:ID=\"Text\">\n";
    echo "  <NumberFormat ss:Format=\"@\"/>\n";
    echo " </Style>\n";
    echo "</Styles>\n";

    echo "<Worksheet ss:Name=\"Lembretes\">\n";
    echo " <Table>\n";
    
    // Header Row
    echo "  <Row>\n";
    foreach($hProcKeys as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCli as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hAg as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCred as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    echo "  </Row>\n";
    
    $clean = function($str) {
        return htmlspecialchars(str_replace(["\r", "\n", "\t"], " ", $str ?? ''), ENT_XML1, 'UTF-8');
    };
    
    $getCell = function($h, $val) use ($clean) {
         $val = $clean($val);
         $textCols = ['Numero_Portabilidade', 'PORTABILIDADE', 'PROPOSTA', 'CPF', 'AG', 'NUMERO_DEPOSITO', 'Certificado'];
         $style = in_array($h, $textCols) ? "ss:StyleID=\"Text\"" : "";
         return "   <Cell $style><Data ss:Type=\"String\">$val</Data></Cell>\n";
    };

    foreach ($processos as $proc) {
        $cpf = get_value_ci($proc, 'CPF');
        $port = get_value_ci($proc, 'Numero_Portabilidade');
        $ag = get_value_ci($proc, 'AG');

        $cliData = $clientMap[$cpf] ?? array_fill_keys($hCli, '');
        $agData = $agenciaMap[$ag] ?? array_fill_keys($hAg, '');
        $credData = $creditoMap[$port] ?? array_fill_keys($hCred, '');
        
        echo "  <Row>\n";
        foreach($hProcKeys as $h) echo $getCell($h, get_value_ci($proc, $h));
        foreach($hCli as $h) echo $getCell($h, get_value_ci($cliData, $h));
        foreach($hAg as $h) echo $getCell($h, get_value_ci($agData, $h));
        foreach($hCred as $h) echo $getCell($h, get_value_ci($credData, $h));
        echo "  </Row>\n";
    }
    echo " </Table>\n";
    echo "</Worksheet>\n";
    echo "</Workbook>";
    exit;
}

// POST PROCESSING
$mensagem = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';


    if ($acao == 'salvar_processo') { // Fallback legacy
        // ... (Keep existing logic just in case, but duplicated logic is bad. For now, let's just make submitProcessForm change the action to ajax_salvar_processo)
        // Actually, let's keep it dry.
    }

    if ($acao == 'limpar_base' || $acao == 'limpar_base_creditos') {
        $base = $_POST['base'] ?? 'Identificacao_cred.json';
        if ($db->truncate($base)) {
            $mensagem = "Base ($base) limpa com sucesso!";
        } else {
            $mensagem = "Erro: Não foi possível limpar a base.";
        }
    }
    
    if ($acao == 'excluir_processo') {
        $port = $_POST['id_exclusao'];
        if ($port) {
            $file = $indexer->get($port);
            if ($file) {
                $db->delete($file, 'Numero_Portabilidade', $port);
                $indexer->delete($port);
                $mensagem = "Processo excluído com sucesso.";
            } else {
                // Fallback (e.g. legacy check)
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $files = $db->getProcessFiles($years, $months);
                foreach ($files as $f) {
                    if ($db->delete($f, 'Numero_Portabilidade', $port)) {
                         // Found by brute force
                         break;
                    }
                }
                $mensagem = "Processo excluído (se encontrado).";
            }
        }
    }

    if ($acao == 'confirm_upload') {
        if (isset($_SESSION['upload_preview']) && !empty($_SESSION['upload_preview'])) {
            $data = $_SESSION['upload_preview'];
            $base = $_SESSION['upload_preview_base'] ?? 'Identificacao_cred.json';
            
            // Clear session to free memory and state
            unset($_SESSION['upload_preview']);
            unset($_SESSION['upload_preview_base']);
            
            // Release session lock to prevent blocking other requests (heartbeats)
            session_write_close();
            
            $cleanData = [];
            foreach($data as $row) {
                if (isset($row['DATA_ERROR'])) continue;
                unset($row['DATA_ERROR']);
                $cleanData[] = $row;
            }
            
            try {
                $res = $db->importExcelData($base, $cleanData);
                if ($res) {
                    $mensagem = "Base ($base) atualizada com sucesso! (Inseridos: {$res['inserted']}, Atualizados: {$res['updated']})";
                } else {
                    $mensagem = "Erro: Falha ao atualizar a base de dados.";
                }
            } catch (Exception $e) {
                $mensagem = "Erro Crítico: " . $e->getMessage();
            }
        } else {
            $mensagem = "Erro: Sessão de upload expirada.";
        }
    }

    if ($acao == 'cancel_upload') {
        unset($_SESSION['upload_preview']);
        $mensagem = "Upload cancelado.";
    }
    
    if ($acao == 'paste_data') {
        $text = $_POST['paste_content'] ?? '';
        $base = $_POST['base'] ?? 'Identificacao_cred.json';

        if ($text) {
            // Ensure UTF-8
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            }

            $rows = [];
            $lines = explode("\n", $text);
            $delimiter = "\t"; // Default
            
            // Check first line for delimiter
            if (!empty($lines[0])) {
                if (strpos($lines[0], "\t") !== false) $delimiter = "\t";
                elseif (strpos($lines[0], ";") !== false) $delimiter = ";";
                elseif (strpos($lines[0], ",") !== false) $delimiter = ",";
            }
            
            $confFields = $config->getFields($base);
            $headers = [];
            foreach($confFields as $f) {
                if(isset($f['deleted']) && $f['deleted']) continue;
                if(isset($f['type']) && $f['type'] === 'title') continue;
                $headers[] = $f['key'];
            }
            
            if (empty($headers)) {
                if (stripos($base, 'client') !== false) {
                    $headers = ['Nome', 'CPF'];
                } elseif (stripos($base, 'agenc') !== false) {
                    $headers = ['AG', 'UF', 'SR', 'Nome SR', 'Filial', 'E-mail AG', 'E-mails SR', 'E-mails Filial', 'E-mail Gerente'];
                } else {
                    $headers = ['Status', 'Número Depósito', 'Data Depósito', 'Valor Depósito Principal', 'Texto Pagamento', 'Portabilidade', 'Certificado', 'Status 2', 'CPF', 'AG'];
                }
            }

            $isHeader = true;
            
            $headerMap = [];
            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line, $delimiter);
                $cols = array_map('trim', $cols);

                // Header detection (Smart)
                if ($isHeader) {
                    $isHeader = false;
                    
                    // Check intersection with expected headers
                    $matches = 0;
                    $tempMap = [];
                    foreach ($cols as $idx => $colVal) {
                        $matchedKey = null;
                        
                        // Try matching against Config Fields (Labels or Keys)
                        foreach ($confFields as $f) {
                             if (isset($f['type']) && $f['type'] === 'title') continue;
                             $key = $f['key'];
                             $lbl = isset($f['label']) ? $f['label'] : $key;
                             $valUpper = mb_strtoupper($colVal, 'UTF-8');
                             
                             if ($valUpper === mb_strtoupper($key, 'UTF-8') || $valUpper === mb_strtoupper($lbl, 'UTF-8')) {
                                 $matchedKey = $key;
                                 break;
                             }
                        }
                        
                        // Fallback to headers array if not found (e.g. legacy or manual headers)
                        if (!$matchedKey) {
                            foreach ($headers as $h) {
                                if (mb_strtoupper($colVal, 'UTF-8') === mb_strtoupper($h, 'UTF-8')) {
                                    $matchedKey = $h;
                                    break;
                                }
                            }
                        }

                        if ($matchedKey) {
                            $matches++;
                            $tempMap[$idx] = $matchedKey; 
                        }
                    }
                    
                    // If significant match found (e.g. > 2 headers matched or 50% of cols), assume header line
                    if ($matches >= 2 || ($matches > 0 && count($cols) <= 3)) {
                        $headerMap = $tempMap;
                        continue; // Skip header line
                    }
                }
                
                $rows[] = $cols;
            }
            
            // Mapping Logic
            $mappedData = [];
            foreach ($rows as $cols) {
                $newRow = [];
                
                if (!empty($headerMap)) {
                    // Map by detected headers
                    foreach ($headers as $h) {
                        // Find index for this header
                        $foundIdx = array_search($h, $headerMap);
                        if ($foundIdx !== false && isset($cols[$foundIdx])) {
                            $newRow[$h] = $cols[$foundIdx];
                        } else {
                            $newRow[$h] = '';
                        }
                    }
                } else {
                    // Map by position (Legacy)
                    foreach($headers as $i => $h) {
                        $newRow[$h] = isset($cols[$i]) ? $cols[$i] : '';
                    }
                }
                
                // Validation / Key Check
                if (stripos($base, 'client') !== false) {
                    if (empty($newRow['CPF'])) continue;
                } elseif (stripos($base, 'agenc') !== false) {
                    if (empty($newRow['AG'])) continue;
                } elseif (stripos($base, 'Processos') !== false || stripos($base, 'Base_processos') !== false) {
                    if (empty($newRow['Numero_Portabilidade'])) continue;
                } else {
                    if (empty($newRow['PORTABILIDADE'])) continue;
                }
                
                $mappedData[] = $newRow;
            }
            
            // Validation
            $validatedData = [];
            foreach ($mappedData as $row) {
                // Dynamic Date Validation based on Config
                foreach ($confFields as $f) {
                     if (isset($f['type']) && $f['type'] == 'date' && isset($row[$f['key']])) {
                         $val = $row[$f['key']];
                         if ($val !== '') {
                             $validDate = normalizeDate($val);
                             if ($validDate === false) {
                                 $row['DATA_ERROR'] = true;
                             } else {
                                 $row[$f['key']] = $validDate;
                             }
                         }
                     }
                }

                // Fallback for core date fields
                $coreDates = ['DATA_DEPOSITO', 'DATA'];
                foreach($coreDates as $cd) {
                    if (isset($row[$cd])) {
                         $val = $row[$cd];
                         if ($val !== '') {
                             $validDate = normalizeDate($val);
                             if ($validDate === false) {
                                 $row['DATA_ERROR'] = true;
                             } else {
                                 $row[$cd] = $validDate;
                             }
                         }
                    }
                }

                $validatedData[] = $row;
            }
            
            if (empty($validatedData)) {
                $mensagem = "Erro: Nenhum dado válido identificado no texto colado.";
            } else {
                $_SESSION['upload_preview'] = $validatedData;
                $_SESSION['upload_preview_base'] = $base;
                $showPreview = true;
            }
        }
    }

    if ($acao == 'salvar_campo') {
        $file = $_POST['arquivo_base'];
        $oldKey = $_POST['old_key'] ?? '';
        $required = isset($_POST['required']) ? true : false;
        
        $key = trim($_POST['key']);
        $fieldData = ['key' => $key, 'label' => $_POST['label'], 'type' => $_POST['type'], 'options' => $_POST['options'] ?? '', 'required' => $required];
        
        if ($oldKey) {
            $config->updateField($file, $oldKey, $fieldData);
            $mensagem = "Campo atualizado com sucesso!";
        } else {
            // Check for duplicates
            $existing = $config->getFields($file);
            $exists = false;
            $deletedKey = null;

            foreach ($existing as $f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                    $exists = true; 
                    if (isset($f['deleted']) && $f['deleted']) {
                         $deletedKey = $f['key'];
                    }
                    break;
                }
            }
            
            if ($exists) {
                if ($deletedKey) {
                    $config->updateField($file, $deletedKey, $fieldData);
                    $config->reactivateField($file, $deletedKey);
                    $mensagem = "Campo restaurado e atualizado com sucesso!";
                } else {
                    $mensagem = "Erro: O campo '$key' já existe!";
                }
            } else {
                $config->addField($file, $fieldData);
                $mensagem = "Campo adicionado com sucesso!";
            }
        }
    }

    if ($acao == 'remover_campo') {
        $file = $_POST['arquivo_base'];
        $key = $_POST['key'];
        $config->removeField($file, $key);
        $mensagem = "Campo removido com sucesso!";
    }

    if ($acao == 'salvar_template') {
        $templates->save($_POST['id_template'] ?? '', $_POST['titulo'], $_POST['corpo']);
        $mensagem = "Modelo salvo com sucesso!";
    }

    if ($acao == 'excluir_template') {
        $templates->delete($_POST['id_exclusao']);
        $mensagem = "Modelo excluído!";
    }
}

$page = $_GET['p'] ?? 'dashboard';

$getVal = function($arr, $key) {
    if (!is_array($arr)) return '';
    if (isset($arr[$key])) return $arr[$key];
    foreach ($arr as $k => $v) {
        if (mb_strtoupper($k, 'UTF-8') === mb_strtoupper($key, 'UTF-8')) return $v;
    }
    return '';
};
?>



<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPA - Sistema de Portabilidade</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <style>
        :root { --laranja: #FF8C00; --navy: #003366; }
        body { background: #f4f6f9; font-family: 'Arial', sans-serif; }
        .navbar-custom { background: var(--navy); }
        .navbar-brand { font-weight: bold; color: white !important; }
        .nav-link { color: rgba(255,255,255,0.8) !important; }
        .nav-link.active { color: var(--laranja) !important; font-weight: bold; }
        .card-custom { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        /* Buttons Enhanced */
        .btn {
            padding: 8px 16px !important;
            font-size: 14px !important;
            border-radius: 6px !important;
            min-height: 38px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-sm {
            padding: 4px 10px !important;
            font-size: 12px !important;
            border-radius: 4px !important;
            min-height: 28px !important;
            line-height: 1.5 !important;
        }

        .btn-navy { 
            background-color: var(--navy); 
            color: white; 
        }
        .btn-navy:hover { background-color: #002244; color: white; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        
        .table-custom thead { background-color: var(--navy); color: white; }
        .status-badge { padding: 5px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .dashboard-text { font-size: 0.85rem; }
        .money-bag { color: var(--laranja); animation: blink 1.5s infinite; }
        #loadingModal { z-index: 10000; }
        
        /* Modal Styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .modal-header {
            background-color: var(--navy);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Custom Field Styling */
        .form-label-custom {
            font-weight: 600;
            color: var(--navy);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        .form-control-custom, .form-select-custom {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.5rem 0.75rem;
            font-size: 0.95rem;
            box-shadow: none; 
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--navy);
            box-shadow: 0 0 0 0.25rem rgba(0, 51, 102, 0.25);
        }
        .form-control-custom:disabled, .form-select-custom:disabled {
            background-color: #e9ecef;
            opacity: 1;
        }

        /* ESTILOS APRIMORADOS PARA ABAS */
        .nav-tabs { border-bottom: 2px solid var(--navy); }
        .nav-tabs .nav-link { 
            color: white !important; 
            background-color: #6c757d; 
            margin-right: 4px; 
            border: 1px solid #6c757d;
            border-bottom: none;
            font-weight: 600;
            opacity: 0.8;
        }
        .nav-tabs .nav-link.active { 
            background-color: var(--laranja) !important; 
            color: white !important; 
            border-color: var(--laranja); 
            font-weight: bold; 
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
            opacity: 1;
        }
        .nav-tabs .nav-link:hover { 
            background-color: #5a6268; 
            color: white !important;
            opacity: 1;
        }
        .nav-link { color: rgba(255,255,255,0.8); } /* Navbar links */
        
        /* Base Navigation Pills */
        #base-tab .nav-link {
            background-color: #e9ecef;
            color: var(--navy) !important;
            border: 1px solid #dee2e6;
            margin: 0; /* Reset for gap */
            opacity: 1;
        }
        #base-tab .nav-link.active {
            background-color: var(--navy) !important;
            color: white !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        #base-tab .nav-link:hover {
            background-color: #dee2e6;
            color: var(--navy) !important;
        }
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545 !important;
        }
        .btn.border-danger {
            border-color: #dc3545 !important;
        }
    </style>
</head>
<body>

<div class="modal fade" id="loadingModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <div class="spinner-border text-primary mx-auto mb-3"></div>
            <h5>Aguarde... Processando</h5>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPaste" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" onsubmit="handleFormSubmit(this)">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-paste me-2"></i>Colar Dados da Planilha</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="paste_data">
                <input type="hidden" name="base" id="paste_base_target">
                <div class="alert alert-secondary small">
                    Cole aqui as linhas copiadas diretamente do Excel. O sistema detectará automaticamente se há cabeçalho.
                    <br><strong>Ordem esperada:</strong> Status, Número, Data, Valor, Texto, Portabilidade, Certificado, Status 2, CPF, AG.
                </div>
                <textarea name="paste_content" class="form-control" rows="15" placeholder="Cole os dados aqui..." required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                <button class="btn btn-info text-white">Processar Dados</button>
            </div>
        </form>
    </div>
</div>

<nav class="navbar navbar-expand-lg navbar-custom mb-4 sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="?p=dashboard" onclick="showPage('dashboard'); return false;"><i class="fas fa-chart-pie me-2"></i>SPA</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link <?= $page=='dashboard'?'active':'' ?>" href="?p=dashboard" onclick="showPage('dashboard'); return false;">Processos</a></li>
                <li class="nav-item"><a class="nav-link <?= $page=='detalhes'?'active':'' ?>" href="?p=detalhes" onclick="showPage('detalhes'); return false;">Serviços</a></li>
                <li class="nav-item"><a class="nav-link <?= $page=='lembretes'?'active':'' ?>" href="?p=lembretes" onclick="showPage('lembretes'); return false;">Lembretes</a></li>
                <li class="nav-item"><a class="nav-link <?= $page=='base'?'active':'' ?>" href="?p=base" onclick="showPage('base'); return false;">Bases</a></li>
                <li class="nav-item"><a class="nav-link <?= $page=='config'?'active':'' ?>" href="?p=config" onclick="showPage('config'); return false;">Configurações</a></li>
                <li class="nav-item"><a class="nav-link" href="#" onclick="refreshCurrentView(); return false;"><i class="fas fa-sync-alt me-1"></i> Atualizar</a></li>
                <li class="nav-item d-flex align-items-center ms-2">
                    <div class="dropdown" onclick="event.stopPropagation()">
                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" id="dropdownBaseFilter" data-bs-toggle="dropdown" aria-expanded="false" style="color: #003366; font-weight: bold;">
                            <i class="fas fa-database me-1"></i> Filtrar Base
                        </button>
                        <div class="dropdown-menu p-3" aria-labelledby="dropdownBaseFilter" style="min-width: 320px;" onclick="event.stopPropagation()">
                            <div class="row">
                                <div class="col-6 border-end">
                                    <h6 class="dropdown-header text-navy ps-0">Anos</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach($availableYears as $y): ?>
                                        <div class="form-check">
                                            <input class="form-check-input chk-year" type="checkbox" value="<?= $y ?>" id="year_<?= $y ?>" <?= in_array($y, $selYears)?'checked':'' ?>>
                                            <label class="form-check-label small" for="year_<?= $y ?>"><?= $y ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h6 class="dropdown-header text-navy ps-0">Meses</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        <?php for($i=1; $i<=12; $i++): $mName = $db->getPortugueseMonth($i); ?>
                                        <div class="form-check">
                                            <input class="form-check-input chk-month" type="checkbox" value="<?= $i ?>" id="month_<?= $i ?>" <?= in_array($i, $selMonths)?'checked':'' ?>>
                                            <label class="form-check-label small" for="month_<?= $i ?>"><?= $mName ?></label>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 pt-2 border-top">
                                <button class="btn btn-navy w-100 btn-sm" onclick="applyBaseSelection()">Aplicar Filtro</button>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
            <div class="d-flex align-items-center text-white">
                <i class="fas fa-user-circle me-2"></i> <?= htmlspecialchars($_SESSION['nome_completo']) ?>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <?php if($mensagem): ?>
        <?php $alertType = (strpos($mensagem, 'Erro:') === 0) ? 'alert-danger' : 'alert-success'; ?>
        <div class="alert <?= $alertType ?> alert-dismissible fade show"><?= $mensagem ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div id="page-dashboard" class="page-section" style="<?= $page=='dashboard'?'':'display:none' ?>">
        <?php
        // LOGICA DE FILTRO RESTAURADA COMPLETA
        $fAtendente = $_GET['fAtendente'] ?? '';
        $fStatus = $_GET['fStatus'] ?? '';
        $fDataIni = $_GET['fDataIni'] ?? '';
        $fDataFim = $_GET['fDataFim'] ?? '';

        // Buscando valores únicos para os filtros
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $dashFiles = $db->getProcessFiles($years, $months);

        $uniqueAtendentes = [];
        $uniqueStatus = [];
        
        $dashProcFields = $config->getFields('Base_processos_schema');
        foreach($dashProcFields as $f) {
             if ($f['key'] === 'STATUS' && !empty($f['options'])) {
                 $uniqueStatus = array_map('trim', explode(',', $f['options']));
                 break;
             }
        }
        
        foreach($dashFiles as $df) {
             $ua = $db->getUniqueValues($df, 'Nome_atendente');
             $uniqueAtendentes = array_merge($uniqueAtendentes, $ua);
        }
        $uniqueAtendentes = array_unique($uniqueAtendentes);
        sort($uniqueAtendentes);

        $fMes = $_GET['fMes'] ?? '';
        $fAno = $_GET['fAno'] ?? '';
        $fBusca = $_GET['fBusca'] ?? '';
        $pPagina = $_GET['pag'] ?? 1;
        
        $filters = [];
        if ($fAtendente) $filters['Nome_atendente'] = $fAtendente;
        if ($fStatus) $filters['STATUS'] = $fStatus;
        
        // Helper Data
        $checkDate = function($row) use ($fDataIni, $fDataFim, $fMes, $fAno) {
            if (!$fDataIni && !$fDataFim && !$fMes && !$fAno) return true;
            $d = $row['DATA'] ?? ''; 
            if (!$d) return false;
            $dt = DateTime::createFromFormat('d/m/Y', $d);
            if (!$dt) return false;
            
            if ($fDataIni) {
                $di = DateTime::createFromFormat('Y-m-d', $fDataIni);
                if ($dt < $di) return false;
            }
            if ($fDataFim) {
                $df = DateTime::createFromFormat('Y-m-d', $fDataFim);
                if ($dt > $df) return false;
            }
            if ($fMes && $fAno) {
                if ($dt->format('m') != $fMes || $dt->format('Y') != $fAno) return false;
            }
            return true;
        };

        if ($fBusca) {
             $foundCpfs = []; $foundPorts = []; $foundAgs = [];
             if (!preg_match('/^\d+$/', $fBusca)) {
                 $resCli = $db->select('Base_clientes.json', ['global' => $fBusca], 1, 1000); 
                 $foundCpfs = array_column($resCli['data'], 'CPF');
             }
             $resCred = $db->select('Identificacao_cred.json', ['global' => $fBusca], 1, 1000);
             $foundPorts = array_column($resCred['data'], 'PORTABILIDADE');
             $resAg = $db->select('Base_agencias.json', ['global' => $fBusca], 1, 1000);
             $foundAgs = array_column($resAg['data'], 'AG');

             $filters['callback'] = function($row) use ($fBusca, $foundCpfs, $foundPorts, $foundAgs, $checkDate) {
                  if (!$checkDate($row)) return false;
                  foreach ($row as $val) if (stripos($val, $fBusca) !== false) return true;
                  if (!empty($foundCpfs) && isset($row['CPF']) && in_array($row['CPF'], $foundCpfs)) return true;
                  if (!empty($foundPorts) && isset($row['Numero_Portabilidade']) && in_array($row['Numero_Portabilidade'], $foundPorts)) return true;
                  if (!empty($foundAgs) && isset($row['AG']) && in_array($row['AG'], $foundAgs)) return true;
                  return false;
             };
        } else {
            if ($fDataIni || $fDataFim || ($fMes && $fAno)) $filters['callback'] = $checkDate;
        }

        // $dashFiles already resolved above
        $dashRes = $db->select($dashFiles, $filters, $pPagina, 20, 'DATA', true);
        $dashProcessos = $dashRes['data'];
        $dashTotal = $dashRes['total'];
        $dashCountStr = str_pad($dashTotal, 2, '0', STR_PAD_LEFT);
        
        $cpfs = array_column($dashProcessos, 'CPF');
        $ports = array_column($dashProcessos, 'Numero_Portabilidade');
        $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
        $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c['Nome'];
        $dashCreditos = $db->findMany('Identificacao_cred.json', 'PORTABILIDADE', $ports);
        $dashCreditoMap = []; foreach ($dashCreditos as $c) $dashCreditoMap[$c['PORTABILIDADE']] = $c;
        ?>
        <div class="card card-custom p-4 mb-4">
            <h4 class="text-navy mb-4"><i class="fas fa-filter me-2"></i>Filtros</h4>
            <form onsubmit="filterDashboard(event)" class="row g-3" id="form_dashboard_filter">
                <input type="hidden" name="p" value="dashboard">
                <div class="col-md-2">
                    <label>Atendente</label>
                    <select name="fAtendente" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach($uniqueAtendentes as $ua): ?>
                            <option value="<?= htmlspecialchars($ua) ?>" <?= $fAtendente==$ua?'selected':'' ?>><?= htmlspecialchars($ua) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Status</label>
                    <select name="fStatus" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach($uniqueStatus as $us): ?>
                            <option value="<?= htmlspecialchars($us) ?>" <?= $fStatus==$us?'selected':'' ?>><?= htmlspecialchars($us) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label>Início</label><input type="date" name="fDataIni" class="form-control form-control-sm" value="<?= htmlspecialchars($fDataIni) ?>"></div>
                <div class="col-md-2"><label>Fim</label><input type="date" name="fDataFim" class="form-control form-control-sm" value="<?= htmlspecialchars($fDataFim) ?>"></div>
                <div class="col-md-2"><label>Mês/Ano</label><div class="input-group input-group-sm"><select name="fMes" class="form-select"><option value="">Mês</option><?php for($i=1;$i<=12;$i++) echo "<option value='".sprintf('%02d',$i)."' ".($fMes==sprintf('%02d',$i)?'selected':'').">".sprintf('%02d',$i)."</option>"; ?></select><input type="number" name="fAno" class="form-control" placeholder="Ano" value="<?= htmlspecialchars($fAno) ?>"></div></div>
                <div class="col-md-2"><label>Busca Global</label><input type="text" name="fBusca" class="form-control form-control-sm" placeholder="CPF, Nome..." value="<?= htmlspecialchars($fBusca) ?>"></div>
                <div class="col-12 text-end">
                    <button class="btn btn-navy btn-sm"><i class="fas fa-search me-1"></i> Filtrar</button>
                    <button type="button" onclick="clearDashboardFilters()" class="btn btn-outline-secondary btn-sm">Limpar</button>
                    <button type="button" onclick="downloadExcel(this)" class="btn btn-success btn-sm"><i class="fas fa-file-excel me-1"></i> Excel</button>
                </div>
            </form>
        </div>

        <div class="card card-custom p-4">
            <h4 class="text-navy mb-3" id="process_list_header"><i class="fas fa-list me-2"></i>Processos (<?= $dashCountStr ?>)</h4>
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle" id="dash_table_head">
                    <thead><tr>
                        <th class="sortable-header" data-col="DATA" onclick="setDashSort('DATA')" style="cursor:pointer">Data <span class="sort-icon"><i class="fas fa-sort-down text-dark ms-1"></i></span></th>
                        <th class="sortable-header" data-col="Nome" onclick="setDashSort('Nome')" style="cursor:pointer">Nome <span class="sort-icon"><i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i></span></th>
                        <th class="sortable-header" data-col="CPF" onclick="setDashSort('CPF')" style="cursor:pointer">CPF <span class="sort-icon"><i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i></span></th>
                        <th class="sortable-header" data-col="Numero_Portabilidade" onclick="setDashSort('Numero_Portabilidade')" style="cursor:pointer">Portabilidade <span class="sort-icon"><i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i></span></th>
                        <th class="sortable-header" data-col="VALOR DA PORTABILIDADE" onclick="setDashSort('VALOR DA PORTABILIDADE')" style="cursor:pointer">Valor <span class="sort-icon"><i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i></span></th>
                        <th class="sortable-header" data-col="STATUS" onclick="setDashSort('STATUS')" style="cursor:pointer">Status <span class="sort-icon"><i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i></span></th>
                        <th class="sortable-header" data-col="Nome_atendente" onclick="setDashSort('Nome_atendente')" style="cursor:pointer">Atendente <span class="sort-icon"><i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i></span></th>
                        <th>Ação</th>
                    </tr></thead>
                    <tbody id="dash_table_body">
                        <?php foreach($dashProcessos as $proc): 
                            $nome = $clientMap[$proc['CPF']] ?? (get_value_ci($proc, 'Nome') ?: 'N/A');
                            $cred = isset($dashCreditoMap[$proc['Numero_Portabilidade']]); 
                            $l = $lockManager->checkLock($proc['Numero_Portabilidade'], '');
                            $rowClass = $l['locked'] ? 'table-warning' : '';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="dashboard-text"><?= $proc['DATA'] ?></td>
                            <td class="dashboard-text"><?= $nome ?></td>
                            <td class="dashboard-text"><?= $proc['CPF'] ?></td>
                            <td class="dashboard-text"><?= $proc['Numero_Portabilidade'] ?> <?php if($cred): ?><i class="fas fa-sack-dollar money-bag ms-2" title="Crédito Identificado!"></i><?php endif; ?></td>
                            <td class="dashboard-text"><?= $proc['VALOR DA PORTABILIDADE'] ?? '' ?></td>
                            <td><span class="badge bg-secondary status-badge"><?= $proc['STATUS'] ?></span></td>
                            <td class="dashboard-text">
                                <?= $proc['Nome_atendente'] ?>
                                <?php if(!empty($proc['Ultima_Alteracao'])): ?>
                                    <div class="small text-muted" style="font-size:0.75rem"><i class="fas fa-clock me-1"></i> <?= $proc['Ultima_Alteracao'] ?></div>
                                <?php endif; ?>
                                <?php if($l['locked']): ?>
                                        <div class="small text-danger fw-bold"><i class="fas fa-lock me-1"></i> Em uso por: <?= $l['by'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td><a href="#" onclick="loadProcess('<?= $proc['Numero_Portabilidade'] ?>', this); return false;" class="btn btn-sm btn-outline-dark border-0"><i class="fas fa-folder-open fa-lg text-warning"></i> Abrir</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($dashProcessos)): ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="dash_pagination_container">
                <?php if($dashRes['pages'] > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if($dashRes['page'] > 1): ?><li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, <?= $dashRes['page']-1 ?>)">Anterior</a></li><?php endif; ?>
                        <li class="page-item disabled"><a class="page-link">Página <?= $dashRes['page'] ?> de <?= $dashRes['pages'] ?></a></li>
                        <?php if($dashRes['page'] < $dashRes['pages']): ?><li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, <?= $dashRes['page']+1 ?>)">Próxima</a></li><?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="page-detalhes" class="page-section" style="<?= $page=='detalhes'?'':'display:none' ?>">
        <?php
        $id = $_GET['id'] ?? '';
        
        $processo = null;
        if ($id) {
            $allFiles = $db->getAllProcessFiles();
            $res = $db->select($allFiles, ['Numero_Portabilidade' => $id], 1, 1);
            if (!empty($res['data'])) {
                $processo = $res['data'][0];
            }
        }

        $cpf = $processo['CPF'] ?? '';
        $cliente = $cpf ? $db->find('Base_clientes.json', 'CPF', $cpf) : null;
        $ag = $processo['AG'] ?? '';
        $agencia = $ag ? $db->find('Base_agencias.json', 'AG', $ag) : null;
        $credito = $id ? $db->find('Identificacao_cred.json', 'PORTABILIDADE', $id) : null;
        
        // Auto-fill Data for JS
        $autoFillData = (!$processo && $credito) ? json_encode($credito) : 'null';
        
        $procFields = $config->getFields('Base_processosatual.txt'); // Generic
        $cliFields = $config->getFields('Base_clientes.json');
        $agFields = $config->getFields('Base_agencias.json');

        // Helper para verificar obrigatoriedade
        $getReq = function($fields, $key) {
            foreach($fields as $f) {
                if(mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) return ($f['required'] ?? false) ? 'required' : '';
            }
            return '';
        };

        // Helper para exibir asterisco
        $getReqStar = function($fields, $key) {
            foreach($fields as $f) {
                if(mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) return ($f['required'] ?? false) ? '<span class="text-danger">*</span>' : '';
            }
            return '';
        };

        // Valores únicos para o formulário
        $allStatus = [];
        foreach($procFields as $f) {
            if ($f['key'] === 'STATUS' && !empty($f['options'])) {
                $allStatus = array_map('trim', explode(',', $f['options']));
                break;
            }
        }
        if (empty($allStatus)) {
             $uniqueStatusForm = $db->getUniqueValues('Base_processosatual.txt', 'STATUS');
             $defaultStatus = ['EM ANDAMENTO', 'CONCLUÍDO', 'CANCELADO', 'ASSINADO'];
             $allStatus = array_unique(array_merge($defaultStatus, $uniqueStatusForm));
             sort($allStatus);
        }

        $uniqueAtendentesForm = $db->getUniqueValues('Base_processosatual.txt', 'Nome_atendente');
        
        // Coleta emails da agência se houver agência carregada
        $agencyEmails = [];
        if ($agencia) {
            $emailFields = ['E-MAIL AG', 'E-MAILS SR', 'E-MAIL GERENTE', 'E-MAILS FILIAL'];
            foreach ($emailFields as $ef) {
                if (!empty($agencia[$ef])) {
                    // Split by ; or ,
                    $parts = preg_split('/[;,]/', $agencia[$ef]);
                    foreach ($parts as $p) {
                        $p = trim($p);
                        if ($p && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                            $agencyEmails[] = $p;
                        }
                    }
                }
            }
            $agencyEmails = array_unique($agencyEmails);
            sort($agencyEmails);
        }

        // Locking Check (Initial)
        $lockInfo = null;
        if ($id) {
            $lockInfo = $lockManager->checkLock($id, $_SESSION['nome_completo']);
        }
        ?>
        <div class="row">
            <div class="col-12 mb-3">
            <a href="?p=dashboard" class="btn btn-outline-secondary" onclick="goBack(); return false;"><i class="fas fa-arrow-left me-1"></i> Voltar</a>
            <button type="button" class="btn btn-navy ms-2" onclick="startNewService()">Iniciar novo atendimento</button>
            </div>
            <div class="col-md-12">
                <ul class="nav nav-tabs mb-3 justify-content-center">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dados">Dados do Processo</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-textos">Registro de Envio</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-registros">Registros de Processo</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-dados">
                        <?php if ($lockInfo && $lockInfo['locked']): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-lock me-2"></i> 
                                Este processo está sendo editado por <strong><?= $lockInfo['by'] ?></strong>. Edição bloqueada.
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="form_processo">
                            <input type="hidden" name="acao" value="salvar_processo">
                            
                            <?php 
                            $isCardOpen = false;
                            // Check if first is title
                            $firstIsTitle = !empty($procFields) && ($procFields[0]['type'] ?? '') === 'title';
                            
                            if (!$firstIsTitle) {
                                 echo '<div class="card card-custom p-4 mb-4">';
                                 echo '<h5 class="text-navy fw-bold border-bottom pb-2">Dados do Processo / Portabilidade</h5>';
                                 echo '<div class="row g-3 mb-4">';
                                 $isCardOpen = true;
                            }
                            
                            foreach($procFields as $f): 
                                if (($f['type'] ?? '') === 'title') {
                                    if (isset($f['deleted']) && $f['deleted']) {
                                        continue;
                                    }
                                    if ($isCardOpen) {
                                        echo '</div></div>'; // Close row and card
                                    }
                                    echo '<div class="card card-custom p-4 mb-4">';
                                    echo '<h5 class="text-navy fw-bold border-bottom pb-2">' . htmlspecialchars($f['label']) . '</h5>';
                                    echo '<div class="row g-3 mb-4">';
                                    $isCardOpen = true;
                                    continue;
                                }
                                
                                if (!$isCardOpen) {
                                     echo '<div class="card card-custom p-4 mb-4">';
                                     echo '<h5 class="text-navy fw-bold border-bottom pb-2">Dados do Processo / Portabilidade</h5>';
                                     echo '<div class="row g-3 mb-4">';
                                     $isCardOpen = true;
                                }

                                // Removed skip logic to allow flexible ordering
                                // if(in_array($f['key'], ['CPF', 'AG', 'Numero_Portabilidade', 'Certificado', 'CERTIFICADO'])) continue;
                                $val = $getVal($processo, $f['key']);
                                        
                                        // Deleted field handling
                                        $isDeleted = isset($f['deleted']) && $f['deleted'];
                                        $hideClass = '';
                                        if ($isDeleted) {
                                            if (trim($val) === '') $hideClass = 'd-none deleted-field-row';
                                        }

                                        // Fix Date Format for input type=date
                                        if ($f['type'] == 'date' && !empty($val)) {
                                            $dtObj = DateTime::createFromFormat('d/m/Y', $val);
                                            if ($dtObj) $val = $dtObj->format('Y-m-d');
                                        }

                                        // Fix DateTime Local Format
                                        if ($f['type'] == 'datetime-local' && !empty($val)) {
                                            // Try d/m/Y H:i:s or d/m/Y H:i
                                            $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $val);
                                            if (!$dtObj) $dtObj = DateTime::createFromFormat('d/m/Y H:i', $val);
                                            if ($dtObj) $val = $dtObj->format('Y-m-d\TH:i');
                                        }

                                        $isAtendente = ($f['key'] == 'Nome_atendente');
                                        // If it's a new process and attendant is empty, default to current user
                                        if ($isAtendente && !$val) { $val = $_SESSION['nome_completo']; }
                                    ?>
                                    <div class="col-md-4 <?= $hideClass ?>" data-field-key="<?= $f['key'] ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label-custom">
                                                <?= $f['label'] ?> 
                                                <?php if($f['required'] ?? false): ?><span class="text-danger small">*</span><?php endif; ?>
                                            </label> 
                                            <?php if($isDeleted): ?>
                                                <button type="button" class="btn btn-sm btn-link text-warning p-0" onclick="reactivateField('Base_processos_schema', '<?= $f['key'] ?>')" title="Reativar Campo"><i class="fas fa-undo"></i></button>
                                            <?php endif; ?>
                                        </div>
                                        <?php 
                                            $req = ($f['required'] ?? false) ? 'required' : ''; 
                                            $disabled = $isDeleted ? 'disabled' : '';
                                        ?>
                                        <?php if($f['key'] == 'Numero_Portabilidade'): ?>
                                            <div class="input-group">
                                                <input type="text" name="Numero_Portabilidade" id="proc_port" class="form-control form-control-custom fw-bold" value="<?= htmlspecialchars($val ?: ($id ?? '')) ?>" required placeholder="Buscar/Criar...">
                                                <button type="button" class="btn btn-outline-secondary" onclick="checkProcess()" id="btn_search_port">
                                                    <i class="fas fa-search"></i>
                                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                        <?php elseif($f['key'] == 'CERTIFICADO' || $f['key'] == 'Certificado'): ?>
                                            <div class="input-group">
                                                <input type="text" name="CERTIFICADO" id="proc_cert" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> placeholder="Busca por Certificado...">
                                                <button type="button" class="btn btn-outline-secondary" onclick="checkCert()" id="btn_search_cert">
                                                    <i class="fas fa-search"></i>
                                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                        <?php elseif($f['key'] == 'CPF'): ?>
                                            <div class="input-group">
                                                <input type="text" name="CPF" id="cli_cpf" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?>>
                                                <button type="button" class="btn btn-outline-secondary" onclick="searchClient()" id="btn_search_cpf">
                                                    <i class="fas fa-search"></i>
                                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                        <?php elseif($f['key'] == 'AG'): ?>
                                            <div class="input-group">
                                                <input type="text" name="AG" id="ag_code" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?>>
                                                <button type="button" class="btn btn-outline-secondary" onclick="searchAgency()" id="btn_search_ag">
                                                    <i class="fas fa-search"></i>
                                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                        <?php elseif($f['type'] == 'textarea'): ?>
                                            <textarea name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" rows="1" <?= $req ?> <?= $disabled ?>><?= htmlspecialchars($val) ?></textarea>
                                        
                                        <?php elseif ($isAtendente): ?>
                                            <input type="text" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" readonly style="background-color: #e9ecef;">
                                            <input type="hidden" name="<?= $f['key'] ?>" value="<?= htmlspecialchars($val) ?>">

                                        <?php elseif($f['type'] == 'select' || $f['key'] == 'STATUS' || $f['key'] == 'Status_ocorrencia'): ?>
                                            <select name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-select form-select-custom" <?= $req ?> <?= $disabled ?>>
                                                <option value="">...</option>
                                                <?php 
                                                if($f['key'] == 'STATUS'): 
                                                    foreach($allStatus as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach;
                                                elseif($f['key'] == 'Status_ocorrencia'):
                                                    $optsOco = ['Procedente', 'Parcialmente Procedente', 'Improcedente'];
                                                    foreach($optsOco as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach;
                                                elseif ($f['key'] == 'UF'): 
                                                    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                                                    sort($ufs);
                                                    foreach($ufs as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach;
                                                else: 
                                                    // Handle user defined options from Config
                                                    $opts = [];
                                                    if(isset($f['options']) && $f['options']) {
                                                        $opts = array_map('trim', explode(',', $f['options']));
                                                    }
                                                    foreach($opts as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach; endif; ?>
                                            </select>
                                        <?php elseif($f['type'] == 'multiselect'): ?>
                                            <?php 
                                                $opts = [];
                                                if(isset($f['options']) && $f['options']) {
                                                    $opts = array_map('trim', explode(',', $f['options']));
                                                }
                                                // Handle val as array or comma separated string
                                                $selectedValues = [];
                                                if (is_array($val)) $selectedValues = $val;
                                                elseif ($val) $selectedValues = array_map('trim', explode(',', $val));
                                                
                                                $btnLabel = empty($selectedValues) ? 'Selecione...' : implode(', ', $selectedValues);
                                                if (strlen($btnLabel) > 30) $btnLabel = substr($btnLabel, 0, 27) . '...';
                                            ?>
                                            <div class="dropdown">
                                                <button class="btn btn-outline-secondary w-100 text-start dropdown-toggle bg-white form-control-custom" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btn_ms_<?= $f['key'] ?>">
                                                    <?= htmlspecialchars($btnLabel) ?>
                                                </button>
                                                <ul class="dropdown-menu w-100 p-2" style="max-height: 250px; overflow-y: auto;">
                                                <?php foreach($opts as $opt): 
                                                    $chkId = 'chk_' . $f['key'] . '_' . md5($opt);
                                                    $isChecked = in_array($opt, $selectedValues) ? 'checked' : '';
                                                ?>
                                                    <li class="form-check mb-1" onclick="event.stopPropagation()">
                                                        <input class="form-check-input ms-checkbox" type="checkbox" name="<?= $f['key'] ?>[]" value="<?= htmlspecialchars($opt) ?>" id="<?= $chkId ?>" <?= $isChecked ?> <?= $disabled ?> data-key="<?= $f['key'] ?>" onchange="updateMultiselectLabel(this)">
                                                        <label class="form-check-label" for="<?= $chkId ?>"><?= htmlspecialchars($opt) ?></label>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php elseif($f['type'] == 'money' || $f['key'] == 'VALOR DA PORTABILIDADE'): ?>
                                            <input type="text" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom money-mask" value="<?= htmlspecialchars($val) ?>" placeholder="R$ 0,00" <?= $req ?> <?= $disabled ?>>
                                        <?php elseif($f['type'] == 'number'): ?>
                                            <input type="number" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> <?= $disabled ?>>
                                        <?php elseif($f['type'] == 'custom'): ?>
                                            <input type="text" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> <?= $disabled ?> data-mask="<?= htmlspecialchars($f['custom_mask'] ?? '') ?>" data-case="<?= htmlspecialchars($f['custom_case'] ?? '') ?>" data-allowed="<?= htmlspecialchars($f['custom_allowed'] ?? 'all') ?>" oninput="applyCustomMask(this)">
                                        <?php else: ?>
                                            <input type="<?= ($f['type']=='date' || $f['type']=='datetime-local') ? $f['type'] : 'text' ?>" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> <?= $disabled ?>>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php 
                                    // Orphaned Fields Logic (Moved to correct card)
                                    if ($processo) {
                                        $allProcessKeys = array_keys($processo);
                                        $definedKeys = array_column($procFields, 'key');
                                        $definedKeys = array_merge($definedKeys, ['Numero_Portabilidade', 'CPF', 'AG', 'Certificado', 'Nome_atendente']);
                                        
                                        $orphanedKeys = array_diff($allProcessKeys, $definedKeys);
                                        if (!empty($orphanedKeys)) {
                                            foreach ($orphanedKeys as $k) {
                                                $val = $processo[$k] ?? '';
                                                if (trim($val) === '') continue; 
                                                echo '<div class="col-md-4">';
                                                echo '<label class="text-muted form-label-custom">' . $k . ' <i class="fas fa-history text-secondary" title="Campo Histórico"></i></label>';
                                                echo '<input type="text" class="form-control form-control-custom" value="' . htmlspecialchars($val) . '" readonly style="background-color: #f8f9fa;">';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($isCardOpen): ?>
                                    </div></div>
                                    <?php endif; ?>


                            <?php if($credito): ?>
                            <div id="server_credit_card" class="card card-custom p-4 mb-4 border-warning border-3">
                                <h5 class="text-warning fw-bold border-bottom pb-2"><i class="fas fa-coins me-2"></i>Identificação de Crédito</h5>
                                <div class="row g-3">
                                    <?php foreach($credito as $k => $v): ?>
                                    <div class="col-md-3"><label class="small text-muted"><?= $k ?></label><div class="fw-bold"><?= $v ?></div></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between">
                                <div id="div_delete_process">
                                    <?php if($processo): ?>
                                    <button type="button" class="btn btn-outline-danger" onclick="confirmDelete('<?= htmlspecialchars($processo['Numero_Portabilidade']) ?>')">Excluir Processo</button>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearForm()">Limpar</button>
                                    <button type="button" class="btn btn-navy" onclick="submitProcessForm(this)">Salvar Dados</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="tab-registros">
                        <div class="card card-custom p-4 mb-4">
                            <h5 class="text-navy mb-3"><i class="fas fa-clipboard-list me-2"></i>Registros de Processo</h5>
                            <div class="row g-3">
                                <?php 
                                $regFields = $config->getFields('Base_registros_schema');
                                if (!empty($regFields)):
                                    foreach($regFields as $f): 
                                        if (isset($f['deleted']) && $f['deleted']) continue;
                                        if (($f['type'] ?? '') === 'title') {
                                            echo '<div class="col-12"><h6 class="text-navy fw-bold border-bottom pb-2 mt-3">' . htmlspecialchars($f['label']) . '</h6></div>';
                                            continue;
                                        }
                                        $req = ($f['required'] ?? false) ? 'required' : '';
                                        $val = '';
                                        if ($processo && isset($processo[$f['key']])) $val = $processo[$f['key']];
                                ?>
                                <div class="col-md-4">
                                    <label><?= $f['label'] ?></label> <?php if($req): ?><span class="text-danger">*</span><?php endif; ?>
                                    <?php if($f['type'] == 'textarea'): ?>
                                        <textarea name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" rows="1" <?= $req ?>><?= htmlspecialchars($val) ?></textarea>
                                    <?php elseif($f['type'] == 'select'): ?>
                                        <select name="reg_new_<?= $f['key'] ?>" class="form-select reg-new-field" <?= $req ?>>
                                            <option value="">...</option>
                                            <?php 
                                                $opts = [];
                                                if(isset($f['options']) && $f['options']) {
                                                    $opts = array_map('trim', explode(',', $f['options']));
                                                }
                                                foreach($opts as $opt): ?>
                                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif($f['type'] == 'multiselect'): ?>
                                        <?php 
                                            $opts = [];
                                            if(isset($f['options']) && $f['options']) {
                                                $opts = array_map('trim', explode(',', $f['options']));
                                            }
                                            $selectedValues = [];
                                            if (is_array($val)) $selectedValues = $val;
                                            elseif ($val) $selectedValues = array_map('trim', explode(',', $val));
                                            
                                            $btnLabel = empty($selectedValues) ? 'Selecione...' : implode(', ', $selectedValues);
                                            if (strlen($btnLabel) > 30) $btnLabel = substr($btnLabel, 0, 27) . '...';
                                        ?>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary w-100 text-start dropdown-toggle bg-white form-control-custom" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btn_ms_reg_<?= $f['key'] ?>">
                                                <?= htmlspecialchars($btnLabel) ?>
                                            </button>
                                            <ul class="dropdown-menu w-100 p-2" style="max-height: 250px; overflow-y: auto;">
                                            <?php foreach($opts as $opt): 
                                                $chkId = 'chk_reg_' . $f['key'] . '_' . md5($opt);
                                                $isChecked = in_array($opt, $selectedValues) ? 'checked' : '';
                                            ?>
                                                <li class="form-check mb-1" onclick="event.stopPropagation()">
                                                    <input class="form-check-input ms-checkbox reg-new-field" type="checkbox" name="reg_new_<?= $f['key'] ?>[]" value="<?= htmlspecialchars($opt) ?>" id="<?= $chkId ?>" <?= $isChecked ?> <?= ($req ? 'data-required="true"' : '') ?> data-key="reg_<?= $f['key'] ?>" onchange="updateMultiselectLabel(this)">
                                                    <label class="form-check-label" for="<?= $chkId ?>"><?= htmlspecialchars($opt) ?></label>
                                                </li>
                                            <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php elseif($f['type'] == 'date'): ?>
                                        <input type="date" name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" value="<?= htmlspecialchars($val) ?>" <?= $req ?>>
                                    <?php else: ?>
                                        <input type="text" name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" value="<?= htmlspecialchars($val) ?>" <?= $req ?>>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; else: ?>
                                <div class="col-12 text-muted">Nenhum campo configurado. Vá em Configurações > Campos de Registros.</div>
                                <?php endif; ?>
                                
                                <div class="col-12 text-end mt-3">
                                        <button type="button" class="btn btn-primary" onclick="saveProcessRecord(this)"><i class="fas fa-save me-1"></i> Salvar Registro</button>
                                </div>
                            </div>
                            <hr class="my-4">
                            <h5 class="text-navy mb-3">Histórico de Registros</h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped small">
                                    <thead><tr>
                                        <th>Data</th><th>Usuário</th>
                                        <?php 
                                        $regHeaders = $config->getFields('Base_registros_schema');
                                        foreach($regHeaders as $f) {
                                            if (isset($f['deleted']) && $f['deleted']) continue;
                                            echo '<th>' . htmlspecialchars($f['label']) . '</th>';
                                        }
                                        ?>
                                    </tr></thead>
                                    <tbody id="history_registros_body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-textos">
                        <div class="card card-custom p-4 mb-4">
                            <h5 class="text-navy mb-3"><i class="fas fa-envelope me-2"></i>Registro de Envio</h5>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label>Selecione Modelos (Flag Múltiplos)</label>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary w-100 text-start dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btnTemplates">
                                            Selecione Modelos...
                                        </button>
                                        <ul class="dropdown-menu w-100 p-2" style="max-height: 300px; overflow-y: auto;">
                                            <?php foreach($templates->getAll() as $t): ?>
                                                <li class="form-check mb-1">
                                                    <input class="form-check-input tpl-checkbox" type="checkbox" value="<?= htmlspecialchars($t['id']) ?>" id="tpl_<?= htmlspecialchars($t['id']) ?>" onchange="generateText()">
                                                    <label class="form-check-label" for="tpl_<?= htmlspecialchars($t['id']) ?>">
                                                        <?= htmlspecialchars($t['titulo']) ?>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label>Corpo do Texto</label>
                                    <textarea id="tpl_result" class="form-control" rows="8"></textarea>
                                    <button type="button" class="btn btn-sm btn-navy mt-1" onclick="copyToClipboard()"><i class="fas fa-copy"></i> Copiar</button>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="button" class="btn btn-success" onclick="saveHistory(this)"><i class="fas fa-paper-plane me-1"></i> Registrar Envio</button>
                                </div>
                            </div>
                            <hr class="my-4">
                            <h5 class="text-navy mb-3">Histórico de Envios</h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped small">
                                    <thead><tr>
                                        <th>Data</th><th>Usuário</th><th>Modelo</th>
                                    </tr></thead>
                                    <tbody id="history_table_body">
                                        <?php 
                                        if ($processo) {
                                            $hist = $templates->getHistory($processo['Numero_Portabilidade']);
                                            foreach ($hist as $h) {
                                                $data = htmlspecialchars($h['DATA'] ?? '');
                                                $user = htmlspecialchars($h['USUARIO'] ?? '');
                                                $modelo = htmlspecialchars($h['MODELO'] ?? '');
                                                echo "<tr><td>{$data}</td><td>{$user}</td><td>{$modelo}</td></tr>";
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <div id="page-lembretes" class="page-section" style="<?= $page=='lembretes'?'':'display:none' ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-navy mb-0"><i class="fas fa-calendar-check me-2"></i>Lembretes</h3>
            <div>
                <button class="btn btn-sm btn-outline-success me-2" onclick="downloadLembretesExcel(this)"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="filterLembretes()"><i class="fas fa-sync-alt"></i> Atualizar</button>
            </div>
        </div>
        
        <div class="card card-custom p-4 mb-4">
             <form id="form_lembretes_filter" onsubmit="filterLembretes(event)">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label>Data Lembrete Início</label>
                        <input type="date" name="fLembreteIni" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>Data Lembrete Fim</label>
                        <input type="date" name="fLembreteFim" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label>Busca Global</label>
                        <input type="text" name="fBuscaGlobal" class="form-control" placeholder="Cliente, CPF, Portabilidade...">
                    </div>
                    <div class="col-md-2">
                        <div class="d-flex gap-2">
                            <button class="btn btn-navy btn-sm w-100"><i class="fas fa-search"></i> Filtrar</button>
                        </div>
                    </div>
                </div>
             </form>
        </div>

        <div class="card card-custom p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm small" id="lembretes_table">
                    <!-- Loaded via AJAX -->
                    <thead><tr><th class="text-center p-4 text-muted">Carregando...</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="lembretes_pagination_container" class="mt-3"></div>
        </div>
    </div>

    <div id="page-base" class="page-section" style="<?= $page=='base'?'':'display:none' ?>">
       <h3 class="text-navy mb-4">Gestão de Bases</h3>

       <div class="card card-custom p-3 mb-4">
           <ul class="nav nav-pills nav-fill gap-3" id="base-tab">
               <li class="nav-item">
                   <button class="nav-link active rounded-pill" id="tab-cred" onclick="switchBase('Identificacao_cred.json')">Identificação de Crédito</button>
               </li>
               <li class="nav-item">
                   <button class="nav-link rounded-pill" id="tab-proc" onclick="switchBase('Processos')">Registros</button>
               </li>
           </ul>
       </div>

       <?php
       // Logic for Base View
       $cfBusca = $_GET['cfBusca'] ?? '';
       $cpPagina = $_GET['cpPagina'] ?? 1;
       
       // Check if filter is active
       $isFilter = isset($_GET['cfDataIni']) || isset($_GET['cfDataFim']) || isset($_GET['cfBusca']);
       
       if (!$isFilter) {
           // Default to today
           $cfDataIni = date('Y-m-d');
           $cfDataFim = date('Y-m-d');
       } else {
           $cfDataIni = $_GET['cfDataIni'] ?? '';
           $cfDataFim = $_GET['cfDataFim'] ?? '';
       }

       $cFilters = [];
       if ($cfBusca) $cFilters['global'] = $cfBusca;
       
       if ($cfDataIni || $cfDataFim) {
           $cFilters['callback'] = function($row) use ($cfDataIni, $cfDataFim) {
               $d = $row['DATA_DEPOSITO'] ?? '';
               if (!$d) return false;
               $dt = DateTime::createFromFormat('d/m/Y', $d);
               if (!$dt) return false;
               
               if ($cfDataIni) {
                   $di = DateTime::createFromFormat('Y-m-d', $cfDataIni);
                   if ($dt < $di) return false;
               }
               if ($cfDataFim) {
                   $df = DateTime::createFromFormat('Y-m-d', $cfDataFim);
                   if ($dt > $df) return false;
               }
               return true;
           };
       }

       $base = 'Identificacao_cred.json';
       $cRes = $db->select($base, $cFilters, $cpPagina, 50, null, true);
       $creditos = $cRes['data'];
       $credTotal = $cRes['total'];
       $credCountStr = str_pad($credTotal, 2, '0', STR_PAD_LEFT);
       ?>
       
       <?php if (isset($showPreview) && $showPreview): ?>
           <div class="card card-custom p-4 mb-4 border-warning">
                <h5 class="text-warning fw-bold mb-3"><i class="fas fa-eye me-2"></i>Pré-visualização da Importação</h5>
                <div class="alert alert-info small">
                    <i class="fas fa-info-circle me-1"></i> Verifique os dados abaixo antes de confirmar. Registros com datas inválidas não serão importados.
                </div>
                
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-bordered table-striped small">
                        <thead>
                            <tr class="bg-light">
                                <th>#</th>
                                <?php 
                                $previewBase = $_SESSION['upload_preview_base'] ?? '';
                                $previewFields = $config->getFields($previewBase);
                                $previewHeaders = [];
                                
                                foreach($previewFields as $f) {
                                    if(!isset($f['deleted']) || !$f['deleted']) {
                                        if (isset($f['type']) && $f['type'] === 'title') continue;
                                        $previewHeaders[] = $f['key'];
                                    }
                                }
                                
                                if (empty($previewHeaders)) {
                                    // Fallback defaults
                                    if (stripos($previewBase, 'client') !== false) {
                                        $previewHeaders = ['Nome', 'CPF'];
                                    } elseif (stripos($previewBase, 'agenc') !== false) {
                                        $previewHeaders = ['AG', 'UF', 'SR', 'NOME SR', 'FILIAL', 'E-MAIL AG', 'E-MAILS SR', 'E-MAILS FILIAL', 'E-MAIL GERENTE'];
                                    } elseif (stripos($previewBase, 'Processos') !== false || stripos($previewBase, 'Base_processos') !== false) {
                                        $previewHeaders = ['DATA', 'Ocorrencia', 'Status_ocorrencia', 'Nome_atendente', 'Numero_Portabilidade', 'CPF', 'AG', 'Certificado', 'PROPOSTA', 'VALOR DA PORTABILIDADE', 'AUT_PROPOSTA', 'PROPOSTA_2', 'MOTIVO DE CANCELAMENTO', 'STATUS', 'Data Cancelamento', 'OBSERVAÇÃO'];
                                    } else {
                                        $previewHeaders = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', 'PORTABILIDADE', 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
                                    }
                                }
                                
                                foreach($previewHeaders as $h) {
                                    // Use label if available
                                    $lbl = $h;
                                    foreach($previewFields as $f) { if($f['key'] == $h) { $lbl = $f['label']; break; } }
                                    echo '<th>' . htmlspecialchars($lbl) . '</th>';
                                }
                                ?>
                                <th>Validação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($_SESSION['upload_preview'] as $idx => $row): ?>
                            <tr class="<?= isset($row['DATA_ERROR']) ? 'table-danger' : '' ?>">
                                <td><?= $idx + 1 ?></td>
                                <?php foreach($previewHeaders as $h): ?>
                                    <td><?= htmlspecialchars($row[$h] ?? '') ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if(isset($row['DATA_ERROR'])): ?>
                                        <span class="badge bg-danger">Data Inválida</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <form method="POST" onsubmit="handleFormSubmit(this)">
                        <input type="hidden" name="acao" value="cancel_upload">
                        <button class="btn btn-outline-secondary">Cancelar</button>
                    </form>
                    <form method="POST" onsubmit="handleFormSubmit(this)">
                        <input type="hidden" name="acao" value="confirm_upload">
                        <button class="btn btn-primary"><i class="fas fa-check me-1"></i> Confirmar Importação</button>
                    </form>
                </div>
           </div>
       <?php endif; ?>

       <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 id="updateBaseTitle">Atualizar Base</h5>
                <div class="d-flex gap-2">
                    <button onclick="openPasteModal()" class="btn btn-info btn-sm text-white"><i class="fas fa-paste me-1"></i> Colar Dados</button>
                    <a href="#" onclick="downloadBase(this)" class="btn btn-success btn-sm"><i class="fas fa-file-excel me-1"></i> Baixar</a>
                </div>
            </div>
            <form id="form_limpar_base" method="POST" style="display:none">
                <input type="hidden" name="acao" value="limpar_base">
                <input type="hidden" name="base" id="limpar_base_target">
            </form>
       </div>

       <!-- VISUALIZACAO -->
       <div class="card card-custom p-4 mb-4">
            <h5 class="text-navy mb-3">Visualização</h5>
            <form onsubmit="filterBase(event)" class="row g-3 align-items-end" id="form_base_filter">
                <input type="hidden" name="p" value="base">
                <div class="col-md-3">
                    <label>Data Início</label>
                    <input type="date" name="cfDataIni" class="form-control">
                </div>
                <div class="col-md-3">
                    <label>Data Fim</label>
                    <input type="date" name="cfDataFim" class="form-control">
                </div>
                <div class="col-md-3 base-process-filter" style="display:none">
                    <label>Status</label>
                    <select name="fStatus" id="base_fStatus" class="form-select"></select>
                </div>
                <div class="col-md-3 base-process-filter" style="display:none">
                    <label>Atendente</label>
                    <select name="fAtendente" id="base_fAtendente" class="form-select"></select>
                </div>
                <div class="col-md-4">
                    <label>Busca Global</label>
                    <input type="text" name="cfBusca" class="form-control" placeholder="Pesquisar...">
                </div>
                <div class="col-md-2">
                    <div class="d-flex gap-2">
                        <button class="btn btn-navy btn-sm"><i class="fas fa-search"></i> Filtrar</button>
                        <button type="button" onclick="clearBaseFilters()" class="btn btn-outline-secondary btn-sm">Limpar</button>
                    </div>
                </div>
            </form>
       </div>

       <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 class="text-navy" id="base_registros_header">Registros (<?= $credCountStr ?>)</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-navy btn-sm me-2" onclick="editSelectedBase()"><i class="fas fa-edit me-1"></i> Edição Selecionada</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteSelectedBase(this)"><i class="fas fa-trash me-1"></i> Excluir Selecionados</button>
                    <button class="btn btn-success btn-sm" onclick="openBaseModal(null, this)"><i class="fas fa-plus"></i> Adicionar</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm small align-middle" id="base_table">
                    <!-- Content loaded via AJAX -->
                    <tbody id="cred_table_body">
                         <tr><td class="text-center p-5">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="cred_pagination_container"></div>
       </div>

       <!-- Modal Base Record (Dynamic) -->
       <div class="modal fade" id="modalBase" tabindex="-1">
           <div class="modal-dialog modal-lg">
               <form class="modal-content" id="form_base" onsubmit="event.preventDefault(); saveBase();">
                   <div class="modal-header bg-navy text-white">
                       <h5 class="modal-title">Dados do Registro</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                   </div>
                   <div class="modal-body">
                       <input type="hidden" name="original_id" id="base_original_id">
                       <input type="hidden" name="base" id="base_target_name">
                       <div class="row g-3" id="modal_base_fields">
                           <!-- JS Injected -->
                       </div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                       <button class="btn btn-navy">Salvar</button>
                   </div>
               </form>
           </div>
       </div>

       <!-- Modal Bulk Edit -->
       <div class="modal fade" id="modalBulkEdit" tabindex="-1">
           <div class="modal-dialog modal-lg">
               <form class="modal-content" id="form_bulk_edit" onsubmit="event.preventDefault(); saveBulkBase();">
                   <div class="modal-header bg-navy text-white">
                       <h5 class="modal-title">Edição em Massa</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                   </div>
                   <div class="modal-body">
                       <div class="alert alert-info small">
                           <i class="fas fa-info-circle me-1"></i> Apenas campos com valores idênticos entre os registros selecionados podem ser editados.
                       </div>
                       <input type="hidden" name="base" id="bulk_base_target">
                       <div class="row g-3" id="modal_bulk_fields">
                           <!-- JS Injected -->
                       </div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                       <button class="btn btn-navy">Aplicar Alterações</button>
                   </div>
               </form>
           </div>
       </div>

       <div class="modal fade" id="modalCredit" tabindex="-1">
           <div class="modal-dialog modal-lg">
               <form class="modal-content" id="form_credit" onsubmit="event.preventDefault(); saveCredit();">
                   <div class="modal-header bg-navy text-white">
                       <h5 class="modal-title">Editar Crédito</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                   </div>
                   <div class="modal-body">
                       <input type="hidden" name="original_port" id="cred_original_port">
                       <div class="row g-3">
                           <div class="col-md-4">
                               <label class="form-label">Portabilidade</label>
                               <input type="text" name="PORTABILIDADE" id="cred_PORTABILIDADE" class="form-control" required>
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Status</label>
                               <input type="text" name="STATUS" id="cred_STATUS" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Número Depósito</label>
                               <input type="text" name="NUMERO_DEPOSITO" id="cred_NUMERO_DEPOSITO" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Data Depósito</label>
                               <input type="date" name="DATA_DEPOSITO" id="cred_DATA_DEPOSITO" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Valor Depósito</label>
                               <input type="text" name="VALOR_DEPOSITO_PRINCIPAL" id="cred_VALOR_DEPOSITO_PRINCIPAL" class="form-control money-mask">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Certificado</label>
                               <input type="text" name="CERTIFICADO" id="cred_CERTIFICADO" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Status 2</label>
                               <input type="text" name="STATUS_2" id="cred_STATUS_2" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">CPF</label>
                               <input type="text" name="CPF" id="cred_CPF" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Agência</label>
                               <input type="text" name="AG" id="cred_AG" class="form-control">
                           </div>
                           <div class="col-12">
                               <label class="form-label">Texto Pagamento</label>
                               <textarea name="TEXTO_PAGAMENTO" id="cred_TEXTO_PAGAMENTO" class="form-control" rows="2"></textarea>
                           </div>
                       </div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                       <button class="btn btn-navy">Salvar</button>
                   </div>
               </form>
           </div>
       </div>

    </div>
    <div id="page-config" class="page-section" style="<?= $page=='config'?'':'display:none' ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-navy mb-0">Configurações de Campos</h3>
        </div>
        <div class="row mb-5">
            <?php foreach(['Base_processos_schema' => 'Processos', 'Base_registros_schema' => 'Campos de Registros', 'Identificacao_cred.json' => 'Identificação de Crédito'] as $file => $label): ?>
            <div class="col-md-3">
                <div class="card card-custom p-3 h-100">
                    <h5 class="text-navy"><?= $label ?> <small class="text-muted fs-6">(Arraste para ordenar)</small></h5>
                    <ul class="list-group list-group-flush mb-3 sortable-list" data-file="<?= $file ?>">
                        <?php foreach($config->getFields($file) as $f): 
                            if(isset($f['deleted']) && $f['deleted']) continue;
                            // Only lock primary identifiers. Ocorrencia etc should be removable if duplicated or not needed.
                            $lockedFields = ['Numero_Portabilidade', 'CPF', 'AG', 'Nome', 'STATUS', 'DATA', 'VALOR DA PORTABILIDADE', 'Nome_atendente', 'PORTABILIDADE']; 
                            $isLocked = ($file !== 'Base_registros_schema') && in_array($f['key'], $lockedFields);
                            $isTitle = (($f['type'] ?? '') === 'title');
                            $liClass = $isTitle ? 'list-group-item-secondary fw-bold' : '';
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center <?= $liClass ?>" data-key="<?= $f['key'] ?>">
                            <div>
                                <i class="fas fa-grip-vertical text-muted me-2 handle"></i> 
                                <?= $f['label'] ?> 
                                <?php if(!$isTitle): ?>
                                    <small class="text-muted">(<?= $f['type'] ?>)</small> 
                                    <?php if($f['required'] ?? false): ?><span class="text-danger">*</span><?php endif; ?>
                                <?php else: ?>
                                    <small class="text-muted fst-italic">(Título/Seção)</small>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-link text-info" onclick='editField(<?= json_encode($f) ?>, "<?= $file ?>")'><i class="fas fa-pen"></i></button>
                                <?php if(!$isLocked): ?>
                                <button class="btn btn-sm btn-link text-danger" onclick="removeField('<?= $file ?>', '<?= $f['key'] ?>')"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-link text-muted" disabled title="Campo Protegido"><i class="fas fa-lock"></i></button>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="addFieldModal('<?= $file ?>')">Add Campo</button>
                        <button class="btn btn-sm btn-outline-secondary flex-grow-1" onclick="addTitleModal('<?= $file ?>')">Add Título</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <h3 class="text-navy mb-4">Modelos de Textos</h3>
        <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 class="text-navy">Modelos Cadastrados</h5>
                <button class="btn btn-navy btn-sm" onclick="modalTemplate()">Novo Modelo</button>
            </div>
            <table class="table table-hover">
                <thead><tr><th>Título</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach($templates->getAll() as $t): ?>
                    <tr>
                        <td><?= $t['titulo'] ?></td>
                        <td>
                            <button class="btn btn-sm btn-link text-info" onclick='editTemplate(<?= json_encode($t) ?>)'><i class="fas fa-pen"></i></button>
                            <button class="btn btn-sm btn-link text-danger" onclick="confirmTemplateDelete('<?= $t['id'] ?>')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProcessList" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-navy text-white">
                <h5 class="modal-title">Processos Encontrados</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Existem processos vinculados a este CPF. Selecione para abrir:</p>
                <div class="list-group" id="process_list_group"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTemplate" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" onsubmit="event.preventDefault(); submitConfigForm(this, 'ajax_salvar_template')">
            <div class="modal-header"><h5 class="modal-title">Modelo de Texto / Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="salvar_template">
                <input type="hidden" name="id_template" id="mt_id">
                <div class="mb-3"><label>Título</label><input class="form-control" name="titulo" id="mt_titulo" required></div>
                <div class="mb-3">
                    <label>Corpo do Texto (Use {CPF}, {Nome}, {Numero_Portabilidade}...)</label>
                    <textarea class="form-control" name="corpo" id="mt_corpo" rows="10" required></textarea>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-navy">Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalField" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" onsubmit="event.preventDefault(); submitConfigForm(this, 'ajax_salvar_campo')">
            <div class="modal-header"><h5 class="modal-title">Configurar Campo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="salvar_campo">
                <input type="hidden" name="arquivo_base" id="field_file">
                <input type="hidden" name="old_key" id="field_old_key">
                <div class="mb-3" id="div_field_label"><label>Nome (Label)</label><input class="form-control" name="label" id="field_label" required></div>
                <div class="mb-3" id="div_field_key"><label>Chave (Coluna)</label><input class="form-control" name="key" id="field_key" required placeholder="Sem espaços"></div>
                <div class="mb-3" id="div_field_type">
                    <label>Tipo</label>
                    <select name="type" id="field_type" class="form-select" onchange="toggleFieldOptions(this.value)">
                        <option value="text">Texto</option>
                        <option value="number">Número</option>
                        <option value="date">Data</option>
                        <option value="money">Moeda</option>
                        <option value="select">Lista</option>
                        <option value="multiselect">Múltipla Escolha (Flag)</option>
                        <option value="textarea">Texto Longo</option>
                        <option value="title">Título/Seção</option>
                        <option value="custom">Personalizável</option>
                    </select>
                </div>
                <div class="mb-3" id="div_options" style="display:none">
                    <label>Opções (separadas por vírgula)</label>
                    <input class="form-control" name="options" id="field_options" placeholder="Ex: Sim, Não, Talvez">
                </div>
                <div id="div_custom_config" style="display:none">
                    <div class="mb-3">
                        <label>Máscara / Formato (Ex: 000.000.000-00, TEXTO-0000)</label>
                        <input class="form-control" name="custom_mask" id="field_custom_mask" placeholder="Use 0 para números, A para letras, * para qualquer">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Caixa do Texto</label>
                            <select name="custom_case" id="field_custom_case" class="form-select">
                                <option value="">Normal</option>
                                <option value="upper">Maiúsculas</option>
                                <option value="lower">Minúsculas</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Permitir</label>
                            <select name="custom_allowed" id="field_custom_allowed" class="form-select">
                                <option value="all">Tudo</option>
                                <option value="numbers">Apenas Números</option>
                                <option value="letters">Apenas Letras</option>
                                <option value="alphanumeric">Alfanumérico</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-check mb-3" id="div_field_required">
                    <input class="form-check-input" type="checkbox" name="required" id="field_required">
                    <label class="form-check-label" for="field_required">Campo Obrigatório</label>
                </div>
                <div class="form-check mb-3" id="div_field_show_reminder">
                    <input class="form-check-input" type="checkbox" name="show_reminder" id="field_show_reminder">
                    <label class="form-check-label" for="field_show_reminder">Exibir em Lembretes</label>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-navy">Salvar</button></div>
        </form>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none">
    <input type="hidden" name="acao" value="excluir_processo">
    <input type="hidden" name="id_exclusao" id="del_id">
</form>

<form id="deleteTemplateForm" method="POST" style="display:none">
    <input type="hidden" name="acao" value="excluir_template">
    <input type="hidden" name="id_exclusao" id="del_tpl_id">
</form>

<form id="deleteFieldForm" method="POST" style="display:none">
    <input type="hidden" name="acao" value="remover_campo">
    <input type="hidden" name="arquivo_base" id="del_field_file">
    <input type="hidden" name="key" id="del_field_key">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function validateElements(elements) {
        var firstInvalid = null;
        var isValid = true;
        elements.forEach(el => {
             if (el.type === 'checkbox' || el.type === 'radio') return; 
             el.classList.remove('is-invalid');
             
             if (!el.checkValidity()) {
                 el.classList.add('is-invalid');
                 if(isValid) firstInvalid = el;
                 isValid = false;
                 el.addEventListener('input', function() {
                     if(this.checkValidity()) this.classList.remove('is-invalid');
                 });
             }
        });
        if (firstInvalid) {
            firstInvalid.scrollIntoView({behavior: 'smooth', block: 'center'});
            firstInvalid.focus();
        }
        return isValid;
    }

    const CURRENT_USER = "<?= $_SESSION['nome_completo'] ?? '' ?>";
    var currentLoadedPort = null;
    var currentDashboardPage = 1;
    var currentDashSortCol = 'DATA';
    var currentDashSortDir = 'desc';
    var currentBaseSortCol = '';
    var currentBaseSortDir = '';

    function refreshCurrentView() {
        window.location.reload();
    }

    function setDashSort(col) {
        if (currentDashSortCol === col) {
            currentDashSortDir = (currentDashSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            currentDashSortCol = col;
            // Smart Defaults
            if (['DATA', 'CPF', 'Numero_Portabilidade', 'VALOR DA PORTABILIDADE', 'Ultima_Alteracao'].includes(col)) {
                currentDashSortDir = 'desc';
            } else {
                currentDashSortDir = 'asc';
            }
        }
        filterDashboard(null, currentDashboardPage);
        updateSortIcons('dash_table_head', currentDashSortCol, currentDashSortDir);
    }

    function setBaseSort(col) {
        if (currentBaseSortCol === col) {
            currentBaseSortDir = (currentBaseSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            currentBaseSortCol = col;
            // Smart Defaults
            if (['DATA', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'CPF', 'PORTABILIDADE', 'Numero_Portabilidade', 'VALOR DA PORTABILIDADE'].includes(col)) {
                currentBaseSortDir = 'desc';
            } else {
                currentBaseSortDir = 'asc';
            }
        }
        renderBaseTable(1);
    }

    function updateSortIcons(containerId, sortCol, sortDir) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var headers = container.querySelectorAll('.sortable-header');
        headers.forEach(th => {
            var col = th.getAttribute('data-col');
            var iconContainer = th.querySelector('.sort-icon');
            if (iconContainer) {
                if (col === sortCol) {
                    iconContainer.innerHTML = (sortDir === 'asc') 
                        ? '<i class="fas fa-sort-up text-dark ms-1"></i>' 
                        : '<i class="fas fa-sort-down text-dark ms-1"></i>';
                } else {
                    iconContainer.innerHTML = '<i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i>';
                }
            }
        });
    }

    function setButtonLoading(btn, isLoading) {
        if (!btn) return;
        if (isLoading) {
            btn.dataset.originalHtml = btn.innerHTML;
            // Fix width to prevent collapse, but only if not already set (to handle multiple calls if needed)
            if (!btn.style.width) {
                 var w = btn.offsetWidth;
                 if (w > 0) btn.style.width = w + 'px';
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
            btn.style.width = '';
        }
    }

    function handleFormSubmit(form) {
        var btn = form.querySelector('button[type="submit"]') || form.querySelector('button:not([type="button"])');
        if(btn) setButtonLoading(btn, true);
        showLoading();
    }

    function downloadLembretesExcel(btn) {
        setButtonLoading(btn, true);
        
        var form = document.getElementById('form_lembretes_filter');
        var fd = new FormData(form || undefined);
        var params = new URLSearchParams(fd);
        params.append('acao', 'exportar_lembretes_excel');
        
        fetch('?' + params.toString(), {
            method: 'GET'
        })
        .then(response => {
            if (response.ok) {
                return response.blob().then(blob => {
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    var filename = 'lembretes.xls';
                    var disposition = response.headers.get('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) { 
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    setButtonLoading(btn, false);
                });
            } else {
                setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha ao baixar arquivo.', 'error');
            }
        })
        .catch(error => {
            setButtonLoading(btn, false);
            console.error('Download error:', error);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function downloadExcel(btn) {
        setButtonLoading(btn, true);
        
        var form = document.getElementById('form_dashboard_filter');
        var fd = new FormData(form);
        var params = new URLSearchParams(fd);
        params.append('acao', 'exportar_excel');
        
        fetch('?' + params.toString(), {
            method: 'GET'
        })
        .then(response => {
            if (response.ok) {
                return response.blob().then(blob => {
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    // Try to get filename from header
                    var filename = 'relatorio.xls';
                    var disposition = response.headers.get('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) { 
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    setButtonLoading(btn, false);
                });
            } else {
                setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha ao baixar arquivo.', 'error');
            }
        })
        .catch(error => {
            setButtonLoading(btn, false);
            console.error('Download error:', error);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    let loadingTimeout;
    function showLoading() { 
        var el = document.getElementById('loadingModal');
        var modal = bootstrap.Modal.getOrCreateInstance(el);
        modal.show();
        
        // Safety timeout (45 seconds)
        clearTimeout(loadingTimeout);
        loadingTimeout = setTimeout(() => {
            hideLoading();
            Swal.fire('Tempo Excedido', 'O processo está demorando muito ou o servidor não respondeu. Verifique se a ação foi concluída.', 'warning');
        }, 450000);
    }

    function hideLoading() {
        clearTimeout(loadingTimeout);
        
        var el = document.getElementById('loadingModal');
        var modal = bootstrap.Modal.getOrCreateInstance(el);
        if (modal) modal.hide();
        
        // Failsafe for stuck backdrops
        setTimeout(() => {
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Force hide modal element
            if(el) {
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                el.removeAttribute('aria-modal');
                el.removeAttribute('role');
            }
        }, 300);
    }
    function confirmClearCredits() {
        Swal.fire({
            title: 'Tem certeza?',
            text: "Esta ação excluirá TODOS os dados da Base de Créditos. Não poderá ser desfeito!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#003366',
            confirmButtonText: 'Sim, limpar tudo!',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                showLoading();
                document.getElementById('form_limpar_creditos').submit();
            }
        });
    }

    function reactivateField(file, key) {
        Swal.fire({
            title: 'Reativar Campo?', 
            text: 'Deseja reativar este campo na configuração?', 
            icon: 'question', 
            showCancelButton: true, 
            confirmButtonText: 'Sim, reativar!'
        }).then((r) => {
            if(r.isConfirmed) {
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_reactivate_field');
                fd.append('file', file);
                fd.append('key', key);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire({title: 'Sucesso', text: 'Campo reativado!', icon: 'success', timer: 1500, showConfirmButton: false});
                        
                        // DOM Manipulation to avoid reload
                        var container = document.querySelector('div[data-field-key="'+key+'"]');
                        if (container) {
                            container.classList.remove('d-none');
                            container.classList.remove('deleted-field-row');
                            
                            container.querySelectorAll('input, select, textarea').forEach(el => {
                                el.disabled = false;
                            });
                            
                            var btn = container.querySelector('button[onclick*="reactivateField"]');
                            if (btn) btn.remove();
                        }
                    } else {
                        Swal.fire('Erro', 'Falha ao reativar campo.', 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }

    function toggleSelectAll(source) {
        var checkboxes = document.querySelectorAll('.credit-checkbox, .base-checkbox');
        for(var i=0; i<checkboxes.length; i++) {
            checkboxes[i].checked = source.checked;
        }
    }

    function deleteSelectedCredits(btn) {
        var checkboxes = document.querySelectorAll('.credit-checkbox:checked');
        var ports = [];
        for(var i=0; i<checkboxes.length; i++) {
            ports.push(checkboxes[i].value);
        }

        if (ports.length === 0) {
            Swal.fire('Atenção', 'Selecione pelo menos um registro para excluir.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Excluir ' + ports.length + ' registros?', 
            text: "Esta ação não pode ser desfeita.", 
            icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir todos!'
        }).then((result) => {
            if (result.isConfirmed) {
                if(btn) setButtonLoading(btn, true); else showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_credit_bulk');
                for(var i=0; i<ports.length; i++) {
                    fd.append('ports[]', ports[i]);
                }
                
                fetch('', { method: 'POST', body: fd })
                .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
                .then(res => {
                    if(btn) setButtonLoading(btn, false); else hideLoading();
                    
                    if (res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        if(document.getElementById('base_table')) renderBaseTable();
                        else filterCredits();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(err => {
                    if(btn) setButtonLoading(btn, false); else hideLoading();
                    Swal.fire('Erro', 'Falha na comunicação.', 'error');
                });
            }
        });
    }

    function submitPasteData(e) {
        e.preventDefault();
        showLoading();
        var form = document.getElementById('form_paste_data');
        var fd = new FormData(form);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalPaste')).hide();
                var html = '<div class="card card-custom p-4 mb-4 border-warning">' + 
                    '<h5 class="text-warning fw-bold mb-3"><i class="fas fa-eye me-2"></i>Pré-visualização da Importação</h5>' +
                    '<div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i> Verifique os dados abaixo antes de confirmar.</div>' +
                    '<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">' + res.html + '</div>' +
                    '<div class="d-flex justify-content-end gap-2 mt-3">' +
                    '<button class="btn btn-outline-secondary" onclick="cancelUpload()">Cancelar</button>' +
                    '<button class="btn btn-primary" onclick="submitConfirmUpload()"><i class="fas fa-check me-1"></i> Confirmar Importação</button>' +
                    '</div></div>';
                document.getElementById('paste_preview_container').innerHTML = html;
                document.getElementById('paste_preview_container').scrollIntoView();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha ao processar.', 'error'); });
    }

    function submitConfirmUpload() {
        showLoading();
        var fd = new FormData();
        fd.append('acao', 'ajax_confirm_upload');
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                Swal.fire('Sucesso', res.message, 'success');
                document.getElementById('paste_preview_container').innerHTML = '';
                renderBaseTable();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha ao confirmar.', 'error'); });
    }

    function cancelUpload() {
        var fd = new FormData();
        fd.append('acao', 'ajax_cancel_upload');
        fetch('', { method: 'POST', body: fd });
        document.getElementById('paste_preview_container').innerHTML = '';
    }
    function confirmDelete(id) { 
        Swal.fire({
            title: 'Tem certeza?', text: "Deseja excluir este processo?", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) { 
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_excluir_processo');
                fd.append('id_exclusao', id);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        if(document.getElementById('page-dashboard').style.display !== 'none') {
                            filterDashboard(null, currentDashboardPage);
                        } else if(document.getElementById('page-detalhes').style.display !== 'none') {
                            goBack();
                        } else if(document.getElementById('page-base').style.display !== 'none') {
                            renderBaseTable(1);
                        }
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }
    function confirmTemplateDelete(id) {
        Swal.fire({
            title: 'Tem certeza?', text: "Deseja excluir este modelo?", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) { 
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_excluir_template');
                fd.append('id_exclusao', id);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        refreshConfigView();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }
    function toggleFieldOptions(type) {
        document.getElementById('div_options').style.display = (type == 'select' || type == 'multiselect') ? 'block' : 'none';
        document.getElementById('div_custom_config').style.display = (type == 'custom') ? 'block' : 'none';
    }

    function addFieldModal(file) { 
        document.getElementById('field_file').value = file; 
        document.getElementById('field_old_key').value = ''; 
        document.getElementById('field_label').value = ''; 
        document.getElementById('field_key').value = ''; 
        document.getElementById('field_key').readOnly = false; 
        document.getElementById('field_type').value = 'text'; 
        
        document.getElementById('div_field_key').style.display = 'block';
        document.getElementById('div_field_type').style.display = 'block';
        document.getElementById('div_field_required').style.display = 'block';
        document.querySelector('#modalField .modal-title').innerText = 'Adicionar Campo';
        
        document.getElementById('field_options').value = '';
        document.getElementById('field_custom_mask').value = '';
        document.getElementById('field_custom_case').value = '';
        document.getElementById('field_custom_allowed').value = 'all';
        
        toggleFieldOptions('text');
        
        document.getElementById('field_required').checked = false;
        document.getElementById('field_show_reminder').checked = false;
        new bootstrap.Modal(document.getElementById('modalField')).show(); 
    }

    function addTitleModal(file) {
        document.getElementById('field_file').value = file;
        document.getElementById('field_old_key').value = '';
        document.getElementById('field_label').value = '';
        
        var key = 'TITLE_' + Date.now();
        document.getElementById('field_key').value = key;
        document.getElementById('field_key').readOnly = true; 
        
        document.getElementById('field_type').value = 'title';
        
        document.getElementById('div_field_key').style.display = 'none';
        document.getElementById('div_field_type').style.display = 'none';
        document.getElementById('div_field_required').style.display = 'none';
        toggleFieldOptions('title');
        
        document.querySelector('#modalField .modal-title').innerText = 'Adicionar Título';
        
        new bootstrap.Modal(document.getElementById('modalField')).show();
    }

    function editField(f, file) { 
        document.getElementById('field_file').value = file; 
        document.getElementById('field_old_key').value = f.key; 
        document.getElementById('field_label').value = f.label; 
        document.getElementById('field_key').value = f.key; 
        document.getElementById('field_key').readOnly = true; 
        document.getElementById('field_type').value = f.type; 
        document.getElementById('field_options').value = f.options || '';
        
        document.getElementById('field_custom_mask').value = f.custom_mask || '';
        document.getElementById('field_custom_case').value = f.custom_case || '';
        document.getElementById('field_custom_allowed').value = f.custom_allowed || 'all';

        var isTitle = (f.type === 'title');
        
        document.getElementById('div_field_key').style.display = isTitle ? 'none' : 'block';
        document.getElementById('div_field_type').style.display = isTitle ? 'none' : 'block';
        document.getElementById('div_field_required').style.display = isTitle ? 'none' : 'block';
        
        toggleFieldOptions(f.type);
        
        document.querySelector('#modalField .modal-title').innerText = isTitle ? 'Editar Título' : 'Configurar Campo';
        
        document.getElementById('field_required').checked = (f.required === true || f.required === "true");
        document.getElementById('field_show_reminder').checked = (f.show_reminder === true || f.show_reminder === "true");
        new bootstrap.Modal(document.getElementById('modalField')).show(); 
    }
    function removeField(file, key) { 
        Swal.fire({ title: 'Remover Campo?', text: 'Deseja remover este campo?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sim, remover!' }).then((r) => {
            if(r.isConfirmed) { 
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_remover_campo');
                fd.append('arquivo_base', file);
                fd.append('key', key);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        refreshConfigView();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }

    function refreshConfigView() {
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_render_config' })
        .then(r => r.json())
        .then(res => {
            if(res.status == 'ok') {
                var container = document.getElementById('page-config');
                if(container) {
                    container.innerHTML = res.html;
                    var elList = document.querySelectorAll('.sortable-list');
                    elList.forEach(function(el) { new Sortable(el, { handle: '.handle', animation: 150, onEnd: function (evt) { var file = el.getAttribute('data-file'); var order = []; el.querySelectorAll('li').forEach(li => order.push(li.getAttribute('data-key'))); fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_reorder_fields&file=' + file + '&order[]=' + order.join('&order[]=') }); } }); });
                }
            }
        });
    }

    function submitConfigForm(form, action) {
        var btn = form.querySelector('button:not([type="button"])');
        setButtonLoading(btn, true);
        
        var fd = new FormData(form);
        fd.set('acao', action);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                Swal.fire('Sucesso', res.message, 'success');
                var modalEl = form.closest('.modal');
                var modal = bootstrap.Modal.getInstance(modalEl);
                if(modal) modal.hide();
                refreshConfigView();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function clearForm() {
        document.getElementById('form_processo').reset();
        isDirty = false;
    }
    
    // AJAX Functions
    function toggleLoading(btnId, show) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        var icon = btn.querySelector('.fa-search');
        var spinner = btn.querySelector('.spinner-border');
        if (show) {
            icon.classList.add('d-none');
            spinner.classList.remove('d-none');
            btn.disabled = true;
        } else {
            icon.classList.remove('d-none');
            spinner.classList.add('d-none');
            btn.disabled = false;
        }
    }

    function searchClient() {
        var cpf = document.getElementById('cli_cpf').value;
        if(!cpf) return;
        
        toggleLoading('btn_search_cpf', true);
        
        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_search_client&cpf=' + encodeURIComponent(cpf)
        })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        })
        .then(res => {
            if(res.found) {
                if(document.getElementById('cli_nome')) document.getElementById('cli_nome').value = res.data.Nome;
                for(var k in res.data) {
                    var el = document.querySelector('input[name="client_' + k + '"]');
                    if(el) el.value = res.data[k];
                }
                checkCPFProcesses(cpf); // Chain call
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Cliente não encontrado',
                    text: 'Preencha os dados para criar um novo registro.',
                    timer: 3000,
                    showConfirmButton: false
                });
                toggleLoading('btn_search_cpf', false);
            }
        })
        .catch(() => toggleLoading('btn_search_cpf', false));
    }

    function renderProcessList(processes) {
        var list = document.getElementById('process_list_group');
        list.innerHTML = '';
        processes.forEach(p => {
            var item = document.createElement('a');
            item.href = '?p=detalhes&id=' + encodeURIComponent(p.port);
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            
            var div = document.createElement('div');
            
            var strong = document.createElement('strong');
            strong.textContent = 'Port: ' + p.port;
            div.appendChild(strong);
            
            div.appendChild(document.createElement('br'));
            
            var small = document.createElement('small');
            small.textContent = (p.data || '');
            div.appendChild(small);
            
            item.appendChild(div);
            
            var span = document.createElement('span');
            var badgeClass = 'bg-secondary';
            
            if (p.source && p.source == 'Crédito') {
                badgeClass = 'bg-info text-dark';
                var icon = document.createElement('i');
                icon.className = 'fas fa-coins me-1';
                span.appendChild(icon);
            }
            
            span.className = 'badge ' + badgeClass;
            span.appendChild(document.createTextNode(p.status || ''));
            
            item.appendChild(span);
            list.appendChild(item);
        });
        new bootstrap.Modal(document.getElementById('modalProcessList')).show();
    }

    function checkCPFProcesses(cpf) {
        // Assume this is part of client search flow, so we stop loading here
        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_check_cpf_processes&cpf=' + encodeURIComponent(cpf)
        })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        })
        .then(res => {
            toggleLoading('btn_search_cpf', false);
            if(res.found) {
               renderProcessList(res.processes);
            }
        })
        .catch(() => toggleLoading('btn_search_cpf', false));
    }

    function searchAgency() {
        var ag = document.getElementById('ag_code').value;
        if(!ag) return;
        
        toggleLoading('btn_search_ag', true);
        
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_search_agency&ag=' + encodeURIComponent(ag) })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        }) 
        .then(res => { 
            toggleLoading('btn_search_ag', false);
            
            if(res.found) { 
                var agencyFields = ['UF', 'SR', 'NOME_SR', 'FILIAL', 'E-MAIL_AG', 'E-MAILS_SR', 'E-MAILS_FILIAL', 'E-MAIL_GERENTE'];
                
                agencyFields.forEach(function(k) {
                    var dataKey = Object.keys(res.data).find(dk => dk.toUpperCase() === k.toUpperCase());
                    if (dataKey) {
                        var el = document.getElementById('proc_' + k);
                        if (!el) {
                             el = document.getElementById('proc_' + k.toUpperCase());
                        }
                        
                        if(el) {
                            el.value = res.data[dataKey];
                            el.dispatchEvent(new Event('change'));
                        }
                    }
                });
                
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Dados da Agência carregados',
                    showConfirmButton: false,
                    timer: 3000
                });
            } else {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: 'Agência não encontrada na base',
                    showConfirmButton: false,
                    timer: 3000
                });
            }
        })
        .catch(() => toggleLoading('btn_search_ag', false));
    }

    function checkProcess() {
        var val = document.getElementById('proc_port').value;
        if(!val) return;
        
        var urlId = new URLSearchParams(window.location.search).get('id');
        // if(val == urlId) return; // Removed to allow re-search

        toggleLoading('btn_search_port', true);

        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_check_process&port=' + encodeURIComponent(val) })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        }) 
        .then(res => { 
            toggleLoading('btn_search_port', false);
            if(res.found) { 
                Swal.fire({title: 'Processo encontrado!', text: 'Deseja carregar?', showCancelButton: true})
                .then(r=>{ if(r.isConfirmed) { isDirty = false; loadProcess(res.port); } }); 
            } else {
                // If credit data found (whether process exists or not, but here process doesn't exist)
                if (res.credit_data) {
                    var c = res.credit_data;
                    
                    // Show Credit Card Dynamically
                    var cardHtml = '<div class="card card-custom p-4 mb-4 border-warning border-3" id="dyn_credit_card">' + 
                        '<h5 class="text-warning fw-bold border-bottom pb-2"><i class="fas fa-coins me-2"></i>Identificação de Crédito</h5>' +
                        '<div class="row g-3">';
                    for (var k in c) {
                        cardHtml += '<div class="col-md-3"><label class="small text-muted">' + k + '</label><div class="fw-bold">' + (c[k] || '-') + '</div></div>';
                    }
                    cardHtml += '</div></div>';
                    
                    // Insert after Agência card (which is the 3rd card-custom in form)
                    // Or just find where to insert. Let's look for .card-custom inside #form_processo
                    var cards = document.querySelectorAll('#form_processo .card-custom');
                    if(cards.length > 0) {
                        var lastCard = cards[cards.length - 1]; // Usually Agency or orphaned
                        // Insert after the last card
                        lastCard.insertAdjacentHTML('afterend', cardHtml);
                    }

                    Swal.fire({
                        title: 'Dados na Base de Crédito',
                        text: 'Processo não cadastrado, porém existem dados na Base de Crédito. Deseja realizar o autopreenchimento?',
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonText: 'Sim, preencher!',
                        cancelButtonText: 'Não'
                    }).then((r) => {
                        if (r.isConfirmed) {
                            // Auto-fill Logic
                            if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').value = c.VALOR_DEPOSITO_PRINCIPAL || '';
                            if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').dispatchEvent(new Event('input'));

                            // Support both keys
                            if(document.getElementById('proc_Certificado')) document.getElementById('proc_Certificado').value = c.Certificado || c.CERTIFICADO || '';
                            
                            if (c.CPF) {
                                if(document.getElementById('cli_cpf')) {
                                    document.getElementById('cli_cpf').value = c.CPF;
                                    searchClient();
                                }
                            }
                            
                            if (c.AG) {
                                if(document.getElementById('ag_code')) {
                                    document.getElementById('ag_code').value = c.AG;
                                    searchAgency();
                                }
                            }
                            
                            Swal.fire('Preenchido', 'Dados importados da Base de Crédito.', 'success');
                        }
                    });
                } else {
                     Swal.fire('Novo Processo', 'Número não cadastrado. Preencha os dados.', 'info');
                }
            }
        })
        .catch(() => toggleLoading('btn_search_port', false));
    }

    function checkCert() {
        var val = document.getElementById('proc_cert').value;
        if(!val) return;
        
        toggleLoading('btn_search_cert', true);
        
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_check_cert&cert=' + encodeURIComponent(val) })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        }) 
        .then(res => { 
            toggleLoading('btn_search_cert', false);
            if(res.found) { 
                Swal.fire({
                    title: 'Certificado Vinculado!',
                    text: `Este certificado já consta em ${res.count} processo(s).`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#003366',
                    cancelButtonColor: '#28a745',
                    confirmButtonText: 'Ver Existentes',
                    cancelButtonText: 'Criar Novo'
                }).then((result) => {
                    if (result.isConfirmed) {
                        renderProcessList(res.processes);
                    }
                });
            } else {
                Swal.fire('Disponível', 'Certificado não encontrado. Pode prosseguir.', 'success');
            }
        })
        .catch(() => toggleLoading('btn_search_cert', false));
    }

    // Persistence Logic
    document.addEventListener("DOMContentLoaded", function() {
        const form = document.getElementById('form_processo');
        if (form) {
            const urlParams = new URLSearchParams(window.location.search);
            const isNew = !urlParams.get('id');
            const storageKey = 'draft_processo';

            // SAFETY: If we are viewing an EXISTING process (Edit Mode), strictly CLEAR the draft.
            // This prevents "leaking" data from a previous "New Process" attempt or confusion if the user navigates back and forth.
            if (!isNew) {
                sessionStorage.removeItem(storageKey);
            }

            // Restore (Only if New)
            if (isNew) {
                const draft = sessionStorage.getItem(storageKey);
                if (draft) {
                    const data = JSON.parse(draft);
                    for (const key in data) {
                        const el = form.elements[key];
                        if (el && (el.type !== 'hidden' || key === 'acao')) {
                             // Handle checkboxes/radios if needed, but simple value works for most
                             if (el.type === 'checkbox' || el.type === 'radio') {
                                 // Simple restore for now
                             } else {
                                 el.value = data[key];
                             }
                        }
                    }
                }
            }

            // Save on change (Only if New)
            form.addEventListener('input', function() {
                if (isNew) { 
                    const data = {};
                    new FormData(form).forEach((value, key) => data[key] = value);
                    sessionStorage.setItem(storageKey, JSON.stringify(data));
                }
            });
            
        }
        
        // Voltar Button Logic - Removed Draft Clearing to preserve state
    });

    function modalTemplate() { document.getElementById('mt_id').value = ''; document.getElementById('mt_titulo').value = ''; document.getElementById('mt_corpo').value = ''; new bootstrap.Modal(document.getElementById('modalTemplate')).show(); }
    function editTemplate(t) { document.getElementById('mt_id').value = t.id; document.getElementById('mt_titulo').value = t.titulo; document.getElementById('mt_corpo').value = t.corpo; new bootstrap.Modal(document.getElementById('modalTemplate')).show(); }

    function generateText() {
        // Collect selected template IDs
        var selectedTpls = [];
        document.querySelectorAll('.tpl-checkbox:checked').forEach(cb => selectedTpls.push(cb.value));
        
        if(selectedTpls.length === 0) {
            // Clear text if none selected
            // document.getElementById('tpl_result').value = ''; 
            return; 
        }

        var data = {};
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea').forEach(el => {
            if(el.name) {
                var k = el.name.replace('client_', '').replace('agency_', '').replace('reg_new_', '').replace('reg_', '');
                data[k] = el.value;
            }
        });
        
        // Accumulate text
        // Note: This implementation generates text sequentially.
        // To be safe, we clear first? Or append? User said "Multiplas escolhas", usually implies composition.
        // Let's clear and rebuild.
        var finalBody = "";
        var processedCount = 0;

        selectedTpls.forEach(tplId => {
            fetch('', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'acao=ajax_generate_text&tpl_id=' + tplId + '&data=' + encodeURIComponent(JSON.stringify(data))
            })
            .then(r => r.json())
            .then(res => { 
                if(res.status == 'ok') {
                    finalBody += res.text + "\n\n";
                }
                processedCount++;
                if (processedCount === selectedTpls.length) {
                    document.getElementById('tpl_result').value = finalBody;
                }
            });
        });
    }

    function copyToClipboard() {
        var copyText = document.getElementById("tpl_result");
        copyText.select();
        copyText.setSelectionRange(0, 99999); // For mobile devices
        
        // Try modern API first, fallback to execCommand
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(copyText.value).then(() => {
                Swal.fire('Copiado!', 'Texto copiado para a área de transferência.', 'success');
            }).catch(err => {
                fallbackCopy(copyText);
            });
        } else {
            fallbackCopy(copyText);
        }
    }

    function fallbackCopy(textElement) {
        try {
            document.execCommand('copy');
            Swal.fire('Copiado!', 'Texto copiado para a área de transferência.', 'success');
        } catch (err) {
            Swal.fire('Erro', 'Não foi possível copiar o texto automaticamente.', 'error');
        }
    }

    function saveHistory(btn) {
        var text = document.getElementById('tpl_result').value;
        var cpf = document.getElementById('cli_cpf') ? document.getElementById('cli_cpf').value : '';
        var nome = document.getElementById('cli_nome') ? document.getElementById('cli_nome').value : '';
        var port = document.getElementById('proc_port') ? document.getElementById('proc_port').value : '';
        
        var emailTo = '';
        
        // Collect Model Names
        var modelNames = [];
        document.querySelectorAll('.tpl-checkbox:checked').forEach(cb => {
            var label = document.querySelector('label[for="' + cb.id + '"]').innerText;
            modelNames.push(label.trim());
        });
        var modelNameStr = modelNames.join('; ');

        var textToSave = "Assunto: " + modelNameStr + "\n\n" + text;

        setButtonLoading(btn, true);

        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_save_history&cliente='+encodeURIComponent(nome)+'&cpf='+encodeURIComponent(cpf)+'&port='+encodeURIComponent(port)+'&modelo='+encodeURIComponent(modelNameStr)+'&texto='+encodeURIComponent(textToSave)+'&destinatarios='+encodeURIComponent(emailTo)
        })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);

            if(res.status == 'ok') { 
                Swal.fire('Sucesso', 'Envio Registrado!', 'success');
                
                var tbody = document.getElementById('history_table_body');
                var row = document.createElement('tr');
                // Handle potential XSS by treating as text if not for strict requirements (but here it's admin tool)
                // Use simple assignment for now
                row.innerHTML = '<td>' + res.data + '</td><td>' + res.usuario + '</td><td>' + modelNameStr + '</td>';
                
                if (tbody.firstChild) {
                    tbody.insertBefore(row, tbody.firstChild);
                } else {
                    tbody.appendChild(row);
                }
            }
        })
        .catch(err => {
            setButtonLoading(btn, false);
        });
    }

    function submitProcessForm(btn) {
        var form = document.getElementById('form_processo');
        if (!validateElements(form.querySelectorAll('input, select, textarea'))) return;

        var inputPort = document.getElementById('proc_port') ? document.getElementById('proc_port').value : '';

        var doSubmit = function() {
            setButtonLoading(btn, true);

            var formData = new FormData(form);
            formData.set('acao', 'ajax_salvar_processo');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
            .then(res => {
                setButtonLoading(btn, false);

                if (res.status == 'ok') {
                    sessionStorage.removeItem('draft_processo');
                    Swal.fire('Sucesso', res.message, 'success');
                    isDirty = false;
                    currentLoadedPort = inputPort;
                    updateEmailListFromForm();
                } else {
                    Swal.fire('Erro', res.message, 'error');
                }
            })
            .catch(err => {
                setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha na comunicação.', 'error');
            });
        };

        if (inputPort && inputPort !== currentLoadedPort) {
             setButtonLoading(btn, true);
             fetch('', { 
                 method: 'POST', 
                 headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
                 body: 'acao=ajax_check_process&port=' + encodeURIComponent(inputPort) 
             })
             .then(r => r.json())
             .then(res => {
                 setButtonLoading(btn, false);
                 
                 if (res.found) {
                     Swal.fire({
                        title: 'Processo já cadastrado',
                        text: 'O processo de portabilidade de nº ' + inputPort + ' já está cadastrado. Deseja atualizar ou cancelar?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#003366',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Atualizar',
                        cancelButtonText: 'Cancelar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            doSubmit();
                        }
                    });
                 } else {
                     doSubmit();
                 }
             })
             .catch(() => {
                 setButtonLoading(btn, false);
                 Swal.fire('Erro', 'Falha ao verificar duplicidade.', 'error');
             });
        } else {
            doSubmit();
        }
    }
    
    // Dirty Form Logic
    var isDirty = false;
    
    // Sortable
    document.addEventListener("DOMContentLoaded", function() {
        // Auto-fill check on load
        var autoFillCredit = <?= $autoFillData ?? 'null' ?>;
        if (autoFillCredit) {
            Swal.fire({
                title: 'Dados na Base de Crédito',
                text: 'Processo não cadastrado, porém existem dados na Base de Crédito. Deseja realizar o autopreenchimento?',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Sim, preencher!',
                cancelButtonText: 'Não'
            }).then((r) => {
                if (r.isConfirmed) {
                    var c = autoFillCredit;
                    if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').value = c.VALOR_DEPOSITO_PRINCIPAL || '';
                    if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').dispatchEvent(new Event('input'));
                    
                    // Support both keys
                    if(document.getElementById('proc_Certificado')) document.getElementById('proc_Certificado').value = c.Certificado || c.CERTIFICADO || '';
                    
                    if (c.CPF) {
                        if(document.getElementById('cli_cpf')) {
                             document.getElementById('cli_cpf').value = c.CPF;
                             searchClient();
                        }
                    }
                    if (c.AG) {
                        if(document.getElementById('ag_code')) {
                             document.getElementById('ag_code').value = c.AG;
                             searchAgency();
                        }
                    }
                }
            });
        }

        var elList = document.querySelectorAll('.sortable-list');
        elList.forEach(function(el) { new Sortable(el, { handle: '.handle', animation: 150, onEnd: function (evt) { var file = el.getAttribute('data-file'); var order = []; el.querySelectorAll('li').forEach(li => order.push(li.getAttribute('data-key'))); fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_reorder_fields&file=' + file + '&order[]=' + order.join('&order[]=') }); } }); });
        
        // Dirty Form Detection
        var form = document.getElementById('form_processo');
        if (form) {
            form.addEventListener('change', function() { isDirty = true; });
            form.addEventListener('input', function() { isDirty = true; });
            form.addEventListener('submit', function() { isDirty = false; });
        }
        
        // Intercept internal links for styled alert
        document.body.addEventListener('click', function(e) {
            if (!isDirty) return;
            var target = e.target.closest('a');
            if (target && target.getAttribute('href') && !target.getAttribute('href').startsWith('#') && !target.getAttribute('target') && !target.getAttribute('href').startsWith('javascript')) {
                e.preventDefault();
                Swal.fire({
                    title: 'Alterações não salvas',
                    text: "Você tem alterações pendentes. Se sair agora, elas serão perdidas.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#003366',
                    confirmButtonText: 'Sair sem salvar',
                    cancelButtonText: 'Continuar editando'
                }).then((result) => {
                    if (result.isConfirmed) {
                        isDirty = false; // Prevent beforeunload
                        window.location.href = target.href;
                    }
                });
            }
        });

        window.addEventListener('beforeunload', function(e) {
            if (isDirty) {
                var confirmationMessage = 'Você tem alterações não salvas. Deseja realmente sair?';
                e.returnValue = confirmationMessage; // Geeky legacy way
                return confirmationMessage;
            }
        });

        // Money Mask
        const moneyInputs = document.querySelectorAll('.money-mask');
        moneyInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                value = (value / 100).toFixed(2) + '';
                value = value.replace('.', ',');
                value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                e.target.value = 'R$ ' + value;
            });
            // Initial format if value exists
            if(input.value && !input.value.includes('R$')) {
                 // Try to parse existing "R$ 1.000,00" or raw "1000.00"
                 // If it's already "R$ ...", leave it. If it's plain, format it.
                 // Actually, if coming from DB, it might be raw string. 
                 // Simple init trigger
                 // let evt = new Event('input'); input.dispatchEvent(evt); 
                 // But wait, the value might be 'R$ 40.149,48' already. 
            }
        });

        // Locking Logic
        const processId = new URLSearchParams(window.location.search).get('id');
        const isLocked = <?= (isset($lockInfo) && $lockInfo && $lockInfo['locked']) ? 'true' : 'false' ?>;
        
        if (processId && !isLocked) {
            startLocking(processId);

            // Release on exit
            window.addEventListener('beforeunload', function() {
                releaseCurrentLock();
            });
        } else if (isLocked) {
            // Disable form inputs
            document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = true);
        }
    });

    var lockInterval;
    var currentLockPort = null;

    function releaseCurrentLock() {
         if (currentLockPort) {
             navigator.sendBeacon('', new URLSearchParams({
                 'acao': 'ajax_release_lock',
                 'port': currentLockPort
             }));
             currentLockPort = null;
         }
         if (lockInterval) clearInterval(lockInterval);
    }

    function startLocking(port) {
        releaseCurrentLock(); // Release previous if any
        currentLockPort = port;
        
        const acquireLock = () => {
            fetch('', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'acao=ajax_acquire_lock&port=' + encodeURIComponent(port)
            }).then(r => r.json()).then(res => {
                if (!res.success) {
                    clearInterval(lockInterval);
                    currentLockPort = null;
                    Swal.fire('Bloqueado', 'Este processo acabou de ser bloqueado por ' + res.locked_by, 'error')
                    .then(() => {
                        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = true);
                    });
                }
            });
        };
        acquireLock();
        lockInterval = setInterval(acquireLock, 30000); 
    }

    function openCreditModal(data) {
        document.getElementById('form_credit').reset();
        if (data) {
             document.getElementById('cred_original_port').value = data.PORTABILIDADE || '';
             document.getElementById('cred_PORTABILIDADE').value = data.PORTABILIDADE || '';
             document.getElementById('cred_STATUS').value = data.STATUS || '';
             document.getElementById('cred_NUMERO_DEPOSITO').value = data.NUMERO_DEPOSITO || '';
             
             // Date Handling: d/m/Y -> YYYY-MM-DD
             if (data.DATA_DEPOSITO) {
                 var parts = data.DATA_DEPOSITO.split('/');
                 if (parts.length == 3) {
                     document.getElementById('cred_DATA_DEPOSITO').value = parts[2] + '-' + parts[1] + '-' + parts[0];
                 } else {
                     document.getElementById('cred_DATA_DEPOSITO').value = '';
                 }
             } else {
                 document.getElementById('cred_DATA_DEPOSITO').value = '';
             }

             document.getElementById('cred_VALOR_DEPOSITO_PRINCIPAL').value = data.VALOR_DEPOSITO_PRINCIPAL || '';
             document.getElementById('cred_TEXTO_PAGAMENTO').value = data.TEXTO_PAGAMENTO || '';
             document.getElementById('cred_CERTIFICADO').value = data.CERTIFICADO || '';
             document.getElementById('cred_STATUS_2').value = data.STATUS_2 || '';
             document.getElementById('cred_CPF').value = data.CPF || '';
             document.getElementById('cred_AG').value = data.AG || '';
        } else {
             document.getElementById('cred_original_port').value = '';
        }
        new bootstrap.Modal(document.getElementById('modalCredit')).show();
    }

    function saveCredit() {
        var form = document.getElementById('form_credit');
        var btn = form.querySelector('.btn-navy');
        if (!validateElements(form.querySelectorAll('input, select, textarea'))) return;
        
        setButtonLoading(btn, true);
        var formData = new FormData(form);
        formData.append('acao', 'ajax_save_credit');
        
        fetch('', { method: 'POST', body: formData })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);
            
            if (res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalCredit')).hide();
                Swal.fire('Sucesso', res.message, 'success');
                if(document.getElementById('base_table')) renderBaseTable();
                else filterCredits();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(err => {
            setButtonLoading(btn, false);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function deleteCredit(port) {
        Swal.fire({
            title: 'Tem certeza?', text: "Deseja excluir este registro da Base de Créditos?", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) {
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_credit');
                fd.append('port', port);
                
                fetch('', { method: 'POST', body: fd })
                .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
                .then(res => {
                    hideLoading();
                    
                    if (res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        if(document.getElementById('base_table')) renderBaseTable();
                        else filterCredits();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => {
                    hideLoading();
                    Swal.fire('Erro', 'Falha na comunicação.', 'error');
                });
            }
        });
    }

    function showPage(pageId) {
        document.querySelectorAll('.page-section').forEach(el => el.style.display = 'none');
        document.getElementById('page-' + pageId).style.display = 'block';
        
        document.querySelectorAll('.navbar-nav .nav-link').forEach(el => el.classList.remove('active'));
        var link = document.querySelector('a[href*="?p=' + pageId + '"]');
        if(link) link.classList.add('active');
        
        window.history.pushState(null, '', '?p=' + pageId);

        if (pageId === 'lembretes') {
            filterLembretes();
        }
    }

    function goBack() {
        showPage('dashboard');
        hideLoading(); // Ensure any stuck loading state is cleared
        
        // Safety: Reset any loading buttons in dashboard table immediately
        document.querySelectorAll('#dash_table_body button, #dash_table_body a.btn').forEach(btn => {
            if ((btn.disabled || btn.innerHTML.includes('spinner-border'))) {
                btn.disabled = false;
                if (btn.dataset.originalHtml) {
                    btn.innerHTML = btn.dataset.originalHtml;
                } else {
                    // Fallback reconstruction: Force "Abrir" if we can't determine original state but it's in the dashboard table
                    btn.innerHTML = '<i class="fas fa-folder-open fa-lg text-warning"></i> Abrir';
                }
                btn.style.width = '';
            }
        });

        // Refresh dashboard to reset loading buttons and update data
        filterDashboard(null, currentDashboardPage);
    }

    function startNewService() {
        currentLoadedPort = null;
        // Re-enable all fields
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = false);

        document.getElementById('form_processo').reset();
        // Reset deleted fields visibility
        document.querySelectorAll('.deleted-field-row').forEach(el => el.classList.add('d-none'));
        sessionStorage.removeItem('draft_processo');
        
        var cc = document.getElementById('dyn_credit_card');
        if(cc) cc.remove();
        
        var serverCC = document.getElementById('server_credit_card');
        if(serverCC) serverCC.style.display = 'none';
        
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea').forEach(el => {
            if (el.type == 'hidden' && el.name == 'acao') return; 
            if (el.readOnly) return; 
            
            if (el.type == 'checkbox' || el.type == 'radio') el.checked = false;
            else el.value = '';
        });

        // Clear Registro de Processo inputs (which are outside form_processo)
        document.querySelectorAll('.reg-new-field').forEach(el => {
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = false;
                if (el.classList.contains('ms-checkbox')) {
                    updateMultiselectLabel(el);
                }
            } else {
                el.value = '';
            }
        });

        // Reset Email List
        var emailList = document.getElementById('email_list_ul');
        if(emailList) emailList.innerHTML = '<li class="text-muted small">Nenhum email encontrado na agência.</li>';
        var selList = document.getElementById('selected_emails_list');
        if(selList) selList.innerText = '';

        // Reset Delete Button
        var divDel = document.getElementById('div_delete_process');
        if(divDel) divDel.innerHTML = '';

        // Reset Tab to First
        try {
            var triggerEl = document.querySelector('button[data-bs-target="#tab-dados"]');
            if(triggerEl) bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        } catch(e) { console.error(e); }
        
        Swal.fire('Novo Atendimento', 'Formulário limpo.', 'success');
    }

    function clearDashboardFilters() {
        var form = document.getElementById('form_dashboard_filter');
        if(form) {
            form.querySelectorAll('input, select').forEach(el => {
                if(el.type != 'hidden' && el.type != 'button' && el.type != 'submit') {
                    el.value = '';
                }
            });
            
            // Reset Sort to Default
            currentDashSortCol = 'DATA';
            currentDashSortDir = 'desc';
            updateSortIcons('dash_table_head', 'DATA', 'desc');
            
            filterDashboard(null, 1);
        }
    }

    function clearBaseFilters() {
        var form = document.getElementById('form_base_filter');
        if(form) {
            form.querySelectorAll('input, select').forEach(el => {
                if(el.type != 'hidden' && el.type != 'button' && el.type != 'submit') {
                    el.value = '';
                }
            });
            
            // Reset Sort
            currentBaseSortCol = '';
            currentBaseSortDir = '';
            
            renderBaseTable(1);
        }
    }

    function filterDashboard(e, page) {
        if(e) e.preventDefault();
        
        var form = document.getElementById('form_dashboard_filter');
        var btn = form.querySelector('button:not([type="button"])');
        setButtonLoading(btn, true);
        
        var fd = new FormData(form);
        fd.append('acao', 'ajax_render_dashboard_table');
        if(page) fd.append('pag', page);
        fd.append('sortCol', currentDashSortCol);
        fd.append('sortDir', currentDashSortDir);
        
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                if(res.page) currentDashboardPage = res.page;
                document.getElementById('dash_table_body').innerHTML = res.html;
                if(document.getElementById('dash_pagination_container')) {
                    document.getElementById('dash_pagination_container').innerHTML = res.pagination;
                }
                if(res.count !== undefined) {
                     var countStr = res.count < 10 ? '0' + res.count : res.count;
                     var headerEl = document.getElementById('process_list_header');
                     if(headerEl) headerEl.innerHTML = '<i class="fas fa-list me-2"></i>Processos (' + countStr + ')';
                }
            } else {
                Swal.fire('Erro', 'Falha ao filtrar', 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha na comunicação.', 'error'); });
    }

    function filterCredits(e, page) {
        if(e) e.preventDefault();
        showLoading();
        
        var form = document.getElementById('form_credit_filter');
        var fd = new FormData(form);
        fd.append('acao', 'ajax_render_credit_table');
        if(page) fd.append('cpPagina', page);
        
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                document.getElementById('cred_table_body').innerHTML = res.html;
                if(document.getElementById('cred_pagination_container')) {
                    document.getElementById('cred_pagination_container').innerHTML = res.pagination;
                }
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha na comunicação.', 'error'); });
    }

    function applyBaseSelection() {
        var years = [];
        document.querySelectorAll('.chk-year:checked').forEach(cb => years.push(cb.value));
        var months = [];
        document.querySelectorAll('.chk-month:checked').forEach(cb => months.push(cb.value));
        
        if (years.length === 0 || months.length === 0) {
            Swal.fire('Atenção', 'Selecione pelo menos um ano e um mês.', 'warning');
            return;
        }

        showLoading();
        var fd = new FormData();
        fd.append('acao', 'ajax_set_base_selection');
        years.forEach(y => fd.append('years[]', y));
        months.forEach(m => fd.append('months[]', m));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status == 'ok') {
                hideLoading();
                if(document.getElementById('page-dashboard').style.display != 'none') {
                    filterDashboard();
                } else if(document.getElementById('page-base').style.display != 'none') {
                    renderBaseTable();
                } else {
                    window.location.reload();
                }
            } else {
                hideLoading();
                Swal.fire('Erro', 'Falha ao aplicar filtro.', 'error');
            }
        })
        .catch(() => {
            hideLoading();
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function loadProcess(port, btn) {
        // Re-enable all fields first (fix blocked state persistence)
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = false);

        if(btn) setButtonLoading(btn, true); else showLoading();
        
        var fd = new FormData();
        fd.append('acao', 'ajax_get_process_full');
        fd.append('port', port);
        
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            if(btn) setButtonLoading(btn, false); else hideLoading();
            if(res.status == 'ok') {
                currentLoadedPort = port;
                if (res.lock && res.lock.locked) {
                     Swal.fire({
                        icon: 'warning',
                        title: 'Processo Bloqueado',
                        text: 'Este processo está sendo editado por ' + res.lock.by + '. Modo somente leitura.',
                        timer: 5000
                     });
                     releaseCurrentLock();
                } else {
                     startLocking(port);
                }

                var p = res.process;
                var c = res.client;
                var a = res.agency;
                
                if(res.registros_history) {
                    renderRegistrosHistory(res.registros_history);
                } else {
                    document.getElementById('history_registros_body').innerHTML = '';
                }

                var emailBody = document.getElementById('history_table_body');
                if (emailBody) emailBody.innerHTML = '';
                
                if (res.email_history && res.email_history.length > 0) {
                    res.email_history.forEach(function(h) {
                        var tr = document.createElement('tr');
                        var safeData = (h.DATA || '').replace(/</g, '&lt;');
                        var safeUser = (h.USUARIO || '').replace(/</g, '&lt;');
                        var safeModelo = (h.MODELO || '').replace(/</g, '&lt;');
                        tr.innerHTML = '<td>' + safeData + '</td><td>' + safeUser + '</td><td>' + safeModelo + '</td>';
                        emailBody.appendChild(tr);
                    });
                }

                document.getElementById('form_processo').reset();
                // Reset deleted fields visibility
                document.querySelectorAll('.deleted-field-row').forEach(el => el.classList.add('d-none'));

                // Reset agency container visibility
                if(document.getElementById('agency_details_container')) document.getElementById('agency_details_container').style.display = 'none';
                
                document.querySelectorAll('.reg-new-field').forEach(el => {
                    var key = el.name.replace('reg_new_', '');
                    var val = '';
                    if(p && p[key]) val = p[key];
                    else if(c && c[key]) val = c[key];
                    else if(a && a[key]) val = a[key];
                    else if(res.credit && res.credit[key]) val = res.credit[key];
                    
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        if (el.name.endsWith('[]')) {
                            var values = val ? String(val).split(',').map(s => s.trim()) : [];
                            el.checked = values.includes(el.value);
                        } else {
                            el.checked = (String(el.value) === String(val));
                        }
                        if (el.classList.contains('ms-checkbox')) {
                            updateMultiselectLabel(el);
                        }
                    } else {
                        if(val) {
                            if (el.type === 'date' && /^\d{2}\/\d{2}\/\d{4}$/.test(val)) {
                                var parts = val.split('/');
                                val = parts[2] + '-' + parts[1] + '-' + parts[0];
                            }
                            el.value = val;
                        } else {
                            el.value = '';
                        }
                    }
                });

                if(p) {
                    document.querySelectorAll('#form_processo [name]').forEach(el => {
                        var key = el.name;
                        // Filter for process fields (not prefixed)
                        if (!key.startsWith('client_') && !key.startsWith('agency_') && !key.startsWith('reg_') && key != 'acao') {
                            var lookupKey = key.endsWith('[]') ? key.substring(0, key.length-2) : key;
                            
                            var pKey = Object.keys(p).find(k => k.toLowerCase() === lookupKey.toLowerCase());
                            if (pKey) {
                                var val = p[pKey];
                                
                                // Unhide deleted field if it has value
                                if (val && String(val).trim() !== '') {
                                    var row = el.closest('.deleted-field-row');
                                    if(row) row.classList.remove('d-none');
                                }

                                if (el.type === 'date' && /^\d{2}\/\d{2}\/\d{4}$/.test(val)) {
                                    var parts = val.split('/');
                                    val = parts[2] + '-' + parts[1] + '-' + parts[0];
                                }

                                if (el.type === 'datetime-local' && /^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}(:\d{2})?$/.test(val)) {
                                    var dtParts = val.split(' ');
                                    var dParts = dtParts[0].split('/');
                                    var timePart = dtParts[1].substring(0, 5);
                                    val = dParts[2] + '-' + dParts[1] + '-' + dParts[0] + 'T' + timePart;
                                }
                                
                                if (el.type === 'checkbox' || el.type === 'radio') {
                                    if (el.name.endsWith('[]')) {
                                        var values = val ? String(val).split(',').map(s => s.trim()) : [];
                                        el.checked = values.includes(el.value);
                                    } else {
                                        el.checked = (String(el.value) == String(val));
                                    }
                                } else {
                                    el.value = val;
                                }
                            }
                        }
                    });
                    
                    var portKey = Object.keys(p).find(k => k.toLowerCase() === 'numero_portabilidade');
                    if(document.getElementById('proc_port') && portKey) 
                        document.getElementById('proc_port').value = p[portKey];
                }
                
                if(c) {
                    document.querySelectorAll('#form_processo [name^="client_"]').forEach(el => {
                        var key = el.name.replace('client_', '');
                        var cKey = Object.keys(c).find(k => k.toLowerCase() === key.toLowerCase());
                        if (cKey) {
                            el.value = c[cKey];
                        }
                    });
                    
                    var cpfKey = Object.keys(c).find(k => k.toLowerCase() === 'cpf');
                    if(document.getElementById('cli_cpf') && cpfKey) document.getElementById('cli_cpf').value = c[cpfKey];
                    
                    var nomeKey = Object.keys(c).find(k => k.toLowerCase() === 'nome');
                    if(document.getElementById('cli_nome') && nomeKey) document.getElementById('cli_nome').value = c[nomeKey];
                }
                
                if(a) {
                    // Populate agency fields to prevent data loss on save
                    document.querySelectorAll('#form_processo [name^="agency_"]').forEach(el => {
                        var key = el.name.replace('agency_', '');
                        var aKey = Object.keys(a).find(k => k.toLowerCase() === key.toLowerCase());
                        if (aKey) {
                            el.value = a[aKey];
                        }
                    });

                    var agKey = Object.keys(a).find(k => k.toLowerCase() === 'ag');
                    if(document.getElementById('ag_code') && agKey) document.getElementById('ag_code').value = a[agKey];
                } else if (p) {
                    var agKeyP = Object.keys(p).find(k => k.toLowerCase() === 'ag');
                    if(document.getElementById('ag_code') && agKeyP) document.getElementById('ag_code').value = p[agKeyP];
                }

                // Update Email List
                var agencyEmails = [];
                var emailList = document.getElementById('email_list_ul');
                if (emailList) {
                    emailList.innerHTML = '';
                    if(a) {
                        for (var k in a) {
                            if (k.toUpperCase().includes('MAIL') || k.toUpperCase().includes('EMAIL')) {
                                if (a[k]) {
                                    var parts = String(a[k]).split(/[;,]/);
                                    parts.forEach(p => {
                                        p = p.trim();
                                        if(p && p.includes('@')) { 
                                            agencyEmails.push(p);
                                        }
                                    });
                                }
                            }
                        }
                    }
                    
                    agencyEmails = [...new Set(agencyEmails)].sort();
                    
                    if(agencyEmails.length === 0) {
                         emailList.innerHTML = '<li class="text-muted small">Nenhum email encontrado na agência.</li>';
                    } else {
                        agencyEmails.forEach(em => {
                            var id = 'chk_' + Math.random().toString(36).substr(2, 9);
                            var li = document.createElement('li');
                            li.className = 'form-check mb-1';
                            li.onclick = function(e) { e.stopPropagation(); };
                            
                            var checkbox = document.createElement('input');
                            checkbox.className = 'form-check-input email-checkbox';
                            checkbox.type = 'checkbox';
                            checkbox.value = em;
                            checkbox.id = id;
                            
                            var label = document.createElement('label');
                            label.className = 'form-check-label';
                            label.htmlFor = id;
                            label.textContent = em;
                            
                            li.appendChild(checkbox);
                            li.appendChild(label);
                            emailList.appendChild(li);
                        });
                    }
                    // Clear selected list
                    var selList = document.getElementById('selected_emails_list');
                    if(selList) selList.innerText = '';
                }
                
                // Show Delete Button
                var divDel = document.getElementById('div_delete_process');
                if(divDel) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-outline-danger';
                    btn.textContent = 'Excluir Processo';
                    btn.onclick = function() { confirmDelete(port); };
                    divDel.innerHTML = '';
                    divDel.appendChild(btn);
                }

                var oldCC = document.getElementById('server_credit_card');
                if(oldCC) oldCC.style.display = 'none';
                
                var dynCC = document.getElementById('dyn_credit_card');
                if(dynCC) dynCC.remove();
                
                if(res.credit) {
                    var cr = res.credit;
                    var cardHtml = '<div class="card card-custom p-4 mb-4 border-warning border-3" id="dyn_credit_card">' + 
                        '<h5 class="text-warning fw-bold border-bottom pb-2"><i class="fas fa-coins me-2"></i>Identificação de Crédito</h5>' +
                        '<div class="row g-3">';
                    for (var k in cr) {
                        cardHtml += '<div class="col-md-3"><label class="small text-muted">' + k + '</label><div class="fw-bold">' + (cr[k] || '-') + '</div></div>';
                    }
                    cardHtml += '</div></div>';
                    
                    var cards = document.querySelectorAll('#form_processo .card-custom');
                    if(cards.length > 0) {
                        var lastCard = cards[cards.length - 1]; 
                        lastCard.insertAdjacentHTML('afterend', cardHtml);
                    }
                }
                
                showPage('detalhes');

                // Fix URL to include ID so reload works
                var newUrl = '?p=detalhes&id=' + encodeURIComponent(port);
                window.history.replaceState(null, '', newUrl);

                // Reset Tab to First
                try {
                    var triggerEl = document.querySelector('button[data-bs-target="#tab-dados"]');
                    if(triggerEl) bootstrap.Tab.getOrCreateInstance(triggerEl).show();
                } catch(e) { console.error(e); }
                
                if (res.lock && res.lock.locked) {
                    setTimeout(() => {
                        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = true);
                    }, 100);
                }

            } else {
                Swal.fire('Erro', 'Erro ao carregar processo.', 'error');
            }
        })
        .catch(() => { if(btn) setButtonLoading(btn, false); else hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    // --- GENERIC BASE JS ---
    var currentBase = 'Identificacao_cred.json';

    function switchBase(base) {
        currentBase = base;
        currentBaseSortCol = '';
        currentBaseSortDir = '';
        
        // Update Tabs
        document.querySelectorAll('#base-tab .nav-link').forEach(el => el.classList.remove('active'));
        var tabId = 'tab-cred';
        if(base.includes('agencia')) tabId = 'tab-ag';
        if(base.includes('client')) tabId = 'tab-cli';
        if(base === 'Processos') tabId = 'tab-proc';
        if(document.getElementById(tabId)) document.getElementById(tabId).classList.add('active');
        
        // Update Title and Inputs
        var title = 'Identificação de Crédito';
        if(base.includes('agencia')) title = 'Base de Agências';
        if(base.includes('client')) title = 'Base de Clientes';
        if(base === 'Processos') title = 'Base de Processos';
        document.getElementById('updateBaseTitle').innerText = 'Atualizar ' + title;
        if(document.getElementById('upload_base_name')) document.getElementById('upload_base_name').value = base;
        
        // Toggle Filters
        var processFilters = document.querySelectorAll('.base-process-filter');
        if (base === 'Processos') {
            processFilters.forEach(el => el.style.display = 'block');
            
            // Fetch Options
            fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_get_process_filter_options' })
            .then(r => r.json())
            .then(res => {
                if(res.status == 'ok') {
                    var selStatus = document.getElementById('base_fStatus');
                    selStatus.innerHTML = '<option value="">Todos</option>';
                    res.status_opts.forEach(s => {
                        selStatus.innerHTML += '<option value="'+s+'">'+s+'</option>';
                    });
                    
                    var selAt = document.getElementById('base_fAtendente');
                    selAt.innerHTML = '<option value="">Todos</option>';
                    res.atendentes_opts.forEach(a => {
                        selAt.innerHTML += '<option value="'+a+'">'+a+'</option>';
                    });
                }
            });
        } else {
            processFilters.forEach(el => el.style.display = 'none');
        }

        renderBaseTable(1);
    }

    function renderBaseTable(page, btn) {
        if(!page) page = 1;
        if(btn) setButtonLoading(btn, true);
        else showLoading();
        
        var form = document.getElementById('form_base_filter');
        var fd = new FormData(form);
        fd.append('acao', 'ajax_render_base_table');
        fd.append('base', currentBase);
        fd.append('cpPagina', page);
        if(currentBaseSortCol) {
            fd.append('sortCol', currentBaseSortCol);
            fd.append('sortDir', currentBaseSortDir);
        }
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(btn) setButtonLoading(btn, false);
            else hideLoading();
            
            if(res.status == 'ok') {
                document.getElementById('base_table').innerHTML = res.html;
                if(document.getElementById('cred_pagination_container')) document.getElementById('cred_pagination_container').innerHTML = res.pagination;
                if(res.count !== undefined) {
                     var countStr = res.count < 10 ? '0' + res.count : res.count;
                     var headerEl = document.getElementById('base_registros_header');
                     if(headerEl) headerEl.innerHTML = 'Registros (' + countStr + ')';
                }
            } else {
                Swal.fire('Erro', res.message || 'Erro ao carregar base', 'error');
            }
        })
        .catch(() => { 
            if(btn) setButtonLoading(btn, false);
            else hideLoading();
            Swal.fire('Erro', 'Falha de comunicação', 'error'); 
        });
    }

    function filterBase(e) {
        e.preventDefault();
        var form = document.getElementById('form_base_filter');
        var btn = form.querySelector('button');
        renderBaseTable(1, btn);
    }

    function openBaseModal(record, btn) {
        setButtonLoading(btn, true);
        // Fetch Schema first
        var fd = new FormData();
        fd.append('acao', 'ajax_get_base_schema');
        fd.append('base', currentBase);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                var fields = res.fields;
                var html = '';
                
                document.getElementById('base_original_id').value = '';
                document.getElementById('base_target_name').value = currentBase;
                
                // Determine PK for original_id
                var pk = fields.length > 0 ? fields[0].key : 'id';
                if(currentBase.includes('cred')) pk = 'PORTABILIDADE';
                if(currentBase.includes('client')) pk = 'CPF';
                if(currentBase.includes('agenc')) pk = 'AG';
                if(currentBase === 'Processos') pk = 'Numero_Portabilidade';
                
                if(record) {
                    document.getElementById('base_original_id').value = record[pk] || '';
                }

                fields.forEach(f => {
                    var rawVal = record ? (record[f.key] || '') : '';
                    
                    if (f.key.toLowerCase() === 'nome_atendente' && !rawVal && CURRENT_USER) {
                        rawVal = CURRENT_USER;
                    }

                    // Escape for HTML Attribute
                    var safeVal = String(rawVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    
                    var disabledAttr = (f.key.toLowerCase() === 'nome_atendente') ? 'disabled' : '';
                    
                    var inputType = 'text';
                    if(f.type == 'date') inputType = 'date';
                    if(f.type == 'number') inputType = 'number';
                    
                    var dateVal = rawVal;
                    if(inputType == 'date' && rawVal && rawVal.includes('/')) {
                        var parts = rawVal.split('/');
                        if(parts.length == 3) dateVal = parts[2] + '-' + parts[1] + '-' + parts[0];
                    }
                    var safeDateVal = String(dateVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

                    html += '<div class="col-md-6 mb-3">';
                    html += '<label class="form-label">' + f.label + '</label>';
                    
                    if (f.type == 'textarea') {
                        // Textarea content should be escaped for HTML content (not attribute)
                        var safeContent = String(rawVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        html += '<textarea name="' + f.key + '" class="form-control" rows="3">' + safeContent + '</textarea>';
                    } else if (f.type == 'select') {
                        html += '<select name="' + f.key + '" class="form-select">';
                        html += '<option value="">...</option>';
                        if (f.key == 'UF') {
                             ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'].forEach(o => {
                                 var selected = (rawVal == o) ? 'selected' : '';
                                 html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                             });
                        } else {
                             var opts = [];
                             if (f.options) {
                                 opts = f.options.split(',').map(s => s.trim());
                             }
                             var valFound = false;
                             opts.forEach(o => {
                                 var selected = (rawVal == o) ? 'selected' : '';
                                 html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                                 if(rawVal == o) valFound = true;
                             });
                             if(rawVal && !valFound && String(rawVal).trim() !== '') {
                                 html += '<option value="'+safeVal+'" selected>'+safeVal+'</option>';
                             }
                        }
                        html += '</select>';
                    } else if (f.type == 'money') {
                         html += '<input type="text" name="' + f.key + '" class="form-control money-mask" value="' + safeVal + '">';
                    } else if (f.type == 'custom') {
                         html += '<input type="text" name="' + f.key + '" class="form-control" value="' + safeVal + '" ' + disabledAttr + ' data-mask="' + (f.custom_mask || '') + '" data-case="' + (f.custom_case || '') + '" data-allowed="' + (f.custom_allowed || 'all') + '" oninput="applyCustomMask(this)">';
                    } else {
                         var useVal = (inputType == 'date') ? safeDateVal : safeVal;
                         html += '<input type="' + inputType + '" name="' + f.key + '" class="form-control" value="' + useVal + '" ' + disabledAttr + '>';
                    }
                    html += '</div>';
                });
                
                document.getElementById('modal_base_fields').innerHTML = html;
                
                document.querySelectorAll('.money-mask').forEach(input => {
                    input.addEventListener('input', function(e) {
                        let value = e.target.value.replace(/\D/g, '');
                        value = (value / 100).toFixed(2) + '';
                        value = value.replace('.', ',');
                        value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                        e.target.value = 'R$ ' + value;
                    });
                });

                new bootstrap.Modal(document.getElementById('modalBase')).show();
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function saveBase() {
        var form = document.getElementById('form_base');
        var btn = form.querySelector('.btn-navy'); 
        if (!validateElements(form.querySelectorAll('input, select, textarea'))) return;
        
        setButtonLoading(btn, true);
        var fd = new FormData(form);
        fd.append('acao', 'ajax_save_base_record');
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalBase')).hide();
                Swal.fire('Sucesso', res.message, 'success');
                renderBaseTable();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function deleteBaseRecord(id) {
        Swal.fire({
            title: 'Tem certeza?', text: "Excluir registro?", icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Sim'
        }).then((r) => {
            if(r.isConfirmed) {
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_base_record');
                fd.append('base', currentBase);
                fd.append('id', id);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Excluído', res.message, 'success');
                        renderBaseTable();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }

    function deleteSelectedBase(btn) {
        var ids = [];
        document.querySelectorAll('.base-checkbox:checked').forEach(cb => ids.push(cb.value));
        
        if(ids.length == 0) { Swal.fire('Aviso', 'Selecione registros.', 'info'); return; }
        
        Swal.fire({
            title: 'Excluir ' + ids.length + ' registros?',
            text: 'Essa ação não tem volta.',
            icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Sim'
        }).then((r) => {
            if(r.isConfirmed) {
                setButtonLoading(btn, true);
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_base_bulk');
                fd.append('base', currentBase);
                ids.forEach(id => fd.append('ids[]', id));
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    setButtonLoading(btn, false);
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        renderBaseTable();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }


    function editSelectedBase() {
        var ids = [];
        document.querySelectorAll('.base-checkbox:checked').forEach(cb => ids.push(cb.value));
        
        if(ids.length == 0) { Swal.fire('Aviso', 'Selecione registros.', 'info'); return; }

        showLoading();
        var fd = new FormData();
        fd.append('acao', 'ajax_prepare_bulk_edit');
        fd.append('base', currentBase);
        ids.forEach(id => fd.append('ids[]', id));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                var fields = res.fields;
                var html = '';
                
                document.getElementById('bulk_base_target').value = currentBase;

                fields.forEach(f => {
                    // Skip certain fields
                    if (f.key.toLowerCase() === 'id') return;
                    if (f.key.toLowerCase() === 'cpf' && currentBase.includes('client')) return; // PK
                    if (f.key.toLowerCase() === 'ag' && currentBase.includes('agenc')) return; // PK
                    if (f.key.toLowerCase() === 'numero_portabilidade') return; // PK

                    var rawVal = f.value;
                    var safeVal = String(rawVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    
                    var disabled = !f.is_common;
                    
                    var disabledAttr = disabled ? 'disabled' : '';
                    var valDisplay = disabled ? '(Vários valores)' : safeVal;
                    if (disabled) safeVal = ''; // Don't put various values in value attribute, just visual

                    var inputType = 'text';
                    if(f.type == 'date') inputType = 'date';
                    if(f.type == 'number') inputType = 'number';

                    if (inputType == 'date' && !disabled && rawVal && rawVal.includes('/')) {
                        var parts = rawVal.split('/');
                        if(parts.length == 3) safeVal = parts[2] + '-' + parts[1] + '-' + parts[0];
                    }

                    html += '<div class="col-md-6 mb-3">';
                    html += '<label class="form-label">' + f.label + '</label>';
                    
                    if (f.type == 'textarea') {
                         html += '<textarea name="' + f.key + '" class="form-control" rows="3" ' + disabledAttr + (disabled ? ' placeholder="(Vários valores)"' : '') + '>' + safeVal + '</textarea>';
                    } else if (f.type == 'select') {
                        html += '<select name="' + f.key + '" class="form-select" ' + disabledAttr + '>';
                        html += '<option value="">...</option>';
                        if (disabled) {
                             html += '<option value="" selected>(Vários valores)</option>';
                        } else {
                            if (f.key == 'UF') {
                                 ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'].forEach(o => {
                                     var selected = (rawVal == o) ? 'selected' : '';
                                     html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                                 });
                            } else {
                                 var opts = [];
                                 if (f.options) {
                                     opts = f.options.split(',').map(s => s.trim());
                                 }
                                 var valFound = false;
                                 opts.forEach(o => {
                                     var selected = (rawVal == o) ? 'selected' : '';
                                     html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                                     if(rawVal == o) valFound = true;
                                 });
                                 if(rawVal && !valFound && String(rawVal).trim() !== '') {
                                     html += '<option value="'+safeVal+'" selected>'+safeVal+'</option>';
                                 }
                            }
                        }
                        html += '</select>';
                    } else if (f.type == 'money') {
                         html += '<input type="text" name="' + f.key + '" class="form-control money-mask" value="' + safeVal + '" ' + disabledAttr + (disabled ? ' placeholder="(Vários valores)"' : '') + '>';
                    } else {
                         html += '<input type="' + inputType + '" name="' + f.key + '" class="form-control" value="' + safeVal + '" ' + disabledAttr + (disabled ? ' placeholder="(Vários valores)"' : '') + '>';
                    }
                    html += '</div>';
                });
                
                document.getElementById('modal_bulk_fields').innerHTML = html;
                
                // Re-init masks
                document.querySelectorAll('#modalBulkEdit .money-mask').forEach(input => {
                    input.addEventListener('input', function(e) {
                        let value = e.target.value.replace(/\D/g, '');
                        value = (value / 100).toFixed(2) + '';
                        value = value.replace('.', ',');
                        value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                        e.target.value = 'R$ ' + value;
                    });
                });

                new bootstrap.Modal(document.getElementById('modalBulkEdit')).show();
            } else {
                Swal.fire('Erro', 'Erro ao preparar edição.', 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function saveBulkBase() {
        var form = document.getElementById('form_bulk_edit');
        var btn = form.querySelector('.btn-navy');
        
        var ids = [];
        document.querySelectorAll('.base-checkbox:checked').forEach(cb => ids.push(cb.value));
        
        setButtonLoading(btn, true);
        var fd = new FormData(form);
        fd.append('acao', 'ajax_save_base_bulk');
        ids.forEach(id => fd.append('ids[]', id));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalBulkEdit')).hide();
                Swal.fire('Sucesso', res.message, 'success');
                renderBaseTable();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }
    function confirmClearBase() {
        Swal.fire({
            title: 'Limpar Base?',
            text: "Você está prestes a apagar TODOS os dados da base " + currentBase + ".",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Sim, limpar tudo!'
        }).then((r) => {
            if(r.isConfirmed) {
                if(document.getElementById('limpar_base_target')) document.getElementById('limpar_base_target').value = currentBase;
                showLoading();
                document.getElementById('form_limpar_base').submit();
            }
        });
    }

    function downloadBase(btn) {
        if(btn) setButtonLoading(btn, true);
        
        var url = '?acao=download_base&base=' + currentBase;
        
        fetch(url, { method: 'GET' })
        .then(response => {
            if (response.ok) {
                return response.blob().then(blob => {
                    var a = document.createElement('a');
                    var u = window.URL.createObjectURL(blob);
                    a.href = u;
                    // Try to extract filename
                    var filename = "Base_" + currentBase.replace('.json', '') + "_" + new Date().toLocaleDateString('pt-BR').replace(/\//g, '-') + ".xls";
                    var disposition = response.headers.get('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) { 
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(u);
                    if(btn) setButtonLoading(btn, false);
                });
            } else {
                if(btn) setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha ao baixar arquivo.', 'error');
            }
        })
        .catch(() => { 
            if(btn) setButtonLoading(btn, false); 
            Swal.fire('Erro', 'Falha na comunicação.', 'error'); 
        });
    }
    
    function openPasteModal() {
        document.getElementById('paste_base_target').value = currentBase;
        
        var fd = new FormData();
        fd.append('acao', 'ajax_get_base_schema');
        fd.append('base', currentBase);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status == 'ok') {
                var headers = [];
                if (res.fields && res.fields.length > 0) {
                    res.fields.forEach(function(f) {
                        if (f.type === 'title') return;
                        headers.push(f.key);
                    });
                } else {
                    // Fallback Defaults
                    if (currentBase.includes('client')) headers = ['Nome', 'CPF'];
                    else if (currentBase.includes('agencia')) headers = ['AG', 'UF', 'SR', 'NOME SR', 'FILIAL', 'E-MAIL AG', 'E-MAILS SR', 'E-MAILS FILIAL', 'E-MAIL GERENTE'];
                    else if (currentBase === 'Processos') headers = ['DATA', 'Ocorrencia', 'Status_ocorrencia', 'Nome_atendente', 'Numero_Portabilidade', 'CPF', 'AG', 'Certificado', 'PROPOSTA', 'VALOR DA PORTABILIDADE', 'AUT_PROPOSTA', 'PROPOSTA_2', 'MOTIVO DE CANCELAMENTO', 'STATUS', 'Data Cancelamento', 'OBSERVAÇÃO'];
                    else headers = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', 'PORTABILIDADE', 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
                }
                
                var msg = "Ordem esperada: " + headers.join(', ');
                document.querySelector('#modalPaste .alert').innerHTML = "Cole aqui as linhas copiadas diretamente do Excel. O sistema detectará automaticamente se há cabeçalho.<br><strong>" + msg + "</strong>";
                new bootstrap.Modal(document.getElementById('modalPaste')).show();
            } else {
                Swal.fire('Erro', 'Não foi possível carregar a estrutura.', 'error');
            }
        })
        .catch(() => { Swal.fire('Erro', 'Falha na comunicação.', 'error'); });
    }
    
    function renderRegistrosHistory(history) {
        var tbody = document.getElementById('history_registros_body');
        tbody.innerHTML = '';
        
        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_get_base_schema&base=Base_registros_schema'
        })
        .then(r=>r.json())
        .then(resSchema => {
            if(resSchema.status == 'ok') {
                var fields = resSchema.fields;
                
                history.forEach(row => {
                    var tr = document.createElement('tr');
                    
                    var tdData = document.createElement('td');
                    tdData.textContent = row.DATA || '';
                    tr.appendChild(tdData);
                    
                    var tdUser = document.createElement('td');
                    tdUser.textContent = row.USUARIO || '';
                    tr.appendChild(tdUser);
                    
                    fields.forEach(f => {
                        var td = document.createElement('td');
                        td.textContent = row[f.key] || '';
                        tr.appendChild(td);
                    });
                    
                    tbody.appendChild(tr);
                });
            }
        });
    }

    function saveProcessRecord(btn) {
        var port = document.getElementById('proc_port').value;
        if(!port) { Swal.fire('Erro', 'Número da portabilidade não identificado.', 'error'); return; }
        
        var inputs = document.querySelectorAll('.reg-new-field');
        
        // 1. Standard Inputs Validation
        var standardInputs = Array.from(inputs).filter(el => el.type !== 'checkbox' && el.type !== 'radio');
        if (!validateElements(standardInputs)) return;

        // 2. Custom Multiselect Validation
        var checkboxGroups = {};
        inputs.forEach(el => {
            if(el.type === 'checkbox' && el.dataset.required === 'true') {
                var name = el.name;
                if(!checkboxGroups[name]) checkboxGroups[name] = [];
                checkboxGroups[name].push(el);
            }
        });

        var isCheckboxValid = true;
        for(var name in checkboxGroups) {
            var group = checkboxGroups[name];
            var isChecked = group.some(el => el.checked);
            
            var first = group[0];
            var dropdownBtn = first.closest('.dropdown').querySelector('.dropdown-toggle');

            if(!isChecked) {
                if(dropdownBtn) {
                     dropdownBtn.classList.add('border-danger');
                     // Add listener to remove
                     group.forEach(el => el.addEventListener('change', function() {
                          if (group.some(e => e.checked)) dropdownBtn.classList.remove('border-danger');
                     }));
                     if(isCheckboxValid) dropdownBtn.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
                isCheckboxValid = false;
            } else {
                if(dropdownBtn) dropdownBtn.classList.remove('border-danger');
            }
        }
        
        if (!isCheckboxValid) return;

        var fd = new FormData();
        fd.append('acao', 'ajax_save_process_data_record');
        fd.append('port', port);
        
        document.querySelectorAll('.reg-new-field').forEach(el => {
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            var key = el.name.replace('reg_new_', '');
            fd.append(key, el.value);
        });
        
        setButtonLoading(btn, true);
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                Swal.fire('Sucesso', 'Registro salvo!', 'success');
                renderRegistrosHistory(res.history);
            } else {
                Swal.fire('Erro', res.message || 'Erro ao salvar', 'error');
            }
        })
        .catch(e => {
            setButtonLoading(btn, false);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function updateMultiselectLabel(checkbox) {
        var container = checkbox.closest('.dropdown');
        var selected = [];
        container.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => selected.push(cb.value));
        
        var label = selected.length ? selected.join(', ') : 'Selecione...';
        if (label.length > 30) label = label.substring(0, 27) + '...';
        
        var btn = container.querySelector('.dropdown-toggle');
        if(btn) btn.innerText = label;
    }

    function updateEmailListFromForm() {
        var agencyEmails = [];
        // Scan inputs for email fields
        document.querySelectorAll('#form_processo [name^="agency_"]').forEach(el => {
            var name = el.name.toUpperCase();
            if (name.includes('MAIL') || name.includes('EMAIL')) {
                var val = el.value;
                if (val) {
                    var parts = val.split(/[;,]/);
                    parts.forEach(p => {
                        p = p.trim();
                        if(p && p.includes('@')) { 
                            agencyEmails.push(p);
                        }
                    });
                }
            }
        });
        
        agencyEmails = [...new Set(agencyEmails)].sort();
        
        var emailList = document.getElementById('email_list_ul');
        if (emailList) {
            emailList.innerHTML = '';
            if(agencyEmails.length === 0) {
                 emailList.innerHTML = '<li class="text-muted small">Nenhum email encontrado na agência.</li>';
            } else {
                agencyEmails.forEach(em => {
                    var id = 'chk_' + Math.random().toString(36).substr(2, 9);
                    var li = document.createElement('li');
                    li.className = 'form-check mb-1';
                    li.onclick = function(e) { e.stopPropagation(); };
                    
                    var checkbox = document.createElement('input');
                    checkbox.className = 'form-check-input email-checkbox';
                    checkbox.type = 'checkbox';
                    checkbox.value = em;
                    checkbox.id = id;
                    
                    var label = document.createElement('label');
                    label.className = 'form-check-label';
                    label.htmlFor = id;
                    label.textContent = em;
                    
                    li.appendChild(checkbox);
                    li.appendChild(label);
                    emailList.appendChild(li);
                });
            }
            // Clear selected list display
            var selList = document.getElementById('selected_emails_list');
            if(selList) selList.innerText = '';
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        // Init Base Page if visible or just available
        if(document.getElementById('page-base')) {
            switchBase('Identificacao_cred.json');
        }

        // Add Enter key listener for Portability Search
        var procPortInput = document.getElementById('proc_port');
        if(procPortInput) {
            procPortInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter') {
                    e.preventDefault();
                    checkProcess();
                }
            });
        }
    });
</script>
<script>
    function applyCustomMask(el) {
        let mask = el.getAttribute('data-mask');
        let allowed = el.getAttribute('data-allowed');
        let txtCase = el.getAttribute('data-case');
        let val = el.value;

        // 1. Case
        if (txtCase === 'upper') val = val.toUpperCase();
        if (txtCase === 'lower') val = val.toLowerCase();

        // 2. Allowed Chars (Pre-mask filtering logic helper)
        let stripRegex = null;
        if (allowed === 'numbers') stripRegex = /[^0-9]/g;
        else if (allowed === 'letters') stripRegex = /[^a-zA-Z]/g;
        else if (allowed === 'alphanumeric') stripRegex = /[^a-zA-Z0-9]/g;

        if (!mask) {
            if (stripRegex) val = val.replace(stripRegex, '');
            if (el.value !== val) el.value = val;
            return;
        }

        // 3. Mask Logic
        // If we have a mask, we strip the value based on allowed chars (ignoring literals usually, but simplest is to strip based on allowed).
        // If allowed is 'numbers', strip everything else. This implies literals must be auto-added and not typed by user if they are non-numbers.
        // But if user types literal, we might consume it.
        
        let stripped = val;
        if (stripRegex) stripped = val.replace(stripRegex, '');
        
        let output = "";
        let rawIdx = 0;
        
        for (let i = 0; i < mask.length; i++) {
            let m = mask[i];
            
            if (m === '0' || m === 'A' || m === '*') {
                // It's a slot. Find next valid char in stripped
                while (rawIdx < stripped.length) {
                    let c = stripped[rawIdx++];
                    if (m === '0' && /[0-9]/.test(c)) { output += c; break; }
                    if (m === 'A' && /[a-zA-Z]/.test(c)) { output += c; break; }
                    if (m === '*') { output += c; break; }
                }
            } else {
                // It's a literal.
                output += m;
                // If the user typed this literal (present in stripped), consume it.
                // BUT if we stripped it (e.g. allowed=numbers and literal is '-'), it won't be in stripped.
                // So we only consume if it exists in stripped (which implies allowed='all' or literal is valid).
                
                if (rawIdx < stripped.length && stripped[rawIdx] === m) {
                    rawIdx++;
                }
            }
        }
        
        if (el.value !== output) el.value = output;
    }

    var colFiltersLembretes = {};
    var lembretesDebounce = null;
    var lastFilterCol = null;
    var lembretesSortCol = 'Data_Lembrete';
    var lembretesSortDir = 'asc';
    var lembretesColumnOrder = [];

    function sortLembretes(col) {
        if (lembretesSortCol === col) {
            lembretesSortDir = (lembretesSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            lembretesSortCol = col;
            lembretesSortDir = 'asc';
        }
        filterLembretes(null, 1);
    }

    function clearLembretesFilters() {
        colFiltersLembretes = {};
        lembretesSortCol = 'Data_Lembrete';
        lembretesSortDir = 'asc';
        filterLembretes(null, 1);
    }

    function filterLembretes(e, page) {
        if(e) e.preventDefault();
        
        var form = document.getElementById('form_lembretes_filter');
        var fd = new FormData(form || undefined);
        fd.append('acao', 'ajax_render_lembretes_table');
        if(page) fd.append('pag', page);
        fd.append('colFilters', JSON.stringify(colFiltersLembretes));
        fd.append('sortCol', lembretesSortCol);
        fd.append('sortDir', lembretesSortDir);
        if(lembretesColumnOrder.length > 0) {
            fd.append('columnOrder', JSON.stringify(lembretesColumnOrder));
        }
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status == 'ok') {
                var container = document.getElementById('lembretes_table');
                if(container) {
                    container.innerHTML = res.html;
                    
                    // Init Sortable on Header
                    var theadRow = container.querySelector('thead tr:first-child');
                    if(theadRow && typeof Sortable !== 'undefined') {
                        new Sortable(theadRow, {
                            animation: 150,
                            onEnd: function (evt) {
                                // Capture new order
                                var newOrder = [];
                                theadRow.querySelectorAll('th').forEach(th => {
                                    var key = th.getAttribute('data-key');
                                    if(key) newOrder.push(key);
                                });
                                lembretesColumnOrder = newOrder;
                                // Refresh table to sync body and filters
                                filterLembretes(null, 1);
                            }
                        });
                    }

                    // Restore focus
                    if (lastFilterCol) {
                         var input = container.querySelector("input[onkeyup*=\"'" + lastFilterCol + "'\"]");
                         if(input) {
                             var len = input.value.length;
                             input.focus();
                             input.setSelectionRange(len, len);
                         }
                    }
                }
                if(document.getElementById('lembretes_pagination_container')) {
                    document.getElementById('lembretes_pagination_container').innerHTML = res.pagination;
                }
            }
        });
    }

    function filterLembretesCol(input, colKey) {
        colFiltersLembretes[colKey] = input.value;
        lastFilterCol = colKey;
        
        clearTimeout(lembretesDebounce);
        lembretesDebounce = setTimeout(function() {
            filterLembretes(null, 1);
        }, 500);
    }

    // Global listener to prevent leading whitespace in inputs
    document.addEventListener('input', function(e) {
        var target = e.target;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
            var type = target.type;
            // Exclude non-text inputs
            if (['checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'image', 'hidden', 'range', 'color'].indexOf(type) !== -1) {
                return;
            }
            
            var val = target.value;
            if (val && val.length > 0 && /^\s/.test(val)) {
                var start = target.selectionStart;
                var end = target.selectionEnd;
                var newVal = val.replace(/^\s+/, '');
                
                if (val !== newVal) {
                    target.value = newVal;
                    // Adjust cursor position
                    if (type !== 'email' && type !== 'number') { 
                        try {
                            var diff = val.length - newVal.length;
                            if (start >= diff) {
                                target.setSelectionRange(start - diff, end - diff);
                            } else {
                                target.setSelectionRange(0, 0);
                            }
                        } catch(err) {
                            // Ignore errors for input types that don't support selection
                        }
                    }
                }
            }
        }
    });
</script>
</body>
