<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

define('MAX_XML_SIZE', 2 * 1024 * 1024);

$rawMethod = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$method = strtoupper($body['_method'] ?? $rawMethod);
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$module = $_GET['module'] ?? 'cotizaciones';

// Módulos tipo documento: cotizaciones y órdenes de compra.
$documentModules = ['cotizaciones', 'ordenes'];
if (!in_array($module, $documentModules, true) && !in_array($module, ['facturas','inventario','cxc'], true)) {
    $module = 'cotizaciones';
}

$tbl      = $module === 'ordenes' ? 'ordenes'      : 'cotizaciones';
$tblItems = $module === 'ordenes' ? 'orden_items'  : 'cotizacion_items';
$fkCol    = $module === 'ordenes' ? 'orden_id'     : 'cotizacion_id';

try {
    $pdo = getPDO();

    // ─────────────────────────────────────────────────────────
    // AUTH
    // ─────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email = trim($body['email'] ?? '');
        $pass  = $body['password'] ?? '';
        $stmt  = $pdo->prepare('SELECT * FROM usuarios WHERE email=? AND activo=1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pass, $user['password'])) {
            jsonError(401, 'Correo o contraseña incorrectos');
        }
        $_SESSION['uid']    = $user['id'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol']    = $user['rol'];
        jsonOk(['id' => $user['id'], 'nombre' => $user['nombre'], 'rol' => $user['rol']]);
    }

    if ($action === 'register') {
        $nombre = trim($body['nombre'] ?? '');
        $email  = trim($body['email']  ?? '');
        $pass   = $body['password']    ?? '';
        if (!$nombre || !$email || !$pass) jsonError(400, 'Todos los campos son requeridos');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError(400, 'Correo inválido');
        if (strlen($pass) < 6) jsonError(400, 'La contraseña debe tener al menos 6 caracteres');
        $check = $pdo->prepare('SELECT id FROM usuarios WHERE email=?');
        $check->execute([$email]);
        if ($check->fetch()) jsonError(409, 'Este correo ya está registrado');
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO usuarios (nombre,email,password) VALUES (?,?,?)');
        $stmt->execute([$nombre, $email, $hash]);
        $uid = (int)$pdo->lastInsertId();
        $_SESSION['uid']    = $uid;
        $_SESSION['nombre'] = $nombre;
        $_SESSION['rol']    = 'usuario';
        jsonOk(['id' => $uid, 'nombre' => $nombre, 'rol' => 'usuario']);
    }

    if ($action === 'logout') {
        session_destroy();
        jsonOk(['logout' => true]);
    }

    if ($action === 'me') {
        if (empty($_SESSION['uid'])) jsonError(401, 'No autenticado');
        jsonOk(['id' => $_SESSION['uid'], 'nombre' => $_SESSION['nombre'], 'rol' => $_SESSION['rol']]);
    }

    if ($action === 'health') {
        jsonOk(['ready' => true]);
    }

    // Todas las rutas de datos requieren sesión.
    if (empty($_SESSION['uid'])) jsonError(401, 'No autenticado');
    $uid = (int)$_SESSION['uid'];

    // ─────────────────────────────────────────────────────────
    // REPORTES (acciones globales, no dependen del módulo)
    //   ?action=dashboard_financiero
    //   ?action=ventas_reportes&periodo=mes&source=cotizaciones
    //   ?action=flujo_efectivo&meses=6
    //   ?action=cxc_aging
    // ─────────────────────────────────────────────────────────
    $reportActions = ['dashboard_financiero','ventas_reportes','flujo_efectivo','cxc_aging'];
    if (in_array($action, $reportActions, true)) {
        $hasCxc = tableExists($pdo, 'cuentas_cobrar');

        // ── Tablero financiero: responde las preguntas clave del negocio ──
        if ($action === 'dashboard_financiero') {
            // CUENTAS POR PAGAR (facturas de proveedor no pagadas/canceladas)
            $cxp = $pdo->query("
                SELECT
                    COALESCE(SUM(i.total),0) AS por_pagar_total,
                    COALESCE(SUM(CASE WHEN i.due_date IS NOT NULL AND CAST(i.due_date AS DATE) < CURDATE() THEN i.total ELSE 0 END),0) AS por_pagar_vencido,
                    COALESCE(SUM(CASE WHEN i.due_date IS NOT NULL AND CAST(i.due_date AS DATE) BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY THEN i.total ELSE 0 END),0) AS por_pagar_proximo,
                    COUNT(*) AS por_pagar_num
                FROM invoices i
                JOIN invoice_status s ON s.id = i.status_id
                WHERE i.deleted_at IS NULL AND s.name NOT IN ('Pagada','Cancelada')
            ")->fetch();

            // Compras y pagos del mes en curso
            $comprasMes = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE deleted_at IS NULL AND MONTH(issue_date)=MONTH(CURDATE()) AND YEAR(issue_date)=YEAR(CURDATE())")->fetchColumn();
            $pagadoMes  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn();

            // CUENTAS POR COBRAR
            $cxc = ['por_cobrar_total'=>0,'por_cobrar_vencido'=>0,'por_cobrar_proximo'=>0,'por_cobrar_num'=>0];
            $cobradoMes = 0; $ventasMes = 0;
            if ($hasCxc) {
                $cxc = $pdo->query("
                    SELECT
                        COALESCE(SUM(saldo),0) AS por_cobrar_total,
                        COALESCE(SUM(CASE WHEN fecha_vencimiento IS NOT NULL AND fecha_vencimiento < CURDATE() THEN saldo ELSE 0 END),0) AS por_cobrar_vencido,
                        COALESCE(SUM(CASE WHEN fecha_vencimiento IS NOT NULL AND fecha_vencimiento BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY THEN saldo ELSE 0 END),0) AS por_cobrar_proximo,
                        SUM(CASE WHEN estatus IN ('pendiente','parcial') THEN 1 ELSE 0 END) AS por_cobrar_num
                    FROM cuentas_cobrar WHERE estatus <> 'cancelada'
                ")->fetch();
                $cobradoMes = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM cxc_pagos WHERE MONTH(fecha_pago)=MONTH(CURDATE()) AND YEAR(fecha_pago)=YEAR(CURDATE())")->fetchColumn();
                $ventasMes  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM cuentas_cobrar WHERE estatus<>'cancelada' AND MONTH(fecha_emision)=MONTH(CURDATE()) AND YEAR(fecha_emision)=YEAR(CURDATE())")->fetchColumn();
            }

            // Top deudores (clientes que más nos deben)
            $topDeudores = $hasCxc ? $pdo->query("
                SELECT cliente_nombre AS nombre, SUM(saldo) AS monto
                FROM cuentas_cobrar WHERE estatus IN ('pendiente','parcial') AND saldo > 0
                GROUP BY cliente_nombre ORDER BY monto DESC LIMIT 6
            ")->fetchAll() : [];

            // Top acreedores (proveedores a los que más debemos)
            $topAcreedores = $pdo->query("
                SELECT COALESCE(s.name,'Sin proveedor') AS nombre, SUM(i.total) AS monto
                FROM invoices i
                LEFT JOIN suppliers s ON s.id=i.supplier_id
                JOIN invoice_status st ON st.id=i.status_id
                WHERE i.deleted_at IS NULL AND st.name NOT IN ('Pagada','Cancelada')
                GROUP BY s.id ORDER BY monto DESC LIMIT 6
            ")->fetchAll();

            $porCobrar = (float)$cxc['por_cobrar_total'];
            $porPagar  = (float)$cxp['por_pagar_total'];

            jsonOk([
                'por_cobrar_total'    => $porCobrar,
                'por_cobrar_vencido'  => (float)$cxc['por_cobrar_vencido'],
                'por_cobrar_proximo'  => (float)$cxc['por_cobrar_proximo'],
                'por_cobrar_num'      => (int)$cxc['por_cobrar_num'],
                'por_pagar_total'     => $porPagar,
                'por_pagar_vencido'   => (float)$cxp['por_pagar_vencido'],
                'por_pagar_proximo'   => (float)$cxp['por_pagar_proximo'],
                'por_pagar_num'       => (int)$cxp['por_pagar_num'],
                'posicion_neta'       => $porCobrar - $porPagar,
                'ventas_mes'          => $ventasMes,
                'compras_mes'         => $comprasMes,
                'cobrado_mes'         => $cobradoMes,
                'pagado_mes'          => $pagadoMes,
                'utilidad_mes_estim'  => $ventasMes - $comprasMes,
                'top_deudores'        => $topDeudores,
                'top_acreedores'      => $topAcreedores,
                'cxc_disponible'      => $hasCxc,
            ]);
        }

        // ── Ventas por periodo (corrige el endpoint que faltaba) ──
        if ($action === 'ventas_reportes') {
            $periodo = $_GET['periodo'] ?? 'mes';
            $source  = $_GET['source']  ?? 'cotizaciones';
            $from    = $_GET['from']    ?? '';
            $to      = $_GET['to']      ?? '';

            // Origen de los datos de "ventas"
            if ($source === 'ordenes') {
                $tblR = 'ordenes'; $fechaCol = 'fecha'; $montoCol = 'total'; $whereExtra = '';
            } elseif ($source === 'cxc' && $hasCxc) {
                $tblR = 'cuentas_cobrar'; $fechaCol = 'fecha_emision'; $montoCol = 'total'; $whereExtra = "estatus <> 'cancelada'";
            } else { // cotizaciones (default)
                $tblR = 'cotizaciones'; $fechaCol = 'fecha'; $montoCol = 'total'; $whereExtra = '';
            }

            switch ($periodo) {
                case 'dia':    $fmt = '%Y-%m-%d'; break;
                case 'semana': $fmt = '%x-S%v';   break;
                case 'anio':   $fmt = '%Y';       break;
                case 'mes':
                default:       $fmt = '%Y-%m';    break;
            }

            $where = []; $params = [];
            if ($whereExtra) $where[] = $whereExtra;
            if ($periodo === 'rango' && $from && $to) {
                $fmt = '%Y-%m-%d';
                $where[] = "$fechaCol BETWEEN ? AND ?"; $params[] = $from; $params[] = $to;
            } else {
                if ($from) { $where[] = "$fechaCol >= ?"; $params[] = $from; }
                if ($to)   { $where[] = "$fechaCol <= ?"; $params[] = $to; }
            }
            $w = $where ? 'WHERE '.implode(' AND ', $where) : '';

            $sql = "SELECT DATE_FORMAT($fechaCol, '$fmt') AS periodo,
                           COALESCE(SUM($montoCol),0) AS total_ventas,
                           COUNT(*) AS operaciones
                    FROM $tblR $w
                    GROUP BY periodo ORDER BY MIN($fechaCol) ASC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $acc = 0; $totalVentas = 0; $totalOps = 0;
            foreach ($rows as &$r) {
                $r['total_ventas'] = (float)$r['total_ventas'];
                $r['operaciones']  = (int)$r['operaciones'];
                $acc += $r['total_ventas'];
                $r['total_acumulado'] = $acc;
                $totalVentas += $r['total_ventas'];
                $totalOps += $r['operaciones'];
            }
            unset($r);
            jsonOk([
                'rows' => $rows,
                'kpis' => [
                    'total_ventas'      => $totalVentas,
                    'total_operaciones' => $totalOps,
                    'periodos'          => count($rows),
                ],
            ]);
        }

        // ── Flujo de efectivo: proyección (por vencimientos) + histórico ──
        if ($action === 'flujo_efectivo') {
            $meses = max(1, min(12, (int)($_GET['meses'] ?? 6)));

            // Histórico real: cobros (cxc_pagos) vs pagos (invoice_payments) por mes
            $hist = [];
            $cobrosHist = $hasCxc ? $pdo->query("
                SELECT DATE_FORMAT(fecha_pago,'%Y-%m') AS mes, SUM(monto) AS monto
                FROM cxc_pagos WHERE fecha_pago >= DATE_SUB(CURDATE(), INTERVAL $meses MONTH)
                GROUP BY mes
            ")->fetchAll() : [];
            $pagosHist = $pdo->query("
                SELECT DATE_FORMAT(payment_date,'%Y-%m') AS mes, SUM(amount) AS monto
                FROM invoice_payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL $meses MONTH)
                GROUP BY mes
            ")->fetchAll();
            $mapCobro = []; foreach ($cobrosHist as $r) $mapCobro[$r['mes']] = (float)$r['monto'];
            $mapPago  = []; foreach ($pagosHist  as $r) $mapPago[$r['mes']]  = (float)$r['monto'];
            for ($i = $meses - 1; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("-$i month"));
                $hist[] = ['mes'=>$m, 'cobrado'=>$mapCobro[$m] ?? 0, 'pagado'=>$mapPago[$m] ?? 0, 'neto'=>($mapCobro[$m] ?? 0) - ($mapPago[$m] ?? 0)];
            }

            // Proyección: entradas (CxC por vencer) vs salidas (CxP por vencer) próximos meses
            $proj = [];
            $entradas = $hasCxc ? $pdo->query("
                SELECT DATE_FORMAT(fecha_vencimiento,'%Y-%m') AS mes, SUM(saldo) AS monto
                FROM cuentas_cobrar
                WHERE estatus IN ('pendiente','parcial') AND saldo > 0 AND fecha_vencimiento >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
                  AND fecha_vencimiento < DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL $meses MONTH)
                GROUP BY mes
            ")->fetchAll() : [];
            $salidas = $pdo->query("
                SELECT DATE_FORMAT(i.due_date,'%Y-%m') AS mes, SUM(i.total) AS monto
                FROM invoices i JOIN invoice_status s ON s.id=i.status_id
                WHERE i.deleted_at IS NULL AND s.name NOT IN ('Pagada','Cancelada')
                  AND i.due_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
                  AND i.due_date < DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL $meses MONTH)
                GROUP BY mes
            ")->fetchAll();
            $mapEnt = []; foreach ($entradas as $r) $mapEnt[$r['mes']] = (float)$r['monto'];
            $mapSal = []; foreach ($salidas  as $r) $mapSal[$r['mes']]  = (float)$r['monto'];
            for ($i = 0; $i < $meses; $i++) {
                $m = date('Y-m', strtotime(date('Y-m-01')." +$i month"));
                $proj[] = ['mes'=>$m, 'entradas'=>$mapEnt[$m] ?? 0, 'salidas'=>$mapSal[$m] ?? 0, 'neto'=>($mapEnt[$m] ?? 0) - ($mapSal[$m] ?? 0)];
            }

            jsonOk(['historico'=>$hist, 'proyeccion'=>$proj, 'cxc_disponible'=>$hasCxc]);
        }

        // ── Antigüedad de saldos por cobrar (aging) ──
        if ($action === 'cxc_aging') {
            if (!$hasCxc) jsonOk(['buckets'=>[], 'cxc_disponible'=>false]);
            $row = $pdo->query("
                SELECT
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha_vencimiento) <= 0 THEN saldo ELSE 0 END),0) AS por_vencer,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha_vencimiento) BETWEEN 1 AND 30 THEN saldo ELSE 0 END),0) AS d1_30,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha_vencimiento) BETWEEN 31 AND 60 THEN saldo ELSE 0 END),0) AS d31_60,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha_vencimiento) BETWEEN 61 AND 90 THEN saldo ELSE 0 END),0) AS d61_90,
                    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), fecha_vencimiento) > 90 THEN saldo ELSE 0 END),0) AS d90_mas
                FROM cuentas_cobrar
                WHERE estatus IN ('pendiente','parcial') AND saldo > 0
            ")->fetch();
            jsonOk(['buckets'=>$row, 'cxc_disponible'=>true]);
        }
    }

    // ─────────────────────────────────────────────────────────
    // FACTURAS XML CFDI
    // Usar: api.php?module=facturas&action=list
    // ─────────────────────────────────────────────────────────
    if ($module === 'facturas') {
        if ($action === 'health') jsonOk(['ready'=>true]);

        if ($action === 'upload') {
          if (empty($_FILES['xml_files'])) jsonError(400, 'No se recibieron archivos XML');
          // Leer supplier_id enviado por el formulario (el usuario lo selecciona antes de cargar el XML)
          $supplierIdFromForm = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
          $supplierFromForm = null;
          if ($supplierIdFromForm > 0) {
            $stmtS = $pdo->prepare('SELECT id, name, rfc, payment_days FROM suppliers WHERE id=?');
            $stmtS->execute([$supplierIdFromForm]);
            $supplierFromForm = $stmtS->fetch() ?: null;
    }
          $results = [];
          $files = normalizeFiles($_FILES['xml_files']);
          foreach ($files as $file) {
      try { $results[] = processXmlUpload($pdo, $file, $uid, $supplierFromForm); }
            catch (Throwable $e) { $results[] = ['ok'=>false, 'file'=>$file['name'] ?? 'archivo', 'error'=>$e->getMessage()]; }
    }
          jsonOk($results, 201);
  }

        if ($action === 'list') {
          $where = ['i.deleted_at IS NULL']; $params=[];
          if (!empty($_GET['q'])) { $where[] = '(i.uuid LIKE ? OR s.name LIKE ? OR s.rfc LIKE ? OR i.folio LIKE ?)'; $q='%'.$_GET['q'].'%'; $params=[$q,$q,$q,$q]; }
          if (!empty($_GET['status'])) { $where[]='st.name=?'; $params[]=$_GET['status']; }
          if (!empty($_GET['from'])) { $where[]='DATE(i.issue_date)>=?'; $params[]=$_GET['from']; }
          if (!empty($_GET['to'])) { $where[]='DATE(i.issue_date)<=?'; $params[]=$_GET['to']; }
          $sql = "SELECT i.*, s.name AS supplier_name, s.rfc AS supplier_rfc, s.payment_days AS supplier_payment_days, s.payment_form AS supplier_payment_form, st.name AS status_name, st.color AS status_color, u.nombre AS uploaded_by_name
            FROM invoices i
            LEFT JOIN suppliers s ON s.id=i.supplier_id
            LEFT JOIN invoice_status st ON st.id=i.status_id
            LEFT JOIN usuarios u ON u.id=i.uploaded_by
            WHERE ".implode(' AND ', $where)." ORDER BY i.issue_date DESC, i.id DESC";
          $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
          foreach ($rows as &$r) $r['due_state'] = dueState($r['due_date'], $r['status_name']);
          jsonOk(['rows'=>$rows, 'kpis'=>invoiceKpis($pdo)]);
  }

        if ($action === 'detail') {
          if (!$id) jsonError(400, 'Se requiere id');
          $stmt=$pdo->prepare("SELECT i.*, s.name AS supplier_name, s.rfc AS supplier_rfc, st.name AS status_name, st.color AS status_color, u.nombre AS uploaded_by_name FROM invoices i LEFT JOIN suppliers s ON s.id=i.supplier_id LEFT JOIN invoice_status st ON st.id=i.status_id LEFT JOIN usuarios u ON u.id=i.uploaded_by WHERE i.id=? AND i.deleted_at IS NULL");
          $stmt->execute([$id]); $row=$stmt->fetch(); if(!$row) jsonError(404,'Factura no encontrada');
          $items=$pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id'); $items->execute([$id]);
          $row['items']=$items->fetchAll();
          // FIX: no exponer la ruta interna del servidor, solo entregar el XML formateado
          if (!empty($row['xml_path'])) {
            $fullPath = __DIR__.'/'.$row['xml_path'];
            if (file_exists($fullPath)) $row['xml_content'] = formatXml(file_get_contents($fullPath));
      unset($row['xml_path']); // No exponer rutas del sistema de archivos al cliente
    }
          jsonOk($row);
  }

        if ($action === 'update_status') {
          if (!$id) jsonError(400, 'Se requiere id');
          $status=(int)($body['status_id'] ?? 0); if(!$status) jsonError(400,'Estatus inválido');
          // FIX: verificar que el status_id exista antes de actualizar
          $chk=$pdo->prepare('SELECT id FROM invoice_status WHERE id=?'); $chk->execute([$status]);
          if (!$chk->fetch()) jsonError(400, 'Estatus no válido');
          $obs=trim($body['observations'] ?? '');
          $pdo->prepare('UPDATE invoices SET status_id=?, observations=IF(?="", observations, ?), updated_at=NOW() WHERE id=? AND deleted_at IS NULL')->execute([$status,$obs,$obs,$id]);
          $pdo->prepare('INSERT INTO invoice_history (invoice_id,user_id,action,comments) VALUES (?,?,?,?)')->execute([$id,$uid,'Cambio de estatus',$obs]);
          jsonOk(['updated'=>true]);
  }

        if ($action === 'delete') {
          if (!$id) jsonError(400, 'Se requiere id');
          // FIX: verificar que la factura existe y pertenece al contexto antes de borrar
          $chk=$pdo->prepare('SELECT id FROM invoices WHERE id=? AND deleted_at IS NULL'); $chk->execute([$id]);
          if (!$chk->fetch()) jsonError(404, 'Factura no encontrada');
          $pdo->prepare('UPDATE invoices SET deleted_at=NOW() WHERE id=?')->execute([$id]);
          $pdo->prepare('INSERT INTO invoice_history (invoice_id,user_id,action,comments) VALUES (?,?,?,?)')->execute([$id,$uid,'Eliminación','Soft delete']);
          jsonOk(['deleted'=>true]);
  }

        if ($action === 'statuses') {
          jsonOk($pdo->query('SELECT * FROM invoice_status ORDER BY id')->fetchAll());
  }

        // ——— PROVEEDORES ———
        if ($action === 'suppliers_list') {
          $rows = $pdo->query('SELECT id, name, rfc, email, phone, payment_days, payment_form, notes, created_at FROM suppliers ORDER BY name ASC')->fetchAll();
          jsonOk($rows);
  }

        if ($action === 'suppliers_save') {
          $name = trim($body['name'] ?? '');
          $rfc  = strtoupper(trim($body['rfc'] ?? ''));
          $days = max(0, (int)($body['payment_days'] ?? 30));
          $payForm = trim($body['payment_form'] ?? '');
          $email = trim($body['email'] ?? '');
          $phone = trim($body['phone'] ?? '');
          $notes = trim($body['notes'] ?? '');
          if (!$name || !$rfc) jsonError(400, 'Nombre y RFC son requeridos');
          $sid = isset($body['id']) ? (int)$body['id'] : 0;
          if ($sid > 0) {
            $pdo->prepare('UPDATE suppliers SET name=?, rfc=?, payment_days=?, payment_form=?, email=?, phone=?, notes=?, updated_at=NOW() WHERE id=?')
          ->execute([$name, $rfc, $days, $payForm, $email, $phone, $notes, $sid]);
            jsonOk(['id' => $sid, 'action' => 'updated']);
    } else {
            $chkRfc = $pdo->prepare('SELECT id FROM suppliers WHERE rfc=?'); $chkRfc->execute([$rfc]);
            if ($chkRfc->fetch()) jsonError(409, 'Ya existe un proveedor con ese RFC');
            $pdo->prepare('INSERT INTO suppliers (name,rfc,payment_days,payment_form,email,phone,notes,created_at) VALUES (?,?,?,?,?,?,?,NOW())')
          ->execute([$name, $rfc, $days, $payForm, $email, $phone, $notes]);
            jsonOk(['id' => (int)$pdo->lastInsertId(), 'action' => 'created']);
    }
  }

        if ($action === 'suppliers_delete') {
          $sid = $id ?? 0;
          if (!$sid) jsonError(400, 'Se requiere id');
          $chk = $pdo->prepare('SELECT id FROM suppliers WHERE id=?'); $chk->execute([$sid]);
          if (!$chk->fetch()) jsonError(404, 'Proveedor no encontrado');
          // Verificar que no tenga facturas activas
          $inv = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id=? AND deleted_at IS NULL'); $inv->execute([$sid]);
          if ((int)$inv->fetchColumn() > 0) jsonError(409, 'No se puede eliminar: el proveedor tiene facturas registradas');
          $pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$sid]);
          jsonOk(['deleted' => true]);
  }


        jsonError(404, 'Acción de facturas no encontrada');
    }


    // ─────────────────────────────────────────────────────────
    // INVENTARIO
    // Usar: api.php?module=inventario&action=...
    // ─────────────────────────────────────────────────────────
    if ($module === 'inventario') {

        // AUTO-CREAR TABLAS si no existen
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inv_productos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(100) NOT NULL UNIQUE,
                descripcion VARCHAR(500) NOT NULL,
                unidad VARCHAR(50) DEFAULT 'PZ',
                precio_unitario DECIMAL(14,4) DEFAULT 0,
                stock_actual DECIMAL(14,4) DEFAULT 0,
                stock_minimo DECIMAL(14,4) DEFAULT 0,
                categoria VARCHAR(200) DEFAULT '',
                notas TEXT DEFAULT NULL,
                activo TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT NOW(),
                updated_at DATETIME DEFAULT NOW() ON UPDATE NOW()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS inv_movimientos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                producto_id INT NOT NULL,
                tipo ENUM('entrada','salida') NOT NULL,
                cantidad DECIMAL(14,4) NOT NULL,
                precio_unitario DECIMAL(14,4) DEFAULT 0,
                total DECIMAL(14,4) DEFAULT 0,
                referencia VARCHAR(200) DEFAULT '',
                notas TEXT DEFAULT NULL,
                usuario_id INT NOT NULL,
                fecha_movimiento DATE NOT NULL,
                created_at DATETIME DEFAULT NOW(),
                FOREIGN KEY (producto_id) REFERENCES inv_productos(id),
                FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ── LIST PRODUCTOS ──────────────────────────────────────
        if ($action === 'productos_list') {
            $rows = $pdo->query("
                SELECT p.*,
                    COALESCE(SUM(CASE WHEN m.tipo='entrada' THEN m.cantidad ELSE 0 END),0) AS total_entradas,
                    COALESCE(SUM(CASE WHEN m.tipo='salida'  THEN m.cantidad ELSE 0 END),0) AS total_salidas
                FROM inv_productos p
                LEFT JOIN inv_movimientos m ON m.producto_id = p.id
                WHERE p.activo=1
                GROUP BY p.id
                ORDER BY p.descripcion ASC
            ")->fetchAll();
            jsonOk($rows);
        }

        // ── SAVE PRODUCTO ───────────────────────────────────────
        if ($action === 'producto_save') {
            $codigo  = strtoupper(trim($body['codigo'] ?? ''));
            $desc    = trim($body['descripcion'] ?? '');
            $unidad  = trim($body['unidad'] ?? 'PZ');
            $precio  = (float)($body['precio_unitario'] ?? 0);
            $stkMin  = (float)($body['stock_minimo'] ?? 0);
            $cat     = trim($body['categoria'] ?? '');
            $notas   = trim($body['notas'] ?? '');
            if (!$codigo || !$desc) jsonError(400, 'Código y descripción son requeridos');
            $pid = isset($body['id']) ? (int)$body['id'] : 0;
            if ($pid > 0) {
                $pdo->prepare("UPDATE inv_productos SET codigo=?,descripcion=?,unidad=?,precio_unitario=?,stock_minimo=?,categoria=?,notas=?,updated_at=NOW() WHERE id=?")
                    ->execute([$codigo,$desc,$unidad,$precio,$stkMin,$cat,$notas,$pid]);
                jsonOk(['id'=>$pid,'action'=>'updated']);
            } else {
                $chk=$pdo->prepare("SELECT id FROM inv_productos WHERE codigo=?"); $chk->execute([$codigo]);
                if ($chk->fetch()) jsonError(409,'Ya existe un producto con ese código');
                $pdo->prepare("INSERT INTO inv_productos (codigo,descripcion,unidad,precio_unitario,stock_minimo,categoria,notas) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$codigo,$desc,$unidad,$precio,$stkMin,$cat,$notas]);
                jsonOk(['id'=>(int)$pdo->lastInsertId(),'action'=>'created']);
            }
        }

        // ── DELETE PRODUCTO ─────────────────────────────────────
        if ($action === 'producto_delete') {
            if (!$id) jsonError(400,'Se requiere id');
            $pdo->prepare("UPDATE inv_productos SET activo=0 WHERE id=?")->execute([$id]);
            jsonOk(['deleted'=>true]);
        }

        // ── MOVIMIENTO (ENTRADA / SALIDA) ───────────────────────
        if ($action === 'movimiento') {
            $pid    = (int)($body['producto_id'] ?? 0);
            $tipo   = $body['tipo'] ?? '';
            $qty    = (float)($body['cantidad'] ?? 0);
            $precio = (float)($body['precio_unitario'] ?? 0);
            $ref    = trim($body['referencia'] ?? '');
            $notas  = trim($body['notas'] ?? '');
            $fecha  = trim($body['fecha_movimiento'] ?? date('Y-m-d'));
            if (!$pid || !in_array($tipo,['entrada','salida']) || $qty <= 0) jsonError(400,'Datos de movimiento inválidos');

            // Verificar stock suficiente para salidas
            if ($tipo === 'salida') {
                $stock = (float)$pdo->prepare("SELECT stock_actual FROM inv_productos WHERE id=?")->execute([$pid]) ? $pdo->query("SELECT stock_actual FROM inv_productos WHERE id=$pid")->fetchColumn() : 0;
                $stmtStk = $pdo->prepare("SELECT stock_actual FROM inv_productos WHERE id=?");
                $stmtStk->execute([$pid]);
                $stock = (float)$stmtStk->fetchColumn();
                if ($qty > $stock) jsonError(400, "Stock insuficiente. Disponible: $stock");
            }

            $total = round($qty * $precio, 4);
            $pdo->prepare("INSERT INTO inv_movimientos (producto_id,tipo,cantidad,precio_unitario,total,referencia,notas,usuario_id,fecha_movimiento) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$pid,$tipo,$qty,$precio,$total,$ref,$notas,$uid,$fecha]);

            // Actualizar stock
            if ($tipo === 'entrada') {
                $pdo->prepare("UPDATE inv_productos SET stock_actual=stock_actual+?, precio_unitario=IF(?=0,precio_unitario,?), updated_at=NOW() WHERE id=?")
                    ->execute([$qty,$precio,$precio,$pid]);
            } else {
                $pdo->prepare("UPDATE inv_productos SET stock_actual=stock_actual-?, updated_at=NOW() WHERE id=?")->execute([$qty,$pid]);
            }
            jsonOk(['ok'=>true,'total'=>$total]);
        }

        // ── HISTORIAL MOVIMIENTOS ───────────────────────────────
        if ($action === 'movimientos_list') {
            $where=[]; $params=[];
            if (!empty($_GET['producto_id'])) { $where[]='m.producto_id=?'; $params[]=(int)$_GET['producto_id']; }
            if (!empty($_GET['tipo']))         { $where[]='m.tipo=?';        $params[]=$_GET['tipo']; }
            if (!empty($_GET['from']))         { $where[]='m.fecha_movimiento>=?'; $params[]=$_GET['from']; }
            if (!empty($_GET['to']))           { $where[]='m.fecha_movimiento<=?'; $params[]=$_GET['to']; }
            $w = $where ? 'WHERE '.implode(' AND ',$where) : '';
            $stmt=$pdo->prepare("
                SELECT m.*, p.codigo, p.descripcion, p.unidad, u.nombre AS usuario_nombre
                FROM inv_movimientos m
                JOIN inv_productos p ON p.id=m.producto_id
                JOIN usuarios u ON u.id=m.usuario_id
                $w
                ORDER BY m.fecha_movimiento DESC, m.id DESC
                LIMIT 500
            ");
            $stmt->execute($params);
            jsonOk($stmt->fetchAll());
        }

        // ── KPIs / REPORTE ──────────────────────────────────────
        if ($action === 'kpis') {
            $kpis = $pdo->query("
                SELECT
                    COUNT(*) AS total_productos,
                    SUM(stock_actual * precio_unitario) AS valor_total,
                    SUM(CASE WHEN stock_actual <= stock_minimo AND stock_minimo > 0 THEN 1 ELSE 0 END) AS bajo_minimo,
                    SUM(CASE WHEN stock_actual = 0 THEN 1 ELSE 0 END) AS sin_stock
                FROM inv_productos WHERE activo=1
            ")->fetch();
            $movHoy = $pdo->query("SELECT COUNT(*) FROM inv_movimientos WHERE DATE(created_at)=CURDATE()")->fetchColumn();
            $kpis['movimientos_hoy'] = $movHoy;
            jsonOk($kpis);
        }

        jsonError(404,'Acción de inventario no encontrada');
    }

    // ─────────────────────────────────────────────────────────
    // CUENTAS POR COBRAR (CxC)
    // Usar: api.php?module=cxc&action=...
    // ─────────────────────────────────────────────────────────
    if ($module === 'cxc') {
        if (!tableExists($pdo, 'cuentas_cobrar')) {
            jsonError(503, 'El módulo de Cuentas por Cobrar no está instalado. Ejecuta la migración migracion_cxc.sql en la base de datos.');
        }

        // ── LISTADO + KPIs ──────────────────────────────────────
        if ($action === 'list') {
            $where = []; $params = [];
            if (!empty($_GET['q'])) {
                $where[] = '(c.cliente_nombre LIKE ? OR c.cliente_rfc LIKE ? OR c.folio LIKE ? OR c.concepto LIKE ?)';
                $q = '%'.$_GET['q'].'%'; array_push($params, $q, $q, $q, $q);
            }
            if (!empty($_GET['estatus'])) { $where[] = 'c.estatus = ?'; $params[] = $_GET['estatus']; }
            if (!empty($_GET['from']))    { $where[] = 'c.fecha_emision >= ?'; $params[] = $_GET['from']; }
            if (!empty($_GET['to']))      { $where[] = 'c.fecha_emision <= ?'; $params[] = $_GET['to']; }
            $w = $where ? 'WHERE '.implode(' AND ', $where) : '';
            $sql = "SELECT c.*, u.nombre AS creado_por_nombre, cot.folio AS cotizacion_folio
                    FROM cuentas_cobrar c
                    LEFT JOIN usuarios u ON u.id = c.creado_por
                    LEFT JOIN cotizaciones cot ON cot.id = c.cotizacion_id
                    $w ORDER BY c.fecha_emision DESC, c.id DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) $r['due_state'] = cxcDueState($r['fecha_vencimiento'], $r['estatus']);
            unset($r);
            jsonOk(['rows' => $rows, 'kpis' => cxcKpis($pdo)]);
        }

        // ── DETALLE + historial de abonos ───────────────────────
        if ($action === 'detail') {
            if (!$id) jsonError(400, 'Se requiere id');
            $stmt = $pdo->prepare("SELECT c.*, u.nombre AS creado_por_nombre, cot.folio AS cotizacion_folio
                FROM cuentas_cobrar c
                LEFT JOIN usuarios u ON u.id = c.creado_por
                LEFT JOIN cotizaciones cot ON cot.id = c.cotizacion_id
                WHERE c.id = ?");
            $stmt->execute([$id]); $row = $stmt->fetch();
            if (!$row) jsonError(404, 'Cuenta no encontrada');
            $row['due_state'] = cxcDueState($row['fecha_vencimiento'], $row['estatus']);
            $pagos = $pdo->prepare("SELECT p.*, u.nombre AS creado_por_nombre FROM cxc_pagos p LEFT JOIN usuarios u ON u.id=p.creado_por WHERE p.cuenta_id=? ORDER BY p.fecha_pago ASC, p.id ASC");
            $pagos->execute([$id]); $row['pagos'] = $pagos->fetchAll();
            jsonOk($row);
        }

        // ── GUARDAR (crear / editar manual) ─────────────────────
        if ($action === 'save') {
            $cid       = isset($body['id']) ? (int)$body['id'] : 0;
            $nombre    = trim($body['cliente_nombre'] ?? '');
            $rfc       = strtoupper(trim($body['cliente_rfc'] ?? ''));
            $contacto  = trim($body['cliente_contacto'] ?? '');
            $concepto  = trim($body['concepto'] ?? '');
            $emision   = trim($body['fecha_emision'] ?? date('Y-m-d'));
            $dias      = max(0, (int)($body['dias_credito'] ?? 30));
            $venc      = trim($body['fecha_vencimiento'] ?? '');
            if (!$venc) $venc = date('Y-m-d', strtotime($emision." +$dias days"));
            $moneda    = trim($body['moneda'] ?? 'MXN');
            $subtotal  = round((float)($body['subtotal'] ?? 0), 2);
            $ivaMonto  = round((float)($body['iva'] ?? 0), 2);
            $total     = round((float)($body['total'] ?? ($subtotal + $ivaMonto)), 2);
            $notas     = trim($body['notas'] ?? '');
            if (!$nombre) jsonError(400, 'El nombre del cliente es requerido');
            if ($total <= 0) jsonError(400, 'El total debe ser mayor a cero');

            if ($cid > 0) {
                // Editar: respetar abonos ya registrados, recalcular saldo
                $cur = $pdo->prepare('SELECT total, saldo FROM cuentas_cobrar WHERE id=?'); $cur->execute([$cid]);
                $prev = $cur->fetch(); if (!$prev) jsonError(404, 'Cuenta no encontrada');
                $abonado = round((float)$prev['total'] - (float)$prev['saldo'], 2);
                if ($total < $abonado) jsonError(400, 'El total no puede ser menor a lo ya cobrado ('.number_format($abonado,2).')');
                $nuevoSaldo = round($total - $abonado, 2);
                $estatus = $nuevoSaldo <= 0.009 ? 'pagada' : ($abonado > 0 ? 'parcial' : 'pendiente');
                $pdo->prepare("UPDATE cuentas_cobrar SET cliente_nombre=?, cliente_rfc=?, cliente_contacto=?, concepto=?, fecha_emision=?, fecha_vencimiento=?, dias_credito=?, moneda=?, subtotal=?, iva=?, total=?, saldo=?, estatus=IF(estatus='cancelada','cancelada',?), notas=?, updated_at=NOW() WHERE id=?")
                    ->execute([$nombre,$rfc,$contacto,$concepto,$emision,$venc,$dias,$moneda,$subtotal,$ivaMonto,$total,$nuevoSaldo,$estatus,$notas,$cid]);
                jsonOk(['id'=>$cid, 'action'=>'updated']);
            } else {
                $folio = (int)$pdo->query("SELECT COALESCE(MAX(folio),30000) FROM cuentas_cobrar")->fetchColumn() + 1;
                $cotId = isset($body['cotizacion_id']) && $body['cotizacion_id'] ? (int)$body['cotizacion_id'] : null;
                $pdo->prepare("INSERT INTO cuentas_cobrar (folio,cliente_nombre,cliente_rfc,cliente_contacto,cotizacion_id,concepto,fecha_emision,fecha_vencimiento,dias_credito,moneda,subtotal,iva,total,saldo,estatus,notas,creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pendiente',?,?)")
                    ->execute([$folio,$nombre,$rfc,$contacto,$cotId,$concepto,$emision,$venc,$dias,$moneda,$subtotal,$ivaMonto,$total,$total,$notas,$uid]);
                jsonOk(['id'=>(int)$pdo->lastInsertId(), 'folio'=>$folio, 'action'=>'created'], 201);
            }
        }

        // ── GENERAR desde una cotización (vía automática) ───────
        if ($action === 'from_cotizacion') {
            $cotId = (int)($body['cotizacion_id'] ?? 0);
            if (!$cotId) jsonError(400, 'Se requiere cotizacion_id');
            $cot = $pdo->prepare('SELECT * FROM cotizaciones WHERE id=?'); $cot->execute([$cotId]);
            $c = $cot->fetch(); if (!$c) jsonError(404, 'Cotización no encontrada');
            $dup = $pdo->prepare('SELECT id FROM cuentas_cobrar WHERE cotizacion_id=? AND estatus<>"cancelada"'); $dup->execute([$cotId]);
            if ($dup->fetch()) jsonError(409, 'Esta cotización ya tiene una cuenta por cobrar activa');

            // Cliente = "shipto"/"supplier" de la cotización (a quién se le vende)
            $clienteNombre = trim(explode("\n", (string)($c['supplier'] ?? ''))[0]) ?: ($c['shipto'] ?: 'Cliente');
            $dias = max(0, (int)($body['dias_credito'] ?? 30));
            $emision = date('Y-m-d');
            $venc = date('Y-m-d', strtotime($emision." +$dias days"));
            $folio = (int)$pdo->query("SELECT COALESCE(MAX(folio),30000) FROM cuentas_cobrar")->fetchColumn() + 1;
            $total = round((float)$c['total'], 2);
            $pdo->prepare("INSERT INTO cuentas_cobrar (folio,cliente_nombre,cliente_rfc,cliente_contacto,cotizacion_id,concepto,fecha_emision,fecha_vencimiento,dias_credito,moneda,subtotal,iva,total,saldo,estatus,creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pendiente',?)")
                ->execute([$folio,$clienteNombre,(string)$c['rfc'],(string)$c['contacto'],$cotId,'Cotización COT'.$c['folio'],$emision,$venc,$dias,(string)$c['currency'],(float)$c['subtotal'],(float)$c['iva_monto'],$total,$total,$uid]);
            $nuevaId = (int)$pdo->lastInsertId();
            // Marcar la cotización como vendida (no rompe nada: campos ya existen)
            $pdo->prepare("UPDATE cotizaciones SET vendida=1, fecha_venta=IF(fecha_venta IS NULL,?,fecha_venta), vendida_por=? WHERE id=?")->execute([$emision,$uid,$cotId]);
            jsonOk(['id'=>$nuevaId, 'folio'=>$folio], 201);
        }

        // ── REGISTRAR ABONO / COBRO (pago parcial) ──────────────
        if ($action === 'pago') {
            $cuentaId = (int)($body['cuenta_id'] ?? 0);
            $monto    = round((float)($body['monto'] ?? 0), 2);
            $fecha    = trim($body['fecha_pago'] ?? date('Y-m-d'));
            $metodo   = trim($body['metodo'] ?? '');
            $ref      = trim($body['referencia'] ?? '');
            $coment   = trim($body['comentarios'] ?? '');
            if (!$cuentaId || $monto <= 0) jsonError(400, 'Cuenta y monto válido son requeridos');
            $cur = $pdo->prepare('SELECT saldo, estatus FROM cuentas_cobrar WHERE id=?'); $cur->execute([$cuentaId]);
            $cuenta = $cur->fetch(); if (!$cuenta) jsonError(404, 'Cuenta no encontrada');
            if ($cuenta['estatus'] === 'cancelada') jsonError(400, 'La cuenta está cancelada');
            if ($cuenta['estatus'] === 'pagada')    jsonError(400, 'La cuenta ya está saldada');
            if ($monto > (float)$cuenta['saldo'] + 0.009) jsonError(400, 'El abono ('.number_format($monto,2).') supera el saldo pendiente ('.number_format((float)$cuenta['saldo'],2).')');

            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO cxc_pagos (cuenta_id,monto,fecha_pago,metodo,referencia,comentarios,creado_por) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$cuentaId,$monto,$fecha,$metodo,$ref,$coment,$uid]);
                $nuevoSaldo = round((float)$cuenta['saldo'] - $monto, 2);
                $estatus = $nuevoSaldo <= 0.009 ? 'pagada' : 'parcial';
                $pdo->prepare('UPDATE cuentas_cobrar SET saldo=?, estatus=?, updated_at=NOW() WHERE id=?')->execute([$nuevoSaldo,$estatus,$cuentaId]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            jsonOk(['ok'=>true, 'saldo'=>$nuevoSaldo, 'estatus'=>$estatus]);
        }

        // ── ELIMINAR un abono (corrige errores de captura) ──────
        if ($action === 'pago_delete') {
            if (!$id) jsonError(400, 'Se requiere id del abono');
            $p = $pdo->prepare('SELECT cuenta_id, monto FROM cxc_pagos WHERE id=?'); $p->execute([$id]);
            $pago = $p->fetch(); if (!$pago) jsonError(404, 'Abono no encontrado');
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM cxc_pagos WHERE id=?')->execute([$id]);
                $cur = $pdo->prepare('SELECT total, estatus FROM cuentas_cobrar WHERE id=?'); $cur->execute([$pago['cuenta_id']]);
                $cuenta = $cur->fetch();
                $totalPagado = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM cxc_pagos WHERE cuenta_id=".(int)$pago['cuenta_id'])->fetchColumn();
                $nuevoSaldo = round((float)$cuenta['total'] - $totalPagado, 2);
                $estatus = $cuenta['estatus']==='cancelada' ? 'cancelada' : ($nuevoSaldo <= 0.009 ? 'pagada' : ($totalPagado > 0 ? 'parcial' : 'pendiente'));
                $pdo->prepare('UPDATE cuentas_cobrar SET saldo=?, estatus=?, updated_at=NOW() WHERE id=?')->execute([$nuevoSaldo,$estatus,$pago['cuenta_id']]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            jsonOk(['deleted'=>true, 'saldo'=>$nuevoSaldo]);
        }

        // ── CANCELAR cuenta ─────────────────────────────────────
        if ($action === 'cancelar') {
            if (!$id) jsonError(400, 'Se requiere id');
            $pdo->prepare("UPDATE cuentas_cobrar SET estatus='cancelada', updated_at=NOW() WHERE id=?")->execute([$id]);
            jsonOk(['cancelled'=>true]);
        }

        // ── ELIMINAR cuenta (solo si no tiene abonos) ───────────
        if ($action === 'delete') {
            if (!$id) jsonError(400, 'Se requiere id');
            $n = (int)$pdo->query("SELECT COUNT(*) FROM cxc_pagos WHERE cuenta_id=".(int)$id)->fetchColumn();
            if ($n > 0) jsonError(409, 'No se puede eliminar: la cuenta tiene abonos. Usa "Cancelar".');
            $pdo->prepare('DELETE FROM cuentas_cobrar WHERE id=?')->execute([$id]);
            jsonOk(['deleted'=>true]);
        }

        // ── Cotizaciones disponibles para generar CxC ───────────
        if ($action === 'cotizaciones_disponibles') {
            $rows = $pdo->query("
                SELECT c.id, c.folio, c.supplier, c.shipto, c.currency, c.total
                FROM cotizaciones c
                WHERE c.id NOT IN (SELECT cotizacion_id FROM cuentas_cobrar WHERE cotizacion_id IS NOT NULL AND estatus<>'cancelada')
                ORDER BY c.folio DESC
            ")->fetchAll();
            jsonOk($rows);
        }

        jsonError(404, 'Acción de cuentas por cobrar no encontrada');
    }

    // ─────────────────────────────────────────────────────────
    // COTIZACIONES Y ÓRDENES DE COMPRA
    // Usar: api.php?module=cotizaciones o api.php?module=ordenes
    // ─────────────────────────────────────────────────────────

    // Acciones específicas de cotizaciones: PO del cliente (número + PDF).
    // Se interceptan ANTES del enrutado por método para que un POST de archivo
    // no se confunda con la creación de una cotización.
    if ($module === 'cotizaciones') {
        ensureCotizacionesColumns($pdo);

        // ── Subir / asociar el PDF de la orden de compra del cliente ──
        if ($action === 'upload_po') {
            if (!$id) jsonError(400, 'Se requiere ?id= de la cotización');
            if (empty($_FILES['po_file'])) jsonError(400, 'No se recibió el archivo PDF');
            $f = $_FILES['po_file'];
            if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) jsonError(400, 'Error al subir el archivo');
            if (($f['size'] ?? 0) > 5 * 1024 * 1024) jsonError(400, 'Archivo demasiado grande (máx. 5 MB)');
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') jsonError(400, 'El archivo debe ser PDF');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($f['tmp_name']);
            if ($mime !== 'application/pdf') jsonError(400, 'El archivo no es un PDF válido (MIME: '.$mime.')');
            $chk = $pdo->prepare('SELECT folio, po_archivo FROM cotizaciones WHERE id=?'); $chk->execute([$id]);
            $cot = $chk->fetch(); if (!$cot) jsonError(404, 'Cotización no encontrada');
            $destDir = __DIR__.'/uploads/po/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $safe = 'PO_COT'.(int)$cot['folio'].'_'.time().'.pdf';
            $rel  = 'uploads/po/'.$safe;
            if (!move_uploaded_file($f['tmp_name'], __DIR__.'/'.$rel)) jsonError(500, 'No se pudo guardar el archivo');
            // Borrar el archivo anterior si existía
            if (!empty($cot['po_archivo'])) {
                $old = __DIR__.'/'.$cot['po_archivo'];
                if (is_file($old) && strpos(realpath($old), realpath($destDir)) === 0) @unlink($old);
            }
            $pdo->prepare('UPDATE cotizaciones SET po_archivo=? WHERE id=?')->execute([$rel, $id]);
            jsonOk(['po_archivo' => $rel]);
        }

        // ── Quitar el PDF asociado ──
        if ($action === 'po_delete') {
            if (!$id) jsonError(400, 'Se requiere ?id=');
            $chk = $pdo->prepare('SELECT po_archivo FROM cotizaciones WHERE id=?'); $chk->execute([$id]);
            $cot = $chk->fetch(); if (!$cot) jsonError(404, 'Cotización no encontrada');
            if (!empty($cot['po_archivo'])) {
                $old = __DIR__.'/'.$cot['po_archivo'];
                if (is_file($old)) @unlink($old);
            }
            $pdo->prepare('UPDATE cotizaciones SET po_archivo=NULL WHERE id=?')->execute([$id]);
            jsonOk(['deleted' => true]);
        }
    }

    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare("SELECT t.*, uc.nombre AS creado_por_nombre, um.nombre AS modificado_por_nombre
                FROM $tbl t
                LEFT JOIN usuarios uc ON uc.id = t.creado_por
                LEFT JOIN usuarios um ON um.id = t.modificado_por
                WHERE t.id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) jsonError(404, 'Registro no encontrado');
            $items = $pdo->prepare("SELECT * FROM $tblItems WHERE $fkCol=? ORDER BY orden");
            $items->execute([$id]);
            $row['items'] = $items->fetchAll();
            jsonOk($row);
        }
        $stmt = $pdo->query("SELECT t.id, t.folio, t.fecha, t.supplier, t.shipto, t.currency, t.subtotal, t.iva_monto, t.total,
            uc.nombre AS creado_por_nombre, um.nombre AS modificado_por_nombre, t.actualizado
            FROM $tbl t
            LEFT JOIN usuarios uc ON uc.id = t.creado_por
            LEFT JOIN usuarios um ON um.id = t.modificado_por
            ORDER BY t.folio DESC");
        $rows = $stmt->fetchAll();
        if ($module === 'ordenes') {
            $gt = array_sum(array_column($rows, 'total'));
            jsonOk(['rows' => $rows, 'grand_total' => $gt]);
        }
        jsonOk($rows);
    }

    if ($method === 'POST') {
        $maxFolio = $pdo->query("SELECT COALESCE(MAX(folio),".($module==='ordenes'?'10000':'20000').") FROM $tbl")->fetchColumn();
        $folio    = (int)$maxFolio + 1;
        $totals   = calcTotals($body);
        $stmt = $pdo->prepare("INSERT INTO $tbl
            (folio,rfc,contacto,supplier,shipto,fecha,payment,incoterms,currency,iva_pct,terms,subtotal,iva_monto,total,creado_por,modificado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $folio, $body['rfc']??'', $body['contacto']??'', $body['supplier']??'',
            $body['shipto']??'', $body['fecha']??null, $body['payment']??'',
            $body['incoterms']??'', $body['currency']??'USD', $body['iva_pct']??8,
            $body['terms']??'', $totals['subtotal'], $totals['iva_monto'], $totals['total'],
            $uid, $uid
        ]);
        $newId = (int)$pdo->lastInsertId();
        saveItems($pdo, $tblItems, $fkCol, $newId, $body['items'] ?? []);
        if ($module === 'cotizaciones') {
            $pdo->prepare("UPDATE cotizaciones SET po_cliente=?, vigencia=?, observaciones=? WHERE id=?")
                ->execute([trim($body['po_cliente'] ?? ''), trim($body['vigencia'] ?? ''), trim($body['observaciones'] ?? ''), $newId]);
        }
        jsonOk(['id' => $newId, 'folio' => $folio], 201);
    }

    if ($method === 'PUT') {
        if (!$id) jsonError(400, 'Se requiere ?id=N');
        $totals = calcTotals($body);
        $stmt = $pdo->prepare("UPDATE $tbl SET rfc=?,contacto=?,supplier=?,shipto=?,fecha=?,payment=?,incoterms=?,
            currency=?,iva_pct=?,terms=?,subtotal=?,iva_monto=?,total=?,modificado_por=? WHERE id=?");
        $stmt->execute([
            $body['rfc']??'', $body['contacto']??'', $body['supplier']??'',
            $body['shipto']??'', $body['fecha']??null, $body['payment']??'',
            $body['incoterms']??'', $body['currency']??'USD', $body['iva_pct']??8,
            $body['terms']??'', $totals['subtotal'], $totals['iva_monto'], $totals['total'],
            $uid, $id
        ]);
        $pdo->prepare("DELETE FROM $tblItems WHERE $fkCol=?")->execute([$id]);
        saveItems($pdo, $tblItems, $fkCol, $id, $body['items'] ?? []);
        if ($module === 'cotizaciones') {
            $pdo->prepare("UPDATE cotizaciones SET po_cliente=?, vigencia=?, observaciones=? WHERE id=?")
                ->execute([trim($body['po_cliente'] ?? ''), trim($body['vigencia'] ?? ''), trim($body['observaciones'] ?? ''), $id]);
        }
        jsonOk(['id' => $id, 'updated' => true]);
    }

    if ($method === 'DELETE') {
        if (!$id) jsonError(400, 'Se requiere ?id=N');
        $pdo->prepare("DELETE FROM $tbl WHERE id=?")->execute([$id]);
        jsonOk(['deleted' => true]);
    }

    jsonError(405, 'Método no permitido');

} catch (PDOException $e) {
    error_log('[api] PDOException: '.$e->getMessage());
    jsonError(500, 'Error interno de base de datos');
} catch (Throwable $e) {
    error_log('[api] Error: '.$e->getMessage());
    jsonError(500, $e->getMessage());
}

// ─────────────────────────────────────────────────────────────
// Helpers generales
// ─────────────────────────────────────────────────────────────
function jsonOk(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function calcTotals(array $data): array {
    $subtotal = 0;
    foreach (($data['items'] ?? []) as $it) {
        $subtotal += (float)($it['cantidad']??0) * (float)($it['precio_unitario']??0);
    }
    $iva = $subtotal * (float)($data['iva_pct']??0) / 100;
    return ['subtotal'=>round($subtotal,4),'iva_monto'=>round($iva,4),'total'=>round($subtotal+$iva,4)];
}
function saveItems(PDO $pdo, string $tbl, string $fk, int $parentId, array $items): void {
    $stmt = $pdo->prepare("INSERT INTO $tbl ($fk,orden,order_code,descripcion,cantidad,unidad,precio_unitario,precio_total) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($items as $i => $it) {
        $qty = (float)($it['cantidad']??0);
        $prc = (float)($it['precio_unitario']??0);
        $stmt->execute([$parentId,$i,$it['order_code']??'',$it['descripcion']??'',$qty,$it['unidad']??'PZ',$prc,round($qty*$prc,4)]);
    }
}

// ─────────────────────────────────────────────────────────────
// Helpers facturas XML CFDI
// ─────────────────────────────────────────────────────────────
function processXmlUpload(PDO $pdo, array $file, int $uid, ?array $supplierOverride = null): array {
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new Exception('Error al subir archivo');
  // FIX: validar tamaño máximo
  if (($file['size'] ?? 0) > MAX_XML_SIZE) throw new Exception('Archivo demasiado grande (máx. 2 MB)');
  $ext=strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if ($ext !== 'xml') throw new Exception('El archivo debe ser XML');
  // FIX: validar tipo MIME real del archivo, no solo la extensión
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']);
  if (!in_array($mime, ['text/xml','application/xml'], true)) throw new Exception('El archivo no es XML válido (MIME: '.$mime.')');
  $xmlRaw=file_get_contents($file['tmp_name']);
  if (!$xmlRaw) throw new Exception('XML vacío');
  // FIX: deshabilitar entidades externas para prevenir XXE
  libxml_disable_entity_loader(true);
  libxml_use_internal_errors(true);
  $xml=simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR);
  if (!$xml) {
    $errs = array_map(fn($e) => $e->message, libxml_get_errors());
    libxml_clear_errors();
    throw new Exception('XML inválido: '.implode('; ', $errs));
  }
  $ns=$xml->getNamespaces(true);
  if (empty($ns['cfdi'])) throw new Exception('No parece CFDI válido');
  $xml->registerXPathNamespace('cfdi',$ns['cfdi']);
  if (!empty($ns['tfd'])) $xml->registerXPathNamespace('tfd',$ns['tfd']);
  else $xml->registerXPathNamespace('tfd','http://www.sat.gob.mx/TimbreFiscalDigital');

  $comp=$xml->xpath('//cfdi:Comprobante')[0] ?? null;
  $emisor=$xml->xpath('//cfdi:Emisor')[0] ?? null;
  $receptor=$xml->xpath('//cfdi:Receptor')[0] ?? null;
  $timbre=$xml->xpath('//tfd:TimbreFiscalDigital')[0] ?? null;
  if (!$comp || !$emisor || !$receptor || !$timbre) throw new Exception('CFDI incompleto');
  $uuid=(string)$timbre['UUID']; if (!$uuid) throw new Exception('No se encontró UUID');
  // FIX: validar formato UUID
  if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) throw new Exception('UUID con formato inválido');
  $check=$pdo->prepare('SELECT id FROM invoices WHERE uuid=? AND deleted_at IS NULL'); $check->execute([$uuid]);
  if ($check->fetch()) throw new Exception('Duplicado: UUID ya registrado');

  // Si el usuario seleccionó un proveedor antes de subir, usarlo (y sus días de crédito configurados)
  // Si no, hacer upsert automático desde los datos del XML con días por defecto
  if ($supplierOverride && isset($supplierOverride['id'])) {
    $supplierId = (int)$supplierOverride['id'];
    $supplierPaymentDays = (int)($supplierOverride['payment_days'] ?? 30);
    // Actualizar nombre desde el XML si cambió
    $pdo->prepare('UPDATE suppliers SET name=?, updated_at=NOW() WHERE id=?')->execute([trim((string)$emisor['Nombre']), $supplierId]);
  } else {
    [$supplierId, $supplierPaymentDays] = upsertSupplier($pdo, (string)$emisor['Nombre'], (string)$emisor['Rfc']);
  }
  $iva=0; $ret=0;
  foreach ($xml->xpath('//cfdi:Traslado') ?: [] as $tr) if ((string)$tr['Impuesto']==='002') $iva += (float)$tr['Importe'];
  foreach ($xml->xpath('//cfdi:Retencion') ?: [] as $rt) $ret += (float)$rt['Importe'];
  $due = computeDueDate((string)$comp['Fecha'], (string)$comp['MetodoPago'], $supplierPaymentDays);
  // FIX: nombre de archivo seguro basado en UUID (ya validado arriba)
  $safe = strtoupper($uuid).'.xml';
  $relPath = 'uploads/xml/'.$safe;
  $destDir = __DIR__.'/uploads/xml/';
  if (!is_dir($destDir)) mkdir($destDir, 0755, true);
  file_put_contents(__DIR__.'/'.$relPath, $xmlRaw);
  $statusId = (int)$pdo->query("SELECT id FROM invoice_status WHERE name='Pendiente' LIMIT 1")->fetchColumn();

  $stmt=$pdo->prepare("INSERT INTO invoices
    (uuid,serie,folio,invoice_number,supplier_id,receiver_name,receiver_rfc,subtotal,iva,retentions,total,currency,exchange_rate,issue_date,due_date,payment_method,payment_form,cfdi_use,cfdi_version,invoice_type,sat_status,stamp_date,certificate_number,status_id,xml_path,uploaded_by,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
  $serie=(string)$comp['Serie']; $folio=(string)$comp['Folio'];
  $stmt->execute([$uuid,$serie,$folio,trim($serie.'-'.$folio,'-'),$supplierId,(string)$receptor['Nombre'],(string)$receptor['Rfc'],(float)$comp['SubTotal'],$iva,$ret,(float)$comp['Total'],(string)$comp['Moneda'],(float)($comp['TipoCambio'] ?: 1),(string)$comp['Fecha'],$due,(string)$comp['MetodoPago'],(string)$comp['FormaPago'],(string)$receptor['UsoCFDI'],(string)$comp['Version'],(string)$comp['TipoDeComprobante'],'Pendiente de validar',(string)$timbre['FechaTimbrado'],(string)$timbre['NoCertificadoSAT'],$statusId,$relPath,$uid]);
  $invoiceId=(int)$pdo->lastInsertId();

  $ins=$pdo->prepare('INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price,amount,tax) VALUES (?,?,?,?,?,?,?)');
  foreach ($xml->xpath('//cfdi:Concepto') ?: [] as $c) {
    $tax=0; foreach ($c->xpath('.//cfdi:Traslado') ?: [] as $tr) $tax+=(float)$tr['Importe'];
    $ins->execute([$invoiceId,(string)$c['Descripcion'],(float)$c['Cantidad'],(string)($c['Unidad'] ?: $c['ClaveUnidad']),(float)$c['ValorUnitario'],(float)$c['Importe'],$tax]);
  }
  $pdo->prepare('INSERT INTO invoice_history (invoice_id,user_id,action,comments) VALUES (?,?,?,?)')->execute([$invoiceId,$uid,'Carga XML','Factura importada desde XML']);
  return ['ok'=>true,'file'=>$file['name'],'id'=>$invoiceId,'uuid'=>$uuid,'total'=>(float)$comp['Total']];
}
function upsertSupplier(PDO $pdo, string $name, string $rfc): array {
  // FIX: sanitizar RFC antes de insertar
  $rfc = strtoupper(trim($rfc));
  $stmt=$pdo->prepare('SELECT id, payment_days FROM suppliers WHERE rfc=?'); $stmt->execute([$rfc]); $row=$stmt->fetch();
  if ($row) {
    // FIX: actualizar nombre si cambió; respetar los días de pago ya configurados por el usuario
    $pdo->prepare('UPDATE suppliers SET name=?, updated_at=NOW() WHERE rfc=?')->execute([trim($name), $rfc]);
    return [(int)$row['id'], (int)($row['payment_days'] ?? 30)];
  }
  // Proveedor nuevo desde XML: insertar con 30 días por defecto; el usuario puede ajustarlo en el catálogo
  $ins=$pdo->prepare('INSERT INTO suppliers (name,rfc,payment_days,created_at) VALUES (?,?,30,NOW())'); $ins->execute([trim($name),$rfc]);
  return [(int)$pdo->lastInsertId(), 30];
}
function normalizeFiles(array $files): array {
  $out=[]; foreach ($files['name'] as $i=>$name) $out[]=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??0,'size'=>$files['size'][$i]??0]; return $out;
}
function computeDueDate(string $issueDate, string $method, int $supplierDays = 30): ?string { if(!$issueDate) return null; $days = strtoupper($method)==='PUE' ? 0 : $supplierDays; return date('Y-m-d H:i:s', strtotime($issueDate.' +'.$days.' days')); }
function dueState($dueDate, $status): string { if(in_array($status,['Pagada','Cancelada'])) return strtolower($status); if(!$dueDate) return 'vigente'; $d=floor((strtotime($dueDate)-time())/86400); if($d<0) return 'vencida'; if($d<=7) return 'proxima'; return 'vigente'; }
function invoiceKpis(PDO $pdo): array {
  $row=$pdo->query("SELECT COUNT(*) total_facturas, COALESCE(SUM(total),0) total_general, COALESCE(SUM(iva),0) total_iva, SUM(CASE WHEN MONTH(issue_date)=MONTH(CURDATE()) AND YEAR(issue_date)=YEAR(CURDATE()) THEN 1 ELSE 0 END) facturas_mes FROM invoices WHERE deleted_at IS NULL")->fetch();
  $paid=$pdo->query("SELECT COALESCE(SUM(i.total),0) FROM invoices i JOIN invoice_status s ON s.id=i.status_id WHERE s.name='Pagada' AND i.deleted_at IS NULL")->fetchColumn();
  $pending=$pdo->query("SELECT COALESCE(SUM(i.total),0) FROM invoices i JOIN invoice_status s ON s.id=i.status_id WHERE s.name NOT IN ('Pagada','Cancelada') AND i.deleted_at IS NULL")->fetchColumn();
  $row['total_pagado']=(float)$paid; $row['total_pendiente']=(float)$pending; return $row;
}
function formatXml($xml): string { $dom=new DOMDocument('1.0'); $dom->preserveWhiteSpace=false; $dom->formatOutput=true; return $dom->loadXML($xml) ? $dom->saveXML() : $xml; }

// ─────────────────────────────────────────────────────────────
// Helpers Cuentas por Cobrar (CxC) y reportes
// ─────────────────────────────────────────────────────────────
function tableExists(PDO $pdo, string $name): bool {
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->execute([$name]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { return false; }
}
// Asegura las columnas nuevas de cotizaciones (PO del cliente, vigencia, observaciones).
// Idempotente: solo agrega lo que falte, sin tocar datos existentes.
function ensureCotizacionesColumns(PDO $pdo): void {
  static $done = false; if ($done) return; $done = true;
  try {
    $existing = $pdo->query("SHOW COLUMNS FROM cotizaciones")->fetchAll(PDO::FETCH_COLUMN);
    $cols = [
      'po_cliente'    => "VARCHAR(120) DEFAULT NULL",
      'po_archivo'    => "VARCHAR(255) DEFAULT NULL",
      'vigencia'      => "VARCHAR(80) DEFAULT NULL",
      'observaciones' => "TEXT DEFAULT NULL",
    ];
    foreach ($cols as $name => $def) {
      if (!in_array($name, $existing, true)) {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN $name $def");
      }
    }
  } catch (Throwable $e) { /* si falla, las rutas siguen funcionando con los campos base */ }
}
function cxcDueState($dueDate, $estatus): string {
  if ($estatus === 'pagada')    return 'pagada';
  if ($estatus === 'cancelada') return 'cancelada';
  if (!$dueDate) return 'vigente';
  $d = floor((strtotime($dueDate) - strtotime(date('Y-m-d'))) / 86400);
  if ($d < 0)  return 'vencida';
  if ($d <= 7) return 'proxima';
  return 'vigente';
}
function cxcKpis(PDO $pdo): array {
  $row = $pdo->query("
    SELECT
      COUNT(*) AS total_cuentas,
      COALESCE(SUM(total),0) AS total_facturado,
      COALESCE(SUM(saldo),0) AS total_por_cobrar,
      COALESCE(SUM(total - saldo),0) AS total_cobrado,
      COALESCE(SUM(CASE WHEN estatus IN ('pendiente','parcial') AND fecha_vencimiento < CURDATE() THEN saldo ELSE 0 END),0) AS total_vencido,
      SUM(CASE WHEN estatus IN ('pendiente','parcial') AND fecha_vencimiento < CURDATE() THEN 1 ELSE 0 END) AS num_vencidas,
      SUM(CASE WHEN estatus='pendiente' THEN 1 ELSE 0 END) AS num_pendientes,
      SUM(CASE WHEN estatus='parcial' THEN 1 ELSE 0 END) AS num_parciales,
      SUM(CASE WHEN estatus='pagada' THEN 1 ELSE 0 END) AS num_pagadas
    FROM cuentas_cobrar WHERE estatus <> 'cancelada'
  ")->fetch();
  $cobradoMes = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM cxc_pagos WHERE MONTH(fecha_pago)=MONTH(CURDATE()) AND YEAR(fecha_pago)=YEAR(CURDATE())")->fetchColumn();
  $row['cobrado_mes'] = $cobradoMes;
  return $row;
}

