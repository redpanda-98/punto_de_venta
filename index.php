<?php

session_start();

$settings = require __DIR__ . '/config.php';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function chartAmountLabel(float $amount): string
{
    if ($amount >= 1000000) {
        return '$' . number_format($amount / 1000000, 1, '.', '') . ' M';
    }
    if ($amount >= 1000) {
        return '$' . number_format($amount / 1000, 1, '.', '') . ' k';
    }
    return '$' . number_format($amount, 0);
}

function employeeFields(): array
{
    $name = $_POST['nombre'] ?? '';
    $phone = $_POST['telefono'] ?? '';

    if (!is_string($name) || !is_string($phone)) {
        throw new DomainException('Revisa los datos del empleado.');
    }

    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 75) {
        throw new DomainException('El nombre debe tener entre 1 y 75 caracteres.');
    }
    if (!preg_match('/^\d{0,10}$/', $phone)) {
        throw new DomainException('El teléfono debe contener solo números y hasta 10 dígitos.');
    }

    return [$name, $phone === '' ? null : $phone];
}

$pageDefinitions = [
    'resumen' => ['title' => 'Resumen', 'description' => 'Actividad y ventas del día.'],
    'ventas' => ['title' => 'Registrar venta', 'description' => 'Busca productos y registra una venta con varios artículos.'],
    'historial' => ['title' => 'Últimas ventas', 'description' => 'Consulta tickets, productos y cantidades vendidos.'],
    'inventario' => ['title' => 'Inventario', 'description' => 'Administra productos, precios y existencias.'],
    'empleados' => ['title' => 'Empleados', 'description' => 'Consulta y administra los datos de empleados.'],
];
$currentPage = $_GET['pagina'] ?? 'resumen';
if (!is_string($currentPage) || !isset($pageDefinitions[$currentPage])) {
    $currentPage = 'resumen';
}
$currentPageInfo = $pageDefinitions[$currentPage];

$connectionError = null;
$products = [];
$availableProducts = [];
$employees = [];
$recentSales = [];
$todaySales = 0.0;
$todayCount = 0;
$stockUnits = 0;
$monthlyLeader = null;
$chartMonths = [];
$storeMonthlySales = [];
$employeeMonthlySales = [];
$storeChartMax = 0.0;
$employeeChartMax = 0.0;
$salesPageSize = 10;
$requestedSalesPage = filter_var($_GET['ventas_pagina'] ?? 1, FILTER_VALIDATE_INT);
$salesPage = max(1, $requestedSalesPage === false ? 1 : $requestedSalesPage);
$salesPageCount = 1;
$salesTotalCount = 0;
$salesFirstItem = 0;
$salesLastItem = 0;

