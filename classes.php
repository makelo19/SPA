<?php

class ExcelParser {
    public static function parseXLSX($filename) {
        $zip = new ZipArchive();
        if ($zip->open($filename) !== TRUE) {
            return false;
        }

        // 1. Read Shared Strings
        $sharedStrings = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $xml = $zip->getFromName('xl/sharedStrings.xml');
            $dom = new DOMDocument();
            $dom->loadXML($xml);
            $si = $dom->getElementsByTagName('si');
            foreach ($si as $item) {
                $sharedStrings[] = $item->textContent;
            }
        }

        // 2. Read Sheet 1
        $rows = [];
        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $dom = new DOMDocument();
            $dom->loadXML($xml);
            
            $sheetData = $dom->getElementsByTagName('sheetData')->item(0);
            if ($sheetData) {
                foreach ($sheetData->getElementsByTagName('row') as $rowNode) {
                    $rowCells = [];
                    
                    foreach ($rowNode->getElementsByTagName('c') as $cellNode) {
                         $attr = $cellNode->getAttribute('r'); // e.g. A1
                         $colRef = preg_replace('/[0-9]+/', '', $attr);
                         $colIndex = self::colToIndex($colRef);
                         
                         $val = '';
                         $type = $cellNode->getAttribute('t');
                         
                         $vNode = $cellNode->getElementsByTagName('v')->item(0);
                         if ($vNode) {
                             $v = $vNode->textContent;
                             if ($type == 's') {
                                 $val = isset($sharedStrings[$v]) ? $sharedStrings[$v] : '';
                             } else {
                                 $val = $v;
                             }
                         }
                         
                         if ($type == 'inlineStr') {
                             $isNode = $cellNode->getElementsByTagName('is')->item(0);
                             if ($isNode) $val = $isNode->textContent;
                         }
                         
                         $rowCells[$colIndex] = $val;
                    }
                    
                    if (!empty($rowCells)) {
                        $maxIdx = max(array_keys($rowCells));
                        $denseRow = [];
                        for ($i = 0; $i <= $maxIdx; $i++) {
                            $denseRow[] = isset($rowCells[$i]) ? $rowCells[$i] : '';
                        }
                        $rows[] = $denseRow;
                    } else {
                         $rows[] = [];
                    }
                }
            }
        }
        
        $zip->close();
        return $rows;
    }

    private static function colToIndex($col) {
        $col = strtoupper($col);
        $len = strlen($col);
        $idx = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - 64);
        }
        return $idx - 1;
    }
}

class Database {
    private $dataDir;

    public function __construct($dataDir = 'dados/') {
        $this->dataDir = rtrim($dataDir, '/') . '/';
    }

    public function getPath($file) {
        // Prevent Directory Traversal
        while (strpos($file, '..') !== false) {
             $file = str_replace('..', '', $file);
        }
        return $this->dataDir . $file;
    }

    private function isExcel($file) {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xls';
    }

    private function isJSON($file) {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json';
    }

    public function readJSON($path) {
        if (!file_exists($path)) return [];
        
        $handle = fopen($path, 'r');
        if (!$handle) return [];
        
        $content = '';
        if (flock($handle, LOCK_SH)) {
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        
        if (!$content) return [];
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    public function writeJSON($path, $data) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
             return false;
        }
        
        $handle = fopen($path, 'c');
        if (!$handle) return false;
        
        if (flock($handle, LOCK_EX)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            return true;
        }
        fclose($handle);
        return false;
    }

