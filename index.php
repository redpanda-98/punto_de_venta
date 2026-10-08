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

$connectionError = null;
$products = [];
$employees = [];
$recentSales = [];
$todaySales = 0.0;
$todayCount = 0;
$stockUnits = 0;

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
            header('Location: index.php');
            exit;
        }

        $employeeId = filter_input(INPUT_POST, 'id_empleado', FILTER_VALIDATE_INT);
        $productId = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);
        $quantity = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT);

        if (!$employeeId || !$productId || !$quantity || $quantity < 1) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Selecciona un empleado, un producto y una cantidad válida.'];
            header('Location: index.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $productQuery = $pdo->prepare('SELECT cantidad FROM productos WHERE id_producto = ? FOR UPDATE');
            $productQuery->execute([$productId]);
            $product = $productQuery->fetch();

            if (!$product) {
                throw new DomainException('El producto seleccionado ya no está disponible.');
            }
            if ($quantity > (int) $product['cantidad']) {
                throw new DomainException('No hay existencias suficientes para esa cantidad.');
            }

            $saleQuery = $pdo->prepare(
                'INSERT INTO ventas (id_empleado, id_producto, cantidad, fecha_compra) VALUES (?, ?, ?, CURDATE())'
            );
            $saleQuery->execute([$employeeId, $productId, $quantity]);
            $pdo->commit();

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Venta registrada y existencias actualizadas.'];
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo registrar la venta. Verifica los datos e inténtalo de nuevo.'];
        }

        header('Location: index.php');
        exit;
    }

    $products = $pdo->query('SELECT id_producto, nombre, precio, cantidad FROM productos ORDER BY nombre')->fetchAll();
    $employees = $pdo->query('SELECT id_empleado, nombre FROM empleados ORDER BY nombre')->fetchAll();
    $recentSales = $pdo->query(
        'SELECT v.id_venta, v.fecha_compra, v.cantidad, v.total, p.nombre AS producto, e.nombre AS empleado
         FROM ventas v
         INNER JOIN productos p ON p.id_producto = v.id_producto
         INNER JOIN empleados e ON e.id_empleado = v.id_empleado
         ORDER BY v.id_venta DESC
         LIMIT 8'
    )->fetchAll();

    $summary = $pdo->query(
        'SELECT
            COALESCE(SUM(CASE WHEN fecha_compra = CURDATE() THEN total ELSE 0 END), 0) AS ventas_hoy,
            COALESCE(SUM(CASE WHEN fecha_compra = CURDATE() THEN 1 ELSE 0 END), 0) AS cantidad_ventas
         FROM ventas'
    )->fetch();
    $todaySales = (float) $summary['ventas_hoy'];
    $todayCount = (int) $summary['cantidad_ventas'];
    $stockUnits = (int) $pdo->query('SELECT COALESCE(SUM(cantidad), 0) FROM productos')->fetchColumn();
} catch (Throwable $exception) {
    $connectionError = 'No se pudo conectar con la base de datos "ferreteria". Revisa que MariaDB esté activo y que los datos de conexión en config.php sean correctos.';
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#202a28">
    <title>Ventas | Ferretería</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="index.php" aria-label="Ferretería, inicio">
            <span class="brand-mark" aria-hidden="true">F</span>
            <span class="brand-name">Ferretería</span>
        </a>
        <span class="topbar-label"><span class="status-dot"></span> Punto de venta</span>
    </header>

    <main class="page-shell">
        <section class="page-heading" aria-labelledby="page-title">
            <div>
                <p class="eyebrow">OPERACIÓN DIARIA <span></span> <?= escape(date('d / m / Y')) ?></p>
                <h1 id="page-title">Ventas</h1>
                <p class="heading-copy">Registra una venta y mantén el inventario al día.</p>
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
            <section class="metrics" aria-label="Resumen del día">
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
            </section>

            <div class="workspace-grid">
                <section class="sale-panel" aria-labelledby="sale-title">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">NUEVO MOVIMIENTO</p>
                            <h2 id="sale-title">Registrar venta</h2>
                        </div>
                        <span class="panel-index">01</span>
                    </div>

                    <?php if (!$products || !$employees): ?>
                        <p class="empty-state">Agrega productos y empleados a la base de datos para comenzar a registrar ventas.</p>
                    <?php else: ?>
                        <form method="post" class="sale-form">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <label for="id_empleado">Atendido por</label>
                            <select id="id_empleado" name="id_empleado" required>
                                <option value="">Selecciona un empleado</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= (int) $employee['id_empleado'] ?>"><?= escape($employee['nombre'] ?? 'Sin nombre') ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label for="id_producto">Producto</label>
                            <select id="id_producto" name="id_producto" required>
                                <option value="">Selecciona un producto</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= (int) $product['id_producto'] ?>" <?= (int) $product['cantidad'] < 1 ? 'disabled' : '' ?>>
                                        <?= escape($product['nombre'] ?? 'Sin nombre') ?> · <?= (int) $product['cantidad'] ?> disponibles · $<?= number_format((float) $product['precio'], 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="quantity-row">
                                <label for="cantidad">Cantidad</label>
                                <span>El total se calcula automáticamente</span>
                            </div>
                            <input id="cantidad" name="cantidad" type="number" min="1" step="1" value="1" inputmode="numeric" required>

                            <button class="submit-button" type="submit">
                                <span>Registrar venta</span>
                                <span class="button-arrow" aria-hidden="true">&#8594;</span>
                            </button>
                        </form>
                    <?php endif; ?>
                    <div class="panel-footnote"><span class="status-dot"></span> La existencia se descuenta al confirmar.</div>
                </section>

                <section class="history-panel" aria-labelledby="history-title">
                    <div class="section-heading history-heading">
                        <div>
                            <p class="eyebrow">ACTIVIDAD RECIENTE</p>
                            <h2 id="history-title">Últimas ventas</h2>
                        </div>
                        <span class="panel-index">08</span>
                    </div>

                    <?php if (!$recentSales): ?>
                        <p class="empty-state">Todavía no hay ventas registradas.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>PRODUCTO</th>
                                        <th>EMPLEADO</th>
                                        <th>FECHA</th>
                                        <th class="align-right">TOTAL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentSales as $sale): ?>
                                        <tr>
                                            <td>
                                                <strong><?= escape($sale['producto']) ?></strong>
                                                <span class="row-subtitle"><?= (int) $sale['cantidad'] ?> <?= (int) $sale['cantidad'] === 1 ? 'unidad' : 'unidades' ?></span>
                                            </td>
                                            <td><?= escape($sale['empleado']) ?></td>
                                            <td><?= escape(date('d/m/Y', strtotime($sale['fecha_compra']))) ?></td>
                                            <td class="align-right amount">$<?= number_format((float) $sale['total'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="history-footer">Mostrando <?= count($recentSales) ?> de las ventas más recientes</div>
                </section>
            </div>
        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <span>FERRETERÍA</span>
        <span>Inventario preciso. Trabajo bien hecho.</span>
    </footer>
</body>
</html>