try {
    $pdo = new PDO(
        "mysql:host={$settings['host']};dbname={$settings['database']};charset=utf8mb4",
        $settings['username'],
        $settings['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'], $postedToken)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'La sesión expiró. Actualiza la página e inténtalo de nuevo.'];
            header('Location: index.php?pagina=' . rawurlencode($currentPage));
            exit;
        }

        $action = $_POST['action'] ?? '';
        try {
            if (!is_string($action)) {
                throw new DomainException('La operación solicitada no es válida.');
            }

            if ($action === 'register_sale') {
                $employeeId = filter_var($_POST['id_empleado'] ?? null, FILTER_VALIDATE_INT);
                $submittedItems = $_POST['items'] ?? null;
                if ($submittedItems === null) {
                    $submittedItems = [[
                        'id_producto' => $_POST['id_producto'] ?? null,
                        'cantidad' => $_POST['cantidad'] ?? null,
                    ]];
                }
                if (!$employeeId || !is_array($submittedItems) || count($submittedItems) < 1 || count($submittedItems) > 10) {
                    throw new DomainException('Selecciona un empleado y agrega entre 1 y 10 productos.');
                }

                $cart = [];
                foreach ($submittedItems as $item) {
                    if (!is_array($item)) {
                        throw new DomainException('Revisa los productos de la venta.');
                    }
                    $productId = filter_var($item['id_producto'] ?? null, FILTER_VALIDATE_INT);
                    $quantity = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);
                    if (!$productId || !$quantity || $quantity < 1) {
                        throw new DomainException('Cada producto necesita una cantidad válida.');
                    }
                    $currentQuantity = $cart[$productId] ?? 0;
                    if ($quantity > 2147483647 - $currentQuantity) {
                        throw new DomainException('La cantidad solicitada excede el límite permitido.');
                    }
                    $cart[$productId] = $currentQuantity + $quantity;
                }
                if (count($cart) > 10) {
                    throw new DomainException('Una venta admite hasta 10 productos diferentes.');
                }
                ksort($cart, SORT_NUMERIC);

                $pdo->beginTransaction();
                $employeeQuery = $pdo->prepare('SELECT id_empleado FROM empleados WHERE id_empleado = ? FOR UPDATE');
                $employeeQuery->execute([$employeeId]);
                if (!$employeeQuery->fetch()) {
                    throw new DomainException('El empleado seleccionado ya no está disponible.');
                }

                $productQuery = $pdo->prepare('SELECT nombre, cantidad FROM productos WHERE id_producto = ? FOR UPDATE');
                foreach ($cart as $productId => $quantity) {
                    $productQuery->execute([$productId]);
                    $product = $productQuery->fetch();
                    if (!$product) {
                        throw new DomainException('Uno de los productos ya no está disponible.');
                    }
                    if ($quantity > (int) $product['cantidad']) {
                        throw new DomainException('Existencias insuficientes para ' . $product['nombre'] . '.');
                    }
                }

                $saleQuery = $pdo->prepare(
                    'INSERT INTO ventas (folio_venta, id_empleado, id_producto, cantidad, fecha_compra) VALUES (?, ?, ?, ?, CURDATE())'
                );
                $saleFolio = bin2hex(random_bytes(16));
                foreach ($cart as $productId => $quantity) {
                    $saleQuery->execute([$saleFolio, $employeeId, $productId, $quantity]);
                }
                $pdo->commit();
                $message = 'Venta registrada con ' . count($cart) . ' productos. Folio ' . strtoupper(substr($saleFolio, 0, 8)) . '.';
            } elseif ($action === 'create_product') {
                $name = $_POST['nombre'] ?? '';
                $price = $_POST['precio'] ?? '';
                $quantity = filter_var($_POST['cantidad'] ?? null, FILTER_VALIDATE_INT);

                if (!is_string($name) || !is_string($price)) {
                    throw new DomainException('Revisa los datos del producto.');
                }

                $name = trim($name);
                if ($name === '' || mb_strlen($name, 'UTF-8') > 75) {
                    throw new DomainException('El nombre debe tener entre 1 y 75 caracteres.');
                }
                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price) || (float) $price <= 0) {
                    throw new DomainException('Escribe un precio mayor que cero con máximo dos decimales.');
                }
                if ($quantity === false || $quantity < 0) {
                    throw new DomainException('El stock inicial debe ser un número entero igual o mayor que cero.');
                }

                $productQuery = $pdo->prepare('INSERT INTO productos (nombre, precio, cantidad) VALUES (?, ?, ?)');
                $productQuery->execute([$name, $price, $quantity]);
                $message = 'Producto agregado al inventario.';
            } elseif ($action === 'update_product_price') {
                $productId = filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
                $price = $_POST['precio'] ?? '';

                if (!$productId || !is_string($price) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price) || (float) $price <= 0) {
                    throw new DomainException('Escribe un precio mayor que cero con máximo dos decimales.');
                }

                $pdo->beginTransaction();
                $productQuery = $pdo->prepare('SELECT id_producto FROM productos WHERE id_producto = ? FOR UPDATE');
                $productQuery->execute([$productId]);
                if (!$productQuery->fetch()) {
                    throw new DomainException('El producto ya no está disponible.');
                }
                $updateQuery = $pdo->prepare('UPDATE productos SET precio = ? WHERE id_producto = ?');
                $updateQuery->execute([$price, $productId]);
                $pdo->commit();
                $message = 'Precio del producto actualizado.';
            } elseif ($action === 'adjust_stock') {
                $productId = filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($_POST['cantidad'] ?? null, FILTER_VALIDATE_INT);
                $movement = $_POST['movimiento'] ?? '';

                if (!$productId || !$quantity || $quantity < 1 || !in_array($movement, ['sumar', 'restar'], true)) {
                    throw new DomainException('Selecciona un ajuste válido de stock.');
                }

                $pdo->beginTransaction();
                $productQuery = $pdo->prepare('SELECT cantidad FROM productos WHERE id_producto = ? FOR UPDATE');
                $productQuery->execute([$productId]);
                $product = $productQuery->fetch();

                if (!$product) {
                    throw new DomainException('El producto ya no está disponible.');
                }
                $currentStock = (int) $product['cantidad'];
                if ($movement === 'restar' && $quantity > $currentStock) {
                    throw new DomainException('No puedes retirar más unidades de las que hay en existencia.');
                }

                $newStock = $movement === 'sumar' ? $currentStock + $quantity : $currentStock - $quantity;
                $updateQuery = $pdo->prepare('UPDATE productos SET cantidad = ? WHERE id_producto = ?');
                $updateQuery->execute([$newStock, $productId]);
                $pdo->commit();
                $message = $movement === 'sumar' ? 'Stock aumentado correctamente.' : 'Stock disminuido correctamente.';
            } elseif ($action === 'delete_product') {
                $productId = filter_var($_POST['id_producto'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId) {
                    throw new DomainException('Selecciona un producto válido.');
                }

                $pdo->beginTransaction();
                $productQuery = $pdo->prepare('SELECT id_producto FROM productos WHERE id_producto = ? FOR UPDATE');
                $productQuery->execute([$productId]);
                if (!$productQuery->fetch()) {
                    throw new DomainException('El producto ya no está disponible.');
                }
                $salesQuery = $pdo->prepare('SELECT COUNT(*) FROM ventas WHERE id_producto = ?');
                $salesQuery->execute([$productId]);
                if ((int) $salesQuery->fetchColumn() > 0) {
                    throw new DomainException('No se puede eliminar un producto con ventas registradas. Se conservó su historial.');
                }

                $deleteQuery = $pdo->prepare('DELETE FROM productos WHERE id_producto = ?');
                $deleteQuery->execute([$productId]);
                $pdo->commit();
                $message = 'Producto eliminado correctamente.';
            } elseif ($action === 'create_employee') {
                [$name, $phone] = employeeFields();
                $employeeQuery = $pdo->prepare('INSERT INTO empleados (nombre, telefono) VALUES (?, ?)');
                $employeeQuery->execute([$name, $phone]);
                $message = 'Empleado agregado correctamente.';
            } elseif ($action === 'update_employee') {
                $employeeId = filter_var($_POST['id_empleado'] ?? null, FILTER_VALIDATE_INT);
                if (!$employeeId) {
                    throw new DomainException('Selecciona un empleado válido.');
                }
                [$name, $phone] = employeeFields();

                $pdo->beginTransaction();
                $employeeQuery = $pdo->prepare('SELECT id_empleado FROM empleados WHERE id_empleado = ? FOR UPDATE');
                $employeeQuery->execute([$employeeId]);
                if (!$employeeQuery->fetch()) {
                    throw new DomainException('El empleado ya no está disponible.');
                }
                $updateQuery = $pdo->prepare('UPDATE empleados SET nombre = ?, telefono = ? WHERE id_empleado = ?');
                $updateQuery->execute([$name, $phone, $employeeId]);
                $pdo->commit();
                $message = 'Datos del empleado actualizados.';
            } elseif ($action === 'delete_employee') {
                $employeeId = filter_var($_POST['id_empleado'] ?? null, FILTER_VALIDATE_INT);
                if (!$employeeId) {
                    throw new DomainException('Selecciona un empleado válido.');
                }

                $pdo->beginTransaction();
                $employeeQuery = $pdo->prepare('SELECT id_empleado FROM empleados WHERE id_empleado = ? FOR UPDATE');
                $employeeQuery->execute([$employeeId]);
                if (!$employeeQuery->fetch()) {
                    throw new DomainException('El empleado ya no está disponible.');
                }
                $salesQuery = $pdo->prepare('SELECT COUNT(*) FROM ventas WHERE id_empleado = ?');
                $salesQuery->execute([$employeeId]);
                if ((int) $salesQuery->fetchColumn() > 0) {
                    throw new DomainException('No se puede eliminar un empleado con ventas registradas.');
                }

                $deleteQuery = $pdo->prepare('DELETE FROM empleados WHERE id_empleado = ?');
                $deleteQuery->execute([$employeeId]);
                $pdo->commit();
                $message = 'Empleado eliminado correctamente.';
            } else {
                throw new DomainException('La operación solicitada no es válida.');
            }

            $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo guardar el cambio. Verifica los datos e inténtalo de nuevo.'];
        }

        header('Location: index.php?pagina=' . rawurlencode($currentPage));
        exit;
    }

    $products = $pdo->query(
        'SELECT p.id_producto, p.nombre, p.precio, p.cantidad, COUNT(v.id_venta) AS cantidad_ventas
         FROM productos p
         LEFT JOIN ventas v ON v.id_producto = p.id_producto
         GROUP BY p.id_producto, p.nombre, p.precio, p.cantidad
         ORDER BY p.nombre'
    )->fetchAll();
    $availableProducts = array_values(array_map(
        static function (array $product): array {
            return [
                'id' => (int) $product['id_producto'],
                'nombre' => (string) ($product['nombre'] ?? 'Sin nombre'),
                'precio' => (float) $product['precio'],
                'stock' => (int) $product['cantidad'],
            ];
        },
        array_filter($products, static function (array $product): bool {
            return (int) $product['cantidad'] > 0;
        })
    ));
    $employees = $pdo->query(
        'SELECT e.id_empleado, e.nombre, e.telefono, e.ventas_mensuales, COUNT(v.id_venta) AS cantidad_ventas
         FROM empleados e
         LEFT JOIN ventas v ON v.id_empleado = e.id_empleado
         GROUP BY e.id_empleado, e.nombre, e.telefono, e.ventas_mensuales
         ORDER BY e.nombre'
    )->fetchAll();
    $pdo->exec('SET SESSION group_concat_max_len = 8192');
    $salesTotalCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT COALESCE(folio_venta, CONCAT("legacy-", id_venta)) AS grupo
            FROM ventas
            GROUP BY grupo
        ) AS ventas_agrupadas'
    )->fetchColumn();
    $salesPageCount = max(1, (int) ceil($salesTotalCount / $salesPageSize));
    $salesPage = min($salesPage, $salesPageCount);
    $salesOffset = ($salesPage - 1) * $salesPageSize;
    $recentSalesQuery = $pdo->prepare(
        'SELECT MAX(v.id_venta) AS id_venta, MAX(v.folio_venta) AS folio_venta,
                v.fecha_compra, e.nombre AS empleado,
            GROUP_CONCAT(p.nombre ORDER BY v.id_venta SEPARATOR 0x0A) AS productos,
            GROUP_CONCAT(v.cantidad ORDER BY v.id_venta SEPARATOR 0x0A) AS cantidades,
                COUNT(v.id_venta) AS lineas, SUM(v.total) AS total
         FROM ventas v
         INNER JOIN productos p ON p.id_producto = v.id_producto
         INNER JOIN empleados e ON e.id_empleado = v.id_empleado
         GROUP BY COALESCE(v.folio_venta, CONCAT("legacy-", v.id_venta)), v.id_empleado, v.fecha_compra, e.nombre
         ORDER BY MAX(v.id_venta) DESC
         LIMIT :page_size OFFSET :page_offset'
    );
    $recentSalesQuery->bindValue(':page_size', $salesPageSize, PDO::PARAM_INT);
    $recentSalesQuery->bindValue(':page_offset', $salesOffset, PDO::PARAM_INT);
    $recentSalesQuery->execute();
    $recentSales = $recentSalesQuery->fetchAll();
    if ($recentSales) {
        $salesFirstItem = $salesOffset + 1;
        $salesLastItem = $salesOffset + count($recentSales);
    }

    $summary = $pdo->query(
        'SELECT
            COALESCE(SUM(CASE WHEN fecha_compra = CURDATE() THEN total ELSE 0 END), 0) AS ventas_hoy,
            COUNT(DISTINCT CASE WHEN fecha_compra = CURDATE() THEN COALESCE(folio_venta, CONCAT("legacy-", id_venta)) END) AS cantidad_ventas
         FROM ventas'
    )->fetch();
    $todaySales = (float) $summary['ventas_hoy'];
    $todayCount = (int) $summary['cantidad_ventas'];
    $stockUnits = (int) $pdo->query('SELECT COALESCE(SUM(cantidad), 0) FROM productos')->fetchColumn();
    $monthlyLeader = $pdo->query(
        "SELECT e.id_empleado, e.nombre, COALESCE(SUM(v.total), 0) AS total_mes
         FROM empleados e
         LEFT JOIN ventas v ON v.id_empleado = e.id_empleado
            AND v.fecha_compra >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            AND v.fecha_compra < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
         GROUP BY e.id_empleado, e.nombre
         ORDER BY total_mes DESC, e.nombre ASC
         LIMIT 1"
    )->fetch();
    if (!$monthlyLeader || (float) $monthlyLeader['total_mes'] <= 0) {
        $monthlyLeader = null;
    }

    $monthNames = [
        '01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr',
        '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago',
        '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic',
    ];
    $chartCurrentMonth = new DateTimeImmutable('first day of this month');
    for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
        $monthDate = $chartCurrentMonth->modify('-' . $monthOffset . ' months');
        $monthKey = $monthDate->format('Y-m');
        $chartMonths[] = [
            'key' => $monthKey,
            'label' => $monthNames[$monthDate->format('m')],
        ];
        $storeMonthlySales[$monthKey] = 0.0;
    }

    $chartStartDate = $chartMonths[0]['key'] . '-01';
    $chartEndDate = $chartCurrentMonth->modify('+1 month')->format('Y-m-d');
    $storeSalesQuery = $pdo->prepare(
        'SELECT DATE_FORMAT(fecha_compra, "%Y-%m") AS mes, SUM(total) AS total_mes
         FROM ventas
         WHERE fecha_compra >= ? AND fecha_compra < ?
         GROUP BY mes
         ORDER BY mes'
    );
    $storeSalesQuery->execute([$chartStartDate, $chartEndDate]);
    foreach ($storeSalesQuery->fetchAll() as $monthSales) {
        if (array_key_exists($monthSales['mes'], $storeMonthlySales)) {
            $storeMonthlySales[$monthSales['mes']] = (float) $monthSales['total_mes'];
        }
    }
    $storeChartMax = max(array_values($storeMonthlySales));

    foreach ($employees as $employee) {
        $employeeId = (int) $employee['id_empleado'];
        $employeeMonthlySales[$employeeId] = [
            'nombre' => (string) ($employee['nombre'] ?? 'Sin nombre'),
            'ventas' => array_fill_keys(array_keys($storeMonthlySales), 0.0),
        ];
    }
    $employeeSalesQuery = $pdo->prepare(
        'SELECT v.id_empleado, DATE_FORMAT(v.fecha_compra, "%Y-%m") AS mes, SUM(v.total) AS total_mes
         FROM ventas v
         WHERE v.fecha_compra >= ? AND v.fecha_compra < ?
         GROUP BY v.id_empleado, mes
         ORDER BY v.id_empleado, mes'
    );
    $employeeSalesQuery->execute([$chartStartDate, $chartEndDate]);
    foreach ($employeeSalesQuery->fetchAll() as $employeeMonth) {
        $employeeId = (int) $employeeMonth['id_empleado'];
        if (isset($employeeMonthlySales[$employeeId]['ventas'][$employeeMonth['mes']])) {
            $employeeMonthlySales[$employeeId]['ventas'][$employeeMonth['mes']] = (float) $employeeMonth['total_mes'];
        }
    }
    $defaultChartEmployeeId = $monthlyLeader
        ? (int) $monthlyLeader['id_empleado']
        : (int) (array_key_first($employeeMonthlySales) ?? 0);
    $requestedChartEmployeeId = filter_var($_GET['empleado'] ?? $defaultChartEmployeeId, FILTER_VALIDATE_INT);
    $selectedChartEmployeeId = $requestedChartEmployeeId !== false
        && isset($employeeMonthlySales[$requestedChartEmployeeId])
        ? $requestedChartEmployeeId
        : $defaultChartEmployeeId;
    $selectedEmployeeChart = $employeeMonthlySales[$selectedChartEmployeeId] ?? null;
    if ($selectedEmployeeChart) {
        $employeeChartMax = max(array_values($selectedEmployeeChart['ventas']));
    }
} catch (Throwable $exception) {
    $connectionError = 'No se pudo conectar con la base de datos "ferreteria". Revisa que MariaDB esté activo y que los datos de conexión en config.php sean correctos.';
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#263640">
    <title><?= escape($currentPageInfo['title']) ?> | Ferretería</title>
    <link rel="stylesheet" href="styles.css">
    <script src="app.js" defer></script>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="?pagina=resumen" aria-label="Ferretería, inicio">
            <span class="brand-mark" aria-hidden="true">F</span>
            <span class="brand-name">Ferretería</span>
        </a>
        <?php if (!$connectionError): ?>
            <nav class="site-nav" aria-label="Secciones de la página">
                <div class="site-nav-inner">
                    <a href="?pagina=resumen" <?= $currentPage === 'resumen' ? 'aria-current="page"' : '' ?>>Resumen</a>
                    <a href="?pagina=ventas" <?= $currentPage === 'ventas' ? 'aria-current="page"' : '' ?>>Registrar venta</a>
                    <a href="?pagina=historial" <?= $currentPage === 'historial' ? 'aria-current="page"' : '' ?>>Últimas ventas</a>
                    <a href="?pagina=inventario" <?= $currentPage === 'inventario' ? 'aria-current="page"' : '' ?>>Inventario</a>
                    <a href="?pagina=empleados" <?= $currentPage === 'empleados' ? 'aria-current="page"' : '' ?>>Empleados</a>
                </div>
            </nav>
        <?php endif; ?>
        <div class="topbar-tools">
            <span class="topbar-label"><span class="status-dot"></span> Punto de venta</span>
            <label class="theme-picker">
                <span class="visually-hidden">Tema de color</span>
                <select id="theme-select" aria-label="Tema de color">
                    <option value="azul-niebla">Azul niebla</option>
                    <option value="verde-salvia">Verde salvia</option>
                    <option value="gris-mineral">Gris mineral</option>
                    <option value="oscuro">Oscuro</option>
                </select>
            </label>
        </div>
    </header>

    <main class="page-shell">
        <section class="page-heading" aria-labelledby="page-title">
            <div>
                <p class="eyebrow">OPERACIÓN DIARIA <span></span> <?= escape(date('d / m / Y')) ?></p>
                <h1 id="page-title"><?= escape($currentPageInfo['title']) ?></h1>
                <p class="heading-copy"><?= escape($currentPageInfo['description']) ?></p>
            </div>
            <div class="heading-stamp" aria-hidden="true">
                <span>F</span>
                <small>PUNTO DE VENTA</small>
            </div>
        </section>

        <?php if ($connectionError): ?>
            <div class="notice error" role="alert"><?= escape($connectionError) ?></div>
        <?php elseif ($flash): ?>
            <div class="notice <?= escape($flash['type']) ?>" role="status"><?= escape($flash['message']) ?></div>
        <?php endif; ?>

        <?php if (!$connectionError): ?>
            <?php if ($currentPage === 'resumen'): ?>
            <section class="metrics" id="resumen" aria-label="Resumen del día">
                <article class="metric metric-accent">
                    <span class="metric-label">VENTAS DE HOY</span>
                    <strong>$<?= number_format($todaySales, 2) ?></strong>
                    <span class="metric-note"><?= $todayCount ?> <?= $todayCount === 1 ? 'venta registrada' : 'ventas registradas' ?></span>
                </article>
                <article class="metric">
                    <span class="metric-label">UNIDADES EN INVENTARIO</span>
                    <strong><?= number_format($stockUnits) ?></strong>
                    <span class="metric-note">Existencias actuales</span>
                </article>
                <article class="metric metric-date">
                    <span class="metric-label">ESTADO DEL SISTEMA</span>
                    <strong><span class="status-dot"></span> En línea</strong>
                    <span class="metric-note">Base de datos conectada</span>
                </article>
                <article class="metric metric-leader">
                    <span class="metric-label">EMPLEADO DEL MES</span>
                    <strong><?= escape($monthlyLeader['nombre'] ?? 'Sin ventas') ?></strong>
                    <span class="metric-note">
                        <?= $monthlyLeader ? '$' . number_format((float) $monthlyLeader['total_mes'], 2) . ' en ventas este mes' : 'Aún no hay ventas este mes' ?>
                    </span>
                </article>
            </section>
            <section class="summary-charts" aria-label="Gráficas mensuales de ventas">
                <article class="chart-panel" aria-labelledby="store-chart-title">
                    <div class="chart-heading">
                        <div>
                            <p class="eyebrow">ÚLTIMOS SEIS MESES</p>
                            <h2 id="store-chart-title">Ventas generales</h2>
                        </div>
                    </div>
                    <p class="chart-description">Total vendido por la tienda, agrupado por mes.</p>
                    <?php
                        $chartBaseY = 196;
                        $chartPlotHeight = 142;
                        $chartLeft = 58;
                        $chartSlotWidth = 88;
                        $storeBarWidth = 38;
                        $storeScale = max(1, $storeChartMax);
                    ?>
                    <svg class="monthly-chart" viewBox="0 0 620 242" role="img" aria-labelledby="store-chart-title store-chart-description">
                        <desc id="store-chart-description">Ventas totales de la tienda durante los últimos seis meses.</desc>
                        <?php for ($gridIndex = 0; $gridIndex <= 4; $gridIndex++): ?>
                            <?php $gridY = $chartBaseY - ($chartPlotHeight * $gridIndex / 4); ?>
                            <?php $gridValue = $storeChartMax * $gridIndex / 4; ?>
                            <line class="chart-grid-line" x1="54" y1="<?= $gridY ?>" x2="608" y2="<?= $gridY ?>"></line>
                            <text class="chart-axis-label" x="48" y="<?= $gridY + 4 ?>" text-anchor="end"><?= escape(chartAmountLabel($gridValue)) ?></text>
                        <?php endfor; ?>
                        <?php foreach ($chartMonths as $monthIndex => $month): ?>
                            <?php
                                $monthSales = $storeMonthlySales[$month['key']];
                                $barHeight = $monthSales > 0 ? $monthSales / $storeScale * $chartPlotHeight : 0;
                                $barX = $chartLeft + ($monthIndex * $chartSlotWidth) + (($chartSlotWidth - $storeBarWidth) / 2);
                                $barY = $chartBaseY - $barHeight;
                            ?>
                            <g>
                                <rect class="store-sales-bar" x="<?= $barX ?>" y="<?= $barY ?>" width="<?= $storeBarWidth ?>" height="<?= $barHeight ?>" rx="2">
                                    <title><?= escape($month['label'] . ': ' . number_format($monthSales, 2)) ?></title>
                                </rect>
                                <text class="chart-value-label" x="<?= $barX + ($storeBarWidth / 2) ?>" y="<?= max(20, $barY - 8) ?>" text-anchor="middle"><?= escape(chartAmountLabel($monthSales)) ?></text>
                                <text class="chart-month-label" x="<?= $barX + ($storeBarWidth / 2) ?>" y="224" text-anchor="middle"><?= escape($month['label']) ?></text>
                            </g>
                        <?php endforeach; ?>
                    </svg>
                </article>

                <article class="chart-panel" aria-labelledby="employee-chart-title">
                    <div class="chart-heading">
                        <div>
                            <p class="eyebrow">VENTAS DEL MES</p>
                            <h2 id="employee-chart-title">Ventas por empleado</h2>
                        </div>
                        <form class="chart-month-filter" id="employee-filter" method="get">
                            <input type="hidden" name="pagina" value="resumen">
                            <label for="employee-chart-employee">Empleado</label>
                            <select id="employee-chart-employee" name="empleado">
                                <?php foreach ($employeeMonthlySales as $employeeId => $employee): ?>
                                    <option value="<?= (int) $employeeId ?>" <?= (int) $selectedChartEmployeeId === (int) $employeeId ? 'selected' : '' ?>><?= escape($employee['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <p class="chart-description">
                        <?= $selectedEmployeeChart ? 'Ventas de ' . escape($selectedEmployeeChart['nombre']) . ' por mes.' : 'Ventas mensuales por empleado.' ?>
                    </p>
                    <?php if (!$selectedEmployeeChart): ?>
                        <p class="empty-state">Agrega empleados y ventas para ver la comparativa mensual.</p>
                    <?php else: ?>
                        <?php
                            $employeeScale = max(1, $employeeChartMax);
                            $employeePlotLeft = 82;
                            $employeePlotWidth = 518;
                            $employeeBaseY = 190;
                            $employeePlotHeight = 136;
                            $employeeLinePoints = [];
                            foreach ($chartMonths as $monthIndex => $month) {
                                $employeeX = $employeePlotLeft + ($monthIndex * $employeePlotWidth / max(1, count($chartMonths) - 1));
                                $employeeY = $employeeBaseY - ($selectedEmployeeChart['ventas'][$month['key']] / $employeeScale * $employeePlotHeight);
                                $employeeLinePoints[] = number_format($employeeX, 2, '.', '') . ',' . number_format($employeeY, 2, '.', '');
                            }
                        ?>
                        <svg class="monthly-chart" viewBox="0 0 620 242" role="img" aria-labelledby="employee-chart-title employee-chart-description">
                            <desc id="employee-chart-description">Ventas mensuales de <?= escape($selectedEmployeeChart['nombre']) ?> durante los últimos seis meses.</desc>
                            <?php for ($gridIndex = 0; $gridIndex <= 4; $gridIndex++): ?>
                                <?php $gridY = $employeeBaseY - ($employeePlotHeight * $gridIndex / 4); ?>
                                <?php $gridValue = $employeeChartMax * $gridIndex / 4; ?>
                                <line class="chart-grid-line" x1="54" y1="<?= $gridY ?>" x2="608" y2="<?= $gridY ?>"></line>
                                <text class="chart-axis-label" x="48" y="<?= $gridY + 4 ?>" text-anchor="end"><?= escape(chartAmountLabel($gridValue)) ?></text>
                            <?php endfor; ?>
                            <polyline class="employee-sales-line" points="<?= escape(implode(' ', $employeeLinePoints)) ?>"></polyline>
                            <?php foreach ($chartMonths as $monthIndex => $month): ?>
                                <?php
                                    $employeeSales = $selectedEmployeeChart['ventas'][$month['key']];
                                    $employeeX = $employeePlotLeft + ($monthIndex * $employeePlotWidth / max(1, count($chartMonths) - 1));
                                    $employeeY = $employeeBaseY - ($employeeSales / $employeeScale * $employeePlotHeight);
                                ?>
                                <g>
                                    <circle class="employee-sales-point" cx="<?= $employeeX ?>" cy="<?= $employeeY ?>" r="5">
                                        <title><?= escape($selectedEmployeeChart['nombre'] . ', ' . $month['label'] . ' ' . substr($month['key'], 0, 4) . ': ' . number_format($employeeSales, 2)) ?></title>
                                    </circle>
                                    <text class="chart-month-label" x="<?= $employeeX ?>" y="224" text-anchor="middle"><?= escape($month['label']) ?></text>
                                </g>
                            <?php endforeach; ?>
                        </svg>
                    <?php endif; ?>
                </article>
            </section>
            <?php endif; ?>

            <?php if (in_array($currentPage, ['ventas', 'historial'], true)): ?>
            <div class="workspace-grid single-section-grid">
                <?php if ($currentPage === 'ventas'): ?>
                <section class="sale-panel" id="venta" aria-labelledby="sale-title">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">NUEVO MOVIMIENTO</p>
                            <h2 id="sale-title">Registrar venta</h2>
                        </div>
                        <span class="panel-index">01</span>
                    </div>

                    <?php if (!$employees): ?>
                        <p class="empty-state">Agrega al menos un empleado para comenzar a registrar ventas.</p>
                    <?php elseif (!$availableProducts): ?>
                        <p class="empty-state">No hay productos con existencias disponibles.</p>
                    <?php else: ?>
                        <form method="post" class="sale-form">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="register_sale">
                            <label for="id_empleado">Atendido por</label>
                            <select id="id_empleado" name="id_empleado" required>
                                <option value="">Selecciona un empleado</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= (int) $employee['id_empleado'] ?>"><?= escape($employee['nombre'] ?? 'Sin nombre') ?></option>
                                <?php endforeach; ?>
                            </select>

                            <div class="product-search-wrap">
                                <label for="producto-busqueda">Buscar producto</label>
                                <input id="producto-busqueda" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="product-suggestions" placeholder="Escribe el nombre del producto">
                                <div id="product-suggestions" class="product-suggestions" role="listbox" hidden></div>
                                <span id="producto-resultados" class="search-status" role="status" aria-live="polite"></span>
                            </div>

                            <div class="cart-add-row">
                                <div class="cart-quantity-field">
                                    <label for="cart-quantity">Cantidad</label>
                                    <input id="cart-quantity" type="number" min="1" step="1" value="1" inputmode="numeric" required>
                                </div>
                                <button id="add-cart-item" class="add-cart-button" type="button" disabled>Agregar al carrito <span aria-hidden="true">+</span></button>
                            </div>

                            <section class="sale-cart" aria-labelledby="cart-title">
                                <div class="cart-heading">
                                    <h3 id="cart-title">Productos de la venta</h3>
                                    <span id="cart-count">0 productos</span>
                                </div>
                                <p id="cart-empty" class="cart-empty">Busca y agrega productos para continuar.</p>
                                <ul id="cart-items" class="cart-items"></ul>
                                <div class="cart-total"><span>Total estimado</span><strong id="cart-total">$0.00</strong></div>
                            </section>
                            <div id="cart-hidden-inputs"></div>
                            <script type="application/json" id="product-catalog"><?= json_encode($availableProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

                            <button id="complete-sale" class="submit-button" type="submit" disabled>
                                <span>Registrar venta</span>
                                <span class="button-arrow" aria-hidden="true">&#8594;</span>
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="panel-footnote"><span class="status-dot"></span> La existencia se descuenta al confirmar.</div>
                </section>
                <?php endif; ?>

                <?php if ($currentPage === 'historial'): ?>
                <section class="history-panel" id="historial" aria-labelledby="history-title">
                    <div class="section-heading history-heading">
                        <div>
                            <p class="eyebrow">ACTIVIDAD RECIENTE</p>
                            <h2 id="history-title">Últimas ventas</h2>
                        </div>
                        <span class="panel-index"><?= $salesTotalCount ?> VENTAS</span>
                    </div>

                    <?php if (!$recentSales): ?>
                        <p class="empty-state">Todavía no hay ventas registradas.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>VENTA</th>
                                        <th>PRODUCTOS VENDIDOS</th>
                                        <th>CANTIDAD</th>
                                        <th>EMPLEADO</th>
                                        <th>FECHA</th>
                                        <th class="align-right">TOTAL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentSales as $sale): ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?php if ($sale['folio_venta'] && str_starts_with($sale['folio_venta'], 'DEMO2026')): ?>
                                                        <?= 'DEMO ' . escape(substr($sale['folio_venta'], 4, 4) . '/' . substr($sale['folio_venta'], 8, 2) . ' · E' . substr($sale['folio_venta'], 13, 2)) ?>
                                                    <?php elseif ($sale['folio_venta']): ?>
                                                        <?= 'Folio ' . escape(strtoupper(substr($sale['folio_venta'], 0, 8))) ?>
                                                    <?php else: ?>
                                                        <?= 'Venta #' . (int) $sale['id_venta'] ?>
                                                    <?php endif; ?>
                                                </strong>
                                            </td>
                                            <td class="sales-list-cell"><?= escape($sale['productos']) ?></td>
                                            <td class="sales-list-cell sales-quantity-cell"><?= escape($sale['cantidades']) ?></td>
                                            <td><?= escape($sale['empleado']) ?></td>
                                            <td><?= escape(date('d/m/Y', strtotime($sale['fecha_compra']))) ?></td>
                                            <td class="align-right amount">$<?= number_format((float) $sale['total'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="history-footer">
                        <span>Mostrando <?= $salesFirstItem ?>–<?= $salesLastItem ?> de <?= $salesTotalCount ?> ventas</span>
                        <?php if ($salesPageCount > 1): ?>
                            <nav class="sales-pagination" aria-label="Paginación de ventas">
                                <?php if ($salesPage > 1): ?>
                                    <a href="?pagina=historial&amp;ventas_pagina=<?= $salesPage - 1 ?>#historial" aria-label="Página anterior">Anterior</a>
                                <?php else: ?>
                                    <span class="pagination-disabled">Anterior</span>
                                <?php endif; ?>
                                <span class="pagination-current">Página <?= $salesPage ?> de <?= $salesPageCount ?></span>
                                <?php if ($salesPage < $salesPageCount): ?>
                                    <a href="?pagina=historial&amp;ventas_pagina=<?= $salesPage + 1 ?>#historial" aria-label="Página siguiente">Siguiente</a>
                                <?php else: ?>
                                    <span class="pagination-disabled">Siguiente</span>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($currentPage === 'inventario'): ?>
            <section class="inventory-section" id="inventario" aria-labelledby="inventory-title">
                <div class="inventory-heading">
                    <div>
                        <p class="eyebrow">CATÁLOGO Y EXISTENCIAS</p>
                        <h2 id="inventory-title">Control de stock</h2>
                    </div>
                    <span class="panel-index"><?= count($products) ?> PRODUCTOS</span>
                </div>

                <div class="inventory-grid">
                    <section class="inventory-list-panel" aria-label="Productos y existencias">
                        <?php if (!$products): ?>
                            <p class="empty-state">Aún no hay productos. Agrega el primero con el formulario.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="inventory-table">
                                    <thead>
                                        <tr>
                                            <th>PRODUCTO</th>
                                            <th>PRECIO</th>
                                            <th>EXISTENCIAS</th>
                                            <th>AJUSTAR STOCK</th>
                                            <th>ELIMINAR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $product): ?>
                                            <tr>
                                                <td><strong><?= escape($product['nombre'] ?? 'Sin nombre') ?></strong></td>
                                                <td>
                                                    <form method="post" class="product-price-form">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="update_product_price">
                                                        <input type="hidden" name="id_producto" value="<?= (int) $product['id_producto'] ?>">
                                                        <label class="visually-hidden" for="precio-<?= (int) $product['id_producto'] ?>">Precio de <?= escape($product['nombre'] ?? 'producto') ?></label>
                                                        <div class="product-price-input"><span>$</span><input id="precio-<?= (int) $product['id_producto'] ?>" name="precio" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= number_format((float) $product['precio'], 2, '.', '') ?>" required></div>
                                                        <button class="price-save-button" type="submit" aria-label="Guardar precio de <?= escape($product['nombre'] ?? 'producto') ?>" title="Guardar precio">Guardar</button>
                                                    </form>
                                                </td>
                                                <td><span class="stock-count"><?= number_format((int) $product['cantidad']) ?></span> unidades</td>
                                                <td>
                                                    <form method="post" class="stock-form">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="adjust_stock">
                                                        <input type="hidden" name="id_producto" value="<?= (int) $product['id_producto'] ?>">
                                                        <label class="visually-hidden" for="stock-<?= (int) $product['id_producto'] ?>">Cantidad a ajustar de <?= escape($product['nombre'] ?? 'producto') ?></label>
                                                        <input id="stock-<?= (int) $product['id_producto'] ?>" type="number" name="cantidad" min="1" step="1" value="1" inputmode="numeric" required>
                                                        <button class="stock-button add-stock" type="submit" name="movimiento" value="sumar">+ Sumar</button>
                                                        <button class="stock-button remove-stock" type="submit" name="movimiento" value="restar">− Restar</button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <form method="post" class="product-delete-form" onsubmit="return confirm('¿Eliminar este producto? Esta acción no se puede deshacer.')">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="delete_product">
                                                        <input type="hidden" name="id_producto" value="<?= (int) $product['id_producto'] ?>">
                                                        <button class="product-delete-button" type="submit" <?= (int) $product['cantidad_ventas'] > 0 ? 'disabled' : '' ?>>Eliminar</button>
                                                        <?php if ((int) $product['cantidad_ventas'] > 0): ?>
                                                            <span class="product-lock-note">Tiene ventas</span>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="new-product-panel" aria-labelledby="new-product-title">
                        <div class="section-heading">
                            <div>
                                <p class="eyebrow">AMPLÍA TU CATÁLOGO</p>
                                <h2 id="new-product-title">Nuevo producto</h2>
                            </div>
                            <span class="panel-index">+</span>
                        </div>
                        <form method="post" class="new-product-form">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create_product">
                            <label for="nuevo-nombre">Nombre del producto</label>
                            <input id="nuevo-nombre" name="nombre" type="text" maxlength="75" autocomplete="off" required>
                            <label for="nuevo-precio">Precio</label>
                            <div class="price-input"><span>$</span><input id="nuevo-precio" name="precio" type="number" min="0.01" max="99999999.99" step="0.01" placeholder="0.00" required></div>
                            <label for="nuevo-stock">Stock inicial</label>
                            <input id="nuevo-stock" name="cantidad" type="number" min="0" step="1" value="0" inputmode="numeric" required>
                            <button class="submit-button" type="submit">
                                <span>Agregar producto</span>
                                <span class="button-arrow" aria-hidden="true">&#8594;</span>
                            </button>
                        </form>
                        <div class="panel-footnote">Los ajustes de stock no generan una venta.</div>
                    </section>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($currentPage === 'empleados'): ?>
            <section class="employee-section" id="empleados" aria-labelledby="employee-title">
                <div class="inventory-heading">
                    <div>
                        <p class="eyebrow">EQUIPO DE TRABAJO</p>
                        <h2 id="employee-title">Administrar empleados</h2>
                    </div>
                    <span class="panel-index"><?= count($employees) ?> EMPLEADOS</span>
                </div>

                <div class="employee-grid">
                    <section class="employee-list-panel" aria-label="Empleados registrados">
                        <?php if (!$employees): ?>
                            <p class="empty-state">Aún no hay empleados. Agrega el primero con el formulario.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="employee-table">
                                    <thead>
                                        <tr>
                                            <th>DATOS DEL EMPLEADO</th>
                                            <th>VENTAS DEL MES</th>
                                            <th>ELIMINAR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $employee): ?>
                                            <tr>
                                                <td>
                                                    <form method="post" class="employee-edit-form">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="update_employee">
                                                        <input type="hidden" name="id_empleado" value="<?= (int) $employee['id_empleado'] ?>">
                                                        <label>
                                                            <span>Nombre</span>
                                                            <input type="text" name="nombre" maxlength="75" value="<?= escape($employee['nombre'] ?? '') ?>" required>
                                                        </label>
                                                        <label>
                                                            <span>Teléfono</span>
                                                            <input type="tel" name="telefono" maxlength="10" pattern="[0-9]{0,10}" inputmode="numeric" value="<?= escape((string) ($employee['telefono'] ?? '')) ?>">
                                                        </label>
                                                        <button class="employee-save-button" type="submit">Guardar</button>
                                                    </form>
                                                </td>
                                                <td>
                                                    <strong class="employee-sales">$<?= number_format((float) $employee['ventas_mensuales'], 2) ?></strong>
                                                    <span class="row-subtitle"><?= (int) $employee['cantidad_ventas'] ?> ventas registradas</span>
                                                </td>
                                                <td>
                                                    <form method="post" class="employee-delete-form">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="delete_employee">
                                                        <input type="hidden" name="id_empleado" value="<?= (int) $employee['id_empleado'] ?>">
                                                        <button class="employee-delete-button" type="submit" <?= (int) $employee['cantidad_ventas'] > 0 ? 'disabled' : '' ?>>Eliminar</button>
                                                        <?php if ((int) $employee['cantidad_ventas'] > 0): ?>
                                                            <span class="employee-lock-note">Tiene ventas</span>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="new-employee-panel" aria-labelledby="new-employee-title">
                        <div class="section-heading">
                            <div>
                                <p class="eyebrow">INCORPORA PERSONAL</p>
                                <h2 id="new-employee-title">Nuevo empleado</h2>
                            </div>
                            <span class="panel-index">+</span>
                        </div>
                        <form method="post" class="new-employee-form">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create_employee">
                            <label for="nuevo-empleado-nombre">Nombre completo</label>
                            <input id="nuevo-empleado-nombre" type="text" name="nombre" maxlength="75" autocomplete="name" required>
                            <label for="nuevo-empleado-telefono">Teléfono</label>
                            <input id="nuevo-empleado-telefono" type="tel" name="telefono" maxlength="10" pattern="[0-9]{0,10}" inputmode="numeric" autocomplete="tel">
                            <button class="submit-button" type="submit">
                                <span>Agregar empleado</span>
                                <span class="button-arrow" aria-hidden="true">&#8594;</span>
                            </button>
                        </form>
                        <div class="panel-footnote">Las ventas mensuales se calculan automáticamente.</div>
                    </section>
                </div>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <span>FERRETERÍA</span>
        <span>Inventario preciso. Trabajo bien hecho.</span>
    </footer>
</body>
</html>