    public function readExcelXML($path) {
        if (!file_exists($path)) return [];
        
        $content = '';
        $handle = fopen($path, 'r');
        if ($handle) {
            if (flock($handle, LOCK_SH)) {
                $content = stream_get_contents($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
        
        if (!$content) return [];

        $dom = new DOMDocument();
        // Suppress warnings
        $old = libxml_use_internal_errors(true);
        if (!$dom->loadXML($content)) {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
            return [];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    
        $rows = $dom->getElementsByTagName('Row');
        $data = [];
        $headers = [];
        
        foreach ($rows as $rowIndex => $row) {
            $cells = $row->getElementsByTagName('Cell');
            $rowData = [];
            $colIndex = 0;
            
            foreach ($cells as $cell) {
                 if ($cell->hasAttribute('ss:Index')) {
                     $colIndex = (int)$cell->getAttribute('ss:Index') - 1;
                 }
                 
                 $dataNode = $cell->getElementsByTagName('Data')->item(0);
                 $val = $dataNode ? $dataNode->nodeValue : '';
                 $rowData[$colIndex] = $val;
                 $colIndex++;
            }
            
            if ($rowIndex === 0) {
                foreach ($rowData as $k => $v) $headers[$k] = trim($v);
            } else {
                 $mapped = [];
                 $hasData = false;
                 foreach($headers as $i => $h) {
                     $val = isset($rowData[$i]) ? $rowData[$i] : '';
                     $mapped[$h] = $val;
                     if ($val !== '') $hasData = true;
                 }
                 if ($hasData) $data[] = $mapped;
            }
        }
        return ['headers' => $headers, 'data' => $data];
    }

    private function writeExcelXML($path, $headers, $data) {
        $xml = '<?xml version="1.0"?>' . "\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ';
        $xml .= 'xmlns:o="urn:schemas-microsoft-com:office:office" ';
        $xml .= 'xmlns:x="urn:schemas-microsoft-com:office:excel" ';
        $xml .= 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" ';
        $xml .= 'xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
        
        $xml .= '<Styles>' . "\n";
        $xml .= ' <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Bottom"/><Borders/><Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#000000"/><Interior/><NumberFormat/><Protection/></Style>' . "\n";
        $xml .= ' <Style ss:ID="Header"><Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/><Interior ss:Color="#003366" ss:Pattern="Solid"/></Style>' . "\n";
        $xml .= '</Styles>' . "\n";
    
        $xml .= '<Worksheet ss:Name="Sheet1">' . "\n";
        $xml .= ' <Table>' . "\n";
        
        // Headers
        $xml .= '  <Row>' . "\n";
        foreach ($headers as $h) {
            $xml .= '   <Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n";
        }
        $xml .= '  </Row>' . "\n";
        
        // Data
        foreach ($data as $row) {
            $xml .= '  <Row>' . "\n";
            foreach ($headers as $h) {
                $val = isset($row[$h]) ? $row[$h] : '';
                $type = "String";
                $xml .= '   <Cell><Data ss:Type="' . $type . '">' . htmlspecialchars($val) . '</Data></Cell>' . "\n";
            }
            $xml .= '  </Row>' . "\n";
        }
        
        $xml .= ' </Table>' . "\n";
        $xml .= '</Worksheet>' . "\n";
        $xml .= '</Workbook>';
        
        $handle = fopen($path, 'c+');
        if ($handle) {
            if (flock($handle, LOCK_EX)) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $xml);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            return true;
        }
        return false;
    }
    
    public function importProcessData($newData) {
        $countInserted = 0;
        $countUpdated = 0;
        $groups = [];

        foreach ($newData as $row) {
             $date = get_value_ci($row, 'DATA');
             $dt = DateTime::createFromFormat('d/m/Y', $date);
             if (!$dt) $dt = new DateTime(); 
             
             $year = $dt->format('Y');
             $month = $dt->format('n');
             $key = "$year-$month";
             
             if (!isset($groups[$key])) $groups[$key] = [];
             $groups[$key][] = $row;
        }

        foreach ($groups as $key => $rows) {
             list($year, $month) = explode('-', $key);
             $file = $this->ensurePeriodStructure($year, $month);
             $path = $this->getPath($file);
             
             $existing = $this->readJSON($path);
             $dataMap = [];
             foreach ($existing as $r) {
                 $rPk = get_value_ci($r, 'Numero_Portabilidade');
                 if ($rPk) {
                     $dataMap[$rPk] = $r;
                 }
             }
             
             foreach ($rows as $row) {
                 $pk = get_value_ci($row, 'Numero_Portabilidade');
                 if ($pk) {
                     if (isset($dataMap[$pk])) {
                         // Filter empty values from row to prevent overwriting existing data with blanks
                         $rowFiltered = array_filter($row, function($v) {
                             return $v !== null && trim((string)$v) !== '';
                         });
                         
                         $dataMap[$pk] = $this->mergeCaseInsensitive($dataMap[$pk], $rowFiltered);
                         $countUpdated++;
                     } else {
                         $dataMap[$pk] = $row;
                         $countInserted++;
                     }
                 }
             }
             
             $this->writeJSON($path, array_values($dataMap));
        }
        
        return ['inserted' => $countInserted, 'updated' => $countUpdated];
    }

    public function importExcelData($file, $newData) {
        $path = $this->getPath($file);
        
        if ($file === 'Processos' || strpos($file, 'Base_processos') !== false) {
             if (!is_file($path)) {
                 return $this->importProcessData($newData);
             }
        }

        $keyCol = 'PORTABILIDADE';
        if (stripos($file, 'client') !== false) $keyCol = 'CPF';
        if (stripos($file, 'agenc') !== false) $keyCol = 'AG';

        if ($this->isJSON($file)) {
            $existing = $this->readJSON($path);
            $dataMap = [];
            foreach ($existing as $row) {
                $rKey = get_value_ci($row, $keyCol);
                if ($rKey) {
                    $dataMap[$rKey] = $row;
                }
            }
            
            $countInserted = 0;
            $countUpdated = 0;
            
            foreach ($newData as $row) {
                 $keyVal = get_value_ci($row, $keyCol);
                 if ($keyVal) {
                     if (isset($dataMap[$keyVal])) {
                         $dataMap[$keyVal] = $this->mergeCaseInsensitive($dataMap[$keyVal], $row);
                         $countUpdated++;
                     } else {
                         $dataMap[$keyVal] = $row;
                         $countInserted++;
                     }
                 }
            }
            
            $this->writeJSON($path, array_values($dataMap));
            return ['inserted' => $countInserted, 'updated' => $countUpdated];
        }

        if (!$this->isExcel($file)) return false;
        
        $existing = $this->readExcelXML($path);
        $headers = !empty($existing['headers']) ? $existing['headers'] : [];
        $dataMap = [];
        
        if (!empty($existing['data'])) {
            foreach ($existing['data'] as $row) {
                if (isset($row[$keyCol])) {
                    $dataMap[$row[$keyCol]] = $row;
                }
            }
        }
        
        if (empty($headers)) {
             $headers = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', 'PORTABILIDADE', 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
        }
        
        $countInserted = 0;
        $countUpdated = 0;
        
        foreach ($newData as $row) {
             if (isset($row[$keyCol]) && $row[$keyCol]) {
                 $keyVal = $row[$keyCol];
                 if (isset($dataMap[$keyVal])) {
                     $dataMap[$keyVal] = array_merge($dataMap[$keyVal], $row);
                     $countUpdated++;
                 } else {
                     $finalRow = [];
                     foreach($headers as $h) $finalRow[$h] = $row[$h] ?? '';
                     $dataMap[$keyVal] = $finalRow;
                     $countInserted++;
                 }
             }
        }
        
        $this->writeExcelXML($path, $headers, array_values($dataMap));
        return ['inserted' => $countInserted, 'updated' => $countUpdated];
    }

    public function getHeaders($file) {
        $path = $this->getPath($file);
        
        if ($this->isJSON($file)) {
            if (!file_exists($path)) return [];
            $data = $this->readJSON($path);
            if (!empty($data)) {
                return array_keys($data[0]);
            }
            return [];
        }

        if (!file_exists($path)) return [];

        if ($this->isExcel($file)) {
            $res = $this->readExcelXML($path);
            return $res['headers'];
        }

        $f = fopen($path, 'r');
        if (!$f) return [];
        $line = fgetcsv($f, 0, "\t");
        fclose($f);
        if (!$line) return [];
        return array_map('trim', $line);
    }

    // Helper to find key case-insensitive
    private function getCaseInsensitiveKey($array, $key) {
        foreach ($array as $k => $v) {
            if (mb_strtoupper($k, 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                return $k;
            }
        }
        return null;
    }

    private function mergeCaseInsensitive($original, $new) {
        foreach ($new as $k => $v) {
            $existingKey = $this->getCaseInsensitiveKey($original, $k);
            if ($existingKey !== null) {
                $original[$existingKey] = $v;
            } else {
                $original[$k] = $v;
            }
        }
        return $original;
    }

    // Helper to find column index case-insensitive
    private function getColIndex($headers, $colName) {
        foreach ($headers as $idx => $h) {
            if (mb_strtoupper($h, 'UTF-8') === mb_strtoupper($colName, 'UTF-8')) {
                return $idx;
            }
        }
        return false;
    }

    public function removeDuplicates($file, $uniqueCol) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return false;

        if ($this->isJSON($file)) {
             $data = $this->readJSON($path);
             $uniqueMap = [];
             foreach ($data as $row) {
                 $val = $row[$uniqueCol] ?? '';
                 if ($val) $uniqueMap[$val] = $row;
             }
             ksort($uniqueMap);
             return $this->writeJSON($path, array_values($uniqueMap));
        }

        $headers = $this->getHeaders($file);
        $colIdx = $this->getColIndex($headers, $uniqueCol);
        if ($colIdx === false) return false;

        if ($this->isExcel($file)) {
             return false; 
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) return false;

        $headerLine = array_shift($lines);
        $uniqueMap = [];
        $delimiter = "\t";

        foreach ($lines as $line) {
            $cols = str_getcsv($line, $delimiter);
            if (isset($cols[$colIdx])) {
                $key = trim($cols[$colIdx]);
                if ($key !== '') {
                    $uniqueMap[$key] = $line;
                }
            }
        }

        ksort($uniqueMap);

        $handle = fopen($path, 'w');
        if ($handle) {
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $headerLine . "\n");
                foreach ($uniqueMap as $line) {
                    fwrite($handle, $line . "\n");
                }
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            return true;
        }
        return false;
    }

    public function findReverse($file, $colName, $value) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return null;

        if ($this->isJSON($file)) {
            $data = $this->readJSON($path);
            $rows = array_reverse($data);
            foreach ($rows as $row) {
                $val = isset($row[$colName]) ? $row[$colName] : '';
                if (!$val) {
                     $k = $this->getCaseInsensitiveKey($row, $colName);
                     $val = $key ? $row[$key] : '';
                }
                if ($val == $value) return $row;
            }
            return null;
        }

        $headers = $this->getHeaders($file);
        $colIdx = $this->getColIndex($headers, $colName);
        if ($colIdx === false) return null;

        if ($this->isExcel($file)) {
             $res = $this->readExcelXML($path);
             $rows = array_reverse($res['data']);
             foreach ($rows as $row) {
                 $val = isset($row[$colName]) ? $row[$colName] : '';
                 if (!$val) {
                     $k = $this->getCaseInsensitiveKey($row, $colName);
                     $val = $key ? $row[$key] : '';
                 }
                 if ($val == $value) return $row;
             }
             return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) return null;
        
        $headerLine = array_shift($lines);
        $lines = array_reverse($lines);

        foreach ($lines as $line) {
            $cols = str_getcsv($line, "\t");
            if (count($cols) < count($headers)) $cols = array_pad($cols, count($headers), "");
            $cols = array_map('trim', $cols);
            
            if (isset($cols[$colIdx]) && $cols[$colIdx] == $value) {
                return array_combine($headers, array_slice($cols, 0, count($headers)));
            }
        }
        return null;
    }

    public function select($file, $filters = [], $page = 1, $limit = 20, $sortBy = null, $desc = false) {
        $files = is_array($file) ? $file : [$file];
        $lines = [];
        $headerMap = [];
        $headers = [];

        foreach ($files as $f) {
            $path = $this->getPath($f);
            if (!file_exists($path)) continue;

            // Data Loading Strategy
            $rawRows = [];
            if ($this->isJSON($f)) {
                $rawRows = $this->readJSON($path);
                if (!empty($rawRows) && empty($headers)) {
                    $headers = array_keys($rawRows[0]);
                }
            } elseif ($this->isExcel($f)) {
                $res = $this->readExcelXML($path);
                $rawRows = $res['data'];
                if (empty($headers)) $headers = $res['headers'];
            } else {
                $currentHeaders = $this->getHeaders($f);
                if (empty($currentHeaders)) continue;
                if (empty($headers)) $headers = $currentHeaders;
                
                $handle = fopen($path, "r");
                if ($handle) {
                    fgetcsv($handle, 0, "\t"); // skip header
                    while (($cols = fgetcsv($handle, 0, "\t")) !== false) {
                        if ($cols === [null]) continue;
                        if (count($cols) < count($currentHeaders)) $cols = array_pad($cols, count($currentHeaders), "");
                        $cols = array_map('trim', $cols);
                        $rawRows[] = array_combine($currentHeaders, array_slice($cols, 0, count($currentHeaders)));
                    }
                    fclose($handle);
                }
            }

            if (!empty($headers)) {
                foreach ($headers as $h) {
                    $headerMap[$h] = true;
                }
            }

            // Apply Filters and Merge
            foreach ($rawRows as $row) {
                $match = true;
                if (!empty($filters)) {
                    foreach ($filters as $k => $v) {
                        if ($k === 'global') {
                            $found = false;
                            foreach ($row as $val) {
                                if (stripos((string)$val, $v) !== false) {
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) $match = false;
                        } elseif ($k === 'callback') {
                            if (is_callable($v) && !$v($row)) $match = false;
                        } else {
                            $rowKey = $this->getCaseInsensitiveKey($row, $k);
                            if ($rowKey && isset($row[$rowKey]) && $row[$rowKey] != $v) $match = false;
                            if (!$rowKey && $v !== '') $match = false;
                        }
                        if (!$match) break;
                    }
                }
                if ($match) {
                    $lines[] = $row;
                }
            }
        }

        // Sorting
        if ($sortBy) {
            $sortKey = $this->getCaseInsensitiveKey($headerMap, $sortBy);
            if ($sortKey) {
                usort($lines, function($a, $b) use ($sortKey, $desc) {
                    // Use get_value_ci to ensure we get the value regardless of case
                    // even if sortKey is derived from canonical headers
                    $rawA = get_value_ci($a, $sortKey);
                    $rawB = get_value_ci($b, $sortKey);

                    // EMPTY CHECK - ALWAYS AT END
                    $emptyA = (trim((string)$rawA) === '');
                    $emptyB = (trim((string)$rawB) === '');

                    if ($emptyA && !$emptyB) return 1; 
                    if (!$emptyA && $emptyB) return -1;
                    if ($emptyA && $emptyB) return 0;
                    
                    // Helper to normalize values for comparison
                    $normalize = function($val) {
                        $val = trim((string)$val);
                        if ($val === '') return $val;

                        // Date d/m/Y or d/m/Y H:i
                        if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $val)) {
                            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $val);
                            if(!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
                            if(!$dt) $dt = DateTime::createFromFormat('d/m/Y', $val);
                            return $dt ? $dt->getTimestamp() : 0;
                        }
                        
                        // Currency (e.g. R$ 1.000,00)
                        if (strpos($val, 'R$') !== false) {
                            $v = str_replace(['R$', '.', ' '], '', $val);
                            $v = str_replace(',', '.', $v);
                            return (float)$v;
                        }

                        // Check if numeric-like (CPF, Portabilidade)
                        // Must not contain letters
                        if (!preg_match('/[a-zA-Z]/', $val)) {
                             // Strip non-digits and separators
                             $stripped = preg_replace('/[^\d]/', '', $val);
                             if ($stripped !== '') return (float)$stripped;
                        }
                        
                        return strtolower($val);
                    };

                    // Special Case: Atendente (Primary: Name, Secondary: Ultima_Alteracao/DATA DESC)
                    if (stripos($sortKey, 'atendente') !== false) {
                        $nameA = strtolower(trim($rawA));
                        $nameB = strtolower(trim($rawB));
                        
                        if ($nameA != $nameB) {
                             return ($nameA < $nameB) ? ($desc ? 1 : -1) : ($desc ? -1 : 1);
                        }
                        
                        // Secondary Sort: Ultima_Alteracao (Always DESC - Most Recent First)
                        $dateA = $a['Ultima_Alteracao'] ?? ($a['DATA'] ?? '');
                        $dateB = $b['Ultima_Alteracao'] ?? ($b['DATA'] ?? '');
                        
                        $tsA = $normalize($dateA);
                        $tsB = $normalize($dateB);
                        
                        if ($tsA == $tsB) return 0;
                        return ($tsA < $tsB) ? 1 : -1; 
                    }
                    
                    // Normal Sort
                    $nA = $normalize($rawA);
                    $nB = $normalize($rawB);
                    
                    if ($nA == $nB) return 0;
                    return ($nA < $nB) ? ($desc ? 1 : -1) : ($desc ? -1 : 1);
                });
            }
        } else {
             $lines = array_reverse($lines);
        }

        $total = count($lines);
        $offset = ($page - 1) * $limit;
        $data = array_slice($lines, $offset, $limit);

        return ['data' => $data, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)];
    }

    
    // Find one record
    public function find($file, $colName, $value) {
        $res = $this->findMany($file, $colName, [$value]);
        return !empty($res) ? $res[0] : null;
    }

    public function findMany($file, $colName, $values) {
        $path = $this->getPath($file);
        if (!file_exists($path) || empty($values)) return [];

        if ($this->isJSON($file)) {
            $data = $this->readJSON($path);
            $results = [];
            foreach ($data as $row) {
                $val = isset($row[$colName]) ? $row[$colName] : '';
                if (!$val) {
                     $key = $this->getCaseInsensitiveKey($row, $colName);
                     $val = $key ? $row[$key] : '';
                }
                if (in_array($val, $values)) {
                    $results[] = $row;
                }
            }
            return $results;
        }

        $headers = $this->getHeaders($file);
        $colIdx = $this->getColIndex($headers, $colName);
        if ($colIdx === false) return [];

        $results = [];

        if ($this->isExcel($file)) {
            $res = $this->readExcelXML($path);
            foreach ($res['data'] as $row) {
                $val = isset($row[$colName]) ? $row[$colName] : '';
                if (!$val) {
                     $key = $this->getCaseInsensitiveKey($row, $colName);
                     $val = $key ? $row[$key] : '';
                }
                if (in_array($val, $values)) {
                    $results[] = $row;
                }
            }
            return $results;
        }

        $handle = fopen($path, "r");
        if ($handle) {
            fgetcsv($handle, 0, "\t");
            while (($cols = fgetcsv($handle, 0, "\t")) !== false) {
                if ($cols === [null]) continue;
                if (count($cols) < count($headers)) $cols = array_pad($cols, count($headers), "");
                $cols = array_map('trim', $cols);
                
                $val = isset($cols[$colIdx]) ? $cols[$colIdx] : '';
                if (in_array($val, $values)) {
                    $results[] = array_combine($headers, array_slice($cols, 0, count($headers)));
                }
            }
            fclose($handle);
        }
        return $results;
    }

    public function insert($file, $data) {
        $path = $this->getPath($file);
        
        if ($this->isJSON($file)) {
             $rows = $this->readJSON($path);
             $rows[] = $data;
             return $this->writeJSON($path, $rows);
        }

        $headers = $this->getHeaders($file);
        
        if ($this->isExcel($file)) {
             $res = $this->readExcelXML($path);
             $rows = $res['data'];
             $newRow = [];
             foreach ($headers as $h) {
                $dataKey = $this->getCaseInsensitiveKey($data, $h);
                $val = ($dataKey !== null && isset($data[$dataKey])) ? $data[$dataKey] : '';
                $newRow[$h] = trim($val);
             }
             $rows[] = $newRow;
             return $this->writeExcelXML($path, $headers, $rows) !== false;
        }

        $row = [];
        foreach ($headers as $h) {
            $dataKey = $this->getCaseInsensitiveKey($data, $h);
            $val = ($dataKey !== null && isset($data[$dataKey])) ? $data[$dataKey] : '';
            $row[] = trim($val);
        }
        
        $handle = fopen($path, 'a');
        if ($handle) {
            if (flock($handle, LOCK_EX)) {
                fputcsv($handle, $row, "\t");
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            return true;
        }
        return false;
    }

    public function update($file, $keyCol, $keyVal, $newData) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return false;

        if ($this->isJSON($file)) {
             $rows = $this->readJSON($path);
             $updated = false;
             foreach ($rows as &$row) {
                 $val = isset($row[$keyCol]) ? $row[$keyCol] : '';
                 if ($val === '') {
                     $k = $this->getCaseInsensitiveKey($row, $keyCol);
                     if ($k) $val = $row[$k];
                 }

                 if ($val == $keyVal) {
                     $row = $this->mergeCaseInsensitive($row, $newData);
                     $updated = true;
                 }
             }
             if ($updated) return $this->writeJSON($path, $rows);
             return false;
        }

        $headers = $this->getHeaders($file);

        if ($this->isExcel($file)) {
             $res = $this->readExcelXML($path);
             $rows = $res['data'];
             $updated = false;
             
             foreach ($rows as &$row) {
                 $val = isset($row[$keyCol]) ? $row[$keyCol] : '';
                 if ($val === '') {
                     $k = $this->getCaseInsensitiveKey($row, $keyCol);
                     if ($k) $val = $row[$k];
                 }

                 if ($val == $keyVal) {
                     foreach ($newData as $nk => $nv) {
                         $hk = $this->getCaseInsensitiveKey(array_flip($headers), $nk);
                         if ($hk) $row[$hk] = $nv;
                         else if (in_array($nk, $headers)) $row[$nk] = $nv;
                     }
                     $updated = true;
                 }
             }
             
             if ($updated) {
                 return $this->writeExcelXML($path, $headers, $rows) !== false;
             }
             return false;
        }

        $tempPath = $path . '.tmp';
        $source = fopen($path, 'r');
        $dest = fopen($tempPath, 'w');
        
        if (!$source || !$dest) return false;

        $headers = fgetcsv($source, 0, "\t");
        $headers = array_map('trim', $headers);
        fputcsv($dest, $headers, "\t");
        
        $colIdx = $this->getColIndex($headers, $keyCol);
        $updated = false;

        $normalizedData = [];
        foreach ($newData as $k => $v) {
            $headerKey = $this->getCaseInsensitiveKey(array_flip($headers), $k);
            if ($headerKey) {
                $normalizedData[$headerKey] = $v;
            } else {
                $normalizedData[$k] = $v;
            }
        }

        while (($cols = fgetcsv($source, 0, "\t")) !== false) {
            if ($cols === [null]) continue;
            if (count($cols) < count($headers)) $cols = array_pad($cols, count($headers), "");
            $cols = array_map('trim', $cols);
            
            if ($colIdx !== false && isset($cols[$colIdx]) && $cols[$colIdx] == $keyVal) {
                $currentData = array_combine($headers, array_slice($cols, 0, count($headers)));
                $mergedData = array_merge($currentData, $normalizedData);
                
                $newRow = [];
                foreach ($headers as $h) {
                    $newRow[] = isset($mergedData[$h]) ? trim($mergedData[$h]) : '';
                }
                fputcsv($dest, $newRow, "\t");
                $updated = true;
            } else {
                fputcsv($dest, $cols, "\t");
            }
        }
        
        fclose($source);
        fclose($dest);
        
        if ($updated) {
            rename($tempPath, $path);
            return true;
        } else {
            unlink($tempPath);
            return false;
        }
    }
    
    public function delete($file, $keyCol, $keyVal) {
        return $this->deleteMany($file, $keyCol, [$keyVal]);
    }

    public function deleteMany($file, $keyCol, $keyValues) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return false;

        if ($this->isJSON($file)) {
             $rows = $this->readJSON($path);
             $newRows = [];
             $deletedCount = 0;
             foreach ($rows as $row) {
                 $val = isset($row[$keyCol]) ? $row[$keyCol] : '';
                 if ($val === '') {
                     $k = $this->getCaseInsensitiveKey($row, $keyCol);
                     if ($k) $val = $row[$k];
                 }
                 if (in_array($val, $keyValues)) {
                     $deletedCount++;
                     continue;
                 }
                 $newRows[] = $row;
             }
             if ($deletedCount > 0) return $this->writeJSON($path, $newRows);
             return false;
        }

        $headers = $this->getHeaders($file);

        if ($this->isExcel($file)) {
             $res = $this->readExcelXML($path);
             $rows = $res['data'];
             $newRows = [];
             $deletedCount = 0;
             
             foreach ($rows as $row) {
                 $val = isset($row[$keyCol]) ? $row[$keyCol] : '';
                 if ($val === '') {
                     $k = $this->getCaseInsensitiveKey($row, $keyCol);
                     if ($k) $val = $row[$k];
                 }

                 if (in_array($val, $keyValues)) {
                     $deletedCount++;
                     continue; 
                 }
                 $newRows[] = $row;
             }
             
             if ($deletedCount > 0) {
                 return $this->writeExcelXML($path, $headers, $newRows) !== false;
             }
             return false;
        }

        $tempPath = $path . '.tmp';
        $source = fopen($path, 'r');
        $dest = fopen($tempPath, 'w');
        
        if (!$source || !$dest) return false;

        $headers = fgetcsv($source, 0, "\t");
        $headers = array_map('trim', $headers);
        fputcsv($dest, $headers, "\t");
        
        $colIdx = $this->getColIndex($headers, $keyCol);
        $deletedCount = 0;

        while (($cols = fgetcsv($source, 0, "\t")) !== false) {
            if ($cols === [null]) continue;
            if (count($cols) < count($headers)) $cols = array_pad($cols, count($headers), "");
            $cols = array_map('trim', $cols);
             
            if ($colIdx !== false && isset($cols[$colIdx]) && in_array($cols[$colIdx], $keyValues)) {
                $deletedCount++;
                continue; 
            }
            fputcsv($dest, $cols, "\t");
        }
        
        fclose($source);
        fclose($dest);
        
        if ($deletedCount > 0) {
            rename($tempPath, $path);
            return true;
        } else {
            unlink($tempPath);
            return false;
        }
    }

    public function truncate($file) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return false;

        if ($this->isJSON($file)) {
            return $this->writeJSON($path, []);
        }

        $headers = $this->getHeaders($file);
        if (empty($headers)) return false;

        if ($this->isExcel($file)) {
            return $this->writeExcelXML($path, $headers, []) !== false;
        }

        $handle = fopen($path, 'w');
        if ($handle) {
            if (flock($handle, LOCK_EX)) {
                fputcsv($handle, $headers, "\t");
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            return true;
        }
        return false;
    }

    public function addColumn($file, $colName) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return false;
        
        if ($this->isJSON($file)) {
             $rows = $this->readJSON($path);
             foreach($rows as &$row) {
                 if ($this->getCaseInsensitiveKey($row, $colName) === null) {
                     $row[$colName] = '';
                 }
             }
             return $this->writeJSON($path, $rows);
        }

        $headers = $this->getHeaders($file);
        if ($this->getColIndex($headers, $colName) !== false) {
            return false;
        }
        
        if ($this->isExcel($file)) {
            $res = $this->readExcelXML($path);
            $rows = $res['data'];
            $headers[] = $colName;
            return $this->writeExcelXML($path, $headers, $rows) !== false;
        }

        $tempPath = $path . '.tmp';
        $source = fopen($path, 'r');
        $dest = fopen($tempPath, 'w');
        
        if (!$source || !$dest) return false;

        $headers = fgetcsv($source, 0, "\t");
        $headers = array_map('trim', $headers);
        
        $headers[] = $colName;
        fputcsv($dest, $headers, "\t");

        while (($cols = fgetcsv($source, 0, "\t")) !== false) {
            if ($cols === [null]) continue;
            $cols[] = ""; 
            fputcsv($dest, $cols, "\t");
        }

        fclose($source);
        fclose($dest);
        rename($tempPath, $path);
        return true;
    }

    public function getUniqueValues($file, $column) {
        $path = $this->getPath($file);
        if (!file_exists($path)) return [];

        if ($this->isJSON($file)) {
            $data = $this->readJSON($path);
            $values = [];
            foreach ($data as $row) {
                $val = $row[$column] ?? '';
                if ($val === '') {
                    $k = $this->getCaseInsensitiveKey($row, $column);
                    if ($k) $val = $row[$k];
                }
                if (trim($val) !== '') $values[trim($val)] = true;
            }
            return array_keys($values);
        }

        $headers = $this->getHeaders($file);
        $colIdx = $this->getColIndex($headers, $column);
        if ($colIdx === false) return [];

        $values = [];
        
        if ($this->isExcel($file)) {
            $res = $this->readExcelXML($path);
            foreach ($res['data'] as $row) {
                $val = isset($row[$column]) ? $row[$column] : '';
                 if ($val === '') {
                     $k = $this->getCaseInsensitiveKey($row, $column);
                     if ($k) $val = $row[$k];
                 }
                if (trim($val) !== '') {
                    $values[trim($val)] = true;
                }
            }
            return array_keys($values);
        }
        
        $handle = fopen($path, "r");
        if ($handle) {
            fgetcsv($handle, 0, "\t"); // skip header
            while (($cols = fgetcsv($handle, 0, "\t")) !== false) {
                if ($cols === [null]) continue;
                if (isset($cols[$colIdx])) {
                    $val = trim($cols[$colIdx]);
                    if ($val !== '') {
                        $values[$val] = true;
                    }
                }
            }
            fclose($handle);
        }
        return array_keys($values);
    }

    // --- New Helpers for Date Structure ---

    public function getAllProcessFiles() {
        $files = glob($this->dataDir . 'Processos/*/*.json');
        $result = [];
        if ($files) {
            foreach($files as $f) {
                $result[] = str_replace($this->dataDir, '', $f);
            }
        }
        return $result;
    }

    public function getPortugueseMonth($num) {
        $months = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];
        return isset($months[(int)$num]) ? $months[(int)$num] : 'Janeiro';
    }

    public function resolvePeriodFile($year, $monthNum) {
        $monthName = $this->getPortugueseMonth($monthNum);
        return "Processos/{$year}/{$monthName}.json";
    }

    public function getProcessFiles($years, $months) {
        $files = [];
        foreach ($years as $y) {
            foreach ($months as $m) {
                $file = $this->resolvePeriodFile($y, $m);
                $files[] = $file;
            }
        }
        return $files;
    }

    public function ensurePeriodStructure($year, $monthNum) {
        $file = $this->resolvePeriodFile($year, $monthNum);
        $path = $this->getPath($file);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (!file_exists($path)) {
            $this->writeJSON($path, []);
        }
        return $file;
    }

    public function findFileForRecord($files, $keyCol, $keyVal) {
        foreach ($files as $file) {
            if ($this->find($file, $keyCol, $keyVal)) {
                return $file;
            }
        }
        return null;
    }
}

class Config {
    private $configFile = 'dados/fields.json';
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->configFile = $db->getPath('fields.json');
        $this->init();
    }

    private function init() {
        if (!file_exists($this->configFile)) {
            $config = [];
            $files = [
                'Base_clientes.json' => [
                    'CPF' => 'text', 'Nome' => 'text'
                ],
                'Base_agencias.json' => [
                    'AG'=>'text'
                ],
                'Identificacao_cred.json' => [
                     'STATUS' => 'text', 'NUMERO_DEPOSITO' => 'text', 'DATA_DEPOSITO' => 'date', 
                     'VALOR_DEPOSITO_PRINCIPAL' => 'money', 'TEXTO_PAGAMENTO' => 'textarea', 
                     'PORTABILIDADE' => 'text', 'CERTIFICADO' => 'text', 'STATUS_2' => 'text', 
                     'CPF' => 'text', 'AG' => 'text'
                ],
                'Base_processos_schema' => [
                    'Numero_Portabilidade'=>'text', 'Certificado'=>'text', 'VALOR DA PORTABILIDADE'=>'money', 
                    'DATA'=>'date', 'STATUS'=>'select', 'Nome_atendente'=>'text', 'Data_Ultima_Cobranca'=>'date'
                ],
                'Base_registros_schema' => []
            ];

            foreach ($files as $file => $defaultTypes) {
                // For schema, we might not have a physical file to get headers from if it's new.
                // But we usually do getHeaders to SYNC config with file headers.
                // If Base_processos_schema is virtual, we can't read headers from it.
                // We should use a representative file or just trust defaults.
                if ($file == 'Base_processos_schema') {
                     // Try to find a representative file
                     $headers = ['DATA', 'Ocorrencia', 'Status_ocorrencia', 'Nome_atendente', 'Numero_Portabilidade', 'CPF', 'AG', 'Certificado', 'PROPOSTA', 'VALOR DA PORTABILIDADE', 'AUT_PROPOSTA', 'PROPOSTA_2', 'MOTIVO DE CANCELAMENTO', 'STATUS', 'Data Cancelamento', 'OBSERVAÇÃO', 'Data_Ultima_Cobranca'];
                } else {
                     $headers = $this->db->getHeaders($file);
                }
                $fields = [];
                foreach ($headers as $h) {
                    $type = isset($defaultTypes[$h]) ? $defaultTypes[$h] : 'text';
                    $fields[] = [
                        'key' => $h,
                        'label' => $h,
                        'type' => $type
                    ];
                }
                $config[$file] = $fields;
            }
            
            file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));
        }
        
        // Ensure Credit Base has new columns (Migration)
        $credFile = 'Identificacao_cred.json'; 
        // With JSON we don't need explicit column addition usually, but can init file if needed.

        // Fix for missing fields and mapping 'Base_processos.txt' to new files
        $data = json_decode(file_get_contents($this->configFile), true);
        $changed = false;
        
        // Migrate TXT keys to JSON
        if (isset($data['Base_clientes.txt'])) {
            $data['Base_clientes.json'] = $data['Base_clientes.txt'];
            unset($data['Base_clientes.txt']);
            $changed = true;
        }
        if (isset($data['Base_agencias.txt'])) {
            $data['Base_agencias.json'] = $data['Base_agencias.txt'];
            unset($data['Base_agencias.txt']);
            $changed = true;
        }
        
        // Migrate old config if exists
        if (isset($data['Base_processos.txt'])) {
            $data['Base_processos_schema'] = $data['Base_processos.txt'];
            unset($data['Base_processos.txt']);
            $changed = true;
        }
        
        if (isset($data['Base_processosatual.txt'])) {
            $data['Base_processos_schema'] = $data['Base_processosatual.txt'];
            unset($data['Base_processosatual.txt']);
            unset($data['Base_processosanterior.txt']); // Clean up
            $changed = true;
        }

        // Ensure CPF and AG
        if (isset($data['Base_processos_schema'])) {
            $keys = array_column($data['Base_processos_schema'], 'key');
            if (!in_array('CPF', $keys)) {
                $data['Base_processos_schema'][] = ['key'=>'CPF', 'label'=>'CPF', 'type'=>'text', 'required'=>true];
                $changed = true;
            }
            if (!in_array('AG', $keys)) {
                $data['Base_processos_schema'][] = ['key'=>'AG', 'label'=>'AG', 'type'=>'text', 'required'=>true];
                $changed = true;
            }
        }

        // Ensure Identificacao_cred.json is populated
        if (isset($data['Identificacao_cred.json']) && empty($data['Identificacao_cred.json'])) {
            $data['Identificacao_cred.json'] = [
                 ['key'=>'STATUS', 'label'=>'Status', 'type'=>'text'],
                 ['key'=>'NUMERO_DEPOSITO', 'label'=>'Número Depósito', 'type'=>'text'],
                 ['key'=>'DATA_DEPOSITO', 'label'=>'Data Depósito', 'type'=>'date'],
                 ['key'=>'VALOR_DEPOSITO_PRINCIPAL', 'label'=>'Valor Depósito Principal', 'type'=>'money'],
                 ['key'=>'TEXTO_PAGAMENTO', 'label'=>'Texto Pagamento', 'type'=>'textarea'],
                 ['key'=>'PORTABILIDADE', 'label'=>'Portabilidade', 'type'=>'text'],
                 ['key'=>'CERTIFICADO', 'label'=>'Certificado', 'type'=>'text'],
                 ['key'=>'STATUS_2', 'label'=>'Status 2', 'type'=>'text'],
                 ['key'=>'CPF', 'label'=>'CPF', 'type'=>'text'],
                 ['key'=>'AG', 'label'=>'Agência', 'type'=>'text']
            ];
            $changed = true;
        }

        if ($changed) {
            file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
        }

        // Normalize File Headers to match Config (Case-Correction)
        // With JSON, headers are keys.
    }


    public function getFields($file) {
        $data = json_decode(file_get_contents($this->configFile), true);
        
        // Generic Schema for Process Files
        if (strpos($file, 'Base_de_Processos') !== false || strpos($file, 'Base_processos') !== false || $file === 'Processos') {
             $fields = isset($data['Base_processos_schema']) ? $data['Base_processos_schema'] : (isset($data['Base_processosatual.txt']) ? $data['Base_processosatual.txt'] : []);
        } else {
             $fields = isset($data[$file]) ? $data[$file] : [];
        }

        // Deduplicate fields by key
        $unique = [];
        foreach ($fields as $f) {
            $unique[mb_strtoupper($f['key'], 'UTF-8')] = $f;
        }
        return array_values($unique);
    }

    public function addField($file, $fieldData) {
        $data = json_decode(file_get_contents($this->configFile), true);
        if (!isset($data[$file])) $data[$file] = [];
        
        $key = $fieldData['key'];
        $foundIndex = -1;
        
        foreach ($data[$file] as $idx => $f) {
            if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                $foundIndex = $idx;
                break;
            }
        }
        
        if ($foundIndex !== -1) {
            // Update existing
            $existing = $data[$file][$foundIndex];
            $merged = array_merge($existing, $fieldData);
            if (isset($merged['deleted'])) unset($merged['deleted']);
            $data[$file][$foundIndex] = $merged;
        } else {
            // Append new
            $data[$file][] = $fieldData;
        }
        
        file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
        
        // Add to TXT file
        if (($fieldData['type'] ?? '') !== 'title') {
            $this->db->addColumn($file, $fieldData['key']);
        }
    }
    
    public function updateField($file, $oldKey, $fieldData) {
        $data = json_decode(file_get_contents($this->configFile), true);
        if (isset($data[$file])) {
            foreach ($data[$file] as &$f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($oldKey, 'UTF-8')) {
                    $f = array_merge($f, $fieldData);
                    break;
                }
            }
            file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
            // Note: Renaming columns in TXT is harder, assuming key doesn't change or we implement renameColumn later.
            // For now, assuming key is stable or only label/type changes.
        }
    }

    public function removeField($file, $key) {
        $data = json_decode(file_get_contents($this->configFile), true);
        if (isset($data[$file])) {
            foreach ($data[$file] as &$f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                    $f['deleted'] = true;
                    break;
                }
            }
            file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    public function reactivateField($file, $key) {
        $data = json_decode(file_get_contents($this->configFile), true);
        if (isset($data[$file])) {
            foreach ($data[$file] as &$f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                    unset($f['deleted']);
                    break;
                }
            }
            file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    public function ensureField($file, $fieldData) {
        $data = json_decode(file_get_contents($this->configFile), true);
        if (!isset($data[$file])) $data[$file] = [];
        
        $key = $fieldData['key'];
        $exists = false;
        
        foreach ($data[$file] as $f) {
            if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $data[$file][] = $fieldData;
            file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
            
            // Add to TXT/JSON file structure if necessary (DB::addColumn handles column check)
            if (($fieldData['type'] ?? '') !== 'title') {
                $this->db->addColumn($file, $fieldData['key']);
            }
            return true;
        }
        return false;
    }

    public function reorderFields($file, $newOrderKeys) {
        $data = json_decode(file_get_contents($this->configFile), true);
        if (isset($data[$file])) {
            $currentFields = $data[$file];
            $fieldMap = [];
            foreach ($currentFields as $f) $fieldMap[strtolower($f['key'])] = $f;
            
            $newFields = [];
            foreach ($newOrderKeys as $key) {
                $lookupKey = strtolower($key);
                if (isset($fieldMap[$lookupKey])) {
                    $newFields[] = $fieldMap[$lookupKey];
                    unset($fieldMap[$lookupKey]);
                }
            }
            // Append remaining (if any missing from newOrder)
            foreach ($fieldMap as $f) $newFields[] = $f;
            
            $data[$file] = $newFields;
            file_put_contents($this->configFile, json_encode($data, JSON_PRETTY_PRINT));
        }
    }
}

class Templates {
    private $file = 'dados/templates.json';
    private $historyFile = 'dados/Base_registros.json';

    public function __construct() {
        if (!file_exists($this->file)) {
            $defaults = [
                ['id' => uniqid(), 'titulo' => 'Cobrança Simples', 'corpo' => "Prezado(a) {Nome},\n\nSolicitamos o envio dos documentos referentes ao processo de portabilidade {PORTABILIDADE}."],
                ['id' => uniqid(), 'titulo' => 'Confirmação', 'corpo' => "Olá {Nome}, confirmamos o recebimento."]
            ];
            file_put_contents($this->file, json_encode($defaults, JSON_PRETTY_PRINT));
        }
        
        if (!file_exists($this->historyFile)) {
            if (file_exists('dados/Base_registros.txt')) {
                // Migrate
                $lines = file('dados/Base_registros.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $header = [];
                if (!empty($lines)) {
                    $headerLine = array_shift($lines);
                    $header = explode("\t", trim($headerLine));
                }
                
                $data = [];
                foreach ($lines as $line) {
                    $cols = explode("\t", $line);
                    // Pad if needed
                    while(count($cols) < count($header)) $cols[] = '';
                    $data[] = array_combine($header, array_slice($cols, 0, count($header)));
                }
                file_put_contents($this->historyFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                // Initialize empty
                file_put_contents($this->historyFile, json_encode([], JSON_PRETTY_PRINT));
            }
        }
    }

    public function getAll() {
        return json_decode(file_get_contents($this->file), true);
    }

    public function save($id, $titulo, $corpo) {
        $data = $this->getAll();
        if ($id) {
            foreach ($data as &$t) {
                if ($t['id'] == $id) {
                    $t['titulo'] = $titulo;
                    $t['corpo'] = $corpo;
                    break;
                }
            }
        } else {
            $data[] = ['id' => uniqid(), 'titulo' => $titulo, 'corpo' => $corpo];
        }
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function delete($id) {
        $data = $this->getAll();
        $data = array_filter($data, function($t) use ($id) { return $t['id'] != $id; });
        file_put_contents($this->file, json_encode(array_values($data), JSON_PRETTY_PRINT));
    }

    public function generate($templateId, $data) {
        $templates = $this->getAll();
        $tpl = null;
        foreach ($templates as $t) {
            if ($t['id'] == $templateId) { $tpl = $t; break; }
        }
        if (!$tpl) return '';

        $text = $tpl['corpo'];
        
        // Normalize Data Keys (Collision Handling)
        $normalizedData = [];
        foreach ($data as $k => $v) {
            $upperKey = mb_strtoupper($k, 'UTF-8');
            if (isset($normalizedData[$upperKey])) {
                if (trim((string)$normalizedData[$upperKey]) === '' && trim((string)$v) !== '') {
                    $normalizedData[$upperKey] = $v;
                }
            } else {
                $normalizedData[$upperKey] = $v;
            }
        }
        $data = $normalizedData;

        // Replace using Callback (Case Insensitive Matching of Placeholders)
        $text = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($data) {
            $original = $matches[0];
            $key = trim($matches[1]);
            $upperKey = mb_strtoupper($key, 'UTF-8');
            
            if (isset($data[$upperKey])) {
                return $data[$upperKey];
            }
            return $original;
        }, $text);
        return $text;
    }

    public function recordHistory($usuario, $cliente, $cpf, $port, $modelo, $texto, $destinatarios = '', $extra = []) {
        $data = json_decode(file_get_contents($this->historyFile), true);
        if (!is_array($data)) $data = [];
        
        $newRecord = [
            'DATA' => date('d/m/Y H:i'),
            'USUARIO' => $usuario,
            'CLIENTE' => $cliente,
            'CPF' => $cpf,
            'PORTABILIDADE' => $port,
            'MODELO' => $modelo,
            'TEXTO' => $texto,
            'DESTINATARIOS' => $destinatarios
        ];
        
        if (is_array($extra)) {
            $newRecord = array_merge($newRecord, $extra);
        }
        
        $data[] = $newRecord;
        file_put_contents($this->historyFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    public function getHistory($port) {
        if (!file_exists($this->historyFile)) return [];
        $data = json_decode(file_get_contents($this->historyFile), true);
        if (!is_array($data)) return [];
        
        $res = [];
        foreach ($data as $row) {
            if (isset($row['PORTABILIDADE']) && $row['PORTABILIDADE'] == $port) {
                $res[] = $row;
            }
        }
        return array_reverse($res);
    }
}





class LockManager {
    private $lockFile;
    // Definição das regras de tempo em segundos
    const TIME_AWAY = 600;    // 10 minutos (600 segundos) para considerar Ausente
    const TIME_OFFLINE = 3600; // 60 minutos (3600 segundos) para considerar Offline/Liberar

    public function __construct($dataDir = 'dados/') {
        $this->lockFile = rtrim($dataDir, '/') . '/locks.json';
        if (!file_exists($this->lockFile)) {
            if (!is_dir(dirname($this->lockFile))) {
                mkdir(dirname($this->lockFile), 0777, true);
            }
            file_put_contents($this->lockFile, json_encode([]));
        }
    }

    private function getLocks() {
        if (!file_exists($this->lockFile)) return [];
        $content = file_get_contents($this->lockFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function saveLocks($locks) {
        // Usa LOCK_EX para evitar colisões de escrita
        file_put_contents($this->lockFile, json_encode($locks, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Tenta adquirir ou renovar o bloqueio de um processo
     * AGORA COM LIMPEZA DE DUPLICIDADE DO USUÁRIO
     */
    public function acquireLock($processId, $user) {
        $locks = $this->getLocks();
        $now = time();
        $dataHoraFormatada = date('d/m/Y H:i');
        $changed = true; // Assumimos true pois vamos reconstruir o registro do usuário

        // Variável para "lembrar" quando o usuário entrou, caso ele já esteja nesse processo
        $savedDatetime = null;

        // 1. LIMPEZA GERAL E REMOÇÃO DE DUPLICIDADE DO USUÁRIO
        foreach ($locks as $pid => $data) {
            
            // A: Limpeza de Expirados (qualquer usuário)
            if (($now - $data['timestamp']) > self::TIME_OFFLINE) {
                unset($locks[$pid]);
                continue;
            }

            // B: Limpeza Total do Próprio Usuário (Garante registro único)
            if (($data['user'] ?? '') === $user) {
                // Se o registro que estamos apagando é justamente do processo que ele quer acessar...
                if ($pid == $processId) {
                    // ...salvamos a data original para não zerar o cronômetro
                    $savedDatetime = $data['datetime'] ?? null;
                }
                
                // Remove o registro (para recriá-lo limpo no final)
                unset($locks[$pid]);
            }
        }

        // 2. VERIFICAR SE O PROCESSO ESTÁ OCUPADO (POR OUTRA PESSOA)
        // Como já apagamos os registros do $user acima, se existir algo aqui, é de outro.
        if (isset($locks[$processId])) {
            $currentLock = $locks[$processId];
            
            // Retorna erro informando quem está usando
            return [
                'success' => false, 
                'locked_by' => $currentLock['user'],
                'since' => $currentLock['datetime'],
                'last_active' => $currentLock['last_active'] ?? $currentLock['datetime']
            ];
        }

        // 3. RECRIAR O REGISTRO DO USUÁRIO
        // Se tínhamos uma data salva (ele já estava aqui), usamos ela. Se não, usa a atual.
        $datetimeFinal = $savedDatetime ? $savedDatetime : $dataHoraFormatada;

        $locks[$processId] = [
            'user' => $user,
            'timestamp' => $now,          // Heartbeat numérico (para timeout)
            'datetime' => $datetimeFinal, // Data Visual (Início da contagem - FIXA)
            'last_active' => $dataHoraFormatada // Data Visual (Última interação)
        ];

        // Salva tudo
        $this->saveLocks($locks);

        return ['success' => true, 'datetime' => $datetimeFinal];
    }

    /**
     * Verifica o status do bloqueio baseado no tempo
     */
    public function checkLock($processId, $currentUser) {
        $locks = $this->getLocks();
        $now = time();

        // Se não existe trava
        if (!isset($locks[$processId])) {
            return ['locked' => false, 'status' => 'offline', 'by' => ''];
        }

        $lock = $locks[$processId];
        $diff = $now - $lock['timestamp'];

        // 1. REGRA OFFLINE (> 60 min)
        if ($diff > self::TIME_OFFLINE) {
            // Está expirado
            return ['locked' => false, 'status' => 'offline', 'by' => ''];
        }

        $isMe = ($lock['user'] === $currentUser);
        
        // Recupera os dados visuais
        $entryTime = $lock['datetime'] ?? date('d/m/Y H:i', $lock['timestamp']);
        $lastActive = $lock['last_active'] ?? $entryTime;

        // 2. REGRA AUSENTE (> 10 min e <= 60 min)
        if ($diff > self::TIME_AWAY) {
            return [
                'locked' => !$isMe, 
                'by' => $lock['user'],
                'status' => 'ausente', 
                'time_diff' => $diff,
                'entry_time' => $entryTime,
                'last_active' => $lastActive
            ];
        }

        // 3. REGRA ONLINE (<= 10 min)
        return [
            'locked' => !$isMe,
            'by' => $lock['user'],
            'status' => 'online', 
            'time_diff' => $diff,
            'entry_time' => $entryTime,
            'last_active' => $lastActive
        ];
    }

    public function releaseLock($processId, $user) {
        $locks = $this->getLocks();
        if (isset($locks[$processId]) && $locks[$processId]['user'] === $user) {
            unset($locks[$processId]);
            $this->saveLocks($locks);
        }
    }
}



class ProcessIndexer {
    private $indexFile;
    private $index = [];
    private $loaded = false;

    public function __construct($indexFile = 'dados/process_index.json') {
        $this->indexFile = $indexFile;
    }

    private function load() {
        if ($this->loaded) return;
        if (file_exists($this->indexFile)) {
            $content = file_get_contents($this->indexFile);
            $this->index = json_decode($content, true) ?: [];
        } else {
            $this->index = [];
        }
        $this->loaded = true;
    }

    private function save() {
        file_put_contents($this->indexFile, json_encode($this->index, JSON_PRETTY_PRINT));
    }

    public function get($id) {
        $this->load();
        return isset($this->index[$id]) ? $this->index[$id] : null;
    }

    public function set($id, $file) {
        $this->load();
        $this->index[$id] = $file;
        $this->save();
    }

    public function delete($id) {
        $this->load();
        if (isset($this->index[$id])) {
            unset($this->index[$id]);
            $this->save();
        }
    }

    public function rebuild($db) {
        $this->index = [];
        $files = $db->getAllProcessFiles();
        foreach ($files as $f) {
            $path = $db->getPath($f);
            if (file_exists($path)) {
                $data = $db->readJSON($path);
                foreach ($data as $row) {
                    $port = get_value_ci($row, 'Numero_Portabilidade');
                    if ($port) {
                        $this->index[$port] = $f;
                    }
                }
            }
        }
        $this->save();
        $this->loaded = true;
        return count($this->index);
    }
    
    public function ensureIndex($db) {
        if (!file_exists($this->indexFile)) {
            $this->rebuild($db);
        }
    }
}

if (!function_exists("normalizeDate")) {
    function normalizeDate($date) {
        if (empty($date)) return '';
        $date = trim($date);
        
        // Check DD/MM/YYYY
        $d = DateTime::createFromFormat('d/m/Y', $date);
        if ($d && $d->format('d/m/Y') === $date) {
            return $date;
        }
        
        // Check DD-MM-YYYY
        $d = DateTime::createFromFormat('d-m-Y', $date);
        if ($d && $d->format('d-m-Y') === $date) {
            return $d->format('d/m/Y');
        }

        // Check YYYY-MM-DD
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d && $d->format('Y-m-d') === $date) {
            return $d->format('d/m/Y');
        }
        
        // Check Excel Numeric
        if (is_numeric($date)) {
            // Excel base date: Dec 30 1899
            $unix = ($date - 25569) * 86400;
            return gmdate('d/m/Y', $unix);
        }
        
        return false;
    }
}

if (!function_exists("get_value_ci")) {
    function get_value_ci($array, $key) {
        if (!is_array($array)) return '';
        if (array_key_exists($key, $array)) return $array[$key];
        foreach ($array as $k => $v) {
            if (mb_strtoupper($k, 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                return $v;
            }
        }
        return '';
    }
}
